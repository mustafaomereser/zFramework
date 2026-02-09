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
        if (!function_exists('apcu_fetch')) return Cache::cache($name, $callback, $timeout);

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
        if (!function_exists('apcu_delete')) return Cache::remove($name);
        return apcu_delete($name);
    }

    /**
     * Clear all cache
     */
    public static function clear(): bool
    {
        if (!function_exists('apcu_clear_cache')) return Cache::clear();
        return apcu_clear_cache();
    }
}
