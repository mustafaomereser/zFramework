<?php

namespace zFramework\Core;

use ReflectionFunction;
use ReflectionMethod;
use zFramework\Core\Facades\Lang;

class Route
{
    /**
     * Route parameters
     */
    static $routes      = [];
    static $calledRoute = null;

    /**
     * Whether a compiled route table may be used. Set from config/route.php at
     * boot; assign directly to override for the rest of the request.
     */
    static $caching     = true;

    /**
     * Lookup index built from $routes, rebuilt when a route is registered.
     */
    private static ?array $index = null;

    /**
     * Group parameters.
     */
    static $groups      = [];
    static $add_groups  = [];

    /**
     * The matched route's group settings, published just before its middlewares
     * run - they are handed no arguments, and this is how one reads what its
     * group asked for. Cleared per request.
     */
    static $matchedGroups = [];

    /**
     * Find was setted route.
     * @param string $name
     * @param array $array
     * @param bool $return_bool
     * @return string|bool
     */
    public static function find(string $name, array $data = [], bool $return_bool = false)
    {
        $route_is_exists = true;
        $return = $name;
        if (!isset(self::$routes[$name])) $route_is_exists = false;

        if ($route_is_exists) {
            $url = self::$routes[$name]['url'];
            foreach ($data as $key => $val) @$url = str_replace(["{" . $key . "}", "{?" . $key . "}"], $val, $url);
            while (strstr($url, '//')) $url = str_replace(['//'], ['/'], $url);
            $return = (host() . script_name()) . rtrim($url, '/');
        }

        if ($return_bool) return $route_is_exists;
        return $return;
    }


    /**
     * Route Has keyword in URI.
     * @param string keyword
     * @return bool
     */
    public static function has(string $keyword)
    {
        return strstr(uri(), $keyword) ? true : false;
    }

    /**
     * Organize and clear route name.
     * @param string $name
     * @return string|\Closure
     */
    private static function nameOrganize(string $name)
    {
        $name = str_replace("..", ".", rtrim(ltrim(str_replace('/', '.', $name), '.'), '.'));
        if (strstr($name, '..')) return self::nameOrganize($name);
        return $name;
    }

    /**
     * set name for route.
     * @param string $name
     * @return self
     */
    public static function name(string $name)
    {
        $name = self::nameOrganize(@self::$groups['name'] . "/$name");

        $old_key = array_key_last(self::$routes);
        self::$routes[$name] = array_pop(self::$routes);
        if (!is_null(self::$calledRoute) && @self::$calledRoute['name'] == $old_key) self::$calledRoute['name'] = $name;

        # The route's array key just changed; the index points at the old one.
        self::$index = null;

        return new self();
    }

    /**
     * Redirect one url to another.
     *
     *   Route::redirect('/old-page', '/new-page');
     *   Route::redirect('/moved', 'https://example.com', 301);
     *
     * The destination is stored as data rather than a closure, so routes using
     * this can still be compiled into the route cache.
     *
     * @param string $url
     * @param string $to
     * @param int    $status 302 by default; 301 when the move is permanent.
     * @return self
     */
    public static function redirect(string $url, string $to, int $status = 302)
    {
        self::any($url, ['redirect' => $to, 'status' => $status]);
        return new self();
    }

    /**
     * Method Any
     * @return self
     */
    public static function any()
    {
        self::call(null, func_get_args());
        return new self();
    }

    /**
     * Method Get
     * @return self
     */
    public static function get()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Post
     * @return self
     */
    public static function post()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Patch
     * @return self
     */
    public static function patch()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Put
     * @return self
     */
    public static function put()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Delete
     * @return self
     */
    public static function delete()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Set a resource scheme.
     * @param string $url
     * @param string $callback
     * @return self
     */
    public static function resource(string $url, string $callback)
    {
        self::get($url, [$callback, 'index'])->name("$url.index");
        self::post($url, [$callback, 'store'])->name("$url.store");
        self::get("$url/create", [$callback, 'create'])->name("$url.create");
        self::get("$url/{id}", [$callback, 'show'])->name("$url.show");
        self::get("$url/{id}/edit", [$callback, 'edit'])->name("$url.edit");
        self::patch("$url/{id}", [$callback, 'update'])->name("$url.update");

        # PUT answers the same handler but stays unnamed on purpose. A route's
        # name is its key in the table, so naming both would leave only the one
        # declared second.
        self::put("$url/{id}", [$callback, 'update']);
        self::delete("$url/{id}", [$callback, 'delete'])->name("$url.delete");

        return new self();
    }

