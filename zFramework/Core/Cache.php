<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Session;

class Cache
{
    private static $path = FRAMEWORK_PATH . "\storage";

    static $caches;
    static $timeouts;

    /**
     * Initial cache data.
     */
    public static function init()
    {
        self::$caches   = Session::get('caching');
        self::$timeouts = Session::get('caching_timeout');
    }

    /**
     * Cache a data and get it for before timeout.
     * 
     * @param string $name
     * @param object $callback / Must be Closure Object and it must do return.
     * @param int $timeout
     * @return mixed
     */
    public static function cache(string $name, $callback, int $timeout = 5)
    {

        if (!isset(self::$caches[$name]) || time() > self::$timeouts[$name]) {
            self::$caches[$name] = $callback();
            self::$timeouts[$name] = (time() + $timeout);
        }

        return self::$caches[$name];
    }

    /**
     * Remove Cache from cache's name.
     * 
     * @param string $name
     * @return bool
     */
    public static function remove(string $name): bool
    {
        unset(self::$caches[$name]);
        return true;
    }

    // public static function cache_view(string $view_name, string $view)
    // {
    //     return file_put_contents(self::$path . "\\views\\$view_name.html", $view);
    // }

    // public static function cache_find_view(string $view_name)
    // {
    //     return @file_get_contents(self::$path . "\\views\\$view_name.html");
    // }
}
