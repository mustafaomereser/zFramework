<?php

namespace zFramework;

use zFramework\Core\Facades\Config;
use zFramework\Core\Route;

class Run
{
    static $loadtime;
    static $included = [];
    static $modules  = [];

    /**
     * Whether dump() has already emitted its stylesheet this request.
     */
    static bool $dumpStyled = false;

    /**
     * @param string|null $except Absolute path to skip (file or directory).
     */
    public static function includer($_path, $include_in_folder = true, $reverse_include = false, $ext = '.php', ?string $except = null)
    {
        $_path = str_replace('\\', '/', $_path);
        if ($except && $_path === str_replace('\\', '/', $except)) return null;

        if (is_file($_path)) {
            self::$included[] = $_path;
            return include($_path);
        }

        $path = [];
        if (is_dir($_path)) $path = array_values(array_diff(scandir($_path), ['.', '..']));
        if ($reverse_include) $path = array_reverse($path);

        foreach ($path as $inc) {
            $inc = "$_path/$inc";
            if ((is_dir($inc) && $include_in_folder)) self::includer($inc, true, false, $ext, $except);
            elseif (file_exists($inc) && strstr($inc, $ext)) {
                if ($except && $inc === str_replace('\\', '/', $except)) continue;
                include($inc);
                self::$included[] = $inc;
            };
        }
    }

    /**
     * Classes holding state that belongs to one request.
     *
     * Each one clears its own - the class knows which of its statics are boot
     * state and which are not, and a single list here is what keeps that
     * knowledge from being scattered across the framework.
     */
    private const REQUEST_STATE = [
        \zFramework\Core\Facades\Auth::class,
        \zFramework\Core\Facades\Session::class,
        \zFramework\Core\Facades\Defer::class,
        \zFramework\Core\Facades\Lang::class,
        \zFramework\Core\Facades\Alerts::class,
        \zFramework\Core\Facades\Response::class,
        \zFramework\Core\Facades\Mail::class,
        \zFramework\Core\Facades\PushNotification\PushNotification::class,
        \zFramework\Core\Facades\cURL::class,
        \zFramework\Core\Route::class,
        \zFramework\Core\View::class,
        \zFramework\Core\Facades\DB::class,
        \zFramework\Core\Facades\Redis::class,
        \zFramework\Core\Helpers\Http::class,
        \zFramework\Core\Facades\DB\Analyzer\Analyze::class,
        \zFramework\Core\Profiler::class,
    ];

    /**
     * How many entries $included held once boot finished.
     *
     * handle() keeps appending to $included as it includes route/dynamic and the
     * middleware autoloader, so resetState() trims back to this mark - otherwise
     * the array grows for as long as a worker lives. Only what boot collected is
     * read afterwards, by Route::sources() when writing the cache.
     */
    private static ?int $bootIncluded = null;

    /**
     * Return the process to a state where it can serve an unrelated request.
     *
     * Under PHP-FPM this is free - the process dies and takes everything with it.
     * Under a long-running server (RoadRunner, Swoole, a queue worker looping)
     * nothing dies, so anything left in a static is handed to the next request:
     * the previous visitor's identity, language, session or mail recipients.
     *
     * Boot state - the route table, view binds, config, database connections - is
     * deliberately kept. That is the whole point of booting once.
     *
     * Only runs under a CLI SAPI, which is where every long-running server lives.
     * Under FPM there is nothing to reset and a stray call - from a module, a
     * provider, application code - would only tear down state the request still
     * needs, so it returns without doing anything.
     *
     * @return void
     */
    public static function resetState(): void
    {
        if (PHP_SAPI !== 'cli') return;

        foreach (self::REQUEST_STATE as $class) if (method_exists($class, 'flushRequestState')) $class::flushRequestState();

        # Back to what boot collected; see $bootIncluded.
        if (self::$bootIncluded !== null && count(self::$included) > self::$bootIncluded)
            self::$included = array_slice(self::$included, 0, self::$bootIncluded);

        self::$dumpStyled = false;
    }

