<?php

namespace zFramework\Core\Helpers\cPanel;

class File
{
    public static function list(string $path = "/"): ?array
    {
        return API::request("Fileman/list_files", ["dir" => $path]);
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
