# zFramework Routing

`zFramework\Core\Route`. Files: `route/web.php`, `route/api.php`, `route/dynamic/*`, and each
enabled module's `route/web.php`.

Every behaviour below was checked by registering routes and reading the resulting table, not
inferred from the source.

## Registering

```php
Route::any|get|post|patch|put|delete(string $url, $callback)
Route::resource(string $url, string $controller)
Route::redirect(string $url, string $to, int $status = 302)
Route::name(string $name)                 // names the route defined immediately before it
```

`$callback` should be `[Controller::class, 'method']`. The string form `'Controller@method'`
also works (resolved via `findFile()` through `App/Controllers/`).

**Prefer `[Controller::class, 'method']` to a closure.** A closure cannot go into the route
cache, so its file stays live: the table is cached with a `['live' => file, 'nth' => n]`
note in the closure's place, and at boot that file alone is re-included and the closure put
back (`Route::revive()`). Order and keys survive. It costs that file's parse per request -
the shipped Hookshot module's five closures are ~0.16 ms because its file calls `glob()` -
and `route cache` names the live files so you can see what is still being paid for.

`->name()` must follow the definition directly; it pops the last registered route and re-keys
it. Route names are array keys, which is why two routes cannot share one.

### `Route::resource`

Exactly two arguments. No `->only()`, `->except()`, `->names()`, no `apiResource`.

| Method | URL | Controller | Name |
|---|---|---|---|
| GET | `/posts` | `index` | `posts.index` |
| POST | `/posts` | `store` | `posts.store` |
| GET | `/posts/create` | `create` | `posts.create` |
| GET | `/posts/{id}` | `show` | `posts.show` |
| GET | `/posts/{id}/edit` | `edit` | `posts.edit` |
| PATCH | `/posts/{id}` | `update` | `posts.update` |
| PUT | `/posts/{id}` | `update` | *(unnamed, deliberately)* |
| DELETE | `/posts/{id}` | `delete` | `posts.delete` |

It is **`delete`**, not `destroy`. The PUT route is left unnamed because a name is the array
key, so naming both PATCH and PUT would have one overwrite the other.

With `Route::resource('/', …)` the leading dot is trimmed and the names are bare: `index`,
`store`, `show`, … That is how the framework's own `/` route is registered.

## Url parameters

`{id}` is required, `{?id}` optional. A parameter may carry a type, and **omitting it is
exactly the old behaviour** — `{id}` still matches any segment.

```php
Route::get('/urun/{id:int}', [ProductController::class, 'show'])->name('product.show');
Route::get('/urun/{slug}',   [ProductController::class, 'bySlug'])->name('product.slug');
```

`int` `uint` `float` `alpha` `alnum` `slug` `uuid`. An unrecognised name constrains nothing, so
a typo weakens a route rather than making it match nothing at all — there is no
`Route::where()` and no raw regex.

**A type that does not match is not a 404.** The route simply does not apply and the next one
gets its turn, which is the whole point: the two routes above coexist, `/urun/42` reaching the
first and `/urun/mavi-tisort` falling through to the second. Measured, along with `/tip/-5`
being refused by `uint` and landing on an untyped route.

The type is split off the url when the route is registered and kept in its own map, so the
stored url is a plain `{id}`: `route('product.show', ['id' => 42])` substitutes it unchanged
and a compiled table stays a table of strings.

**Handler parameters are matched by name**, so `/posts/{id}` needs `$id`, not `$postId`.

### No route model binding

`show(Post $post)` does not work and will not be added. Rows are arrays, so binding would hand
an array to a parameter typed as `Post` and TypeError before the method body runs — verified,
not assumed. Take the id and look the row up:

```php
public function show($id)
{
    $post = $this->posts->where('id', $id)->firstOrFail('No such post.');
}
```

## Groups

Three group settings, all applied by wrapping in `->group(fn() => …)`:

