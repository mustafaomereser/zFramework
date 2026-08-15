<?php

namespace zFramework\Core\Facades;

/**
 * Server-side full-page cache.
 *
 * Page::cache() declares a page cacheable. That alone sets the HTTP headers -
 * the browser and any CDN reuse their copy, but a request that reaches PHP still
 * re-renders. With response.page-cache on, the rendered output is stored here
 * and replayed without running the route at all.
 *
 * Nothing is stored unless a page declares it, so the config is a kill switch
 * rather than a second opt-in. Run::handle() checks for the directory before
 * touching this class, so an application that never declares a cacheable page
 * pays one stat and never loads the file.
 *
 * What is never stored, whatever the page said:
 *
 *   - anything but GET
 *   - a request carrying an auth cookie, so a logged-in page can never be
 *     stored and handed to the next visitor
 *   - a response that is not 200
 *   - a body containing a csrf token - it is per-session, and the copy would be
 *     wrong for everybody who receives it
 *   - anything declared private, or declared to vary: the store is keyed by url
 *     alone, so it can only hold a response that is the same for everyone
 *
 * Those last two are how a per-visitor fragment works - Page::cache(300,
 * shared: false) plus Page::vary('Cookie') caches in the browser and nowhere
 * else, and signing in or out changes the cookie, so the entry stops matching
 * by itself.
 */
class Page
{
    /**
     * Where the entries live, resolved on first use.
     */
    private static ?string $dir = null;

