<?php

namespace Core\Facedas;

class Str
{
    public static function rand($length = 5)
    {
        $q = "QWERTYUIOPASDFHJKLZXCVBNMqwertyuopasdfghjklizxcvbnm0987654321";
        $q_count = strlen($q) - 1;
        $r = "";
        for ($x = $length; $x > 0; $x--) $r .= $q[rand(0, $q_count)];
        return $r;
    }

    public static function slug($text, string $divider = '-')
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', $divider, $text)));;
    }
}