```php
Route::pre(string $prefix, ?string $namePrefix = null)   // url prefix + name prefix
Route::middleware(array $list, ?Closure $onDecline = null)
Route::noCSRF()
Route::throttle(?int $limit = null, ?int $window = null, ?string $by = null, ?int $block = null)
```

`throttle()` attaches `App\Middlewares\Throttle` as well as carrying the numbers, so it is the
only call needed — the limit sits with the routes it governs rather than in a config table
keyed by url. See `references/infrastructure.md`.

They chain in any order, because each one only writes into the pending-group array:

```php
Route::pre('/api')->noCSRF()->middleware([API::class])->group(function () { … });
```

### Prefixes accumulate, and so do names

`Route::pre()` prefixes **both** the url and the route name. Nesting accumulates and unwinds
correctly:

```php
Route::pre('/admin')->middleware([Auth::class])->group(function () {
    Route::get('/dash', [A::class, 'i'])->name('dash');              // /admin/dash        admin.dash        [Auth]
    Route::pre('/blog')->middleware([Editor::class])->group(function () {
        Route::get('/posts', [B::class, 'i'])->name('posts');        // /admin/blog/posts  admin.blog.posts  [Auth, Editor]
    });
    Route::get('/after', [C::class, 'i'])->name('after');            // /admin/after       admin.after       [Auth]
});
```

The inner group inherits the outer prefix and its middleware list, and the outer settings are
restored afterwards — `/after` is back to `[Auth]`.

#### The name is built top-down, one segment per `pre()`

This is the part that differs most from Laravel, where `prefix()` touches only the url and the
name prefix is a separate call. Here **`pre()` contributes a name segment by itself** — you
never write a name prefix, you only write the last piece on the route:

```php
Route::pre('/admin')->group(function () {
    Route::get('/dash', …)->name('dash');                    // admin.dash
    Route::get('/no-name', …);                               // no name at all — key stays numeric
    Route::get('/slashed', …)->name('sub/leaf');             // admin.sub.leaf
    Route::pre('/blog')->group(function () {
        Route::get('/posts', …)->name('posts');              // admin.blog.posts
        Route::resource('/category', CategoryController::class);
        //   admin.blog.category.index / .create / .show / .edit / .store / .update / .delete
        Route::pre('/deep')->group(fn() =>
            Route::get('/x', …)->name('x'));                 // admin.blog.deep.x
    });
});
```

Every `/` becomes a `.` — in the prefixes and in the name you pass, which is why
`->name('sub/leaf')` comes out as `sub.leaf`. Leading and doubled dots are trimmed.

Always look a route up by its **full** name: `route('admin.blog.category.edit', ['id' => 5])`.
There is no "current group" shorthand.

A route with no `->name()` keeps a numeric array key and cannot be looked up by name at all.

#### Splitting the name from the url

The second argument replaces the name segment for that level, which is how a url can change
without touching a single `route()` call site:

```php
Route::pre('/devices', '/assets')->group(function () {
    Route::get('/list', …)->name('list');                    // url /devices/list      name assets.list
    Route::pre('/sub')->group(fn() =>
        Route::get('/y', …)->name('y'));                     // url /devices/sub/y     name assets.sub.y
});
```

Only that level is substituted — nested levels still contribute their own url prefix to the
name (`/sub` → `.sub`).

Pass an empty string to add a url prefix that contributes **nothing** to the name:

```php
Route::pre('/panel', '')->group(fn() => Route::get('/a', …)->name('a'));
// url /panel/a, name just "a"
```

#### Localised urls with a stable name

This is what the second argument is really for — a url that is translated per locale while
every `route()` call site keeps working:

```php
Route::pre('/' . _l('routes.admin.route'), '/admin')->group(function () {
    Route::resource('/posts', AdminPostController::class);   // always admin.posts.index
});
```

`locale=tr` gives `/yonetim/posts`, `locale=en` gives `/admin/posts`, and the name is
`admin.posts.*` either way.

This works because global middlewares are included **before** the route files, on purpose —
`App/Middlewares/Language.php` has already resolved the visitor's locale from the cookie by
the time `route/web.php` runs.

