<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel SSL certificate management via UAPI.
 */
class SSL
{
    /**
     * Check whether an AutoSSL certificate renewal check is currently in progress.
     * Returns data.in_progress = 1 if a check is running, 0 otherwise.
     */
    public static function AutoSSLStatus(): ?array
    {
        return API::request("SSL/is_autossl_check_in_progress");
    }

    /**
     * Trigger an AutoSSL check immediately for all domains on the account.
     * AutoSSL will attempt to issue/renew Let's Encrypt certificates as needed.
     */
    public static function StartAutoSSLCheck(): ?array
    {
        return API::request("SSL/start_autossl_check");
    }

    /**
     * Install an SSL certificate on a domain.
     *
     * @param string $domain    Domain to install the certificate on (e.g. "example.com")
     * @param string $cert      PEM-encoded certificate (the ---BEGIN CERTIFICATE--- block)
     * @param string $key       PEM-encoded private key (the ---BEGIN PRIVATE KEY--- block)
     * @param string $cabundle  PEM-encoded CA/intermediate bundle (optional; leave empty if not needed)
     */
    public static function install(string $domain, string $cert, string $key, string $cabundle = ""): ?array
    {
        return API::request("SSL/install_ssl", compact('domain', 'cert', 'key', 'cabundle'));
    }
}
