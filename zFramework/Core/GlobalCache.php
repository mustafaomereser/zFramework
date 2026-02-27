<?php

namespace zFramework\Core;

class GlobalCache
{

    static $prefix = "";

    /**
     * Build cache name
     *
     * @param string   $name
     * @return mixed
     */
    private static function getName(string $name)
    {
        return self::$prefix . $name;
    }

    /**
     * Cache a data and get it before timeout.
     *
     * @param string   $name
     * @param \Closure $callback
     * @param int|null $timeout
     * @return mixed
     */
    public static function cache(string $name, \Closure $callback, int|null $timeout = null)
    {
        if (!function_exists('apcu_fetch')) return $callback();

        $data = apcu_fetch(self::getName($name), $success);
        if (!$success) {
            $data = $callback();
            if (!is_null($timeout)) apcu_store(self::getName($name), $data, $timeout);
            else apcu_store(self::getName($name), $data);
        }

        return $data;
    }

    /**
     * Remove cache by name.
     *
     * @param string $name
     * @return bool
     */
    public static function remove(string $name): bool
    {
        if (!function_exists('apcu_delete')) return false;
        apcu_delete(self::getName($name));
        return true;
    }

    /**
     * Clear all cache
     */
    public static function clear(): bool
    {
        if (!function_exists('apcu_clear_cache')) return false;
        apcu_clear_cache();
        return true;
    }
}
