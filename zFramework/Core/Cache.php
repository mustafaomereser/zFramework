<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Session;

class Cache
{
    private static $path = FRAMEWORK_PATH . "\storage";

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
        return Session::callback(function () use ($name, $callback, $timeout) {
            if (!isset($_SESSION['caching'][$name]) || time() > $_SESSION['caching_timeout'][$name]) {
                $_SESSION['caching'][$name]         = $callback();
                $_SESSION['caching_timeout'][$name] = (time() + $timeout);
            }

            return $_SESSION['caching'][$name];
        });
    }

    /**
     * Remove Cache from cache's name.
     * 
     * @param string $name
     * @return bool
     */
    public static function remove(string $name): bool
    {
        return Session::callback(function () use ($name) {
            unset($_SESSION['caching'][$name]);
            return true;
        });
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
