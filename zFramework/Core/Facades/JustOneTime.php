<?php

namespace zFramework\Core\Facades;

class JustOneTime
{
    static private $session_name = 'just-one-time';
    static $used = false;

    /**
     * Set just one time data.
     * @param string $name
     * @param mixed $value
     * @return self
     */
    public static function set(string $name, mixed $value): self
    {
        self::$used = true;
        return Session::callback(function () use ($name, $value) {
            $_SESSION[self::$session_name][$name] = $value;
            return new self();
        });
    }

    /**
     * Get data
     * @param string $name
     * @return mixed
     */
    public static function get(string $name): mixed
    {
        return Session::callback(fn() => @$_SESSION[self::$session_name][$name]);
    }

    /**
     * Unset All Data.
     * @return void
     */
    public static function unset(): void
    {
        if (self::$used) Session::delete(self::$session_name);
    }
}
