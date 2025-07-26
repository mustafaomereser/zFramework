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
     * @param string $name
     * @return self
     */
    public static function name(string $name): self
    {
        self::$name = $name;
        return new self();
    }

    /**
     * Get Alerts
     * @param bool $unset_after_get
     * @return array
     */
    public static function get(bool $unset_after_get = false): array
    {
        $alerts = Session::get('alerts') ?? [];
        if (!$unset_after_get) self::unset();
        return $alerts;
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
     * @param string $text
     * @return self
     */
    public static function danger(string $text): self
    {
        return self::set(__FUNCTION__, $text);
    }

    /**
     * Set a success Alert
     * @param string $text
     * @return self
     */
    public static function success(string $text): self
    {
        return self::set(__FUNCTION__, $text);
    }

    /**
     * Set a warning Alert
     * @param string $text
     * @return self
     */
    public static function warning(string $text): self
    {
        return self::set(__FUNCTION__, $text);
    }

    /**
     * Set a info Alert
     * @param string $text
     * @return self
     */
    public static function info(string $text): self
    {
        return self::set(__FUNCTION__, $text);
    }
}
