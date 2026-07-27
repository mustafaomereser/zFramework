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

        $name    = self::keyparse($key);
        $encoded = Crypter::encode($value);
        $options = [
            'expires'  => $expires ? (time() + $expires) : self::$options['expires'],
            'path'     => self::$options['path'],
            'domain'   => self::$options['domain'],
            'secure'   => self::$options['security'],
            'httponly' => self::$options['http_only'],
            'samesite' => self::$options['samesite'],
        ];

        $_COOKIE[$name] = $encoded;

        # setcookie() does nothing under the CLI SAPI, which is what a long-running
        # worker runs as - the cookie would be set in $_COOKIE and never reach the
        # browser. Build the header ourselves there and let the worker attach it.
        if (PHP_SAPI === 'cli') {
            Response::header('Set-Cookie', self::buildHeader($name, $encoded, $options), false);
            return true;
        }

        return setcookie($name, $encoded, $options);
    }

    /**
     * Render a Set-Cookie header value.
     *
     * @param string $name
     * @param string $value
     * @param array  $options
     * @return string
     */
    private static function buildHeader(string $name, string $value, array $options): string
    {
        # rawurlencode, matching how the worker decodes: urlencode would turn a
        # space into "+", which is indistinguishable from a real "+" in base64.
        $header = rawurlencode($name) . '=' . rawurlencode($value);

        if ($options['expires']) $header .= '; Expires=' . gmdate('D, d M Y H:i:s T', $options['expires']) . '; Max-Age=' . max(0, $options['expires'] - time());
        if (strlen($options['path'] ?? ''))   $header .= '; Path=' . $options['path'];
        if (strlen($options['domain'] ?? '')) $header .= '; Domain=' . $options['domain'];
        if ($options['secure'])   $header .= '; Secure';
        if ($options['httponly']) $header .= '; HttpOnly';
        if (strlen($options['samesite'] ?? '')) $header .= '; SameSite=' . $options['samesite'];

        return $header;
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
        $name    = self::keyparse($key);
        $options = [
            'expires'  => -1,
            'path'     => self::$options['path'],
            'domain'   => self::$options['domain'],
            'secure'   => self::$options['security'],
            'httponly' => self::$options['http_only'],
            'samesite' => self::$options['samesite'],
        ];

        unset($_COOKIE[$name]);

        # Same reason as set(): setcookie() is a no-op under the CLI SAPI, so the
        # expiry would never reach the browser and the cookie would survive - a
        # logout that does not log anyone out.
        if (PHP_SAPI === 'cli') {
            Response::header('Set-Cookie', self::buildHeader($name, '', $options), false);
            return true;
        }

        return setcookie($name, '', $options);
    }
}
