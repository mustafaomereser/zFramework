# zFramework Auth and the API layer

`zFramework\Core\Facades\Auth`. Rows are arrays here as everywhere: `Auth::user()['email']`.

## The surface

```php
Auth::attempt(array $fields = [], bool $staymein = false): bool
Auth::login(array $user): bool          // $user must contain id (and the password column)
Auth::token_login(string $token): bool  // by the api_token column
Auth::check(): bool
Auth::user()                            // array, or false when nobody is logged in
Auth::id(): ?int
Auth::logout(): bool
Auth::model(): User                     // the user model, built lazily
Auth::encodePassword(?string $plain): string|bool
Auth::forgetCache(string|int|null $id = null): void
```

## `Auth::attempt` — read this before using it

```php
Auth::attempt(['email' => $email, 'password' => $plain], staymein: true);
```

How it actually works: the key named by the model's `special_columns['password']` is pulled out
and verified; **every other key becomes a `where()` on the user table.** So the first argument
is "find the user by these columns, then check this password".

Three behaviours that are not obvious:

**1. It returns `false` when someone is already logged in.** The first line is
`if (self::check()) return false;`. A failed `attempt()` therefore does not always mean bad
credentials — re-submitting a login form while authenticated returns false too. `Auth::logout()`
first if you mean to switch users.

**2. With no password key, there is no password check.** Verified:

```php
Auth::attempt(['email' => 'user@example.com']);   // → true, logged in
Auth::attempt(['id' => 7]);                        // → true, logged in
```

This is the impersonation / "log this user in" path and it is deliberate, but it means
**never hand `attempt()` unfiltered request data**. `Auth::attempt(request())` with a body of
`{"id": 1}` logs the caller in as user 1. Always name the fields explicitly, and always pass
the password key when you mean to authenticate:

```php
$v = $request->validated();
Auth::attempt(['email' => $v['email'], 'password' => $v['password']], (bool) $v['keep-logged-in']);
```

**3. `$staymein` is the "remember me" flag** — it stores `api_token|<trace>` as an `auth-stay-in`
cookie, where the trace is the tail of the password hash. `auth-token` and `auth-password` expire
after a day; this one is set to outlive them, so without the trace a device that stayed away past
the expiry would be let straight back in by a password change it was meant to be locked out of.
`Auth::restore()` checks the trace against the row before logging anyone in. `api_token` itself is
never rotated — it authenticates the API.

## The model side — `special_columns`

Which columns Auth uses is declared on the user model, not in config:

```php
class User extends Model
{
    public $special_columns = [
        'email'          => 'email',
        'password'       => 'password',
        'passwordencode' => 'bcrypt',    // bcrypt | md5 | crypter
    ];
}
```

Defaults when the model names none: `email`, `password`, and `crypter`. `bcrypt` is the right
choice; `md5` and `crypter` exist for legacy tables (`crypter` is reversible and is meant for
tokens, not passwords).

`Auth::encodePassword()` follows `passwordencode`, so **always write passwords through it**
rather than calling `password_hash()` yourself — otherwise switching the setting silently
breaks logins:

```php
(new User)->insert([
    'username'  => $v['username'],
    'email'     => $v['email'],
    'password'  => Auth::encodePassword($v['password']),
    'api_token' => Str::rand(60),
]);
```

`api_token` is generated at signup and is what `token_login()` and "remember me" match on.

## Two session modes

Which one is active is decided by whether Redis is configured *and* reachable
(`redis.enabled` plus `Redis::available('session')`). Application code does not choose.

**Cookie mode (no Redis).** Two cookies: `auth-token` holds the user id, `auth-password` holds
the password hash. Every `Auth::user()` re-reads the row and `hash_equals` the stored hash
against the cookie — which is how *changing a password ends every other session*. It also
means one SELECT per request that touches Auth.

Both expire after a day, so that check only reaches a device that comes back within one. Past
that, `auth-stay-in` is the only cookie left and the trace it carries is what enforces the same
rule — see `$staymein` above.

**Token mode (Redis).** A random 32-byte token goes in `auth-session`; Redis holds
`{uid, pwd}` under it, and the user row is cached separately. 200 requests cost one SELECT
instead of 200. The password hash is compared the same way, so the logout-on-password-change
behaviour survives.

In token mode a cached row is stale until its TTL expires. **After updating the current user,
call `Auth::forgetCache()`** — a no-op in cookie mode, so it is always safe to call:

```php
Auth::model()->where('id', Auth::id())->update(['username' => $new]);
Auth::forgetCache();
```

## The API layer

`App/Middlewares/API.php` is what makes an API group authenticate by header instead of cookie:

```php
class API
{
    public function attempt()
    {
        Auth::$api_mode = true;
        Auth::logout();
        if (@$auth_token = getallheaders()['Auth-Token']) Auth::token_login($auth_token);
        return true;
    }
}
```

It sets `Auth::$api_mode`, which switches Auth's storage from `Cookie` to `Session`, discards
any inherited login, and then logs in from the **`Auth-Token`** header matched against the
user's `api_token` column. It always returns true — it authenticates, it does not authorise.

**Use it on every API group.** Do not write a bearer-token check by hand:

```php
// route/api.php
Route::pre('/api')->middleware([App\Middlewares\API::class])->noCSRF()->group(function () {
    Route::pre('/v1')->group(function () {
        Route::get('/posts', [Api\PostController::class, 'index'])->name('api.posts.index');
    });
});
```

`noCSRF()` belongs there too: an API client carries no form token, and without it every POST
aborts with 406.

Because `API::attempt()` always passes, requiring a *logged-in* caller is a second middleware:

```php
Route::pre('/api')->middleware([API::class, Auth::class], fn($d) => Response::status(401))
     ->noCSRF()->group(...);
```

Responses go through `Response::json($data, $flags)`; set a status with `Response::status(422)`
before returning it. Inside an API group `Auth::check()` / `Auth::user()` work exactly as they
do on the web side.

**`$api_mode` is request state.** `Auth::flushRequestState()` resets it, and that matters under
a long-running worker: left standing, one `/api` call would make every later request on that
worker authenticate through Session instead of Cookie. Do not set `Auth::$api_mode` yourself
outside a middleware.

## Guarding pages

Authentication is `Auth::`; keeping people out of a route is middleware — `App/Middlewares/Auth.php`
for "must be logged in", `Guest.php` for "must not be". Write your own for anything finer:

```php
php terminal make middleware IsAdmin
```

```php
class IsAdmin
{
    public function attempt() { return (bool) (Auth::user()['is_admin'] ?? false); }
    public function error()   { abort(403); }
}
```

Remember from `references/routing.md`: on a route group, a declined middleware with no fallback
closure is a plain 404 and `error()` never runs. Pass the closure when you want anything else.
