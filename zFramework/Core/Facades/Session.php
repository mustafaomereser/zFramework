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
        if (session_status() === PHP_SESSION_NONE) session_start();
        self::$cache = $_SESSION ?? [];
        session_write_close();
        register_shutdown_function([self::class, 'flush']);
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
        self::load();

        # Deleting a key that was never there changes nothing, so it must not mark
        # the session dirty: run.php clears the alerts and the one-time data at the
        # end of every request, and with an unconditional $dirty that alone made a
        # request which never touched the session open it, write it and hand back a
        # cookie. Measured at 0.32 ms a request on a local disk, more on the network
        # filesystems shared hosting runs on - and it took the session lock, which
        # serialises a visitor's concurrent requests for as long as it is held.
        if (!array_key_exists($key, self::$cache)) return new self();

        unset(self::$cache[$key]);
        self::$dirty = true;
        return new self();
    }
}
