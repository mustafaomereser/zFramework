<?php

namespace zFramework\Core\Helpers\cPanel;

use CURLFile;

class File
{
    public static function list(string $path = "/"): ?array
    {
        return API::request("Fileman/list_files", ["dir" => $path]);
    }

    public static function upload(string $dir, array $files = []): ?array
    {
        $_files = [];
        foreach ($files as $key => $file) $_files["file-$key"] = new CURLFile($file['path'], $file['mime'], $key);
        return API::request('Fileman/upload_files', ['dir' => $dir], $_files);
    }

    public static function create_folder(string $path): ?array
    {
        return API::request("Fileman/mkdir", ["path" => $path]);
    }

    public static function delete_file(string $path): ?array
    {
        return API::request("Fileman/delete", ["path" => $path]);
    }
}
