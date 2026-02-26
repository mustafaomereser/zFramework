<?php

namespace zFramework\Core;

class View
{
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

        $view_path = self::$config['dir'] . '/' . self::parseViewName($view_name);
        if (!is_file($view_path)) $view_path = base_path('modules/' . self::parseViewName($view_name));
        if (!is_file($view_path)) $view_path = base_path(self::parseViewName($view_name));

        self::$compiledFiles[] = $view_path;
        self::$view            = file_get_contents($view_path);
        self::$data            = $data;

        if ($isExtend) self::$sections = $prevSections;

        self::parse();

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
        $caching = (self::$config['caching'] ?? false) && !empty(self::$config['caches']);
        $cache   = null;
        $result  = null;

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

        return $output;
    }

    /**
     * Bind extra parameters to a view.
     * @param string $view
     * @param object $callback
     * @return array
     */
    public static function bind(string $view, $callback)
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
     * Run all parse passes on the current view.
     */
    private static function parse(): void
    {
        self::parseIncludes();
        self::parsePHP();
        self::parseVariables();
        self::parseForEach();
        self::parseSections();
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
                $script = $parts[$i];
                $script = preg_replace('/(?<!:)\/\/.*|\/\*(?!!)[\s\S]*?\*\//', '', $script);
                $script = preg_replace('/\s+/', ' ', $script);
                $script = preg_replace('/\s*([{}:;,])\s*/', '$1', $script);
                $script = preg_replace('/\s*(\(|\)|\[|\])\s*/', '$1', $script);
                $script = preg_replace('/([=+\-*\/<>])\s+/', '$1', $script);
                $script = preg_replace('/\s+([=+\-*\/<>])/', '$1', $script);
                $parts[$i] = trim($script);
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

    /**
     * Parse {{ $variable }} into PHP echo short tags.
     * Example: {{ $title }} => <?=$title?>
     */
    public static function parseVariables(): void
    {
        self::$view = preg_replace_callback(
            '/\{\{(.*?)\}\}/',
            fn($variable) => '<?=' . trim($variable[1]) . '?>',
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
     * Parse @include('view.name') directives.
     * Example: @include('partials.header')
     */
    public static function parseIncludes(): void
    {
        self::$view = preg_replace_callback('/@include\(\'(.*?)\'\)/', function ($viewName) {
            $path                  = self::$config['views'] . '/' . self::parseViewName($viewName[1]);
            self::$compiledFiles[] = $path;
            return file_get_contents($path);
        }, self::$view);
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
     */
    public static function parseYields(): void
    {
        self::$view = preg_replace_callback(
            '/@yield\(\'(.*?)\'\)/',
            fn($yieldName) => self::$sections[$yieldName[1]] ?? '',
            self::$view
        );
    }

    /**
     * Parse @section directives (inline and block variants).
     *
     * Inline:  @section('title', 'My Page Title')
     * Block:   @section('content') ... @endsection
     */
    public static function parseSections(): void
    {
        self::$view = preg_replace_callback('/@section\(\'(.*?)\', \'(.*?)\'\)/', function ($sectionDetail) {
            self::$sections[$sectionDetail[1]] = $sectionDetail[2];
            return '';
        }, self::$view);

        self::$view = preg_replace_callback('/@section\(\'(.*?)\'\)(.*?)@endsection/s', function ($sectionName) {
            self::$sections[$sectionName[1]] = $sectionName[2];
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