    /**
     * Put the table back to a known state, with the index that belongs to it.
     *
     * Used between requests in a long-running worker: the booted table is
     * restored, then route/dynamic adds this request's conditional routes on top.
     * Without the restore those definitions would accumulate request after
     * request.
     *
     * The index is handed back rather than discarded when the caller has one for
     * this exact table - the compiled cache carries it. If route/dynamic then
     * defines anything, call() clears the index itself and the next lookup
     * rebuilds it, so a stale index cannot survive a route being added.
     *
     * @param array      $routes
     * @param array|null $index The index built for $routes, when one is known.
     * @return void
     */
    public static function restoreTable(array $routes, ?array $index = null): void
    {
        self::$routes = $routes;
        self::$index  = $index;
    }

    /**
     * Adopt a table and index that were built elsewhere - the compiled cache.
     *
     * @param array      $routes
     * @param array|null $index
     * @return void
     */
    public static function useCompiled(array $routes, ?array $index = null): void
    {
        self::$routes = $routes;
        self::$index  = is_array($index) ? $index : null;
    }

    /**
     * The lookup index for the current table, if one has been built.
     *
     * @return array|null
     */
    public static function currentIndex(): ?array
    {
        return self::$index;
    }

    /**
     * Drop the match and the group stack, keep the table.
     *
     * $routes and $index are what a long-running worker registers once at boot and
     * reuses - clearing them would mean re-reading every route file per request,
     * which is the cost boot-once exists to avoid. Only the result of matching
     * this request goes.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$calledRoute = null;
        self::$groups      = [];
        self::$add_groups  = [];
        self::$matchedGroups = [];
    }

    /**
     * The table with every closure swapped for a note of where it came from.
     *
     * var_export() cannot write a closure, and until now one closure anywhere
     * meant no cache at all - the whole table parsed on every request because of
     * one route. Instead each closure becomes ['live' => file, 'at' => 'line/n']:
     * the n-th closure on that line of that file. At boot the cached table is
     * loaded, only those files are included again, and their closures are put
     * back where the notes are - same position, same key, so "first definition
     * wins" is undisturbed. See revive() and closures().
     *
     * @return array{routes: array, live: array} The table, and the files still included per request.
     */
    public static function compilable(): array
    {
        $routes = self::$routes;
        $live   = [];

        foreach (self::closures($routes) as $file => $entries) {
            $live[] = $file;

            foreach ($entries as [$key, $slot, , $at]) {
                $note = ['live' => $file, 'at' => $at];

                if ($slot === 'callback') $routes[$key]['callback'] = $note;
                else $routes[$key]['groups']['middlewares'][1] = $note;
            }
        }

        return ['routes' => $routes, 'live' => $live];
    }

    /**
     * Routes the cache cannot hold as data, keyed by route name with the reason.
     * Informational now: they are revived from their file rather than blocking.
     *
     * @return array
     */
    public static function cacheBlockers(): array
    {
        $blockers = [];

        foreach (self::$routes as $key => $route) {
            if (($route['callback'] ?? null) instanceof \Closure) $blockers[$key] = 'closure handler';
            elseif (($route['groups']['middlewares'][1] ?? null) instanceof \Closure) $blockers[$key] = 'closure in middleware fallback';
        }

        return $blockers;
    }

