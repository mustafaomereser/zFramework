<?php

namespace zFramework\Core\Helpers;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Assets
{
    public static function list(string $dir, array $extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'css', 'js'])
    {
        $assets = [];
        if (!is_dir($dir)) return $assets;
        foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS))) as $file) {
            if (!$file->isFile()) continue;
            if (in_array(strtolower($file->getExtension()), $extensions)) $assets[] = $file->getPathname();
        }
        return $assets;
    }

    public static function cssMinify($css)
    {
        return preg_replace(['/\s*(\w)\s*{\s*/', '/\s*(\S*:)(\s*)([^;]*)(\s|\n)*;(\n|\s)*/', '/\n/', '/\s*}\s*/'], ['$1{ ', '$1$3;', "", '} '], $css);
    }

    public static function jsMinify($js)
    {
        return \JShrink\Minifier::minify($js);
    }
}