    public static function initProviders()
    {
        foreach (glob(BASE_PATH . "/App/Providers/*.php") as $provider) new ($provider = str_replace("/", "\\", str_replace([BASE_PATH . '/', '.php'], '', $provider)));
        return new self();
    }

    public static function findModules(string $path)
    {
        if (!is_dir($path)) return new self();
        foreach (scan_dir($path) as $module) {
            $info_path = "$path/$module/info.php";
            if (!is_file($info_path)) continue;
            $info = include($info_path);
            if ($info['status']) self::$modules[$info['sort']] = (['module' => $module, 'path' => "$path/$module"] + $info);
        }
        ksort(self::$modules);
        return new self();
    }

    /**
     * Load module route files.
     *
     * @param bool $skipRoutes Routes are already in the cache.
     */
    public static function loadModules(bool $skipRoutes = false)
    {
        foreach (self::$modules as $module) {
            if (!$module['status']) continue;
            if (!$skipRoutes) self::includer($module['path'] . "/route");
        }
        return new self();
    }

    /**
     * Run each module's callback.
     *
     * Called per request, not at boot. Module callbacks register menu entries,
     * view data and the like - things built from the current request, down to the
     * host in a url. Running them once at boot would freeze the first request's
     * values into every later response, which is exactly how a booted-once server
     * ends up serving links pointing at the wrong host.
     *
     * @return self
     */
    public static function runModuleCallbacks()
    {
        foreach (self::$modules as $module) {
            if (!$module['status']) continue;
            if (isset($module['callback'])) $module['callback']();
        }
        return new self();
    }

    /**
     * Whether boot() has already run in this process.
     */
    private static bool $booted = false;

    /**
     * Route table as it stood after boot, before any route/dynamic definitions.
     */
    private static array $bootRoutes = [];

    /**
     * The lookup index for $bootRoutes, when one is already known - the compiled
     * cache ships with it. Restored alongside the table so a request that adds no
     * dynamic routes never rebuilds it. Null means the first lookup builds one.
     */
    private static ?array $bootIndex = null;

    /**
     * Whether handle() has already served a request in this process.
     */
    private static bool $handled = false;

    /**
     * One request, start to finish: FPM's entry point.
     *
     * A long-running server calls boot() once and handle() per request instead -
     * see worker.php. Keeping both paths going through the same two methods is
     * what stops the two environments from drifting apart.
     *
     * @return void
     */
    public static function begin()
    {
        self::boot();
        self::handle();
    }

    /**
     * Everything that does not depend on the request: modules, providers, view
     * settings, the route table.
     *
     * Under FPM this runs per request because the process is new every time.
     * Under a long-running worker it runs once, which is where the speedup comes
     * from - and why nothing request-specific may happen here.
     *
     * @return void
     */