    /**
     * Every closure in a table, grouped by the file that defined it.
     *
     * A closure is named by the line it starts on and its position among the
     * closures on that line - "file:line/n". Counting position over the whole
     * file was the contract before, and it broke the moment a later file reused
     * a route name: the earlier closure dropped out of the merged table, every
     * closure after it moved up one, and the cache revived the wrong handler.
     * The line does not move when a sibling route is replaced.
     *
     * @param array $routes
     * @return array<string, array<int, array{0: int|string, 1: string, 2: \Closure, 3: string}>> file => [[key, slot, closure, at], ...]
     */
    private static function closures(array $routes): array
    {
        $found = [];
        $seen  = [];
        $base  = str_replace('\\', '/', BASE_PATH) . '/';

        $place = function (\Closure $closure) use ($base, &$seen): array {
            $ref  = new ReflectionFunction($closure);
            $path = str_replace('\\', '/', (string) $ref->getFileName());
            if (str_starts_with($path, $base)) $path = substr($path, strlen($base));
            $line = $ref->getStartLine();
            $n    = $seen["$path:$line"] = ($seen["$path:$line"] ?? -1) + 1;
            return [$path, "$line/$n"];
        };

        foreach ($routes as $key => $route) {
            if (($route['callback'] ?? null) instanceof \Closure) {
                [$file, $at] = $place($route['callback']);
                $found[$file][] = [$key, 'callback', $route['callback'], $at];
            }
            if (($route['groups']['middlewares'][1] ?? null) instanceof \Closure) {
                [$file, $at] = $place($route['groups']['middlewares'][1]);
                $found[$file][] = [$key, 'fallback', $route['groups']['middlewares'][1], $at];
            }
        }

        return $found;
    }

    /**
     * Put the closures back into a cached table.
     *
     * Each file named in the cache is included once more into a scratch table,
     * its closures are collected by line, and every ['live' => file, 'at' => "line/n"]
     * note in the cached table is replaced by the matching one. A file that no
     * longer yields what the cache expects means the cache is stale - a route was
     * added or removed there - and that is reported rather than served as a 404.
     *
     * Called after the providers and modules, not before: an uncached boot runs
     * the route files last, and a route file that reads what a provider set up
     * has to see the same thing on a cached boot.
     *
     * @param array $live Files, relative to BASE_PATH, as written by compilable().
     * @return void
     */
    public static function revive(array $live): void
    {
        if (!$live) return;

        $table  = self::$routes;
        $index  = self::$index;
        $donors = [];

        foreach ($live as $file) {
            self::$routes     = [];
            self::$groups     = [];
            self::$add_groups = [];

            \zFramework\Run::includer(BASE_PATH . '/' . $file);

            foreach (self::closures(self::$routes) as $from => $entries)
                foreach ($entries as [, , $closure, $at]) $donors[$from][$at] = $closure;
        }

        self::$routes = $table;
        self::$index  = $index;
        self::$groups = self::$add_groups = [];

        $resolve = function (array $note) use ($donors): \Closure {
            return $donors[$note['live']][$note['at'] ?? '']
                ?? throw new \RuntimeException("Route cache is stale: `{$note['live']}` no longer defines the closure at " . ($note['at'] ?? '#' . ($note['nth'] ?? '?')) . ". Run `php terminal route cache`.");
        };

        foreach (self::$routes as $key => $route) {
            if (isset($route['callback']['live'])) self::$routes[$key]['callback'] = $resolve($route['callback']);
            if (isset($route['groups']['middlewares'][1]['live'])) self::$routes[$key]['groups']['middlewares'][1] = $resolve($route['groups']['middlewares'][1]);
        }
    }

    /**
     * Files whose modification time decides whether a cache is still current.
     *
     * Directories are watched alongside files: a directory's mtime changes when a
     * route file is added or removed, which no per-file mtime would reveal.
     * route/dynamic is skipped - it never enters the cache, so editing it cannot
     * make one stale.
     *
     * @param array $included Paths included while routes were being collected.
     * @return array path => mtime
     */
    public static function sources(array $included): array
    {
        $dynamic = str_replace('\\', '/', BASE_PATH . '/route/dynamic');
        $sources = [];

        foreach ($included as $file) {
            $file = str_replace('\\', '/', $file);
            if (!strstr($file, '/route/') || strstr($file, $dynamic)) continue;

            if (is_file($file)) $sources[$file] = filemtime($file);
            if (is_dir($dir = dirname($file))) $sources[$dir] = filemtime($dir);
        }

        return $sources;
    }

