<?php

namespace zFramework\Core;

class GlobalCache
{

    static $prefix = "";

    /**
     * Whether APCu may be used. Resolved once from config/cache.php.
     */
    private static ?bool $apcu = null;

    /**
     * Is APCu available and enabled?
     *
     * Extension present is not enough: config cache.apcu turns it off without
     * touching code, for when the working set no longer fits in apc.shm_size and
     * constant eviction costs more than the cache saves - or simply to measure
     * whether it is earning its keep.
     *
     * @return bool
     */
    public static function apcu(): bool
    {
        if (self::$apcu !== null) return self::$apcu;
        if (!function_exists('apcu_fetch')) return self::$apcu = false;

        $config = \zFramework\Core\Facades\Config::framework('cache');

        return self::$apcu = is_array($config) ? (bool) ($config['apcu'] ?? true) : true;
    }

    /**
     * Whether the redis config asks for it.
     */
    private static ?bool $redisEnabled = null;

    /**
     * Answered from config, without naming the Redis class: referring to it
     * autoloads Facades/Redis.php on every request only to be told "disabled".
     *
     * @return bool
     */
    private static function redisEnabled(): bool
    {
        if (self::$redisEnabled !== null) return self::$redisEnabled;

        $config = \zFramework\Core\Facades\Config::framework('redis');

        return self::$redisEnabled = is_array($config) && ($config['enabled'] ?? false);
    }

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
        $useL1 = self::apcu();
        if ($useL1) {
            $data = apcu_fetch($key, $found);
            if ($found) return $data;
        }

        # L2: Redis. Shared by every server, so this is where the truth lives and
        # where remove() actually reaches everyone.
        #
        # Stored wrapped, because Redis::get() answers null for a missing key and
        # a callback may legitimately return null - unwrapped, that value was
        # recomputed and re-written on every single call. An entry from before
        # the wrapper unserializes to something else and reads as one miss,
        # which only recomputes it.
        $redis = self::redisEnabled() && \zFramework\Core\Facades\Redis::available('cache');
        if ($redis) {
            $wrapped = \zFramework\Core\Facades\Redis::get($key, 'cache');
            if (is_array($wrapped) && array_key_exists('v', $wrapped)) {
                if ($useL1) apcu_store($key, $wrapped['v'], self::l1Ttl($timeout));
                return $wrapped['v'];
            }
        }

        $data = $callback();

        if ($redis) \zFramework\Core\Facades\Redis::set($key, ['v' => $data], $timeout, 'cache');
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
        $l1 = (int) (\zFramework\Core\Facades\Config::framework('redis.l1_ttl') ?: 5);
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
        if (self::redisEnabled()) \zFramework\Core\Facades\Redis::delete($key, 'cache');

        if (!self::apcu()) return false;
        apcu_delete($key);
        return true;
    }

    /**
     * Clear this installation's cache - both layers.
     *
     * Scoped to the prefix getName() builds. apcu_clear_cache() emptied the whole
     * shared segment, so clearing one site's cache dropped the schema cache of every
     * other site under the same FPM master, and they each paid a cold rebuild for it.
     * Redis is cleared too: with an L2 in place, dropping only L1 refills it from the
     * same stale values on the next read, which is not a clear at all.
     *
     * @return bool
     */
    public static function clear(): bool
    {
        self::getName('');

        if (self::redisEnabled() && $redis = \zFramework\Core\Facades\Redis::connection('cache')) {
            try {
                # SCAN, not KEYS: a cache big enough to want clearing is big enough
                # that KEYS would block the server while it walks it.
                $match  = \zFramework\Core\Facades\Redis::key(self::$prefix) . '*';
                $cursor = null;

                # do/while, not while: a scan pass may match nothing and still be
                # mid-iteration. The cursor says when it is over, the batch does not.
                do {
                    $keys = $redis->scan($cursor, $match, 500);
                    if ($keys) $redis->del($keys);
                } while ($cursor);
            } catch (\Throwable) {
            }
        }

        if (!self::apcu()) return false;

        # APCUIterator needs the prefix quoted: an install path hashes to hex, but
        # the dots around it are regex metacharacters.
        if (class_exists('APCUIterator', false)) {
            foreach (new \APCUIterator('/^' . preg_quote(self::$prefix, '/') . '/') as $entry) apcu_delete($entry['key']);
            return true;
        }

        apcu_clear_cache();
        return true;
    }
}
