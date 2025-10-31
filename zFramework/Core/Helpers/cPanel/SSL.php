<?php

namespace zFramework\Core\Helpers\cPanel;

class SSL
{
    public static function list(): ?array
    {
        return API::request("SSL/fetch_ssl_vhosts");
    }

    public static function AutoSSLStatus(): ?array
    {
        return API::request("SSL/is_autossl_check_in_progress");
    }

    public static function StartAutoSSLCheck(): ?array
    {
        return API::request("SSL/start_autossl_check");
    }

    public static function install(string $domain, string $cert, string $key, string $cabundle = ""): ?array
    {
        return API::request("SSL/install_ssl", compact('domain', 'cert', 'key', 'cabundle'));
    }
}
