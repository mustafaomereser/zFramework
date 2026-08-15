# zFramework Page Caching

`zFramework\Core\Facades\Page`. Two layers behind one call:

| Layer | What it is | Who honours it |
|---|---|---|
| HTTP headers | `Cache-Control`, `Expires`, `Vary` | the browser, and any CDN or proxy |
| Server-side store | the rendered output on disk under `storage/pages` | this application, before the route runs |

`Page::cache()` sets the headers always. It also stores, when the response is
eligible — which is most of what this file is about.

## Live by default

**A response nobody said anything about is treated as live.** `bootstrap.php` sends
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache` on every
request, before anything else runs, so the default applies even when something fatals later.

It is sent from the framework rather than `public_html/index.php` — policy in the entry file
cannot be overridden per page, and there is nowhere to put the exception.

Guessing the other way round serves one visitor's page to the next. Nothing is cached until a
page says so.

## Declaring

```php
Page::cache();                       // response.cache-ttl seconds, shared
Page::cache(600);                    // 10 minutes, shared caches and the browser
Page::cache(600, shared: false);     // this visitor's browser only
Page::cache(600, name: 'post-5');    // tagged, so forget() can find it
Page::noCache();                     // back to live - to override a cache() set further up
Page::vary('Cookie');                // the response depends on the request's cookies
```

Called from a controller — the constructor covers every method, a single method covers one
page:

```php
class PostController extends Controller
{
    public function __construct()
    {
        Page::cache(600);        // every method on this controller
    }
}
```

## What is never stored

The headers still go out; only the server-side copy is refused. Six rules, all of them
measured:

1. **Not a GET.** A POST is never the same for the next visitor.
2. **The request carries an auth cookie** (`auth-token`, `auth-session`, `auth-stay-in`). A
   logged-in page must never be stored and handed to someone else. Tested as a cookie rather
   than `Auth::check()`, which would open a database connection on every request just to
   decide not to cache.
3. **The response is not 200.** A `Page::cache()` in a constructor runs before the method
   decides the outcome, so `abort(404)` used to go out with `public, max-age=600` on it. The
   declaration is revoked when the status is not 200 — headers included.
4. **The body contains a csrf token.** Per-session, so the stored copy is wrong for everybody
   who receives it and every form they submit fails with 406. Refused, headers reverted, and
   with `app.debug` on, a `Log::warning` saying why. This is a real bug that shipped for an
   hour: the welcome page declared itself cacheable, its form auto-submits on load, and the
   result was a reload loop.
5. **`shared: false`.** "For this visitor only" and "keep one copy for everyone" are
   opposites.
6. **`Page::vary(...)` was called.** The store is keyed by url alone, so it cannot represent a
   response that varies by anything else.

Rules 5 and 6 are not restrictions to work around — they are how per-visitor caching works.

## Per-visitor caching

For a fragment that shows who you are — a login/logout header, a cart count:

```php
Page::cache(300, shared: false);
Page::vary('Cookie');
```

The browser keeps it, nothing else does. **You cannot reach into a browser and delete what it
stored, and with `Vary: Cookie` you do not need to**: signing in or out changes the auth
cookie, the cookie is part of the cache key, so the old entry stops matching and the browser
fetches again.

Conservative by nature — any cookie changing busts it, analytics included. For something that
shows an identity, that is the right way round.

Never pair `Vary: Cookie` with `shared: true`. A shared cache keying on the cookie is one
entry per visitor sitting in a CDN, and any cache that ignores `Vary` hands the first
visitor's copy to everyone.

## Invalidation

The weak point of caching a page: the entry stays correct until the content behind it changes,
and nothing tells it so.

```php
Page::forget('post-5');                  // every url tagged 'post-5'; returns how many
Page::forgetUrl('/blog/hello');          // by url, when it was never tagged
Page::clear();                           // everything - a deploy, or a change too wide to trace
php terminal cache clear pages           // the same, from the CLI
```

**Prefer the tag.** Rebuilding the url — query string and all — at the point where a model is
saved is the part nobody gets right, and one tag can cover several urls:

```php
// where the page renders
Page::cache(600, name: 'post-' . $post['id']);

// where the post is saved - an observer is the tidy place
public function onupdated(array $row)
{
    Page::forget('post-' . $row['id']);
}
```

A tag writes one marker file per entry under `storage/pages/tags/`, so `forget()` reads a
directory instead of opening every cached page looking for a tag it probably does not carry.

The url is still the lookup key — `serve()` runs before the route and has only the request to
go on — so a name is a second way in, not a replacement. The query string is part of the key:
`/blog` and `/blog?page=2` are two entries, and one tag covers both.

## Configuration

```php
// config/framework.php
'response' => [
    'cache-ttl'  => 600,     // what Page::cache() uses when called with no number
    'page-cache' => true,    // the server-side store
],
```

`page-cache` is a **kill switch, not a second opt-in** — nothing is stored unless a page calls
`Page::cache()`. Turning it off leaves the headers working and takes the store out of the
request path.

Cost when nothing is cached: `Run::handle()` checks `is_dir(storage/pages)` before touching
the class, and that directory does not exist until something has actually been stored. An
application that never declares a cacheable page pays one stat per request and never loads
`Page.php`. Boot measured at 0.87–0.95 ms with all of this in, against 0.90 ms before.

## Debugging

`X-Page-Cache: HIT` is sent on a served entry **only with `app.debug` on**. In production it
maps which pages are cached, which is where to look for a stale-content bug.

A hit ends the request before middlewares, route matching and the session — that is where the
saving comes from. Measured on Apache: 21.7 ms → 16.2 ms on the welcome page.

If a page will not cache, in order of likelihood: it contains a csrf token (check the log with
debug on), the url is one segment and the root resource swallowed it into `show()` → 404 (see
`references/routing.md`), or `page-cache` is off.
