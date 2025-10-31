<?php

namespace zFramework\Core\Helpers\cPanel;

class API
{
    // CONFIG
    public static string $domain;    // cPanel domain
    public static string $username;  // cPanel username
    public static string $apiToken;  // API Token (cPanel → Manage API Tokens)
    private static bool $verifySSL = false;

    /**
     * Request function
     */
    public static function request(string $endpoint, array $params = []): ?array
    {
        $url = "https://" . self::$domain . ":2083/execute/" . $endpoint;

        if (!empty($params)) $url .= "?" . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => self::$verifySSL,
            CURLOPT_HTTPHEADER     => ["Authorization: cpanel " . self::$username . ":" . self::$apiToken]
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) return ["error" => curl_error($ch)];
        curl_close($ch);
        return json_decode($response, true);
    }
}
