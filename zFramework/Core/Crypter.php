<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Config;

class Crypter
{
    static $key;
    static $salt;

    /**
     * Set key and salt.
     */
    public static function init()
    {
        if (!Config::get('crypt')) throw new \Exception('Terminal ile bir şifreleme anahtarı oluşturun.', 1000);

        self::$key  = Config::get('crypt.key');
        self::$salt = Config::get('crypt.salt');
    }

    /**
     * Encode a string
     * @param string $xml
     * @return string
     */
    public static function encode(string $xml): string
    {
        $keys = self::$key;
        $encrypted = '';
        for ($i = 0; $i < strlen($xml); $i++) $encrypted .= chr(ord($xml[$i]) + ord($keys[($i + 1) % strlen($keys)]));
        return base64_encode($encrypted) . self::$salt;
    }

    /**
     * Decode what are you encoded.
     * @param string $xml
     * @return string
     */
    public static function decode(string $xml): string
    {
        $xml = base64_decode(str_replace([self::$salt], '', $xml));
        $keys = self::$key;
        $decrypted = '';
        for ($i = 0; $i < strlen($xml); $i++) $decrypted .= chr(ord($xml[$i]) - ord($keys[($i + 1) % strlen($keys)]));
        return $decrypted;
    }

    /**
     * Encode a array in strings. 
     * Except parameter continues except's index
     * @param array $array
     * @param array $except 
     * @return array
     */
    public static function encodeArray(array $array = [], array $except = []): array
    {
        foreach ($array as $key => $val) if (!strstr($val, self::$salt) && !in_array($key, $except)) $array[$key] = self::encode($val);
        return $array;
    }

    /**
     * Decode all of in array strings
     * @param array $array
     * @return array
     */
    public static function decodeArray(array $array = []): array
    {
        foreach ($array as $key => $val) if (strstr($val, self::$salt)) $array[$key] = self::decode($val);
        return $array;
    }
}
