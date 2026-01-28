<?php

namespace zFramework;

use zFramework\Core\Facades\Config;
use zFramework\Core\Route;

class Run
{
    static $loadtime;
    static $included = [];
    static $modules  = [];

    public static function includer($_path, $include_in_folder = true, $reverse_include = false, $ext = '.php')
    {
        $_path = str_replace('\\', '/', $_path);
        if (is_file($_path)) {
            self::$included[] = $_path;
            return include($_path);
        }

        $path = [];
        if (is_dir($_path)) $path = array_values(array_diff(scandir($_path), ['.', '..']));
        if ($reverse_include) $path = array_reverse($path);

        foreach ($path as $inc) {
            $inc = "$_path/$inc";
            if ((is_dir($inc) && $include_in_folder)) self::includer($inc);
            elseif (file_exists($inc) && strstr($inc, $ext)) {
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
            $info = include("$path/$module/info.php");
            if ($info['status']) self::$modules[$info['sort']] = (['module' => $module, 'path' => "$path/$module"] + $info);
        }
        ksort(self::$modules);
        return new self();
    }

    public static function loadModules()
    {
        foreach (self::$modules as $module) {
            if (!$module['status']) continue;
            self::includer($module['path'] . "/route");
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
            self::includer(BASE_PATH . '/zFramework/modules', false);
            self::includer(BASE_PATH . '/zFramework/modules/error_handlers/handle.php');

            # set view options
            \zFramework\Core\View::setSettings([
                'caches'  => "$storage_path/views",
                'dir'     => BASE_PATH . '/resource/views',
                'suffix'  => ''
            ] + Config::get('view'));
            #

            self::includer(BASE_PATH . '/App/Middlewares/autoload.php');
            self::initProviders()::findModules(base_path('/modules'))::loadModules();

            // if (!file_exists("$storage_path/routes.cache.php")) {
            self::includer(BASE_PATH . '/route');
            //     if (\zFramework\Core\Route::$caching) file_put_contents("$storage_path/routes.cache.php", "<?php return " . var_export(Route::$routes, true) . ";");
            // } else {
            //     Route::$routes = include("$storage_path/routes.cache.php");
            // }

            \zFramework\Core\Route::run();
            \zFramework\Core\Facades\Alerts::unset(); # forgot alerts
            \zFramework\Core\Facades\JustOneTime::unset(); # forgot data
        } catch (\Throwable $errorHandle) {
            errorHandler($errorHandle);
        } catch (\Exception $errorHandle) {
            errorHandler($errorHandle);
        }
    }
}
