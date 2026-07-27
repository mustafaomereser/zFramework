<?php

namespace zFramework\Core\Facades;

class Response
{
    /**
     * Response type list
     */
    const list = [
        'json' => 'application/json'
    ];

    /**
     * For each response
     */
    static $addinationals = [];

    /**
     * Headers collected when PHP cannot send them itself (CLI SAPI).
     */
    private static array $headers = [];

    /**
     * Send a response header, or collect it when there is nothing to send to.
     *
     * Under FPM this is header(). Under the CLI SAPI - which is what a
     * RoadRunner worker runs as - header() is silently ignored, so the value is
     * kept here and the worker attaches it to the PSR-7 response instead.
     *
     * Framework code should go through this rather than header() directly, or
     * the header will vanish in a long-running worker.
     *
     * @param string $name
     * @param string $value
     * @param bool   $replace
     * @return void
     */
    public static function header(string $name, string $value, bool $replace = true): void
    {
        if (PHP_SAPI !== 'cli') {
            if (!headers_sent()) header("$name: $value", $replace);
            return;
        }

        if ($replace) self::$headers = array_values(array_filter(self::$headers, fn($header) => strcasecmp($header[0], $name) !== 0));
        self::$headers[] = [$name, $value];
    }

    /**
     * Headers collected during this request, as [name, value] pairs.
     * @return array
     */
    public static function headers(): array
    {
        return self::$headers;
    }

    /**
     * Set the response status.
     *
     * http_response_code() works under CLI as a plain getter/setter, so it is
     * used as the store either way - no separate bookkeeping needed.
     *
     * @param int|null $code
     * @return int
     */
    public static function status(?int $code = null): int
    {
        if ($code !== null) http_response_code($code);
        return (int) (http_response_code() ?: 200);
    }

    /**
     * Drop payload additions and collected headers.
     *
     * json() clears the additions as it writes, so anything left here belongs to
     * a request that added some and never sent a json response.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$addinationals = [];
        self::$headers       = [];
    }

    /**
     * Addinational parameter
     * @param string $key
     * @param mixed $data
     * @return self
     */
    public static function addination(string $key, mixed $data)
    {
        self::$addinationals[$key] = $data;
        return new self();
    }

    /**
     * Result Method
     * @param string $type
     * @param array $data
     * @param ?string $flags
     * @return string|mixed
     */
    private static function do(string $type, array $data = [], ?string $flags = null)
    {
        header("Content-Type: " . self::list[$type]);

        switch ($type) {
            case 'json':
                if (config('response.ajax.include-alerts')) $data['alerts'] = Alerts::get();
                $data = json_encode($data + self::$addinationals, JSON_UNESCAPED_UNICODE | $flags);
                self::$addinationals = [];
                break;
        }

        return $data;
    }

    /**
     * Type Json
     * @param array $data
     * @param ?string $flags
     */
    public static function json(array $data, ?string $flags = null)
    {
        return self::do(__FUNCTION__, $data, $flags);
    }
}
