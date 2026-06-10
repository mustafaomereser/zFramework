<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel MySQL database user management via UAPI.
 * Note: cPanel prefixes usernames with the account username automatically.
 */
class DatabaseUser
{
    /**
     * List all MySQL users on the account.
     */
    public static function list(): ?array
    {
        return API::request("Mysql/list_users");
    }

    /**
     * Create a new MySQL user.
     *
     * @param string $name      Username without prefix; cPanel adds the account prefix automatically
     * @param string $password  User password (must meet cPanel's password strength requirements)
     */
    public static function create(string $name, string $password): ?array
    {
        return API::request("Mysql/create_user", compact('name', 'password'));
    }

    /**
     * Rename an existing MySQL user.
     *
     * @param string $oldname  Current full username (with cPanel prefix)
     * @param string $newname  New username (without prefix; prefix is added automatically)
     */
    public static function rename(string $oldname, string $newname): ?array
    {
        return API::request("Mysql/rename_user", compact('oldname', 'newname'));
    }

    /**
     * Delete a MySQL user permanently.
     *
     * @param string $name  Full username (with cPanel prefix)
     */
    public static function delete(string $name): ?array
    {
        return API::request("Mysql/delete_user", compact('name'));
    }

    /**
     * Change a MySQL user's password.
     *
     * @param string $user      Full username (with cPanel prefix)
     * @param string $password  New password
     */
    public static function setPassword(string $user, string $password): ?array
    {
        return API::request("Mysql/set_password", compact('user', 'password'));
    }

    /**
     * Get the privileges a user has on a specific database.
     *
     * @param string $user      Full username (with cPanel prefix)
     * @param string $database  Full database name (with cPanel prefix)
     */
    public static function privileges(string $user, string $database): ?array
    {
        return API::request("Mysql/get_privileges_on_database", compact('user', 'database'));
    }

    /**
     * Grant privileges to a user on a database.
     *
     * @param string     $user       Full username (with cPanel prefix)
     * @param string     $database   Full database name (with cPanel prefix)
     * @param array|null $privileges List of MySQL privileges to grant (e.g. ["SELECT","INSERT"]).
     *                               Pass null to grant ALL PRIVILEGES.
     */
    public static function grantPrivileges(string $user, string $database, null|array $privileges = null): ?array
    {
        return API::request("Mysql/set_privileges_on_database", compact('user', 'database') + ["privileges" => ($privileges ? implode(',', $privileges) : "ALL PRIVILEGES")]);
    }

    /**
     * Revoke all privileges a user has on a database.
     *
     * @param string $user      Full username (with cPanel prefix)
     * @param string $database  Full database name (with cPanel prefix)
     */
    public static function revokePrivileges(string $user, string $database): ?array
    {
        return API::request("Mysql/revoke_access_to_database", compact('user', 'database'));
    }

    /**
     * List stored routines (procedures/functions), optionally filtered by user.
     *
     * @param string|null $user  Full username (with cPanel prefix). Pass null to list all routines.
     */
    public static function routines(null|string $user = null): ?array
    {
        $options = [];
        if ($user) $options['database_user'] = $user;
        return API::request("Mysql/list_routines", $options);
    }
}
