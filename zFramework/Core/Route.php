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
    }

    /**
     * The route table, if it can be written to a cache file.
     *
     * var_export() cannot write a closure, and a half-written table is worse than
     * none: the missing half would 404. So one closure anywhere means no cache.
     *
     * @return array|false The routes, or false naming nothing - see cacheBlockers().
     */
    public static function compilable(): array|false
    {
        return count(self::cacheBlockers()) ? false : self::$routes;
    }

    /**
     * Routes that cannot be cached, keyed by route name with the reason.
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
        # A closure route makes the whole table uncacheable. `route cache` reports
        # which ones and why - cacheBlockers() names them.
        if (($routes = self::compilable()) === false) return false;

        # The lookup index goes in with the table. It is derived from $routes and
        # nothing else, and it is entirely scalar, so var_export() handles it.
        # Building it walks every route - which a cached table was still paying for
        # on every request, having skipped the parsing precisely to avoid that kind
        # of work.
        $temporary = $path . '.' . getmypid() . '.tmp';
        $content   = "<?php \nreturn " . var_export(['files' => $sources, 'routes' => $routes, 'index' => self::index()], true) . ";";

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

        $segments   = self::segments($url);
        $parameters = array_values(array_filter($segments, fn($segment) => strstr($segment, '{') && strstr($segment, '}')));

        self::$routes[] = [
            'url'        => $url,
            'method'     => strtoupper($method ?? ''),
            'parameters' => $parameters,
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
     * Compare a route's segments against the request's.
     *
     * @param array $URL Route segments, may contain {id} / {?id}
     * @param array $URI Request segments
     * @return array|false Extracted parameters, or false when it does not match.
     */
    private static function matchSegments(array $URL, array $URI): array|false
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

            $URL[$key] = $column;
            $parameters[str_replace(['{?', '{', '}'], '', $row)] = $column;
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

        $method = strtoupper(method());
        $URI    = self::segments(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
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

            $parameters = self::matchSegments($route['segments'], $URI);
            if ($parameters === false) continue;

            $found = ['key' => $key, 'parameters' => $parameters];
            break;
        }

        if ($found === null && $static !== null) $found = ['key' => $static['key'], 'parameters' => []];
        if ($found === null) return;

        $route  = self::$routes[$found['key']];
        $groups = $route['groups'] ?? [];

        if (!Csrf::check(isset($groups['no-csrf']))) abort(406, Lang::get('errors.csrf.no-verify'));

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
        self::$add_groups['middlewares'] = [array_merge((self::$groups['middlewares'][0] ?? []), $list), $callback];
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
