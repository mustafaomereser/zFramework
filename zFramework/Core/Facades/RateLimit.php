<?php

namespace zFramework\Core\Facades;

/**
 * Fixed-window request counter.
 *
 *   $hit = RateLimit::hit('login:' . ip(), 5, 300);
 *   if (!$hit['allowed']) abort(429);
 *
 * Backed by redis when it is configured and reachable, where INCR makes the
 * count atomic across every worker and every machine. Without redis it falls
 * back to one file per key under storage/ratelimit, which is what a shared host
 * actually has - flock makes it correct for one machine, and one machine is
 * what a shared host is.
 *
 * Fixed window, not sliding: the count resets on a boundary, so a caller can
 * send up to twice the limit across two adjacent windows. That is the standard
 * trade and it is worth it here - a sliding window costs a sorted set per key
 * and a read of it on every request, to buy accuracy that matters for billing
 * and not for keeping a login form from being brute forced.
 */
class RateLimit
{
    /**
     * Where the file fallback keeps its counters.
     */
    private static ?string $dir = null;

    /**
     * Count one request against a key.
     *
     * @param string $key    Whatever identifies the caller - an ip, a token, a user id.
     * @param int    $limit  Requests allowed per window.
     * @param int    $window Window length in seconds.
     * @return array{allowed: bool, count: int, remaining: int, retry_after: int}
     */
    public static function hit(string $key, int $limit, int $window): array
    {
        [$count, $resetsIn] = Redis::available('cache')
            ? self::hitRedis($key, $window)
            : self::hitFile($key, $window);

        return [
            'allowed'     => $count <= $limit,
            'count'       => $count,
            'remaining'   => max(0, $limit - $count),
            'retry_after' => $resetsIn,
        ];
    }

    /**
     * Forget a key - after a successful login, so a few failed attempts do not
     * keep counting against someone who then got it right.
     *
     * @param string $key
     * @return void
     */
    public static function clear(string $key): void
    {
        if (Redis::available('cache')) {
            Redis::delete('ratelimit:' . sha1($key), 'cache');
            return;
        }

        @unlink(self::file($key));
    }

    /**
     * INCR returns the new value, so the first caller in a window is the one
     * that sees 1 and sets the expiry. No read-then-write, so two requests
     * landing together cannot both think they are first.
     *
     * @param string $key
     * @param int    $window
     * @return array{0: int, 1: int}
     */
    private static function hitRedis(string $key, int $window): array
    {
        $redis = Redis::connection('cache');
        if (!$redis) return self::hitFile($key, $window);

        $name  = Redis::key('ratelimit:' . sha1($key));
        $count = (int) $redis->incr($name);

        if ($count === 1) $redis->expire($name, $window);

        $ttl = (int) $redis->ttl($name);

        return [$count, $ttl > 0 ? $ttl : $window];
    }

    /**
     * One file per key: [window start, count]. Opened r+ and locked, so two
     * requests on the same key queue rather than overwrite each other.
     *
     * @param string $key
     * @param int    $window
     * @return array{0: int, 1: int}
     */
    private static function hitFile(string $key, int $window): array
    {
        $file = self::file($key);
        $dir  = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [1, $window];

        $handle = @fopen($file, 'c+');
        if (!$handle) return [1, $window];

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return [1, $window];
        }

        $now   = time();
        $parts = explode('|', (string) fgets($handle));
        $start = (int) ($parts[0] ?? 0);
        $count = (int) ($parts[1] ?? 0);

        # Expired, or a file that was never written: start a window.
        if ($start <= 0 || $now - $start >= $window) {
            $start = $now;
            $count = 0;
        }

        $count++;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, "$start|$count");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        # Sweeping here rather than from cron: the directory is only read on a
        # request that is already doing file work, and 1-in-200 keeps it rare.
        if (rand(1, 200) === 1) self::prune($window);

        return [$count, max(1, $window - ($now - $start))];
    }

    /**
     * @param string $key
     * @return string
     */
    private static function file(string $key): string
    {
        if (self::$dir === null) {
            global $storage_path;
            self::$dir = ($storage_path ?: FRAMEWORK_PATH . '/storage') . '/ratelimit';
        }

        return self::$dir . '/' . sha1($key);
    }

    /**
     * Remove counters whose window is long gone, so the directory does not grow
     * one file per ip forever.
     *
     * @param int $window
     * @return void
     */
    private static function prune(int $window): void
    {
        $cutoff = time() - max($window * 2, 3600);

        foreach ((array) glob(self::$dir . '/*') as $file)
            if (@filemtime($file) < $cutoff) @unlink($file);
    }
}
