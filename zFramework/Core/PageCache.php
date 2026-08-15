<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Response;

/**
 * Server-side full-page cache.
 *
 * Response::cache() sets the HTTP headers - it tells the browser and any CDN in
 * front of you to reuse their copy. It does nothing for a request that actually
 * reaches PHP: the page is rendered again every time. This stores the rendered
 * output and serves it back without running the route at all.
 *
 * Off by default (response.page-cache). While off, nothing here is loaded - the
 * calls in Run::handle() are behind a config check, so the class is never
 * autoloaded.
 *
 * What is never cached, regardless of what the page declared:
 *
 *   - anything but GET
 *   - a request carrying an auth cookie, so a logged-in page can never be
 *     stored and handed to the next visitor
 *   - a response that is not 200
 *
 * That leaves the developer one rule to hold: a page declared cacheable must be
 * the same for everyone. A csrf token in the body is the usual thing that
 * quietly breaks it - the token is per-session, so a cached one is wrong for
 * everybody who gets it afterwards.
 */
class PageCache
{
    /**
     * Where the entries live, resolved on first use.
     */
    private static ?string $dir = null;

    /**
     * The key for this request, computed once.
     */
    private static ?string $key = null;

    /**
     * Whether this request may take part at all - GET, no auth cookie.
     *
     * The auth test reads a cookie rather than asking Auth::check(), which
     * would open a database connection on every request just to decide not to
     * cache. A visitor with a stale cookie only loses the cache, not the page.
     *
     * @return bool
     */
    public static function eligible(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;

        foreach (['auth-token', 'auth-session', 'auth-stay-in'] as $cookie)
            if (isset($_COOKIE[$cookie])) return false;

        return true;
    }

    /**
     * Send a stored copy if there is a fresh one.
     *
     * @return bool true when the response has been sent and the request is done.
     */
    public static function serve(): bool
    {
        if (!self::eligible()) return false;

        $file = self::path();
        if (!is_file($file)) return false;

        $handle = @fopen($file, 'rb');
        if (!$handle) return false;

        $meta = json_decode((string) fgets($handle), true);

        if (!is_array($meta) || ($meta['expires'] ?? 0) < time()) {
            fclose($handle);
            @unlink($file);
            return false;
        }

        foreach ((array) ($meta['headers'] ?? []) as [$name, $value]) Response::header($name, $value);
        Response::header('X-Page-Cache', 'HIT');

        while (!feof($handle)) echo fread($handle, 65536);
        fclose($handle);

        return true;
    }

    /**
     * Store the rendered output.
     *
     * @param string $body
     * @param int    $ttl Seconds, as declared by Response::cache().
     * @return void
     */
    public static function store(string $body, int $ttl): void
    {
        if ($ttl <= 0 || !self::eligible()) return;
        if (Response::status() !== 200) return;

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;

        $meta = json_encode([
            'expires' => time() + $ttl,
            'headers' => self::storableHeaders(),
        ], JSON_UNESCAPED_SLASHES);

        # Written beside the target and renamed, so a request reading the entry
        # never sees a half-written body.
        $temporary = self::path() . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temporary, $meta . "\n" . $body) === false) return;
        if (!@rename($temporary, self::path())) @unlink($temporary);
    }

    /**
     * Drop every stored entry - `php terminal cache clear pages`.
     *
     * @return int How many were removed.
     */
    public static function clear(): int
    {
        $removed = 0;
        foreach ((array) glob(self::dir() . '/*.cache') as $file) if (@unlink($file)) $removed++;

        return $removed;
    }

    /**
     * Headers worth replaying. Anything per-visitor - cookies above all - must
     * not be stored, or the next visitor gets somebody else's session.
     *
     * @return array
     */
    private static function storableHeaders(): array
    {
        $keep = ['content-type', 'cache-control', 'expires', 'content-language'];
        $out  = [];

        foreach (Response::headers() as [$name, $value])
            if (in_array(strtolower($name), $keep, true)) $out[] = [$name, $value];

        # Under FPM the headers went out through header() rather than being
        # collected, so read them back from PHP.
        if (PHP_SAPI !== 'cli') {
            foreach (headers_list() as $header) {
                [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
                if (in_array(strtolower(trim($name)), $keep, true)) $out[] = [trim($name), trim($value)];
            }
        }

        return $out;
    }

    private static function dir(): string
    {
        if (self::$dir !== null) return self::$dir;

        global $storage_path;
        return self::$dir = ($storage_path ?: FRAMEWORK_PATH . '/storage') . '/pages';
    }

    /**
     * One file per method+url. The query string is part of the key, so a page
     * that varies by ?page=2 caches each one separately.
     *
     * @return string
     */
    private static function path(): string
    {
        self::$key ??= sha1(($_SERVER['REQUEST_METHOD'] ?? 'GET') . '|' . ($_SERVER['REQUEST_URI'] ?? '/'));

        return self::dir() . '/' . self::$key . '.cache';
    }

    /**
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$key = null;
    }
}