    public static function boot()
    {
        if (self::$booted) return;
        self::$booted = true;

        global $storage_path;
        try {
            self::includer(FRAMEWORK_PATH . '/modules', false);
            self::includer(FRAMEWORK_PATH . '/modules/error_handlers/loader.php');

            \zFramework\Core\View::setSettings([
                'caches'  => "$storage_path/views",
                'dir'     => BASE_PATH . '/resource/views',
                'suffix'  => ''
            ] + (array) Config::framework('view'));
            #

            # Before the route files, as it always was: a global middleware may set
            # up state the route definitions depend on - resolving a tenant and
            # registering its database connection, for one.
            self::includer(BASE_PATH . '/App/Middlewares/autoload.php');

            # Compiled route table (php terminal route cache): skips parsing and
            # executing every route file, which on a large project is the single
            # most repeated piece of work in a request. Module callbacks still run -
            # only their route definitions come from the cache.
            $route_config = Config::framework('route');
            $route_config = is_array($route_config) ? $route_config : [];
            \zFramework\Core\Route::$caching = (bool) ($route_config['caching'] ?? true);

            $route_cache = "$storage_path/routes.cache.php";
            $cache_vardi = is_file($route_cache);
            $cached      = \zFramework\Core\Route::$caching && $cache_vardi;

            if ($cached) {
                $compiled = include($route_cache);

                # auto-check: a route file edited since the cache was built makes the
                # cache stale, and the route files are loaded normally instead. Costs
                # one stat() per source file; turn it off in production, where the
                # deploy script rebuilds the cache anyway.
                if ($route_config['auto-check'] ?? false) {
                    foreach ($compiled['files'] ?? [] as $source => $mtime) {
                        if (!file_exists($source) || filemtime($source) !== $mtime) {
                            $cached = false;
                            break;
                        }
                    }
                }

                # The cache carries the lookup index alongside the table, so a
                # cached boot skips building it too. Older cache files predate it
                # and simply have none; the first lookup builds one as before.
                if ($cached) \zFramework\Core\Route::useCompiled($compiled['routes'] ?? $compiled, $compiled['index'] ?? null);
            }

            $before = self::$included;
            self::initProviders()::findModules(base_path('/modules'))::loadModules($cached);

            if (!$cached) {
                # Cacheable routes first, on their own, so the table written below
                # holds exactly what a CLI `route cache` would have produced.
                self::includer(BASE_PATH . '/route', true, false, '.php', BASE_PATH . '/route/dynamic');

                # Refresh only a cache that already existed and went stale. Creating
                # one is `php terminal route cache` - otherwise an application with a
                # closure route (which can never be cached) would rebuild the whole
                # table, discover that, and throw it away on every single request.
                if ($cache_vardi && \zFramework\Core\Route::$caching && ($route_config['auto-check'] ?? false))
                    \zFramework\Core\Route::writeCache($route_cache, \zFramework\Core\Route::sources(array_values(array_diff(self::$included, $before))));
            }

            # The table as booted. handle() restores it before each request, so
            # route/dynamic definitions do not pile up across requests.
            self::$bootRoutes   = \zFramework\Core\Route::$routes;
            self::$bootIndex    = \zFramework\Core\Route::currentIndex();
            self::$bootIncluded = count(self::$included);
        } catch (\Throwable $errorHandle) {
            errorHandler($errorHandle);
        }
    }

    /**
     * Serve one request against the booted application.
     *
     * @return void
     */
    public static function handle()
    {
        ob_start();

        try {
            # autoload.php executes the global middlewares rather than declaring
            # them, so under a booted-once server they would run exactly once - the
            # language middleware would resolve the first visitor's locale and every
            # later request would inherit it. boot() already ran them for this
            # request, so this only covers the ones after it.
            if (self::$handled) self::includer(BASE_PATH . '/App/Middlewares/autoload.php');
            self::$handled = true;

            # Same reasoning: module callbacks build menus and view data from the
            # current request.
            self::runModuleCallbacks();

            # Back to the booted table, then route/dynamic on top: those definitions
            # depend on request state, so they are re-evaluated every time and never
            # accumulate. A cached table could not hold them at all.
            \zFramework\Core\Route::restoreTable(self::$bootRoutes, self::$bootIndex);
            self::includer(BASE_PATH . '/route/dynamic');

            # Every route is registered by now: resolve the request once, then run it.
            \zFramework\Core\Route::match();
            \zFramework\Core\Route::run();
            \zFramework\Core\Facades\Alerts::unset(); # forgot alerts
            \zFramework\Core\Facades\JustOneTime::unset(); # forgot data
        } catch (\zFramework\Core\ResponseSignal $signal) {
            # abort(), redirect(), refresh(), a file download: the response is
            # ready and nothing else should run. Unlike die(), this leaves a
            # long-running worker alive to serve the next request.
            $signal->send();
            \zFramework\Core\Facades\Alerts::unset();
            \zFramework\Core\Facades\JustOneTime::unset();
        } catch (\Throwable $errorHandle) {
            errorHandler($errorHandle);
        }

        # Release the response, then run whatever was handed to Defer::after().
        # Outside the try/catch on purpose: deferred jobs handle their own errors,
        # and an error page has already been rendered by this point anyway.
        #
        # Only when the class is already loaded, which it is exactly when
        # Defer::after() was called - autoload is deliberately not triggered.
        # Unconditionally meant compiling Defer.php on every request for
        # nothing.
        if (class_exists(\zFramework\Core\Facades\Defer::class, false)) \zFramework\Core\Facades\Defer::flush();
    }
}
