<?php

namespace zFramework\Core\Helpers\cPanel;

class DatabaseUser
{
    public static function list(): ?array
    {
        return API::request("Mysql/list_users");
    }

    public static function create(string $name, string $password): ?array
    {
        return API::request("Mysql/create_user", compact('name', 'password'));
    }

    public static function rename(string $oldname, string $newname): ?array
    {
        return API::request("Mysql/rename_user", compact('oldname', 'newname'));
    }

    public static function delete(string $name): ?array
    {
        return API::request("Mysql/delete_user", compact('name'));
    }

    public static function setPassword(string $user, string $password): ?array
    {
        return API::request("Mysql/set_password", compact('user', 'password'));
    }

    public static function privileges(string $user, string $database): ?array
    {
        return API::request("Mysql/get_privileges_on_database", compact('user', 'database'));
    }

    public static function routines(null|string $user = null): ?array
    {
        $options = [];
        if ($user) $options['database_user'] = $user;
        return API::request("Mysql/list_routines", $options);
    }

    public static function grantPrivileges(string $user, string $database, null|array $privileges = null): ?array
    {
        return API::request("Mysql/set_privileges_on_database", compact('user', 'database') + ["privileges" => ($privileges ? implode(',', $privileges) : "ALL PRIVILEGES")]);
    }

    public static function revokePrivileges(string $user, string $database): ?array
    {
        return API::request("Mysql/revoke_access_to_database", compact('user', 'database'));
    }
}
