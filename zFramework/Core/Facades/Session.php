<?php

namespace zFramework\Core\Facades;

class Session
{

    /**
     * Session management.
     * @param \Closure $callback
     * @return mixed
     */
    public static function callback(\Closure $callback)
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $data = $callback();
        session_write_close();
        return $data;
    }

    /**
     * Set a session.
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public static function set(string $key, mixed $value): self
    {
        return self::callback(function () use ($key, $value) {
            $_SESSION[$key] = $value;
            return new self();
        });
    }

    /**
     * Get session from key.
     * @param $key
     * @return mixed
     */
    public static function get(string $key): mixed
    {
        return self::callback(function () use ($key) {
            return @$_SESSION[$key];
        });
    }

    /**
     * Forget a session by key.
     * @param string $key
     * @return self
     */
    public static function delete(string $key): self
    {
        return self::callback(function () use ($key) {
            unset($_SESSION[$key]);
            return new self();
        });
    }
}
