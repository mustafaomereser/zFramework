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
        # APCu is shared by every PHP process on the machine, so two installations
        # on one host would read each other's entries. Namespaced by install path,
        # and resolved here rather than at boot so nothing is paid for by requests
        # that never touch the cache.
        if (self::$prefix === '') self::$prefix = 'zf.' . substr(md5(defined('BASE_PATH') ? BASE_PATH : __DIR__), 0, 8) . '.';

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
        $key = self::getName($name);

        # L1: APCu. Server-local, so it cannot be invalidated from anywhere else -
        # which is why it is only trusted for a few seconds when there is an L2.
        $useL1 = function_exists('apcu_fetch');
        if ($useL1) {
            $data = apcu_fetch($key, $found);
            if ($found) return $data;
        }

        # L2: Redis. Shared by every server, so this is where the truth lives and
        # where remove() actually reaches everyone.
        $redis = \zFramework\Core\Facades\Redis::available('cache');
        if ($redis) {
            $data = \zFramework\Core\Facades\Redis::get($key, 'cache');
            if ($data !== null) {
                if ($useL1) apcu_store($key, $data, self::l1Ttl($timeout));
                return $data;
            }
        }

        $data = $callback();

        if ($redis) \zFramework\Core\Facades\Redis::set($key, $data, $timeout, 'cache');
        if ($useL1) apcu_store($key, $data, $redis ? self::l1Ttl($timeout) : ($timeout ?? 0));

        return $data;
    }

    /**
     * How long L1 may hold a value while an L2 exists.
     *
     * Capped by config('redis.l1_ttl') - the window in which two servers can
     * disagree after an invalidation. Never longer than the value's own TTL.
     *
     * @param int|null $timeout
     * @return int
     */
    private static function l1Ttl(int|null $timeout): int
    {
        $l1 = (int) (\zFramework\Core\Facades\Config::get('redis.l1_ttl') ?: 5);
        return $timeout ? min($l1, $timeout) : $l1;
    }

    /**
     * Remove cache by name.
     *
     * @param string $name
     * @return bool
     */
    public static function remove(string $name): bool
    {
        $key = self::getName($name);

        # Redis first: that reaches every server. The local APCu copy goes too, but
        # other servers keep theirs until l1_ttl expires - seconds, by design.
        \zFramework\Core\Facades\Redis::delete($key, 'cache');

        if (!function_exists('apcu_delete')) return false;
        apcu_delete($key);
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
