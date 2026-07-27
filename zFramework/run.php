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
     * @param bool $skipRoutes Route files are already in the cache; callbacks still run.
     */
    public static function loadModules(bool $skipRoutes = false)
    {
        foreach (self::$modules as $module) {
            if (!$module['status']) continue;
            if (!$skipRoutes) self::includer($module['path'] . "/route");
            if (isset($module['callback'])) $module['callback']();
        }
        return new self();
    }

    public static function begin()
    {
        global $storage_path;
        ob_start();
        try {
            # includes
            self::includer(FRAMEWORK_PATH . '/modules', false);
            self::includer(FRAMEWORK_PATH . '/modules/error_handlers/handle.php');

            # set view options
            \zFramework\Core\View::setSettings([
                'caches'  => "$storage_path/views",
                'dir'     => BASE_PATH . '/resource/views',
                'suffix'  => ''
            ] + Config::get('view'));
            #

            self::includer(BASE_PATH . '/App/Middlewares/autoload.php');

            # Compiled route table (php terminal route cache): skips parsing and
            # executing every route file, which on a large project is the single
            # most repeated piece of work in a request. Module callbacks still run -
            # only their route definitions come from the cache.
            $cached = \zFramework\Core\Route::$caching && is_file($route_cache = "$storage_path/routes.cache.php");
            if ($cached) \zFramework\Core\Route::$routes = include($route_cache);

            self::initProviders()::findModules(base_path('/modules'))::loadModules($cached);
            if (!$cached) self::includer(BASE_PATH . '/route');

            # route/dynamic is never cached and always runs. A cached table is a
            # snapshot: a route declared inside `if (Auth::check())` is frozen as it
            # was when the cache was built, which is wrong for anything that varies
            # per request. Such definitions belong here so they are re-evaluated.
            if ($cached) self::includer(BASE_PATH . '/route/dynamic');

            # Every route is registered by now: resolve the request once, then run it.
            \zFramework\Core\Route::match();
            \zFramework\Core\Route::run();
            \zFramework\Core\Facades\Alerts::unset(); # forgot alerts
            \zFramework\Core\Facades\JustOneTime::unset(); # forgot data
        } catch (\Throwable $errorHandle) {
            errorHandler($errorHandle);
        } catch (\Exception $errorHandle) {
            errorHandler($errorHandle);
        }

        # Release the response, then run whatever was handed to Defer::after().
        # Outside the try/catch on purpose: deferred jobs handle their own errors,
        # and an error page has already been rendered by this point anyway.
        \zFramework\Core\Facades\Defer::flush();
    }
}
