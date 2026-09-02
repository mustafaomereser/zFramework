<?php

namespace zFramework\Core;

class View
{
    /**
     * How deep the current render is. Only used to tell the outermost view from
     * the ones it renders inside itself.
     */
    private static int $depth = 0;

    static $binds             = [];
    static $config            = [];
    static $view;
    static $view_name;
    static $data;
    static $sections          = [];
    static $directives        = [];
    static $compiledFiles     = [];
    static $hasDynamicExtends = false;

    /**
     * Tracks which bind keys were used during compilation
     * so they can be re-applied on cache hits.
     */
    static $usedBinds         = [];

    /**
     * Prepare config.
     *
     * Config keys:
     *   dir      - primary views directory
     *   views    - views directory for includes
     *   suffix   - file suffix (e.g. 'blade')
     *   caching  - enable/disable caching (recommended: false in dev, true in prod)
     *   caches   - cache directory path
     *   minify   - enable/disable HTML minification
     */
    public static function setSettings(array $config = []): void
    {
        self::reset();
        self::$config = $config;
    }

    /**
     * Drop everything left over from rendering.
     *
     * $binds, $directives and $config are registered at boot by providers and
     * stay; only the state of the render that just happened goes.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::reset();

        # Balanced by view() itself; zeroed here in case a render was abandoned
        # mid-way and left the count standing.
        self::$depth = 0;
    }

    /**
     * Reset all variables.
     */
    private static function reset(): void
    {
        self::$view               = null;
        self::$view_name          = null;
        self::$data               = null;
        self::$sections           = [];
        self::$compiledFiles      = [];
        self::$hasDynamicExtends  = false;
        self::$usedBinds          = [];
    }

    /**
     * Apply binds for a view name and merge into data.
     * Tracks used binds so they can be stored in manifest.
     */
    private static function applyBinds(string $view_name, array $data): array
    {
        if (isset(self::$binds[$view_name])) {
            if (!in_array($view_name, self::$usedBinds)) self::$usedBinds[] = $view_name;
            $data = self::$binds[$view_name]() + $data;
        }
        return $data;
    }

    /**
     * Compile a view without executing it.
     * Resolves extends, sections, yields and all directives
     * but leaves PHP tags (<?= ?>, <?php ?>) intact.
     *
     * Static state is saved and restored around recursive calls
     * (from @extends) so nested compilations don't clobber the parent.
     */
    private static function compile(string $view_name, array $data = [], bool $isExtend = false): array
    {
        $prevView     = self::$view;
        $prevViewName = self::$view_name;
        $prevData     = self::$data;
        $prevSections = self::$sections;

        $data = self::applyBinds($view_name, $data);

        self::$view_name = $view_name;

        $view_path = self::resolveViewPath($view_name);

        self::$compiledFiles[] = $view_path;
        self::$view            = file_get_contents($view_path);
        self::$data            = $data;

        if ($isExtend) self::$sections = $prevSections;

        self::parse($isExtend);

        $result = self::$view;

        // Merge bind data back into parent so view() gets the full dataset
        $mergedData = self::$data;

        self::$view      = $prevView;
        self::$view_name = $prevViewName;
        self::$data      = $prevData;

        return ['compiled' => $result, 'data' => $mergedData];
    }

    /**
     * Sanitize a view name for safe use in file paths.
     * Prevents path traversal attacks (e.g. "../../etc/passwd").
     */
    private static function sanitizeViewName(string $view_name): string
    {
        return str_replace(['..', '/', '\\'], '', $view_name);
    }

    /**
     * Get manifest path for a view.
     */
    private static function getManifestPath(string $view_name): string
    {
        return self::$config['caches'] . '/' . self::sanitizeViewName($view_name) . '.manifest.json';
    }

    /**
     * Get compiled cache path for a view.
     */
    private static function getCachePath(string $view_name): string
    {
        return self::$config['caches'] . '/' . self::sanitizeViewName($view_name) . '.compiled.php';
    }

