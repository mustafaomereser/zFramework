<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel UAPI base client.
 *
 * Configuration (set once at boot or in config):
 *   API::$domain   = 'example.com';      // cPanel hostname (without port)
 *   API::$username = 'cpanelusername';    // cPanel account username
 *   API::$apiToken = 'TOKEN_STRING';      // cPanel → Security → Manage API Tokens
 */
class API
{
    // CONFIG
    public static string $domain;    // cPanel domain
    public static string $username;  // cPanel username
    public static string $apiToken;  // API Token (cPanel → Manage API Tokens)
    private static bool $verifySSL = false;

    /**
     * Send a UAPI request to cPanel (port 2083).
     *
     * @param string $endpoint  UAPI module/function path, e.g. "DomainInfo/list_domains"
     * @param array  $params    GET query parameters
     * @param array  $post      POST fields; when non-empty the request becomes a POST
     * @return array|null       Decoded JSON response, or ["error" => "..."] on curl failure
     */
    public static function request(string $endpoint, array $params = [], array $post = []): ?array
    {
        $url = "https://" . self::$domain . ":2083/execute/" . $endpoint;

        if (!empty($params)) $url .= "?" . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => self::$verifySSL,
            CURLOPT_SSL_VERIFYPEER => self::$verifySSL,
            CURLOPT_HTTPHEADER     => ["Authorization: cpanel " . self::$username . ":" . self::$apiToken]
        ]);

        if (count($post)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        $response = curl_exec($ch);
        if (curl_errno($ch)) return ["error" => curl_error($ch)];
        return json_decode($response, true);
    }
}