    /**
     * Write the route table to a cache file, atomically.
     *
     * Written to a temporary file and renamed into place, so a request reading the
     * cache never sees a half-written one, and two requests racing to refresh it
     * end up with the same table either way.
     *
     * @param string $path
     * @param array  $sources
     * @return bool
     */
    public static function writeCache(string $path, array $sources): bool
    {
        # Closures leave as notes naming their file; those files are included again
        # at boot and the closures put back - see compilable() and revive().
        ['routes' => $routes, 'live' => $live] = self::compilable();

        # The lookup index goes in with the table. It is derived from $routes and
        # nothing else, and it is entirely scalar, so var_export() handles it.
        # Building it walks every route - which a cached table was still paying for
        # on every request, having skipped the parsing precisely to avoid that kind
        # of work.
        $temporary = $path . '.' . getmypid() . '.tmp';
        $content   = "<?php \nreturn " . var_export(['files' => $sources, 'live' => $live, 'routes' => $routes, 'index' => self::index()], true) . ";";

        if (!is_dir($directory = dirname($path))) @mkdir($directory, 0755, true);
        if (@file_put_contents($temporary, $content) === false) return false;

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }

        # Without this the old table survives in shared memory wherever
        # opcache.validate_timestamps is off - which is the recommended production
        # setting, so the refresh would appear to do nothing.
        if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);

        return true;
    }

    /**
     * Split a url into comparable segments.
     *
     * Empty segments are dropped so "/panel//urunler/" and "/panel/urunler" are
     * the same path - except for the root, which is a single empty segment.
     *
     * @param string $url
     * @return array
     */
    private static function segments(string $url): array
    {
        while (strstr($url, '//')) $url = str_replace(['//'], ['/'], $url);

        $parts = explode('/', (string) substr($url, 1));
        if (count($parts) == 1) return $parts;

        return array_values(array_filter($parts, fn($segment) => strlen($segment)));
    }

    /**
     * Register a route. Definitions are only collected here - match() resolves
     * the request once afterwards, through an index, rather than comparing the
     * url against every route as it is defined.
     *
     * @param string|null $method
     * @param array $args
     */
    public static function call($method, array $args): void
    {
        $url = @self::$groups['pre'] . $args[0];
        while (strstr($url, '//')) $url = str_replace(['//'], ['/'], $url);

        # `{id:int}` is split here: the type goes into its own map and the url
        # keeps a plain `{id}`, so route() substitutes it exactly as before and
        # a cached table stays a table of strings.
        $types = self::parameterTypes($url);
        if ($types) $url = preg_replace('/\{(\??)([\w]+):[\w]+\}/', '{$1$2}', $url);

        $segments   = self::segments($url);
        $parameters = array_values(array_filter($segments, fn($segment) => strstr($segment, '{') && strstr($segment, '}')));

        self::$routes[] = [
            'url'        => $url,
            'method'     => strtoupper($method ?? ''),
            'parameters' => $parameters,
            'types'      => $types,
            'groups'     => self::$groups,
            'callback'   => $args[1] ?? null,
            'segments'   => $segments,
            'dynamic'    => (bool) count($parameters),
        ];

        # A new definition invalidates the lookup index.
        self::$index = null;
    }

    /**
     * Lookup index over the registered routes, built once per request.
     *
     * Static routes go into a hash keyed by method and path, so resolving one is
     * a single lookup instead of a scan. Routes carrying {parameters} cannot be
     * hashed and stay in a list, in definition order.
     *
     * @return array
     */
    private static function index(): array
    {
        if (self::$index !== null) return self::$index;

        $index    = ['static' => [], 'dynamic' => []];
        $position = 0;

        foreach (self::$routes as $key => $route) {
            if ($route['dynamic'] ?? false) $index['dynamic'][$position] = $key;
            else {
                # First definition wins, matching the old "first match served" rule.
                $path = implode('/', $route['segments'] ?? []);
                $index['static'][$route['method']][$path] ??= ['position' => $position, 'key' => $key];
            }
            $position++;
        }

        return self::$index = $index;
    }

    /**
     * What `{id:int}` may contain. An unrecognised name constrains nothing, so
     * a typo weakens the route rather than making it match nothing at all.
     */
    private const PARAMETER_TYPES = [
        'int'   => '/^-?\d+$/',
        'uint'  => '/^\d+$/',
        'float' => '/^-?\d+(?:\.\d+)?$/',
        'alpha' => '/^[a-zA-Z]+$/',
        'alnum' => '/^[a-zA-Z0-9]+$/',
        'slug'  => '/^[a-zA-Z0-9_-]+$/',
        'uuid'  => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    ];

    /**
     * Read the types out of a url, before they are stripped from it.
     *
     * @param string $url
     * @return array<string, string> parameter name → type
     */
    private static function parameterTypes(string $url): array
    {
        if (!preg_match_all('/\{\??([\w]+):([\w]+)\}/', $url, $matches, PREG_SET_ORDER)) return [];

        $types = [];
        foreach ($matches as $match) $types[$match[1]] = strtolower($match[2]);

        return $types;
    }

    /**
     * Compare a route's segments against the request's.
     *
     * @param array $URL   Route segments, may contain {id} / {?id}
     * @param array $URI   Request segments
     * @param array $types Parameter name → type, for the constrained ones
     * @return array|false Extracted parameters, or false when it does not match.
     */
    private static function matchSegments(array $URL, array $URI, array $types = []): array|false
    {
        $parameters = [];

        foreach ($URL as $key => $row) {
            if (!(strstr($row, '{') && strstr($row, '}'))) continue;

            $column = $URI[$key] ?? null;

            if (!strlen($column ?? '')) {
                # Optional parameter with nothing to fill it: drop the segment.
                if (strstr($row, '{?')) unset($URL[$key]);
                continue;
            }

            $name = str_replace(['{?', '{', '}'], '', $row);

            # A typed parameter that does not match is not a 404 - the route
            # simply does not apply, and a later one gets its turn. That is what
            # lets /{id:int} and /{slug} live side by side.
            if (isset($types[$name])) {
                $pattern = self::PARAMETER_TYPES[$types[$name]] ?? null;
                if ($pattern && !preg_match($pattern, $column)) return false;
            }

            $URL[$key]        = $column;
            $parameters[$name] = $column;
        }

        return array_values($URL) == array_values($URI) ? $parameters : false;
    }

    /**
     * Resolve the current request against the registered routes.
     *
     * Call once, after every route file has been loaded.
     *
     * @return void
     */
    public static function match(): void
    {
        if (self::$calledRoute !== null || !count(self::$routes)) return;

        # HEAD is answered by the GET route: the SAPI drops the body itself. Left
        # as its own method it matched nothing and monitors saw a 404.
        $method = strtoupper(method());
        if ($method === 'HEAD') $method = 'GET';

        # Without the directory the application lives in: route() builds urls
        # as script_name() . url, so under /sub/ the links the framework itself
        # produced arrived here as /sub/x and matched nothing.
        $request = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        if (($prefix = script_name()) && str_starts_with($request, $prefix)) $request = substr($request, strlen($prefix)) ?: '/';

        $URI    = self::segments($request);
        $path   = implode('/', $URI);
        $index  = self::index();

        $static = $index['static'][$method][$path] ?? $index['static'][''][$path] ?? null;
        $found  = null;

        # A parameterised route defined before the static hit still wins, because
        # the old behaviour served whichever route was declared first.
        foreach ($index['dynamic'] as $position => $key) {
            if ($static !== null && $position > $static['position']) break;

            $route = self::$routes[$key];
            if (!empty($route['method']) && $route['method'] != $method) continue;

            $parameters = self::matchSegments($route['segments'], $URI, $route['types'] ?? []);
            if ($parameters === false) continue;

            $found = ['key' => $key, 'parameters' => $parameters];
            break;
        }

        if ($found === null && $static !== null) $found = ['key' => $static['key'], 'parameters' => []];
        if ($found === null) return;

        $route  = self::$routes[$found['key']];
        $groups = $route['groups'] ?? [];

        if (!Csrf::check(isset($groups['no-csrf']))) abort(406, Lang::get('errors.csrf.no-verify'));

        self::$matchedGroups = $groups;

        if (@$groups['middlewares']) {
            $middleware = Middleware::middleware($groups['middlewares'][0], function ($declines) use ($groups) {
                if (!count($declines)) return true;
                if ($groups['middlewares'][1]) $groups['middlewares'][1]($declines);
                return false;
            });

            if (!$middleware) return;
        }

        self::$calledRoute = [
            'name'       => $found['key'],
            'callback'   => $route['callback'],
            'parameters' => $found['parameters'],
        ];
    }

    /**
     * Run route with options.
     */
    public static function run(): void
    {
        if (self::$calledRoute === null) abort(404);

        $callback = self::$calledRoute['callback'];

        # A redirect carries its destination rather than a handler - see redirect().
        # Keyed by name, so it cannot be mistaken for a [Controller::class, 'method']
        # pair, which is indexed.
        if (is_array($callback) && isset($callback['redirect']))
            throw new ResponseSignal((int) ($callback['status'] ?? 302), ['Location' => (string) $callback['redirect']]);

        if (!in_array(gettype($callback), ['object', 'array', 'string'])) throw new \Exception('This type not valid.');

        switch (gettype($callback)) {
            case 'string':
                $callback    = explode('@', $callback);
                $callback[0] = strtok(findFile($callback[0], 'php', 'App\Controllers'), '.');
                $callback    = [new $callback[0]($callback[1]), $callback[1]];
                break;
            case 'array':
                $callback = [new $callback[0]($callback[1]), $callback[1]];
                break;
        }

        try {
            $reflection = new ReflectionMethod($callback[0], $callback[1]);
        } catch (\Throwable $e) {
            $reflection = new ReflectionFunction($callback);
        }

        $parameters = $reflection->getParameters();
        foreach ($parameters as $parameter) {
            $name       = $parameter->getName();
            $dependence = (string) $parameter->getType();

            # No route model binding here, and it is not an oversight: rows are
            # arrays, so `show(Post $post)` would have to hand an array to a
            # parameter typed as Post - a TypeError before the method body runs.
            # Binding would mean giving up array rows, and that is the framework.
            if (!empty(self::$calledRoute['parameters'][$name]) || !class_exists($dependence)) continue;
            self::$calledRoute['parameters'][$name] = new $dependence;
        }

        # Timed only while something is profiling; otherwise this is one
        # comparison. Covers the controller and anything it renders, so the view
        # stage reported separately is a part of this, not extra to it.
        # class_exists(..., false) does not autoload: with nothing profiling,
        # asking would be the only reason to compile Profiler.php. A profiled
        # request has it loaded already - the recorder calls Profiler::listen()
        # from the module callback, before any route is matched.
        $started = class_exists(\zFramework\Core\Profiler::class, false) && \zFramework\Core\Profiler::active() ? hrtime(true) : 0;

        echo call_user_func_array($callback, self::$calledRoute['parameters']);

        if ($started) \zFramework\Core\Profiler::mark('controller', hrtime(true) - $started);
    }

    #region Groups
    /**
     * Don't check csrf token
     */
    public static function noCSRF()
    {
        self::$add_groups['no-csrf'] = true;
        return new self();
    }

    /**
     * Set prefix.
     * URL prefix'i ile route adı (name) prefix'i ayrılabilir: $namePrefix verilirse route adları
     * URL'den bağımsız bu değerden üretilir; verilmezse URL prefix'i ad olarak kullanılır (geriye dönük uyumlu).
     * Örn: Route::pre('/devices', '/assets') -> URL /devices/*, route adı assets.* (call site'lar değişmeden URL sektöre göre değişebilir).
     * @param string $prefix
     * @param string|null $namePrefix
     * @return self
     */
    public static function pre(string $prefix, ?string $namePrefix = null)
    {
        self::$add_groups['pre']  = @self::$groups['pre'] . $prefix;
        self::$add_groups['name'] = @self::$groups['name'] . ($namePrefix ?? $prefix);
        return new self();
    }

    /**
     * Set route's middlewares.
     * @param array $list
     * @param \Closure|null $callback
     */
    public static function middleware(array $list, $callback = null)
    {
        # Merge with whatever is already pending, then with the enclosing group.
        # Reading only the enclosing group meant a second call at the same level
        # threw the first away - and made `->throttle()->middleware([...])` drop
        # the middleware throttle() had just added. Chaining accumulates now,
        # which is what it looks like it does.
        $existing = self::$add_groups['middlewares'][0] ?? self::$groups['middlewares'][0] ?? [];

        self::$add_groups['middlewares'] = [array_merge($existing, $list), $callback];

        return new self();
    }

    /**
     * Rate limit this group.
     *
     *   Route::pre('/api')->throttle(120)->noCSRF()->group(...);
     *   Route::throttle(5, 300)->group(fn() => Route::post('/sign-in', ...));
     *
     * The limit belongs where the routes it governs are declared, not in a
     * config file keyed by url prefix - a prefix table is a second copy of the
     * routing, and it stops matching silently the moment a url changes. That
     * bites hardest with a translated prefix, where the url is not even a
     * constant.
     *
     * Attaches the middleware as well, so this is the only call needed. The
     * settings ride along in the group and Throttle reads them back from
     * Route::$matchedGroups.
     *
     *   Route::pre('/api')->throttle(100, 10, block: 600)->group(...);
     *
     * With $block, passing the limit stops being "wait for the next window" and
     * becomes "refused for this long" - the answer to someone hammering an
     * endpoint, who would otherwise get a fresh allowance every window.
     *
     * Every argument may be left out; anything omitted comes from the throttle
     * defaults in config/framework.php, so bare `->throttle()` means "limit this
     * group the usual amount".
     *
     * @param int|null    $limit  Requests per window.
     * @param int|null    $window Seconds.
     * @param string|null $by     ip | token
     * @param int|null    $block  Seconds to refuse outright once the limit is passed.
     * @return self
     */
    public static function throttle(?int $limit = null, ?int $window = null, ?string $by = null, ?int $block = null)
    {
        # Only what was actually given: Throttle merges the rest over the config
        # defaults, so a null here has to mean "not said" rather than "zero".
        self::$add_groups['throttle'] = array_filter(
            compact('limit', 'window', 'by', 'block'),
            fn($value) => $value !== null
        );

        $middlewares = self::$add_groups['middlewares'][0] ?? self::$groups['middlewares'][0] ?? [];
        $callback    = self::$add_groups['middlewares'][1] ?? self::$groups['middlewares'][1] ?? null;

        if (!in_array(\App\Middlewares\Throttle::class, $middlewares, true)) $middlewares[] = \App\Middlewares\Throttle::class;

        self::$add_groups['middlewares'] = [$middlewares, $callback];

        return new self();
    }

    /**
     * Group routes with group options
     * @param \Closure $callback
     * @return mixed
     */
    public static function group(\Closure $callback)
    {
        $groupsReverse = [];
        foreach (self::$add_groups as $key => $setting) {
            $groupsReverse[$key] = self::$groups[$key] ?? null;
            self::$groups[$key]  = $setting;
        }
        $callback = $callback();
        foreach ($groupsReverse as $key => $reverse) self::$groups[$key] = $reverse;
        self::$add_groups = [];
        return $callback;
    }
    #endregion
}
