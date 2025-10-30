<?php

namespace zFramework\Core\Helpers;

class cPanel
{
    // CONFIG
    public static string $cpanelHost;   // cPanel domain
    public static string $cpanelUser;   // cPanel username
    public static string $cpanelToken;  // API Token (cPanel → Manage API Tokens)
    private static bool $verifySSL = false;

    /**
     * Request function
     */
    private static function request(string $endpoint, array $params = []): ?array
    {
        $url = "https://" . self::$cpanelHost . ":2083/execute/" . $endpoint;

        if (!empty($params)) $url .= "?" . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => self::$verifySSL,
            CURLOPT_HTTPHEADER     => [
                "Authorization: cpanel " . self::$cpanelUser . ":" . self::$cpanelToken,
            ]
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch))  return ["error" => curl_error($ch)];
        curl_close($ch);
        return json_decode($response, true);
    }

    //  MYSQL
    public static function createDatabase(string $name): ?array
    {
        return self::request("Mysql/add_database", compact('name'));
    }

    public static function deleteDatabase(string $name): ?array
    {
        return self::request("Mysql/delete_database", compact('name'));
    }

    public static function createUser(string $name, string $password): ?array
    {
        return self::request("Mysql/add_user", compact('name', 'password'));
    }

    public static function deleteUser(string $name): ?array
    {
        return self::request("Mysql/delete_user", compact('name'));
    }

    public static function grantPrivileges(string $user, string $db): ?array
    {
        return self::request("Mysql/set_privileges_on_database", [
            "user"       => $user,
            "database"   => $db,
            "privileges" => "ALL PRIVILEGES"
        ]);
    }

    public static function listDatabases(): ?array
    {
        return self::request("Mysql/list_databases");
    }

    // FILE MANAGEMENT
    public static function listFiles(string $path = "/public_html"): ?array
    {
        return self::request("Fileman/list_files", ["dir" => $path]);
    }

    public static function createFolder(string $path): ?array
    {
        return self::request("Fileman/mkdir", ["path" => $path]);
    }

    public static function deleteFile(string $path): ?array
    {
        return self::request("Fileman/delete", ["path" => $path]);
    }

    // E-MAIL
    public static function createEmail(string $email, string $password, int $quota = 250): ?array
    {
        return self::request("Email/add_pop", compact('email', 'password', 'quota'));
    }

    public static function deleteEmail(string $user): ?array
    {
        return self::request("Email/delete_pop", ["email" => $user]);
    }

    public static function listEmails(): ?array
    {
        return self::request("Email/list_pops");
    }

    // DOMAIN & DNS
    public static function addSubdomain(string $name, string $root = "/public_html"): ?array
    {
        return self::request("SubDomain/addsubdomain", [
            "domain"     => $name,
            "rootdomain" => self::$cpanelHost,
            "dir"        => $root . "/" . $name
        ]);
    }

    public static function deleteSubdomain(string $name): ?array
    {
        return self::request("SubDomain/delsubdomain", ["domain" => $name . "." . self::$cpanelHost]);
    }

    public static function addDNSRecord(string $domain, string $type, string $name, string $address, int $ttl = 3600): ?array
    {
        $type = strtoupper($type);
        return self::request("ZoneEdit/add_zone_record", compact('domain', 'type', 'name', 'address', 'ttl'));
    }

    public static function deleteDNSRecord(string $domain, int $line): ?array
    {
        return self::request("ZoneEdit/remove_zone_record", compact('domain', 'line'));
    }

    // CRON JOB
    public static function addCron(string $time, string $command): ?array
    {
        return self::request("Cron/add_line", [
            "command" => $command,
            "linekey" => $time
        ]);
    }

    public static function listCrons(): ?array
    {
        return self::request("Cron/listcron");
    }

    public static function deleteCron(int $lineKey): ?array
    {
        return self::request("Cron/remove_line", compact('lineKey'));
    }

    // SSL
    public static function installSSL(string $domain, string $cert, string $key, string $cabundle = ""): ?array
    {
        return self::request("SSL/install_ssl", compact('domain', 'cert', 'key', 'cabundle'));
    }

    public static function listSSL(): ?array
    {
        return self::request("SSL/fetch_ssl_vhosts");
    }
}
