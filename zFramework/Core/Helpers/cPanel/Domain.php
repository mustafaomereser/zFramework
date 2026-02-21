<?php

namespace zFramework\Core\Helpers\cPanel;

class Domain
{

    public static function list(): ?array
    {
        return API::request("DomainInfo/list_domains");
    }

    public static function data(): ?array
    {
        return API::request("DomainInfo/domains_data");
    }

    public static function aliases(): ?array
    {
        return API::request("DomainInfo/main_domain_builtin_subdomain_aliases");
    }

    public static function primaryDomain(): ?array
    {
        return API::request("DomainInfo/primary_domain");
    }

    public static function domainsConfig(): ?array
    {
        return API::request("DomainInfo/domains_data");
    }

    public static function addSubdomain(string $name, string $root = "/public_html"): ?array
    {
        return API::request("SubDomain/addsubdomain", [
            "domain"     => $name,
            "rootdomain" => API::$domain,
            "dir"        => $root . "/" . $name
        ]);
    }

    public static function deleteSubdomain(string $name): ?array
    {
        return API::request("SubDomain/delsubdomain", ["domain" => $name . "." . API::$domain]);
    }

    public static function addDNSRecord(string $domain, string $type, string $name, string $address, int $ttl = 3600): ?array
    {
        $type = strtoupper($type);
        return API::request("ZoneEdit/add_zone_record", compact('domain', 'type', 'name', 'address', 'ttl'));
    }

    public static function deleteDNSRecord(string $domain, int $line): ?array
    {
        return API::request("ZoneEdit/remove_zone_record", compact('domain', 'line'));
    }
}
