<?php

namespace zFramework\Core\Facades;

class Alerts
{

    static $name = null;

    /**
     * Set just one time alerts.
     * @param mixed
     * @return self
     */
    private static function set(): self
    {
        $args = func_get_args();
        return Session::callback(function () use ($args) {
            $_SESSION['alerts'][self::$name ? self::$name : Str::rand(10)] = $args;
            self::$name = null;
            return new self();
        });
    }

    /**
     * Set name alerts for not duplicate
     * @param $name
     * @return self
     */
    public static function name($name): self
    {
        self::$name = $name;
        return new self();
    }

    /**
     * Get Alerts
     * @return array
     */
    public static function get(): array
    {
        return Session::get('alerts') ?? [];
    }

    /**
     * Unset All Alerts.
     * @return void
     */
    public static function unset()
    {
        Session::delete('alerts');
    }

    /**
     * Set a danger Alert
     * @return self
     */
    public static function danger($text): self
    {
        return self::set(__FUNCTION__, $text);
    }

    /**
     * Set a success Alert
     * @return self
     */
    public static function success($text): self
    {
        return self::set(__FUNCTION__, $text);
    }

    /**
     * Set a warning Alert
     * @return self
     */
    public static function warning($text): self
    {
        return self::set(__FUNCTION__, $text);
    }

    /**
     * Set a info Alert
     * @return self
     */
    public static function info($text): self
    {
        return self::set(__FUNCTION__, $text);
    }
}
