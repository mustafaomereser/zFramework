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
     * Recursive delete file and folder.
     * @param string $path
     * @return bool
     */
    public static function delete(string $path): bool
    {
        $path = base_path($path);
        if (!is_dir($path)) return false;

        return self::remove($path);
    }

    /**
     * Delete a directory that has already been resolved.
     *
     * Separate from delete() because the recursion used to call it with an absolute
     * path, which base_path() then prefixed a second time: is_dir() said no, the
     * subdirectory was left where it was, and rmdir() failed quietly on a directory
     * that was not empty - while true went back to the caller.
     *
     * @param string $path
     * @return bool
     */
    private static function remove(string $path): bool
    {
        foreach (scan_dir($path) as $item) {
            $child = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($child) ? self::remove($child) : @unlink($child);
        }

        return @rmdir($path);
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
