<?php

namespace zFramework\Core\Facades;

use CURLFile;

class cURL
{
    static $cURL;
    static $postFields      = [];
    static $postType        = 'query';
    static $postTypeParse;
    static $post            = false;


    public static function init()
    {
        self::$postTypeParse = [
            'query' => fn() => http_build_query(self::$postFields),
            'json'  => fn() => json_encode(self::$postFields, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK)
        ];
    }


    /**
     * set url and some options
     * @param string $url
     * @return self
     */
    public static function set(string $url): self
    {
        self::$cURL = curl_init($url);
        curl_setopt(self::$cURL, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(self::$cURL, CURLOPT_HEADER, false);
        return new self();
    }

    /**
     * Post parameters
     * @param array $fiels
     * @param string $type
     * @return self
     */
    public static function post(array $fields = [], string $type = 'query'): self
    {
        self::$post = true;
        self::$postFields = self::$postFields + $fields;
        self::$postType = $type;
        return new self();
    }

    /**
     * Post parameters
     * @param string $path
     * @return self
     */
    public static function file(string $key, string $content, string $mime_type = "text/plain"): self
    {
        self::$post = true;
        self::$postFields[$key] = new CURLFile($content, $mime_type, $key);
        return new self();
    }

    /**
     * Set Request Headers
     * @param array $headers
     * @return self
     */

    public static function headers(array $headers)
    {
        $output = [];
        foreach ($headers as $key => $value) $output[] = "$key: $value";
        curl_setopt(self::$cURL, CURLOPT_HTTPHEADER, $output);
        return new self();
    }

    /**
     * Set Options
     * @param array $options
     * @return self
     */
    public static function options(array $options = []): self
    {
        curl_setopt_array(self::$cURL, $options);
        return new self();
    }

    /**
     * Send request to target with all settings.
     * @param \Closure $callback
     */
    public static function send(?\Closure $callback = null)
    {
        if (self::$post) {
            curl_setopt(self::$cURL, CURLOPT_POST, 1);
            curl_setopt(self::$cURL, CURLOPT_POSTFIELDS, self::$postTypeParse[self::$postType]);
            self::$postFields = [];
            self::$postType = "query";
            self::$post = false;
        }

        $response = curl_exec(self::$cURL);
        $err      = curl_errno(self::$cURL);
        $errmsg   = curl_error(self::$cURL);
        $header   = curl_getinfo(self::$cURL);
        curl_close(self::$cURL);

        if ($callback != null) return $callback($response, $header, ['error_no' => $err, 'error_message' => $errmsg]);

        return $response;
    }
}