    /**
     * Try to serve from cache without compiling.
     *
     * Reads the JSON manifest to get file paths and their stored mtimes,
     * compares with current filemtime (metadata only, no file content reads).
     * Returns [cache_path, bind_keys] if fresh, null otherwise.
     */
    private static function tryCache(string $view_name): ?array
    {
        $manifestPath = self::getManifestPath($view_name);
        if (!file_exists($manifestPath)) return null;

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || !isset($manifest['files'])) return null;

        foreach ($manifest['files'] as $file => $mtime) if (!is_file($file) || filemtime($file) !== $mtime) return null;

        $cachePath = self::getCachePath($view_name);
        if (!file_exists($cachePath)) return null;

        return [
            'path'  => $cachePath,
            'binds' => $manifest['binds'] ?? [],
        ];
    }

    /**
     * Save manifest and compiled cache for a view.
     *
     * Manifest stores:
     *   files - dependent file paths and their modification times
     *   binds - view names that have binds (re-applied on cache hit)
     */
    private static function saveCache(string $view_name, string $compiled): string
    {
        $manifest = ['files' => [], 'binds' => self::$usedBinds];
        foreach (self::$compiledFiles as $file) $manifest['files'][$file] = filemtime($file);

        file_put_contents2(self::getManifestPath($view_name), json_encode($manifest));

        $cachePath = self::getCachePath($view_name);
        file_put_contents2($cachePath, $compiled);

        return $cachePath;
    }

    /**
     * Clear all cached views and manifests.
     * Call this on deploy or when views are updated in production.
     *
     * Example: View::clearCache()
     */
    public static function clearCache(): void
    {
        $dir = self::$config['caches'] ?? '';
        if (!$dir || !is_dir($dir)) return;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) if ($file->isFile() && in_array($file->getExtension(), ['php', 'json'])) @unlink($file->getRealPath());
    }

    /**
     * Dispatch view.
     *
     * When caching is enabled:
     *   1. Check manifest for file modification times
     *   2. If all files unchanged -> re-apply stored binds, include cache directly
     *   3. If any file changed -> compile, minify, save cache + manifest (with bind list)
     *
     * Binds always run (cache hit or miss) because they return runtime data
     * (DB queries, auth state etc.) that cannot be cached.
     */
    public static function view(string $view_name, array $data = [])
    {
        # Views nest - a layout renders a page, pagination renders its own view -
        # so only the outermost call is timed. Timing each would report the same
        # milliseconds several times over.
        # Not loaded means nothing is profiling; see the same guard in Route::call().
        $timing = class_exists(\zFramework\Core\Profiler::class, false) && \zFramework\Core\Profiler::active() && self::$depth === 0 ? hrtime(true) : 0;
        self::$depth++;

        try {
            return self::render($view_name, $data, $timing);
        } finally {
            self::$depth--;
        }
    }

    /**
     * @param string $view_name
     * @param array  $data
     * @param float  $timing hrtime() when the outermost render started, or 0.
     * @return string
     */
    private static function render(string $view_name, array $data, float $timing = 0)
    {
        $caching = (self::$config['caching'] ?? false) && !empty(self::$config['caches']);
        $cache   = null;
        $result  = null;

        # Per render, not per process. A view() called from inside an already compiled
        # template inherited whatever the outer one had collected, so its manifest
        # listed files it never uses - any edit invalidated it - and carried the outer
        # view's binds, which a cache hit then replayed: the first caller's values
        # baked into every request after it. The recursion inside compile(), which is
        # where @extends and @include belong, still accumulates as it should.
        self::$compiledFiles = [];
        self::$usedBinds     = [];

        if ($caching) $result = self::tryCache($view_name);

        if ($result) {
            // Cache hit: re-apply all binds from the full chain
            foreach ($result['binds'] as $bindKey) $data = self::applyBinds($bindKey, $data);

            $cache  = $result['path'];
            $output = (function () use ($data, $cache) {
                ob_start();
                extract($data);
                include($cache);
                return ob_get_clean();
            })();
        } else {
            $result   = self::compile($view_name, $data);
            $compiled = $result['compiled'];
            $data     = $result['data'];

            if (self::$config['minify'] ?? false) $compiled = self::minifyTemplate($compiled);
            if ($caching && !self::$hasDynamicExtends) $cache = self::saveCache($view_name, $compiled);

            $output = (function () use ($data, $compiled) {
                ob_start();
                extract($data);
                echo eval('?>' . $compiled);
                return ob_get_clean();
            })();
        }

        self::reset();

        if ($timing) \zFramework\Core\Profiler::mark('view', hrtime(true) - $timing);

        return $output;
    }

    /**
     * Bind extra parameters to a view.
     * @param string $view
     * @param object $callback
     * @return \Closure
     */
    public static function bind(string $view, \Closure $callback): \Closure
    {
        return self::$binds[$view] = $callback;
    }

    /**
     * Convert dot-notation view name to file path.
     * Example: "admin.users.index" => "admin/users/index.blade.php"
     */
    private static function parseViewName(string $name): string
    {
        $name = str_replace('.', '/', $name);
        return $name . (!empty(self::$config['suffix']) ? '.' . self::$config['suffix'] : '') . '.php';
    }

    /**
     * CSS at-rules that may appear inside a <style> block.
     * These are stylesheet syntax, not view directives.
     */
    private const CSS_AT_RULES = [
        'charset',
        'color-profile',
        'container',
        'counter-style',
        'document',
        'font-face',
        'font-feature-values',
        'font-palette-values',
        'import',
        'keyframes',
        'layer',
        'media',
        'namespace',
        'page',
        'property',
        'scope',
        'starting-style',
        'supports',
        'viewport',
        '-webkit-keyframes',
        '-moz-keyframes',
        '-ms-keyframes',
        '-o-keyframes',
    ];

    /**
     * Placeholder that stands in for the "@" of a CSS at-rule during parsing.
     */
    private const CSS_AT_PLACEHOLDER = '___ZF_CSS_AT___';

    /**
     * Mask the "@" of every CSS at-rule inside <style> blocks.
     *
     * Without this, "@media (max-width: 600px)" or "@page { margin: 0 }" is fair
     * game for any pass matching "@word" - most notably customDirectives(), whose
     * pattern makes the argument list optional, so a directive registered as
     * "media" or "page" would rewrite the stylesheet.
     *
     * Only the "@" is replaced, so {{ $var }} and real directives keep working
     * inside <style>.
     */
    private static function maskCssAtRules(): void
    {
        $rules = implode('|', array_map(fn($rule) => preg_quote($rule, '/'), self::CSS_AT_RULES));

        self::$view = preg_replace_callback(
            '/<style\b[^>]*>[\s\S]*?<\/style>/i',
            fn($block) => preg_replace('/@(?=(?:' . $rules . ')\b)/i', self::CSS_AT_PLACEHOLDER, $block[0]),
            self::$view
        );
    }

    /**
     * Restore the "@" of masked CSS at-rules once all passes are done.
     */
    private static function unmaskCssAtRules(): void
    {
        self::$view = str_replace(self::CSS_AT_PLACEHOLDER, '@', self::$view);
    }

    /**
     * Run all parse passes on the current view.
     */
    private static function parse(bool $isExtend = false): void
    {
        self::parseComments();
        self::parseIncludes();
        self::maskCssAtRules();
        self::parsePHP();
        self::parseVariables();
        self::parseForEach();
        self::parseSections($isExtend);
        self::parseExtends();
        self::parseYields();
        self::customDirectives();
        self::parseIfBlocks();
        self::parseEmpty();
        self::parseIsset();
        self::parseForElse();
        self::parseJSON();
        self::parseDump();
        self::parseDd();

        # Only the outermost compilation unmasks. A layout used to lift its own mask
        # at the end of its pass, and the text was then embedded in the child and
        # walked again by the child's directives - so a plain `@page { margin: 1cm }`
        # in the layout's <style> met the `page` directive ViewDirectives registers,
        # became an `if` with no `endif`, and the eval threw a ParseError. The child's
        # own <style> was never affected, only the layout's.
        if (!$isExtend) self::unmaskCssAtRules();
    }

    /**
     * Minify the compiled template while preserving PHP tags,
     * textarea, pre and script blocks.
     *
     * PHP tags are kept intact so they still work when included from cache.
     * Only the static HTML/whitespace portions are minified.
     */
    private static function minifyTemplate(string $template): string
    {
        $parts = preg_split('/(<\?(?:php|=)[\s\S]*?\?>|<textarea.*?>.*?<\/textarea>|<pre.*?>.*?<\/pre>|<script.*?>.*?<\/script>|<input.*?>)/si', $template, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 0; $i < count($parts); $i++) {
            if ($i % 2 == 0) $parts[$i] = preg_replace(['/\s+(?=(?:[^"\'`]*["\'`][^"\'`]*["\'`])*[^"\'`]*$)/', '/>\s+</'], [' ', '><'], $parts[$i]);
            else if (strpos($parts[$i], '<script') !== false) {
                # A script with a src has no body, so there was never anything here to
                # minify - only its attributes to chew on, which is how src="//cdn..."
                # lost everything from the slashes to </script> and took the rest of the
                # page into the script with it.
                if (preg_match('/<script[^>]*\\ssrc\\s*=/i', $parts[$i])) {
                    $parts[$i] = trim($parts[$i]);
                    continue;
                }

                # String literals come out first and go back last. Without that the
                # comment stripper read '//example.com' as a comment, and the whitespace
                # passes reached inside join(', ') and shipped join(','). NUL marks them
                # because no template holds one and none of the passes below match it.
                $strings = [];
                $script  = preg_replace_callback(
                    '/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`)/s',
                    function ($m) use (&$strings) { $strings[] = $m[1]; return "\x00" . (count($strings) - 1) . "\x00"; },
                    $parts[$i]
                );

                $script = preg_replace('/(?<!:)\/\/.*|\/\*(?!!)[\s\S]*?\*\//', '', $script);
                $script = preg_replace('/\s+/', ' ', $script);
                $script = preg_replace('/\s*([{}:;,])\s*/', '$1', $script);
                $script = preg_replace('/\s*(\(|\)|\[|\])\s*/', '$1', $script);
                $script = preg_replace('/([=+\-*\/<>])\s+/', '$1', $script);
                $script = preg_replace('/\s+([=+\-*\/<>])/', '$1', $script);

                $parts[$i] = trim(preg_replace_callback('/\x00(\d+)\x00/', fn($m) => $strings[(int) $m[1]], $script));
            }
        }

        return implode('', $parts);
    }

    /**
     * Match a directive with balanced parentheses support.
     * Handles nested parentheses in closures and function calls.
     *
     * Example: @foreach(array_filter($items, fn($i) => $i > 5) as $item)
     * The old regex (.*?) would break at the first ")" but this method
     * counts depth so nested parens are handled correctly.
     */
    private static function matchBalancedParentheses(string $directive, string $view): array
    {
        $matches = [];
        $pattern = '/@' . preg_quote($directive, '/') . '\(/';
        $offset  = 0;

        while (preg_match($pattern, $view, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $startPos   = $m[0][1];
            $parenStart = $startPos + strlen($m[0][0]);
            $depth      = 1;
            $i          = $parenStart;
            $len        = strlen($view);

            while ($i < $len && $depth > 0) {
                $char = $view[$i];
                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    $i++;
                    while ($i < $len && $view[$i] !== $quote) {
                        if ($view[$i] === '\\') $i++;
                        $i++;
                    }
                } elseif ($char === '(')  $depth++;
                elseif ($char === ')') $depth--;

                $i++;
            }

            if ($depth === 0) $matches[] = [
                'inner' => substr($view, $parenStart, $i - $parenStart - 1),
                'start' => $startPos,
                'end'   => $i,
            ];

            $offset = $i;
        }

        return $matches;
    }

    /**
     * Parse @php ... @endphp blocks.
     *
     * @php
     *   $name = 'John';
     * @endphp
     */
    public static function parsePHP(): void
    {
        self::$view = preg_replace_callback(
            '/@php(.*?)@endphp/s',
            fn($code) => '<?php ' . $code[1] . ' ?>',
            self::$view
        );
    }

    # Strip comment blocks. Two spellings, same behaviour:
    #
    #   {{-- comment --}}
    #   {{/* comment */}}
    #
    # Runs before everything else, so a comment can hold anything the compiler
    # would otherwise act on - a {{ }} echo, an @include, a half-written tag.
    # Nothing reaches the compiled file, so comments cost nothing at runtime and
    # never show up in the page source.
    #
    # Hash comments rather than a docblock: the second spelling ends in the same
    # two characters that would close one.
    #
    # @param string $template
    # @return string
    private static function stripComments(string $template): string
    {
        return preg_replace('/\{\{(?:--.*?--|\/\*.*?\*\/)\}\}/s', '', $template);
    }

    /**
     * Parse comment blocks out of the template.
     * Example: {{-- this line is gone before anything else runs --}}
     * Example: {{ / * so is this one, without the spaces * / }}
     */
    public static function parseComments(): void
    {
        self::$view = self::stripComments(self::$view);
    }

    /**
     * Parse the two echo forms into PHP short tags.
     *
     *   {{ $title }}    escaped  => <?=e($title)?>
     *   {!! $html !!}   raw      => <?=$html?>
     *
     * Raw is handled first so its delimiters are gone before the {{ }} pattern
     * runs; the two cannot overlap, but the order makes that obvious.
     *
     * {{ }} escapes because that is the safe default to reach for without
     * thinking. Anything that emits markup - csrf(), inputMethod(), a rendered
     * partial - has to say so with {!! !!}.
     */
    public static function parseVariables(): void
    {
        self::$view = preg_replace_callback(
            '/\{!!(.*?)!!\}/s',
            fn($variable) => '<?=' . trim($variable[1]) . '?>',
            self::$view
        );

        self::$view = preg_replace_callback(
            '/\{\{(.*?)\}\}/',
            fn($variable) => '<?=e(' . trim($variable[1]) . ')?>',
            self::$view
        );
    }

    /**
     * Parse @foreach ... @endforeach with balanced parentheses.
     *
     * Example:
     *   @foreach($items as $item)
     *   @foreach(array_filter($items, fn($i) => $i > 5) as $item)
     */
    public static function parseForEach(): void
    {
        $matches = self::matchBalancedParentheses('foreach', self::$view);
        foreach (array_reverse($matches) as $match) self::$view  = substr_replace(self::$view, '<?php foreach(' . $match['inner'] . '): ?>', $match['start'], $match['end'] - $match['start']);
        self::$view = preg_replace('/@endforeach/', '<?php endforeach; ?>', self::$view);
    }

    /**
     * How deep @include may nest before it is treated as a circular include.
     */
    private const MAX_INCLUDE_DEPTH = 32;

    /**
     * Parse @include('view.name') directives.
     * Example: @include('partials.header')
     *
     * Runs until no @include is left, because preg_replace_callback never
     * re-scans what a callback returned: a partial pulled in by @include could
     * not @include anything itself. Its directive stayed in the output as plain
     * text and - worse - its file never reached the manifest, so editing it
     * never invalidated the cache.
     */
    /**
     * Where a view name lives on disk.
     *
     * Three candidates, in order: the configured view directory, a module, then
     * the project root - which is how module views (blog.views.client.pages.index)
     * and absolute names resolve. The last candidate is returned even when it does
     * not exist, so the caller reports the path it was actually looking for.
     *
     * @param string $view_name
     * @return string
     */
    private static function resolveViewPath(string $view_name): string
    {
        $path = self::$config['dir'] . '/' . self::parseViewName($view_name);
        if (!is_file($path)) $path = base_path('modules/' . self::parseViewName($view_name));
        if (!is_file($path)) $path = base_path(self::parseViewName($view_name));

        return $path;
    }

    public static function parseIncludes(): void
    {
        for ($depth = 0; $depth < self::MAX_INCLUDE_DEPTH; $depth++) {
            $before = self::$view;

            self::$view = preg_replace_callback('/@include\(\'(.*?)\'\)/', function ($viewName) {
                # Same resolution as any other view, so @include('partials.header')
                # finds the file wherever view('partials.header') would.
                $path                  = self::resolveViewPath($viewName[1]);
                self::$compiledFiles[] = $path;

                if (!is_file($path)) throw new \RuntimeException('View: @include(\'' . $viewName[1] . '\') in `' . self::$view_name . '` - no such view (' . $path . ').');

                # Strip the partial's own comments as it comes in: parse() already
                # ran parseComments over the parent, and this text arrives after.
                return self::stripComments(file_get_contents($path));
            }, self::$view);

            if (self::$view === $before) return;
        }

        throw new \RuntimeException('View: @include nested deeper than ' . self::MAX_INCLUDE_DEPTH . ' levels in `' . self::$view_name . '` - most likely a circular include.');
    }

    /**
     * Parse @extends directive with dynamic expression support.
     * Calls compile() so the parent layout is resolved without execution.
     *
     * Static extends are cacheable. Dynamic extends (@extends($var))
     * mark the view as uncacheable since the layout depends on runtime data.
     *
     * Examples:
     *   @extends('app.main')          - static name (cacheable)
     *   @extends('app.' . $layout)    - dynamic expression (not cacheable)
     *   @extends($layoutName)         - fully dynamic (not cacheable)
     */
    public static function parseExtends(): void
    {
        self::$view = preg_replace_callback('/@extends\(([^)]+)\)/', function ($match) {
            $expression = trim($match[1]);

            if (preg_match("/^'([^']+)'$/", $expression, $literal)) {
                $result     = self::compile($literal[1], self::$data, true);
                self::$data = $result['data'];
                return $result['compiled'];
            }

            self::$hasDynamicExtends = true;

            $resolvedName = (function () use ($expression) {
                extract(self::$data);
                return eval('return ' . $expression . ';');
            })();

            $result     = self::compile($resolvedName, self::$data, true);
            self::$data = $result['data'];
            return $result['compiled'];
        }, self::$view);
    }

    /**
     * Parse @yield('name') and replace with stored section content.
     * Example: @yield('content')
     * With fallback: @yield('title', 'Default title')
     */
    public static function parseYields(): void
    {
        self::$view = preg_replace_callback(
            '/@yield\(\s*\'([^\']*)\'\s*(?:,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*)?\)/s',
            function ($yield) {
                if (isset(self::$sections[$yield[1]])) return self::$sections[$yield[1]];
                return isset($yield[2]) ? str_replace(["\\'", '\\\\'], ["'", '\\'], $yield[2]) : '';
            },
            self::$view
        );
    }

    /**
     * Parse @section directives (inline and block variants).
     *
     * Inline:  @section('title', 'My Page Title')
     * Block:   @section('content') ... @endsection
     */
    public static function parseSections(bool $isExtend = false): void
    {
        # A layout declares defaults; the page that extends it decides. Both were
        # written into the same array in order, and the layout is compiled second, so
        # `@section('title', 'Site')` in the layout overwrote the page's own title -
        # the wrong way round, and silently.
        $keep = fn(string $name) => $isExtend && isset(self::$sections[$name]);

        self::$view = preg_replace_callback(
            '/@section\(\s*\'([^\']*)\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)/',
            function ($sectionDetail) use ($keep) {
                if (!$keep($sectionDetail[1]))
                    self::$sections[$sectionDetail[1]] = str_replace(["\\'", '\\\\'], ["'", '\\'], $sectionDetail[2]);
                return '';
            },
            self::$view
        );

        self::$view = preg_replace_callback('/@section\(\'(.*?)\'\)(.*?)@endsection/s', function ($sectionName) use ($keep) {
            if (!$keep($sectionName[1])) self::$sections[$sectionName[1]] = $sectionName[2];
            return '';
        }, self::$view);
    }

    /**
     * Register a custom directive.
     * @param string $key
     * @param object $callback
     */
    public static function directive(string $key, $callback): void
    {
        self::$directives[$key] = $callback;
    }

    /**
     * Apply all registered custom directives.
     */
    public static function customDirectives(): void
    {
        foreach (self::$directives as $key => $callback) {
            self::$view = preg_replace_callback(
                '/@' . $key . '(\(\'(.*?)\'\)|)/',
                fn($expression) => call_user_func($callback, $expression[2] ?? null),
                self::$view
            );
        }
    }

    /**
     * Parse @if / @elseif / @else / @endif with balanced parentheses.
     *
     * Example:
     *   @if($user)
     *   @elseif(count($items) > 0)
     *   @else
     *   @endif
     */
    public static function parseIfBlocks(): void
    {
        $matches = self::matchBalancedParentheses('if', self::$view);
        foreach (array_reverse($matches) as $match) self::$view = substr_replace(self::$view, '<?php if (' . $match['inner'] . '): ?>', $match['start'], $match['end'] - $match['start']);

        $matches = self::matchBalancedParentheses('elseif', self::$view);
        foreach (array_reverse($matches) as $match) self::$view = substr_replace(self::$view, '<?php elseif (' . $match['inner'] . '): ?>', $match['start'], $match['end'] - $match['start']);

        self::$view = preg_replace('/@else/', '<?php else: ?>', self::$view);
        self::$view = preg_replace('/@endif/', '<?php endif; ?>', self::$view);
    }

    /**
     * Parse @empty($var) ... @endempty.
     * Example: @empty($list) <p>No items.</p> @endempty
     */
    public static function parseEmpty(): void
    {
        self::$view = preg_replace_callback(
            '/@empty\((.*?)\)/',
            fn($expression) => '<?php if (empty(' . $expression[1] . ')): ?>',
            self::$view
        );
        self::$view = preg_replace('/@endempty/', '<?php endif; ?>', self::$view);
    }

    /**
     * Parse @isset($var) ... @endisset.
     * Example: @isset($user) <p>{{ $user->name }}</p> @endisset
     */
    public static function parseIsset(): void
    {
        self::$view = preg_replace_callback(
            '/@isset\((.*?)\)/',
            fn($expression) => '<?php if (isset(' . $expression[1] . ')): ?>',
            self::$view
        );
        self::$view = preg_replace('/@endisset/', '<?php endif; ?>', self::$view);
    }

    /**
     * Parse @forelse ... @empty ... @endforelse with balanced parentheses.
     *
     * Example:
     *   @forelse($users as $user)
     *     <p>{{ $user->name }}</p>
     *   @empty
     *     <p>No users found.</p>
     *   @endforelse
     */
    public static function parseForElse(): void
    {
        $matches = self::matchBalancedParentheses('forelse', self::$view);
        foreach (array_reverse($matches) as $match) {
            $data        = explode('as', $match['inner']);
            $array       = trim($data[0]);
            $replacement = '<?php if (isset(' . $array . ') && !empty(' . $array . ')): foreach(' . $match['inner'] . '): ?>';
            self::$view  = substr_replace(self::$view, $replacement, $match['start'], $match['end'] - $match['start']);
        }

        self::$view = preg_replace('/@empty/', '<?php endforeach; else: ?>', self::$view);
        self::$view = preg_replace('/@endforelse/', '<?php endif; ?>', self::$view);
    }

    /**
     * Parse @json($data) into json_encode output.
     * Example: @json($config) => {"key":"value"}
     */
    public static function parseJSON(): void
    {
        self::$view = preg_replace('/@json\((.*?)\)/', '<?=json_encode($1)?>', self::$view);
    }

    /**
     * Parse @dump($data) into var_dump output.
     * Example: @dump($user)
     */
    public static function parseDump(): void
    {
        self::$view = preg_replace('/@dump\((.*?)\)/', '<?php var_dump($1); ?>', self::$view);
    }

    /**
     * Parse @dd($data) into print_r output.
     * Example: @dd($config)
     */
    public static function parseDd(): void
    {
        self::$view = preg_replace('/@dd\((.*?)\)/', '<?php print_r($1); ?>', self::$view);
    }
}
