<?php

namespace zFramework\Core\Helpers\cPanel;

use CURLFile;

/**
 * cPanel file manager operations via UAPI.
 * Paths are relative to the account's home directory (e.g. "/public_html/images").
 */
class Fileman
{
    /**
     * List files and directories at the given path.
     *
     * @param string $path  Absolute path from home root (e.g. "/public_html", "/")
     * @return array|null   Array of file entries; each has: file, type, size, mtime, mimetype, etc.
     */
    public static function list(string $path = "/"): ?array
    {
        return API::request("Fileman/list_files", ["dir" => $path]);
    }

    /**
     * Upload one or more files to a directory.
     *
     * @param string $dir    Target directory path on the server (e.g. "/public_html/uploads")
     * @param array  $files  Array of files to upload. Each element:
     *                       ['path' => '/tmp/local_file.jpg', 'mime' => 'image/jpeg']
     *                       The array key is used as the destination filename.
     *
     * Example:
     *   Fileman::upload('/public_html/uploads', [
     *       'avatar.jpg' => ['path' => '/tmp/uploaded.jpg', 'mime' => 'image/jpeg']
     *   ]);
     */
    public static function upload(string $dir, array $files = []): ?array
    {
        $_files = [];
        foreach ($files as $key => $file) $_files["file-$key"] = new CURLFile($file['path'], $file['mime'], $key);
        return API::request('Fileman/upload_files', ['dir' => $dir], $_files);
    }

    /**
     * Create a new directory (mkdir). Parent directories must exist.
     *
     * @param string $path  Full path of the directory to create (e.g. "/public_html/newdir")
     */
    public static function create_folder(string $path): ?array
    {
        return API::request("Fileman/mkdir", ["path" => $path]);
    }

    /**
     * Delete a file or directory. Deletion is permanent (not moved to trash).
     *
     * @param string $path  Full path of the file or directory to delete (e.g. "/public_html/old.php")
     */
    public static function delete_file(string $path): ?array
    {
        return API::request("Fileman/delete", ["path" => $path]);
    }
}
