<?php

namespace zFramework\Core\Facades;

use zFramework\Core\Crypter;

class Cookie
{
    static $options = [
        'expires'   => 0,     // expire time
        'path'      => '/',   // store path
        'domain'    => '',    // store domain
        'security'  => false, // only ssl
        'http_only' => false, // only http protocol
        'samesite'  => 'Lax', // Lax: blocks cross-site POST, allows same-site subdomains
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
     * Crypt and parse for cookie key.
     * @param string $key
     * @return string
     */
    private static function keyparse($key): string
    {
        return str_replace(["=", ",", ";", " ", "\t", "\r", "\n", "\013", "\014", "+", "%"], '', Crypter::encode($key));
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
        $_COOKIE[self::keyparse($key)] = Crypter::encode($value);
        return setcookie(self::keyparse($key), Crypter::encode($value), [
            'expires'  => $expires ? (time() + $expires) : self::$options['expires'],
            'path'     => self::$options['path'],
            'domain'   => self::$options['domain'],
            'secure'   => self::$options['security'],
            'httponly' => self::$options['http_only'],
            'samesite' => self::$options['samesite'],
        ]);
    }

    /**
     * Get Cookie from key.
     * @param string $key
     * @return string|bool
     */
    public static function get(string $key)
    {
        return isset($_COOKIE[self::keyparse($key)]) ? Crypter::decode($_COOKIE[self::keyparse($key)]) : NULL;
    }

    /**
     * Get Cookie from key.
     * @param string $key
     * @return bool 
     */
    public static function delete(string $key): bool
    {
        return setcookie(self::keyparse($key), '', [
            'expires'  => -1,
            'path'     => self::$options['path'],
            'domain'   => self::$options['domain'],
            'secure'   => self::$options['security'],
            'httponly' => self::$options['http_only'],
            'samesite' => self::$options['samesite'],
        ]);
    }
}
