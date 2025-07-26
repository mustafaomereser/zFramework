<?php

namespace zFramework;

use zFramework\Core\Facades\Config;

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

        $path = array_values(array_diff(scandir($_path), ['.', '..']));
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

    public static function findModules()
    {
        if (!is_dir(base_path('/modules'))) return false;
        $modules         = scan_dir(base_path('/modules'));
        $include_modules = [];
        foreach ($modules as $module) {
            $info = include(base_path("/modules/$module/info.php"));
            if ($info['status']) $include_modules[$info['sort']] = (['module' => $module] + $info);
        }
        ksort($include_modules);
        self::$modules = $include_modules;
        return new self();
    }

    public static function loadModules()
    {
        foreach (self::$modules as $module) {
            self::includer(base_path("/modules/" . $module['module'] . "/route"));
            if ($module['status']) if (isset($module['callback'])) $module['callback']();
        }
        return new self();
    }

    public static function begin()
    {
        ob_start();
        try {
            # set view options

            // includes
            self::includer(BASE_PATH . '/zFramework/modules', false);
            self::includer(BASE_PATH . '/zFramework/modules/error_handlers/handle.php');
            self::includer(BASE_PATH . '/App/Middlewares/autoload.php');
            self::initProviders()::findModules()::loadModules();
            self::includer(BASE_PATH . '/route');

            \zFramework\Core\View::settingUP([
                'caches'  => FRAMEWORK_PATH . '/storage/views',
                'dir'     => BASE_PATH . '/resource/views',
                'suffix'  => ''
            ] + Config::get('view'));

            \zFramework\Core\Route::run();
            \zFramework\Core\Facades\Alerts::unset(); // forgot alerts
            \zFramework\Core\Facades\JustOneTime::unset(); // forgot data
        } catch (\Throwable $errorHandle) {
            errorHandler($errorHandle);
        } catch (\Exception $errorHandle) {
            errorHandler($errorHandle);
        }
    }
}
