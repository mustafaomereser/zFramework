<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel domain & DNS zone management via UAPI.
 * Covers: domain info, subdomain CRUD, DNS zone record CRUD.
 */
class Domain
{
    /**
     * List all domains on the account (main, addon, sub, parked).
     * Returns arrays keyed by domain type.
     */
    public static function list(): ?array
    {
        return API::request("DomainInfo/list_domains");
    }

    /**
     * Get detailed data for all domains (document root, PHP version, etc.).
     */
    public static function data(): ?array
    {
        return API::request("DomainInfo/domains_data");
    }

    /**
     * List the built-in subdomain aliases of the account's main domain
     * (e.g. mail.example.com, webmail.example.com, cpanel.example.com).
     */
    public static function aliases(): ?array
    {
        return API::request("DomainInfo/main_domain_builtin_subdomain_aliases");
    }

    /**
     * Return the account's primary/main domain name.
     */
    public static function primaryDomain(): ?array
    {
        return API::request("DomainInfo/primary_domain");
    }

    /**
     * Create a subdomain under the account's main domain.
     *
     * @param string $name  Subdomain label only (e.g. "blog" → blog.example.com)
     * @param string $root  Document root prefix, defaults to /public_html
     *                      Final path becomes {root}/{name}
     */
    public static function addSubdomain(string $name, string $root = "/public_html"): ?array
    {
        return API::request("SubDomain/addsubdomain", [
            "domain"     => $name,
            "rootdomain" => API::$domain,
            "dir"        => $root . "/" . $name
        ]);
    }

    /**
     * Remove a subdomain. The document root directory is NOT deleted.
     *
     * @param string $name  Subdomain label only (e.g. "blog"); FQDN is built internally
     */
    public static function deleteSubdomain(string $name): ?array
    {
        return API::request("SubDomain/delsubdomain", ["domain" => $name . "." . API::$domain]);
    }

    // ── DNS Zone Management ─────────────────────────────────────────────────

    /**
     * Fetch all DNS zone records for a domain.
     *
     * @param string $domain  The zone domain, e.g. "example.com"
     * @return array|null     Array of zone records; each record has: line, type, name, address, ttl, etc.
     */
    public static function listDNSRecords(string $domain): ?array
    {
        return API::request("ZoneEdit/fetch_zone_records", compact('domain'));
    }

    /**
     * Add a DNS zone record.
     *
     * @param string $domain   Zone domain, e.g. "example.com"
     * @param string $type     Record type: A, AAAA, CNAME, MX, TXT, CAA, SRV, NS, PTR
     * @param string $name     Record name/host (e.g. "www", "@" for root, "mail")
     * @param string $address  Record value (IP for A/AAAA, hostname for CNAME/MX/NS, text for TXT)
     * @param int    $ttl      Time-to-live in seconds, default 3600
     */
    public static function addDNSRecord(string $domain, string $type, string $name, string $address, int $ttl = 3600): ?array
    {
        $type = strtoupper($type);
        return API::request("ZoneEdit/add_zone_record", compact('domain', 'type', 'name', 'address', 'ttl'));
    }

    /**
     * Edit an existing DNS zone record in-place (identified by line number).
     * Use listDNSRecords() first to find the line number of the record to edit.
     *
     * @param string $domain   Zone domain, e.g. "example.com"
     * @param int    $line     Line number from listDNSRecords() response
     * @param string $type     New record type: A, AAAA, CNAME, MX, TXT, CAA, SRV, NS
     * @param string $name     New record name/host
     * @param string $address  New record value
     * @param int    $ttl      New TTL in seconds, default 3600
     */
    public static function editDNSRecord(string $domain, int $line, string $type, string $name, string $address, int $ttl = 3600): ?array
    {
        $type = strtoupper($type);
        return API::request("ZoneEdit/edit_zone_record", compact('domain', 'line', 'type', 'name', 'address', 'ttl'));
    }

    /**
     * Remove a DNS zone record by its line number.
     * Use listDNSRecords() first to find the line number.
     *
     * @param string $domain  Zone domain, e.g. "example.com"
     * @param int    $line    Line number from listDNSRecords() response
     */
    public static function deleteDNSRecord(string $domain, int $line): ?array
    {
        return API::request("ZoneEdit/remove_zone_record", compact('domain', 'line'));
    }
}
