<?php

namespace zFramework\Core\Facades;

use CURLFile;

class cURL
{

    /**
     * target url
     */
    static $cURL;
    static $postFields = [];
    static $post = false;
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
     * @return self
     */
    public static function post(array $fields = []): self
    {
        self::$post = true;
        self::$postFields = self::$postFields + $fields;
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
            curl_setopt(self::$cURL, CURLOPT_POSTFIELDS, http_build_query(self::$postFields));
            self::$postFields = [];
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
