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

**Do not use closures.** They block the route cache — `Route::compilable()` returns false and
`php terminal route cache` refuses the whole table, not just that route.

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

Parameters are `{id}` (required) or `{?id}` (optional) — plain segment matching. **There is no
`Route::where()`**; you cannot constrain a parameter with a regex.

## Groups

Three group settings, all applied by wrapping in `->group(fn() => …)`:

```php
Route::pre(string $prefix, ?string $namePrefix = null)   // url prefix + name prefix
Route::middleware(array $list, ?Closure $onDecline = null)
Route::noCSRF()
```

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

The second argument splits the name from the url, which is how a url can be renamed without
touching any `route()` call site:

```php
Route::pre('/devices', '/assets')->group(fn() => Route::get('/list', …)->name('list'));
// url /devices/list, name assets.list
```

`noCSRF` is inherited by nested groups too.

Look routes up with the full name: `route('admin.blog.posts')`, `route('posts.show', ['id' => 5])`.

### Two traps, both confirmed

**1. A `pre()` (or `middleware()`) that is never `->group()`ed leaks into the next group.**
The pending settings are only cleared inside `group()`, so:

```php
Route::pre('/forgotten');                    // no ->group(...)
Route::get('/innocent', …)->name('innocent');           // unaffected: /innocent
Route::middleware([X::class])->group(fn() =>
    Route::get('/victim', …)->name('victim'));          // becomes /forgotten/victim !
```

The route defined in between is untouched, which is what makes this hard to spot. Never write
a group setting without immediately chaining `->group()`.

**2. Two `middleware()` calls at the same level: the first is lost.**

```php
Route::middleware([A::class])->middleware([B::class])->group(…);   // ends up [B] only
```

Each call merges against the *active* group, not against the pending one, so the second
overwrites the first. Put them in one array — `middleware([A::class, B::class])` — or nest.

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
php terminal route cache    # compiles the table; refuses if any route uses a closure
php terminal route clear
php terminal route list
```

**`route list` is not the full picture.** Routes registered conditionally — behind a module's
`status`, inside `route/dynamic/` (never cached, re-read every request), or behind any runtime
condition — may not appear. To know what is really registered, read the route files themselves.

`Route::has('/admin')` tests whether a url substring is registered, which is how
`ViewDirectives` decides it is in the admin layer.
