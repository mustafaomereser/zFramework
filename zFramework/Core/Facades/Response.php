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
     * Whether this request said anything about caching. Left false, the response
     * is treated as live and told not to be stored anywhere.
     */
    private static bool $cacheDeclared = false;

    /**
     * Seconds the page asked to be cached for, 0 when it did not ask. Read by
     * PageCache after the route has run.
     */
    private static int $cacheTtl = 0;

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
        # Setting it by hand counts as declaring it, so the live-by-default
        # value is not put back over the top of it.
        if (strcasecmp($name, 'Cache-Control') === 0) self::$cacheDeclared = true;

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
        # Under FPM bootstrap.php already sent the live-by-default headers with
        # header(); under the CLI SAPI that call does nothing, so the same
        # default is supplied here, where the worker reads them.
        if (!self::$cacheDeclared) return array_merge([
            ['Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0'],
            ['Pragma', 'no-cache'],
        ], self::$headers);

        return self::$headers;
    }

    /**
     * Declare this response cacheable.
     *
     *   Response::cache(600);              // shared caches and the browser, 10 minutes
     *   Response::cache(600, false);       // this visitor's browser only
     *
     * Nothing is cached unless a page says so. A page that says nothing is
     * assumed to be live and gets no-store, because guessing the other way
     * serves one visitor's page to the next.
     *
     * Only for responses that are the same for everyone. Anything behind a
     * login, anything containing a csrf token, and anything with alerts on it
     * must stay live - `public` here means a CDN or a proxy may keep a copy.
     *
     * @param int|null $seconds null → response.cache-ttl from config/framework.php.
     * @param bool     $shared  false → private, browser only.
     * @return void
     */
    public static function cache(?int $seconds = null, bool $shared = true): void
    {
        $seconds ??= (int) (Config::framework('response.cache-ttl') ?? 600);

        if ($seconds <= 0) {
            self::noCache();
            return;
        }

        # The live default sent at bootstrap carries Pragma: no-cache, which is
        # HTTP/1.0 and outranks Cache-Control on the intermediaries that still
        # read it. Declaring a page cacheable has to take it back off.
        if (PHP_SAPI !== 'cli') {
            if (!headers_sent()) header_remove('Pragma');
        } else {
            self::$headers = array_values(array_filter(self::$headers, fn($header) => strcasecmp($header[0], 'Pragma') !== 0));
        }

        self::$cacheTtl = $seconds;

        self::header('Cache-Control', ($shared ? 'public' : 'private') . ", max-age=$seconds");
        self::header('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    /**
     * Say explicitly what the default already does. Useful to override a
     * cache() set further up, e.g. in a group-wide middleware.
     *
     * @return void
     */
    public static function noCache(): void
    {
        self::$cacheTtl = 0;

        self::header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        self::header('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
    }


    /**
     * Seconds this response declared, or 0.
     * @return int
     */
    public static function cacheTtl(): int
    {
        return self::$cacheTtl;
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
        self::$cacheDeclared = false;
        self::$cacheTtl      = 0;

        # http_response_code() is process-wide, not request-wide. In a worker a 404
        # set by one request stays set, and every later response carries it - the
        # body is right, the status is somebody else's.
        if (PHP_SAPI === 'cli') http_response_code(200);
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
        # Through self::header(), not header(): under CLI the latter is dropped and
        # a json response would arrive without its content type - jQuery then hands
        # the caller a string, so `response.alerts` is undefined and the callback
        # dies on it.
        self::header('Content-Type', self::list[$type]);

        switch ($type) {
            case 'json':
                # Defaults to on, which is what config/response.php shipped with -
                # an installation carrying neither file should behave as it always
                # did rather than quietly stop sending alerts.
                if (Config::framework('response.ajax.include-alerts') ?? true) $data['alerts'] = Alerts::get();
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
