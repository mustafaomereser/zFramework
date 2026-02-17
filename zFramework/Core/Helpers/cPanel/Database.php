<?php

namespace zFramework\Core\Helpers\cPanel;

class Database
{
    public static function info(): ?array
    {
        return ['server' => API::request("Mysql/get_server_information"), 'locate' => API::request('Mysql/locate_server')];
    }

    public static function list(): ?array
    {
        return API::request("Mysql/list_databases");
    }

    public static function check(string $name): ?array
    {
        return API::request("Mysql/check_database", compact('name'));
    }

    public static function dump_schema(string $name): ?array
    {
        return API::request("Mysql/dump_database_schema", compact('name'));
    }

    public static function create(string $name): ?array
    {
        return API::request("Mysql/create_database", compact('name'));
    }

    public static function createRandom(string $prefix = ""): ?array
    {
        return API::request("Mysql/setup_db_and_user", compact('prefix'));
    }

    public static function rename(string $oldname, string $newname): ?array
    {
        return API::request("Mysql/rename_database", compact('oldname', 'newname'));
    }

    public static function repair(string $name): ?array
    {
        return API::request("Mysql/repair_database", compact('name'));
    }

    public static function update_privileges(): ?array
    {
        return API::request("Mysql/update_privileges");
    }

    public static function delete(string $name): ?array
    {
        return API::request("Mysql/delete_database", compact('name'));
    }
}