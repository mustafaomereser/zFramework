<?php

namespace zFramework\Core\Helpers\cPanel;

use zFramework\Core\Facades\cURL;

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
        $request = cURL::set($url)
            ->headers(['Authorization' => "cpanel " . self::$username . ":" . self::$apiToken])
            ->options([
                CURLOPT_SSL_VERIFYHOST => self::$verifySSL ? 2 : 0,
                CURLOPT_SSL_VERIFYPEER => self::$verifySSL,
                # Installing a certificate can keep UAPI busy past the facade's 30 s.
                CURLOPT_TIMEOUT        => 60,
            ]);

        if (count($post)) $request->post($post);

        return $request->send(fn($response, $info, $error) => $error['error_no'] ? ["error" => $error['error_message']] : json_decode((string) $response, true));
    }
}