    /**
     * Declare this page cacheable.
     *
     *   Page::cache();          // response.cache-ttl seconds
     *   Page::cache(600);       // shared caches and the browser
     *   Page::cache(600, false) // this visitor's browser only
     *
     * Sets the HTTP headers, which is all it does while response.page-cache is
     * off - the browser and any CDN reuse their copy, PHP still renders. With
     * page-cache on, the rendered output is stored and replayed too.
     *
     * Nothing is cached unless a page says so. A page that says nothing is
     * assumed to be live, because guessing the other way serves one visitor's
     * page to the next.
     *
     * Only for pages that are the same for everyone. A csrf token in the body
     * is the usual thing that quietly breaks that - it is per-session, so a
     * stored one is wrong for everybody who gets it afterwards.
     *
     * $name tags the entry so it can be dropped without rebuilding the url:
     *
     *   Page::cache(600, name: 'post-' . $post['id']);
     *   Page::forget('post-' . $post['id']);          // later, when it changes
     *
     * The url stays the lookup key - serve() runs before the route and only has
     * the request to go on - so the name is a second way in, not a replacement.
     *
     * @param int|null    $seconds null → response.cache-ttl from config/framework.php.
     * @param bool        $shared  false → private, browser only.
     * @param string|null $name    Tag for forget().
     * @return void
     */
    public static function cache(?int $seconds = null, bool $shared = true, ?string $name = null): void
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
            Response::dropHeader('Pragma');
        }

        # The ttl is what the server-side store reads, so a private response must
        # not set one: "for this visitor only" and "keep one copy and hand it to
        # everybody" are opposites. The headers still go out - the browser is
        # exactly who should keep it.
        Response::cacheTtl($shared ? $seconds : 0);
        if ($name !== null && $shared) Response::cacheName($name);
        Response::header('Cache-Control', ($shared ? 'public' : 'private') . ", max-age=$seconds");
        Response::header('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    /**
     * Say explicitly what the default already does. Useful to override a
     * cache() set further up, e.g. from a group-wide middleware.
     *
     * @return void
     */
    public static function noCache(): void
    {
        Response::cacheTtl(0);
        Response::header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        Response::header('Pragma', 'no-cache');
        Response::header('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
    }

    /**
     * Tell caches that the response depends on these request headers.
     *
     * The one that matters here is Cookie, and it is what makes a per-visitor
     * fragment cacheable at all:
     *
     *   Page::cache(300, shared: false);   // this browser only, never a CDN
     *   Page::vary('Cookie');              // a different cookie set is a different entry
     *
     * You cannot reach into a browser and delete what it stored. Vary is how
     * you avoid needing to: signing in or out changes the auth cookie, the
     * cookie is part of the cache key, so the old entry simply stops matching
     * and the browser fetches again. No invalidation call, nothing to forget.
     *
     * Conservative by nature - any cookie changing busts it, analytics included.
     * That is the right way round for something that shows who you are.
     *
     * Never pair Vary: Cookie with shared: true. A shared cache keying on the
     * cookie is one entry per visitor sitting in a CDN, and any cache that
     * ignores Vary hands the first visitor's copy to everyone.
     *
     * @param string ...$headers
     * @return void
     */
    public static function vary(string ...$headers): void
    {
        if (!$headers) return;

        # A stored entry is keyed by url alone, so it cannot represent a response
        # that varies by anything else - storing one would serve the wrong
        # variant to everybody. Declaring Vary takes it out of the store and
        # leaves it to the browser, which does honour the header.
        Response::cacheTtl(0);

        Response::header('Vary', implode(', ', $headers));
    }

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

        # A stored page is cacheable by definition, so the live default sent at
        # bootstrap has to go - Pragma: no-cache is HTTP/1.0 and outranks the
        # Cache-Control being replayed on the intermediaries that read it.
        if (PHP_SAPI !== 'cli') {
            if (!headers_sent()) header_remove('Pragma');
        } else {
            Response::dropHeader('Pragma');
        }

        foreach ((array) ($meta['headers'] ?? []) as [$name, $value]) Response::header($name, $value);
        # Debug only: in production it tells a visitor which pages are cached,
        # which is a map of where to look for a stale-token or stale-content bug.
        if (Config::get('app.debug') ?? false) Response::header('X-Page-Cache', 'HIT');

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

        # A csrf token is per-session. Storing a page that carries one hands the
        # same token to everybody who gets the copy, and every form they submit
        # fails with 406 - a loop, if the page submits on load.
        #
        # Caught here rather than left to the developer because the failure is
        # remote from its cause: the page renders fine, and only the next
        # visitor's POST breaks. The headers are undone too, or the browser
        # would keep replaying the stale token from its own cache.
        if (str_contains($body, "name='_token'") || str_contains($body, 'name="_token"')) {
            self::noCache();

            # Debug only. The safe behaviour - staying live - is automatic, and a
            # page that will never be cacheable would otherwise write this line on
            # every single request forever.
            if (Config::get('app.debug') ?? false)
                Log::warning('Page not cached: it contains a csrf token.', ['url' => $_SERVER['REQUEST_URI'] ?? '/']);

            return;
        }

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

        if (!@rename($temporary, self::path())) {
            @unlink($temporary);
            return;
        }

        # A marker per tagged entry, named after the entry it points at. forget()
        # then reads one directory instead of opening every cached page to look
        # for a tag it probably does not carry.
        if ($name = Response::cacheName()) {
            $tagDir = self::tagDir($name);

            if (is_dir($tagDir) || @mkdir($tagDir, 0755, true))
                @file_put_contents($tagDir . '/' . self::key($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/'), '');
        }
    }

    /**
     * Drop every entry tagged with a name.
     *
     *   Page::cache(600, name: 'post-' . $id);   // where the page is rendered
     *   Page::forget('post-' . $id);             // where the post is saved
     *
     * Invalidation is the weak point of caching a page: the entry stays correct
     * until the content behind it changes, and nothing tells it so. An observer
     * on the model is the tidy place to call this.
     *
     * A name can cover several urls - the same post at /blog/x and /blog/x?ref=y
     * are two entries and one tag - so this drops all of them.
     *
     * @param string $name
     * @return int How many entries were removed.
     */
    public static function forget(string $name): int
    {
        $tagDir  = self::tagDir($name);
        $removed = 0;

        foreach ((array) glob($tagDir . '/*') as $marker) {
            if (@unlink(self::dir() . '/' . basename($marker) . '.cache')) $removed++;
            @unlink($marker);
        }

        @rmdir($tagDir);

        return $removed;
    }

    /**
     * Drop the stored copy of one url, when it was never tagged.
     *
     * The url has to match what the visitor requests, query string included,
     * because that is what the key is built from.
     *
     * @param string $url    e.g. /blog/hello or /blog?page=2
     * @param string $method
     * @return bool Whether an entry was there to remove.
     */
    public static function forgetUrl(string $url, string $method = 'GET'): bool
    {
        $file = self::dir() . '/' . self::key($method, $url) . '.cache';

        return is_file($file) && @unlink($file);
    }

    /**
     * Drop every stored entry - the blunt version of forget(), for a deploy or
     * a change wide enough that working out which pages it touched is not worth
     * it. Also `php terminal cache clear pages`.
     *
     * @return int How many were removed.
     */
    public static function clear(): int
    {
        $removed = 0;
        foreach ((array) glob(self::dir() . '/*.cache') as $file) if (@unlink($file)) $removed++;

        foreach ((array) glob(self::dir() . '/tags/*') as $tagDir) {
            foreach ((array) glob($tagDir . '/*') as $marker) @unlink($marker);
            @rmdir($tagDir);
        }

        return $removed;
    }

    /**
     * @param string $name
     * @return string
     */
    private static function tagDir(string $name): string
    {
        return self::dir() . '/tags/' . sha1($name);
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
        return self::dir() . '/' . self::key($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/') . '.cache';
    }

    /**
     * @param string $method
     * @param string $uri
     * @return string
     */
    private static function key(string $method, string $uri): string
    {
        return sha1(strtoupper($method) . '|' . $uri . '|' . self::localeKey());
    }

    /**
     * The language inputs, as part of the key.
     *
     * serve() runs before the global middlewares, so the locale has not been
     * resolved yet - and without this an English visitor was handed the Turkish
     * copy of a page, which is exactly the bug the store's other rules exist to
     * prevent. The url is not enough on a translated site.
     *
     * Keyed on what decides the language rather than on the language itself:
     * identical inputs resolve identically, so the worst case is one extra entry
     * rather than the wrong page. Reads it exactly as the Language middleware
     * does - through Cookie, whose names are hashed and values encrypted, so
     * $_COOKIE['lang'] does not exist - and falls back to the first two
     * characters of Accept-Language the way Lang::locale() does.
     *
     * Anything else that varies per visitor and is not in the url - a tenant, a
     * currency - has to use Page::vary(), which keeps the response out of the
     * shared store altogether.
     *
     * @return string
     */
    private static function localeKey(): string
    {
        return ((string) (Cookie::get('lang') ?: '')) . '|' . substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 2);
    }
}