**But it is incompatible with the route cache.** `writeCache()` stores the table with
`var_export()`, so the url is frozen as a literal string — whatever locale the CLI had when
`php terminal route cache` ran, which is the default, with no cookie in sight. Every visitor
then gets that one language's urls and 404s on the others.

Put locale-dependent groups in **`route/dynamic/`**, which is excluded from the cache in both
directions (`Route::sources()` skips it, and `handle()` re-includes it per request on top of
the booted table). Keep those files cheap — they are re-evaluated on every request, including
ones that never touch those routes.

**If most of the routing is dynamic, stop splitting it and turn the cache off:**

```php
// config/framework.php
'route' => ['caching' => false, 'auto-check' => false],
```

`route/dynamic/` earns its keep when a few definitions are request-dependent and the bulk is
static. Once the bulk is dynamic, the cache holds a small fraction of the table while the
translated or tenant-scoped groups still run every request — you pay the include cost anyway
and get a second place where routes live, plus a cache that has to be rebuilt on deploy and
silently freezes anything that slipped back into `route/web.php`. One file, caching off, is
both cheaper to reason about and closer to what actually happens. `php terminal bench run`
reports what the cache is worth on this project before you decide.

`noCSRF` is inherited by nested groups too.

Look routes up with the full name: `route('admin.blog.posts')`, `route('posts.show', ['id' => 5])`.

### Two traps, both confirmed

**1. A group setting that never gets a `->group()` is left dangling, and the next group picks
it up.** This is not about nesting — a properly closed group inherits inward, which is the
point, and does not affect anything after it:

```php
Route::pre('/admin')->middleware([Auth::class])->group(function () {
    Route::get('/dash', …)->name('dash');               // /admin/dash        [Auth]   inherited, correct
    Route::pre('/blog')->group(fn() =>
        Route::get('/posts', …)->name('posts'));        // /admin/blog/posts  [Auth]   inherited, correct
});
Route::get('/public', …)->name('public');               // /public            []       clean
Route::middleware([Other::class])->group(fn() =>
    Route::get('/other', …)->name('other'));            // /other             [Other]  clean
```

The trap is a call with **no `->group()` at all**. Pending settings are only cleared inside
`group()`, so they sit there waiting:

```php
Route::pre('/dangling');                                // ->group() never called
Route::get('/public', …)->name('public');               // /public           unaffected
Route::middleware([Other::class])->group(fn() =>
    Route::get('/other', …)->name('other'));            // /dangling/other  <- picked it up
```

Plain routes defined in between are untouched — only the next `group()` inherits it, which is
what makes it hard to spot. Never write `pre()`, `middleware()` or `noCSRF()` without
immediately chaining `->group()`.

**2. ~~Two `middleware()` calls at the same level: the first is lost.~~ Fixed.**

`middleware()` used to merge only against the enclosing group, so a second call at the same
level threw the first away — and `->throttle()->middleware([...])` silently dropped the
middleware `throttle()` had just added. It now merges with what is already pending too, so
chaining accumulates:

```php
Route::middleware([A::class])->middleware([B::class])->group(…);   // [A, B]
Route::pre('/api')->throttle(120)->middleware([API::class])->group(…);   // [Throttle, API]
```

Order still matters — see the middleware section.

## Middleware

A middleware is a plain class with two methods:

```php
namespace App\Middlewares;

class IsAdmin
{
    public function attempt()      // truthy = pass
    {
        return (bool) (\zFramework\Core\Facades\Auth::user()['is_admin'] ?? false);
    }

    public function error() { abort(403); }
}
```

`php terminal make middleware IsAdmin` generates it.

Behaviour that is easy to get wrong:

- **Every middleware in the list runs, even after one declines.** There is no short-circuit;
  the declining ones are collected into `$declines` and the group's callback is handed the
  whole list.
