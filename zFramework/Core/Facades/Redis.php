<?php

namespace zFramework\Core\Facades;

/**
 * Thin wrapper over the phpredis extension.
 *
 * Everything the framework shares between servers - cache, sessions, queued jobs -
 * goes through here. It is deliberately forgiving: if the extension is missing,
 * Redis is disabled in config, or the server cannot be reached, available()
 * returns false and each caller falls back to its single-server behaviour rather
 * than taking the request down with it.
 *
 * Sessions are the exception - see config/session.php.
 */
class Redis
{
    /**
     * One connection per database index, for this request.
     */
    private static array $connections = [];

    /**
     * Set once a connection attempt fails, so a down server is not retried on
     * every call within the same request.
     */
    private static bool $failed = false;

    private static ?array $config = null;

    /**
     * @return array
     */
    private static function config(): array
    {
        return self::$config ??= (array) (\zFramework\Core\Facades\Config::get('redis') ?: []);
    }

    /**
     * Is Redis usable at all? (extension present, enabled in config, reachable)
     *
     * @param string $for Logical store: cache | session | queue
     * @return bool
     */
    public static function available(string $for = 'cache'): bool
    {
        if (self::$failed || !class_exists('\Redis')) return false;
        if (!(self::config()['enabled'] ?? false)) return false;

        return self::connection($for) !== null;
    }

    /**
     * Connection for a logical store, opened on first use.
     *
     * @param string $for cache | session | queue
     * @return \Redis|null
     */
    public static function connection(string $for = 'cache'): ?\Redis
    {
        if (self::$failed || !class_exists('\Redis')) return null;

        $config = self::config();
        if (!($config['enabled'] ?? false)) return null;

        $database = $config['database'][$for] ?? 0;
        if (isset(self::$connections[$database])) return self::$connections[$database];

        try {
            $redis = new \Redis();
            $redis->connect($config['host'] ?? '127.0.0.1', $config['port'] ?? 6379, $config['timeout'] ?? 1.5);

            if (!empty($config['password'])) $redis->auth($config['password']);
            if ($database) $redis->select($database);

            return self::$connections[$database] = $redis;
        } catch (\Throwable $e) {
            # Do not take the request down because a cache is unreachable; callers
            # degrade on their own. Sessions are the exception and say so loudly.
            self::$failed = true;
            if (function_exists('errorHandler') && ($config['log_failures'] ?? true)) errorHandler($e);
            return null;
        }
    }

    /**
     * Namespaced key.
     *
     * @param string $key
     * @return string
     */
    public static function key(string $key): string
    {
        return (self::config()['prefix'] ?? 'zf:') . $key;
    }

    /**
     * Read a value. Returns null when missing or unavailable - the two are
     * indistinguishable on purpose, since both mean "compute it yourself".
     *
     * @param string $key
     * @param string $for
     * @return mixed
     */
    public static function get(string $key, string $for = 'cache'): mixed
    {
        if (!$redis = self::connection($for)) return null;

        try {
            $raw = $redis->get(self::key($key));
            return $raw === false ? null : unserialize($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Write a value, optionally with a TTL in seconds.
     *
     * @param string   $key
     * @param mixed    $value
     * @param int|null $ttl
     * @param string   $for
     * @return bool
     */
    public static function set(string $key, mixed $value, ?int $ttl = null, string $for = 'cache'): bool
    {
        if (!$redis = self::connection($for)) return false;

        try {
            $raw = serialize($value);
            return $ttl ? (bool) $redis->setex(self::key($key), $ttl, $raw) : (bool) $redis->set(self::key($key), $raw);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Delete a key.
     *
     * @param string $key
     * @param string $for
     * @return bool
     */
    public static function delete(string $key, string $for = 'cache'): bool
    {
        if (!$redis = self::connection($for)) return false;

        try {
            return (bool) $redis->del(self::key($key));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Append a value to the tail of a list. Used by the queue.
     *
     * @param string $key
     * @param mixed  $value
     * @param string $for
     * @return bool
     */
    public static function push(string $key, mixed $value, string $for = 'queue'): bool
    {
        if (!$redis = self::connection($for)) return false;

        try {
            return (bool) $redis->lPush(self::key($key), serialize($value));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Block until a value can be popped from a list, or until $timeout seconds
     * pass. Returns null on timeout.
     *
     * @param string $key
     * @param int    $timeout Seconds; 0 blocks forever.
     * @param string $for
     * @return mixed
     */
    public static function pop(string $key, int $timeout = 5, string $for = 'queue'): mixed
    {
        if (!$redis = self::connection($for)) return null;

        try {
            $result = $redis->brPop([self::key($key)], $timeout);
            return $result ? unserialize($result[1]) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of entries waiting in a list.
     *
     * @param string $key
     * @param string $for
     * @return int
     */
    public static function size(string $key, string $for = 'queue'): int
    {
        if (!$redis = self::connection($for)) return 0;

        try {
            return (int) $redis->lLen(self::key($key));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Drop cached config and connections. For tests and long-running workers.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connections = [];
        self::$failed      = false;
        self::$config      = null;
    }
}
