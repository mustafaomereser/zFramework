<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Session;

class Cache
{
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

    /**
     * Clear all
     * 
     * @return bool
     */
    public static function clear(): bool
    {
        return Session::callback(function () {
            unset($_SESSION['caching']);
            return true;
        });
    }
}