- **`error()` is never called on the routing path.** `Route::match()` always passes its own
  callback to `Middleware::middleware()`, and `error()` only runs in the no-callback branch.
  It fires only when you call `Middleware::middleware([...])` yourself without a callback.
- **A decline with no fallback closure produces a 404**, because `match()` simply stops and
  `run()` aborts with 404 when no route was matched. Verified: `/route-behind-a-declining-middleware`
  → HTTP 404, and the middleware's `error()` did not run.
- **With a fallback closure, the closure decides.** `fn($declines) => abort(403)` gives a 403.
  `$declines` is the array of middleware class names that returned falsy.

So if you want anything other than a 404 — a redirect to login, a 403, a flash message — pass
the fallback closure. Relying on `error()` from a route group does not work.

```php
Route::pre('/admin')
    ->middleware([Auth::class, IsAdmin::class], fn($declines) => abort(403))
    ->group(fn() => Route::resource('/posts', AdminPostController::class));
```

Outside routing the second form applies, and there `error()` does run:

```php
Middleware::middleware([Auth::class, IsAdmin::class]);                        // calls error() per decline
Middleware::middleware([Auth::class], fn($declined) => abort(403));           // callback decides
```

## Matching order

Static routes are resolved through a hash; parameterised ones are scanned in definition order.
**A dynamic route defined earlier beats a static route defined later.** That is why
`Route::resource` registers `/create` before `/{id}` — reverse them and `/create` would be
swallowed as an id.

### Rule: the root resource goes last

`Route::resource('/', WelcomeController::class)` registers `/{id}`, which matches **any**
single-segment path and lets `show()` serve it. That is intended — a root resource is meant to
own the one-segment namespace (slugs, short links, profile handles).

The consequence is an ordering rule, and it is not optional:

> **Write the root and resource routes at the bottom of the route file.** Everything more
> specific goes above them.

A one-segment static route defined *after* the root resource is out-ranked and is served by
`show($id)` instead — in the generated controller, `abort(404)`:

```php
// route/web.php
Route::get('/status', …)->name('status');            // ✅ above the root resource
Route::pre('/admin')->group(…);
Route::resource('/', WelcomeController::class);      // ← last line, owns /{id}
```

`route/dynamic/` is included **after** `route/web.php`, so this applies to everything in it:
a one-segment route there can never win against the root resource. Give those routes two
segments (`/status/live`) or a prefix. This is why `/_dynamic-check` in
`route/dynamic/example.php` returns 404 even with `debug` on — confirmed by dumping the
table from inside a request: the route is registered and its segments match, it simply loses
on position.

The same ordering applies to any resource: `Route::resource('/posts', …)` owns `/posts/{id}`,
so a later `/posts/archive` would be read as an id.

CSRF is checked in `match()`, before middleware, and a failure aborts with 406. `noCSRF()`
skips it — use it for API groups.

## Controller invocation

`run()` builds the controller as `new $class($method)` — **the constructor receives the name of
the method about to run**, as a string. Accept it or leave the parameter off.

Route parameters are passed as named arguments. Any remaining type-hinted parameter is filled
with a bare `new $type` — this is not a container, so the class must be constructible with no
arguments. That is how `Request` subclasses get injected and validated.

## Cache and inspection

```bash
php terminal route cache    # compiles the table; files with closures stay live and are listed
php terminal route clear
php terminal route list
```

**`route list` is not the full picture.** Routes registered conditionally — behind a module's
`status`, inside `route/dynamic/` (never cached, re-read every request), or behind any runtime
condition — may not appear. To know what is really registered, read the route files themselves.

Before turning caching on, check the route files for a url built from request state — a
locale (`_l()`), a tenant, a config flag read at include time. The cache stores urls as
literal strings, so anything like that is frozen at build time. Those groups belong in
`route/dynamic/` — or, if there are many of them, leave `route.caching` off entirely rather
than maintaining the split. See "Localised urls with a stable name" above.

`Route::has('/admin')` tests whether a url substring is registered, which is how
`ViewDirectives` decides it is in the admin layer.
