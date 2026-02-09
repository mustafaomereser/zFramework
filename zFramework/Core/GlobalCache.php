<?php

namespace zFramework\Core;

class GlobalCache
{
    /**
     * Cache a data and get it before timeout.
     *
     * @param string   $name
     * @param \Closure $callback
     * @param int      $timeout
     * @return mixed
     */
    public static function cache(string $name, \Closure $callback, int $timeout = 5)
    {
        if (!function_exists('apcu_fetch')) $callback();

        $data = apcu_fetch($name, $success);
        if (!$success) {
            $data = $callback();
            apcu_store($name, $data, $timeout);
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
        apcu_delete($name);
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
