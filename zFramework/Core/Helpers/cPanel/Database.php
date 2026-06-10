<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel MySQL database management via UAPI.
 * Note: cPanel prefixes database names with the account username automatically.
 */
class Database
{
    /**
     * Return MySQL server info (version, host) and server location.
     */
    public static function info(): ?array
    {
        return ['server' => API::request("Mysql/get_server_information"), 'locate' => API::request('Mysql/locate_server')];
    }

    /**
     * List all MySQL databases on the account.
     */
    public static function list(): ?array
    {
        return API::request("Mysql/list_databases");
    }

    /**
     * Run integrity/consistency checks on a database.
     *
     * @param string $name  Full database name (with cPanel prefix, e.g. "user_dbname")
     */
    public static function check(string $name): ?array
    {
        return API::request("Mysql/check_database", compact('name'));
    }

    /**
     * Dump the schema (CREATE TABLE statements) of a database without data.
     *
     * @param string $name  Full database name (with cPanel prefix)
     */
    public static function dump_schema(string $name): ?array
    {
        return API::request("Mysql/dump_database_schema", compact('name'));
    }

    /**
     * Create a new MySQL database.
     *
     * @param string $name  Database name without prefix; cPanel adds the account prefix automatically
     */
    public static function create(string $name): ?array
    {
        return API::request("Mysql/create_database", compact('name'));
    }

    /**
     * Create a database and a matching user in one call, with a random generated name.
     *
     * @param string $prefix  Optional prefix for the generated database/user name
     */
    public static function createRandom(string $prefix = ""): ?array
    {
        return API::request("Mysql/setup_db_and_user", compact('prefix'));
    }

    /**
     * Rename an existing database.
     *
     * @param string $oldname  Current full database name (with cPanel prefix)
     * @param string $newname  New database name (without prefix; prefix is added automatically)
     */
    public static function rename(string $oldname, string $newname): ?array
    {
        return API::request("Mysql/rename_database", compact('oldname', 'newname'));
    }

    /**
     * Repair corrupted tables in a database (runs REPAIR TABLE on all tables).
     *
     * @param string $name  Full database name (with cPanel prefix)
     */
    public static function repair(string $name): ?array
    {
        return API::request("Mysql/repair_database", compact('name'));
    }

    /**
     * Flush and reapply all user privileges. Call after grant/revoke operations
     * if changes are not immediately reflected.
     */
    public static function update_privileges(): ?array
    {
        return API::request("Mysql/update_privileges");
    }

    /**
     * Delete a database permanently. This cannot be undone.
     *
     * @param string $name  Full database name (with cPanel prefix)
     */
    public static function delete(string $name): ?array
    {
        return API::request("Mysql/delete_database", compact('name'));
    }
}
