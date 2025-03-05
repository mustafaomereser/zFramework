<?php

namespace zFramework\Core\Facades;

class Str
{
    /**
     * Limit length a text
     * @param string $text
     * @param int $length
     * @param string $continue
     * @return string
     */
    public static function limit(string $text, int $length = 50, string $continue = "..."): string
    {
        if (strlen($text) > $length) $text = mb_substr($text, 0, $length) . $continue;
        return $text;
    }

    /**
     * Word Limit length a text
     * @param string $text
     * @param int $length
     * @param string $continue
     * @return string
     */
    public static function wordLimit(string $text, int $length = 3, string $continue = "..."): string
    {
        $return = [];
        $words  = explode(' ', $text);
        for ($x = 0; $x < $length; $x++) if (isset($words[$x])) $return[] = $words[$x];
        return implode(' ', $return) . (count($words) > $length ? $continue : null);
    }


    /**
     * Create Random String
     * @param int $length
     * @param bool $unique
     * @return string
     */
    public static function rand(int $length = 5, bool $unique = false): string
    {
        $q = "QWERTYUIOPASDFHJKLZXCVBNMqwertyuopasdfghjklizxcvbnm0987654321";
        $q_count = strlen($q) - 1;
        $r = "";
        for ($x = $length; $x > 0; $x--) $r .= $q[rand(0, $q_count)];
        return $r . ($unique ? uniqid('', true) : null);
    }

    /**
     * Text Convert To Slug Url
     * @param string $text
     * @param string $divider
     * @return string
     */
    public static function slug(string $text, string $divider = '-'): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', $divider, str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ö', 'Ç'], ['i', 'g', 'u', 's', 'o', 'c', 'i', 'g', 'u', 'o', 'c'], $text))));
    }
}
