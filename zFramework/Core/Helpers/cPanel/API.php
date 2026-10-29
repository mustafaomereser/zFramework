<?php

namespace zFramework\Core\Helpers\cPanel;

use zFramework\Core\Facades\cURL;

/**
 * cPanel API client: UAPI, and API 2 for what UAPI does not have (cron).
 *
 * Configuration (set once at boot or in config):
 *   API::$domain    = 'example.com';      // cPanel hostname (without port)
 *   API::$username  = 'cpanelusername';   // cPanel account username
 *   API::$apiToken  = 'TOKEN_STRING';     // cPanel → Security → Manage API Tokens
 *   API::$port      = 2083;               // default; 443 behind a proxy that serves cPanel there
 *   API::$verifySSL = true;               // default; false only for a self-signed certificate
 */
class API
{
    // CONFIG
    public static string $domain;    // cPanel domain
    public static string $username;  // cPanel username
    public static string $apiToken;  // API Token (cPanel → Manage API Tokens)
    public static int $port = 2083;

    /**
     * Was false and private: every call - the API token in its header - went to
     * whoever answered for the hostname, and nothing outside could turn it on.
     */
    public static bool $verifySSL = true;

    /**
     * Send a UAPI request to cPanel.
     *
     * @param string $endpoint  UAPI module/function path, e.g. "DomainInfo/list_domains"
     * @param array  $params    GET query parameters
     * @param array  $post      POST fields; when non-empty the request becomes a POST
     * @return array|null       Decoded JSON response, or ["error" => "..."] on curl failure
     */
    public static function request(string $endpoint, array $params = [], array $post = []): ?array
    {
        $url = self::base() . "/execute/" . $endpoint;

        if (!empty($params)) $url .= "?" . http_build_query($params);
        $request = self::client($url);
        if (count($post)) $request->post($post);

        return $request->send(fn($response, $info, $error) => $error['error_no'] ? ["error" => $error['error_message']] : json_decode((string) $response, true));
    }

    /**
     * Send a cPanel API 2 request. Deprecated by cPanel, but the only API that
     * manages cron: UAPI has no Cron module.
     *
     * The reply is brought to UAPI's shape so callers read both the same way:
     * ['status' => 1|0, 'data' => [...], 'errors' => [...] | null, 'messages' => null].
     * API 2 reports a failed operation inside the data - `status` 0 and a
     * `reason` on the row - while the call itself succeeded (`event.result` 1);
     * both count as a failure here.
     *
     * @param string $module    e.g. "Cron"
     * @param string $function  e.g. "listcron"
     * @param array  $params    the function's parameters
     * @return array|null       UAPI-shaped reply, ["error" => "..."] on curl failure, null if not JSON
     */
    public static function api2(string $module, string $function, array $params = []): ?array
    {
        $request = self::client(self::base() . "/json-api/cpanel")->post(http_build_query([
            'cpanel_jsonapi_user'       => self::$username,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module'     => $module,
            'cpanel_jsonapi_func'       => $function,
        ] + $params));

        return $request->send(function ($response, $info, $error) {
            if ($error['error_no']) return ["error" => $error['error_message']];

            $result = json_decode((string) $response, true);
            if (!is_array($result)) return null;
            if (!isset($result['cpanelresult'])) return ['status' => 0, 'data' => null, 'errors' => [(string) ($result['error'] ?? "HTTP {$info['http_code']}")], 'messages' => null];

            $result = $result['cpanelresult'];
            $data   = $result['data'] ?? [];
            $errors = [];
            if (!empty($result['error'])) $errors[] = (string) $result['error'];
            if (is_array($data)) foreach ($data as $row)
                if (is_array($row) && isset($row['status']) && !$row['status']) $errors[] = (string) ($row['reason'] ?? $row['statusmsg'] ?? 'failed');

            $ok = ($result['event']['result'] ?? 0) == 1 && !$errors;
            return ['status' => $ok ? 1 : 0, 'data' => $data, 'errors' => $errors ?: null, 'messages' => null];
        });
    }

    private static function base(): string
    {
        return "https://" . self::$domain . ":" . self::$port;
    }

    private static function client(string $url): cURL
    {
        return cURL::set($url)
            ->headers(['Authorization' => "cpanel " . self::$username . ":" . self::$apiToken])
            ->options([
                CURLOPT_SSL_VERIFYHOST => self::$verifySSL ? 2 : 0,
                CURLOPT_SSL_VERIFYPEER => self::$verifySSL,
                # Installing a certificate can keep UAPI busy past the facade's 30 s.
                CURLOPT_TIMEOUT        => 60,
            ]);
    }
}
