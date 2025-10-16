<?php

namespace zFramework\Core\Helpers;

class Folder
{
    /**
     * Recursive make folder.
     * @param string $path
     * @return bool
     */
    public static function make(string $path): bool
    {
        return @mkdir(base_path($path), 0777, true);
    }

    /**
     * Recursive delete folder.
     * @param string $path
     * @return bool
     */
    public static function delete(string $path): bool
    {
        $path = base_path($path);
        if (!is_dir($path)) return false;

        $items = scan_dir($path);
        foreach ($items as $item) {
            $dir_path = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($dir_path)) self::delete($dir_path);
            else unlink($dir_path);
        }

        @rmdir($path);

        return true;
    }

    /**
     * Folder total size.
     *
     * @param string $path
     * @return array|false 
     */
    public static function size(string $path): array|false
    {
        if (!is_dir($path)) return false;
        $size       = 0;
        $file_count = 0;
        $recursive = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($recursive as $file) if ($file->isFile()) {
            $size += $file->getSize();
            $file_count++;
        }

        return compact('size', 'file_count');
    }
}
