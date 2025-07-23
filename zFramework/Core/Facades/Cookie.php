<?php

namespace zFramework\Core\Facades;

use zFramework\Core\Crypter;

class Cookie
{
    static $options = [
        'expires'   => 0, // expire time
        'path'      => '/', // store path
        'domain'    => '', // store domain
        'security'  => false, // only ssl
        'http_only' => false // only http protocol
    ];

    /**
     * Set Defaults.
     */
    public static function init()
    {
        self::$options['expires'] = time() + 86400;
        // self::$options['domain']  = host();
    }

    /**
     * Set a Cookie
     * @param string $key
     * @param mixed $value
     * @param ?int $expires
     * @return bool
     */
    public static function set(string $key, string $value, ?int $expires = null): bool
    {
        if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        return setcookie($key, Crypter::encode($value), ($expires ? (time() + $expires) : self::$options['expires']), self::$options['path'], self::$options['domain'], self::$options['security'], self::$options['http_only']);
    }

    /**
     * Get Cookie from key.
     * @param string $key
     * @return string|bool
     */
    public static function get(string $key)
    {
        return isset($_COOKIE[$key]) ? Crypter::decode($_COOKIE[$key]) : false;
    }

    /**
     * Get Cookie from key.
     * @param string $key
     * @return bool 
     */
    public static function delete(string $key): bool
    {
        return setcookie($key, '', -1, self::$options['path'], self::$options['domain'], self::$options['security'], self::$options['http_only']);
    }
}
