<?php

namespace zFramework\Core\Facades;

class Session
{
    private static ?array $cache = null;
    private static bool $dirty   = false;

    /**
     * Load session into memory once, release the lock immediately.
     * Registers a shutdown flush so the lock is never held during request execution.
     */
    private static function load(): void
    {
        if (self::$cache !== null) return;
        if (session_status() === PHP_SESSION_NONE) {
            # PHP's session module sends its own Cache-Control and a 1981 Expires
            # unless the limiter is empty. Those landed on top of whatever the
            # page had declared, so a cacheable page that happened to touch the
            # session silently became uncacheable. Caching is the framework's
            # call now - see Response::cache().
            session_cache_limiter('');
            self::cookieParams();
            session_start();
        }
        self::$cache = $_SESSION ?? [];
        session_write_close();
        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * PHPSESSID with the same flags as the framework's own cookies: HttpOnly,
     * SameSite=Lax, Secure when the request is https. PHP's defaults send it
     * readable from javascript and over either scheme.
     *
     * @return void
     */
    private static function cookieParams(): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') return;
        $current = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $current['lifetime'],
            'path'     => $current['path'] ?: '/',
            'domain'   => $current['domain'],
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Write dirty cache back to session storage.
     * Called once at request end via shutdown function.
     * @return void
     */
    public static function flush(): void
    {
        if (!self::$dirty || self::$cache === null) return;
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION    = self::$cache;
        self::$dirty = false;
        session_write_close();
    }

    /**
     * Drop the in-memory session so the next request loads its own.
     *
     * flush() is called first: a long-running worker has no script shutdown, so
     * the shutdown function registered by load() would never fire and pending
     * changes would be lost rather than written.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::flush();

        self::$cache = null;
        self::$dirty = false;
    }

    /**
     * Run a closure with direct $_SESSION access (for nested array manipulation).
     * Syncs cache ↔ $_SESSION around the callback.
     * @param \Closure $callback
     * @return mixed
     */
    public static function callback(\Closure $callback): mixed
    {
        self::load();
        $_SESSION    = self::$cache;
        $result      = $callback();
        self::$cache = $_SESSION;
        self::$dirty = true;
        return $result;
    }

    /**
     * Set a session value.
     * @param string $key
     * @param mixed  $value
     * @return self
     */
    public static function set(string $key, mixed $value): self
    {
        self::load();
        self::$cache[$key] = $value;
        self::$dirty       = true;
        return new self();
    }

    /**
     * Get a session value.
     * @param string $key
     * @return mixed
     */
    public static function get(string $key): mixed
    {
        self::load();
        return self::$cache[$key] ?? null;
    }

    /**
     * Delete a session key.
     * @param string $key
     * @return self
     */
    public static function delete(string $key): self
    {
        # A visitor with no session cookie has nothing stored under any key, so
        # there is nothing here to delete - and finding that out through load()
        # would cost the whole session: a file on disk, a Set-Cookie, and the
        # no-store/no-cache headers PHP's cache limiter sends with every
        # session_start(). That matters because Run::handle() clears alerts and
        # one-time data after every request, including requests that never
        # touched the session at all - a landing page ends up uncacheable, and
        # every anonymous visitor leaves a session file behind.
        #
        # The status check covers a session opened by something other than
        # load(), where $cache is null but the data is real.
        if (self::$cache === null && session_status() === PHP_SESSION_NONE && !isset($_COOKIE[session_name()])) return new self();

        self::load();

        # Deleting a key that is not there changes nothing, so the session is not
        # marked dirty and no write happens at shutdown. Worth the check: the
        # framework clears alerts and one-time data after every request, and
        # without it a request that never used the session would still write one
        # - taking the session lock, which serialises that visitor's other
        # requests while it is held.
        if (!array_key_exists($key, self::$cache)) return new self();

        unset(self::$cache[$key]);
        self::$dirty = true;
        return new self();
    }
}
