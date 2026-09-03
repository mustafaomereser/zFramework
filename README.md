<a href="https://buymeacoffee.com/mustafaomereser" target="_blank"><img src="https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png" alt="Buy Me A Coffee" style="height: 41px !important;width: 174px !important;" ></a>

# zFramework v3.2.0

**Easiest, fastest PHP framework. (Simple)**

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)
![Version](https://img.shields.io/badge/version-3.2.0-green)
![License](https://img.shields.io/badge/license-MIT-orange)

---

### Features

| | |
|---|---|
| ⚡ Route — GET/POST/PUT/PATCH/DELETE, groups, named routes, resource | 🛡️ CSRF protection built-in |
| 🗄️ DB / ORM — fluent query builder, full relation system, pivot ops | 🔐 Auth — session, api token, bcrypt / md5 / crypter |
| 📦 Module system | 📧 Mail — SMTP via PHPMailer |
| 🌍 Multi-language | 🔄 AutoSSL — Let's Encrypt http-01 & dns-01 |
| ✅ Validator | 🖥️ cPanel API — Domain, DNS, DB, Email, SSL |
| 🗃️ Cache — session, APCu (L1) + Redis (L2) | 🔍 Query Analyzer — EXPLAIN + EXPLAIN ANALYZE |
| 🎨 View / template engine (Blade-like directives) | 🔧 Terminal — Artisan-like CLI tool |
| 📮 Queue — Redis-backed jobs + worker command | ⏭️ Defer — run work after the response is sent |
| 🔔 Push Notifications — web push, VAPID, per-app keys | 🤖 AI assistant skill — ships in `.claude/` |
| 🚀 Page cache — HTTP headers + server-side store, tag invalidation | 🚦 Rate limiting — opt-in per route group |
| ⏰ Scheduler — one crontab line, tasks in `schedule/` | 📝 Application log — daily files, levels, retention |
| 🗓️ Cron scripts — one file per job, `cron/cron.php` boots it | ⬆️ Self-update — core only, config merged not overwritten |
| 🚫 Throttle blocking — refuse a flood outright for N seconds | |

---

### Quick Start

```bash
composer install
php terminal run           # starts dev server on local IP:80
php terminal help          # list all available commands
```

```php
// route/web.php
Route::get('/', fn() => view('home.index'));
Route::post('/posts', [PostController::class, 'store']);
Route::pre('/admin')->middleware([Auth::class])->group(function () {
    Route::resource('/posts', PostController::class);
});
```

---

### Working with an AI assistant

This repository ships a Claude Code skill at **`.claude/skills/zframework/`**. Load it with
`/zframework` (it also loads on its own once you touch framework code) and the assistant gets the
whole framework up front — what already exists, the exact signatures, and the conventions — instead
of you re-explaining it every session, or watching it hand-write a feature that already ships here.

```
.claude/skills/zframework/
  SKILL.md                    inventory of what exists, the core API, the Laravel reflexes that break here
  references/api.md              exact signatures for every facade, helper, DB method and job
  references/recipes.md          end-to-end recipes: CRUD, protected area, API, module, upload, push, production
  references/config.md           every key in every config file, and what each PDO option costs
  references/infrastructure.md   AutoSSL, cPanel, query analyzer, RoadRunner workers, backups, error handling
  references/conventions.md      settled decisions, known traps, performance notes
  references/routing.md          groups, parameters, ordering, the route cache
  references/models.md           models, migrations, observers, relations
  references/views.md            template engine, directives, resolution, errors
  references/validation.md       every rule, Request classes, custom rules
  references/auth.md             login flows, middleware, api tokens, redis sessions
  references/caching.md          Page/GlobalCache/Redis and what invalidates what
  templates/                     copy-paste skeletons the recipes refer to
```

It is written off the source, so treat it as a second index into this README rather than a
replacement for it.

---

## Table of Contents

- [Working with an AI assistant](#working-with-an-ai-assistant)
- [1. Route](#1-route)
- [2. Model & DB](#2-model--db)
  - [2.1. Auth](#21-auth)
  - [2.2. Relations](#22-relations)
  - [2.3. Pivot Operations](#23-pivot-operations)
  - [2.4. Observers](#24-observers)
  - [2.5. Migrations](#25-migrations)
  - [2.6. Seeders](#26-seeders)
  - [2.7. Transactions](#27-transactions)
- [3. View](#3-view)
  - [3.1. Page Caching](#31-page-caching)
- [4. Controller](#4-controller)
- [5. Validator](#5-validator)
- [6. Middleware](#6-middleware)
  - [6.1. Rate Limiting](#61-rate-limiting)
- [7. Mail](#7-mail)
  - [7.1. Defer](#71-defer)
- [8. Cache](#8-cache)
  - [8.1. Redis](#81-redis)
  - [8.2. Queue](#82-queue)
  - [8.3. Application Log](#83-application-log)
- [9. Alerts](#9-alerts)
- [10. Csrf](#10-csrf)
- [11. Language](#11-language)
- [12. Crypter](#12-crypter)
- [13. Config](#13-config)
- [14. Terminal](#14-terminal)
  - [14.1. Scheduled Tasks](#141-scheduled-tasks)
  - [14.2. Cron Scripts](#142-cron-scripts)
  - [14.3. Updating the Framework](#143-updating-the-framework)
  - [14.4. Tests](#144-tests)
- [15. API](#15-api)
- [16. Helper Methods](#16-helper-methods)
- [17. AutoSSL](#17-autossl)
- [18. cPanel](#18-cpanel)
- [19. Going to Production](#19-going-to-production)
- [20. RoadRunner](#20-roadrunner)
- [21. Push Notifications](#21-push-notifications)

---

## 1. Route

### HTTP Methods

```php
Route::get('/posts', [PostController::class, 'index']);
Route::post('/posts', [PostController::class, 'store']);
Route::put('/posts/{id}', [PostController::class, 'update']);
Route::patch('/posts/{id}', [PostController::class, 'update']);
Route::delete('/posts/{id}', [PostController::class, 'delete']);
Route::any('/ping', fn() => 'pong');   // matches any HTTP method
```

### Controller Syntax

Both forms are equivalent:

```php
Route::get('/', [WelcomeController::class, 'index']);
Route::get('/', 'WelcomeController@index');
```

The controller is resolved by `findFile()` — it searches recursively in `App/Controllers/`.

### URL Parameters

```php
Route::get('/posts/{id}', [PostController::class, 'show']);        // required
Route::get('/posts/{?id}', [PostController::class, 'show']);       // optional
Route::get('/posts/{id:int}', [PostController::class, 'show']);    // required, digits only
```

Handler parameters are matched **by name**, so `{id}` needs `$id`, not `$postId`.

A parameter may carry a type. **Omitting it is exactly the old behaviour** — `{id}` still
matches any segment.

| Type | Matches |
|---|---|
| `int` | `-?\d+` |
| `uint` | `\d+` |
| `float` | `-?\d+(.\d+)?` |
| `alpha` | letters |
| `alnum` | letters and digits |
| `slug` | letters, digits, `-` and `_` |
| `uuid` | a canonical uuid |

An unrecognised name constrains nothing, so a typo weakens a route rather than making it match
nothing at all. There is no `Route::where()` and no raw regex.

**A type that does not match is not a 404** — the route simply does not apply and the next one
gets its turn. That is the point:

```php
Route::get('/urun/{id:int}', [ProductController::class, 'show']);
Route::get('/urun/{slug}',   [ProductController::class, 'bySlug']);
// /urun/42 reaches the first, /urun/mavi-tisort falls through to the second
```

The type is split off the url when the route is registered, so `route('...', ['id' => 42])`
substitutes a plain `{id}` and the compiled route cache stays a table of strings.

There is **no route model binding**: rows are arrays, so `show(Post $post)` would hand an array
to a parameter typed as `Post` and TypeError before the body runs. Take the id and look the row
up.

### Dependency Injection

Class-typed parameters in route callbacks are automatically resolved via Reflection:

```php
Route::get('/posts', function (PostRepository $repo) {
    return $repo->all();
});
```

This is a bare `new $type`, not a container — the class must be constructible with no
arguments. It is how `Request` subclasses are injected and validated.

URL parameters arrive as **named** arguments, so the handler's parameter name must match the
placeholder: `/posts/{id}` needs `$id`, not `$postId`.

The controller itself is built as `new $class($method)` — the constructor receives the name of
the method about to run, as a string. Accept it or leave the parameter off.

### Redirect

```php
Route::redirect('/old-url', '/new-url');   // issues a 302
```

### Resource

```php
Route::resource('/posts', PostController::class);
```

Registers 8 routes automatically — 7 named, plus the unnamed PUT:

| URL | HTTP Method | Controller Method | Route Name |
|---|---|---|---|
| /posts | GET | index() | posts.index |
| /posts | POST | store() | posts.store |
| /posts/create | GET | create() | posts.create |
| /posts/{id} | GET | show($id) | posts.show |
| /posts/{id}/edit | GET | edit($id) | posts.edit |
| /posts/{id} | PATCH | update($id) | posts.update |
| /posts/{id} | PUT | update($id) | *(unnamed)* |
| /posts/{id} | DELETE | delete($id) | posts.delete |

A route name is the table's array key, so naming both PATCH and PUT would have one overwrite
the other — the PUT route is deliberately left unnamed. The destroy method is **`delete`**.

`resource()` takes exactly two arguments: there is no `->only()`, `->except()` or `->names()`.
For a subset, write those routes individually.

**Write the root and resource routes last.** `Route::resource('/', HomeController::class)`
registers `/{id}`, which matches every one-segment url and lets `show()` claim it — that is
the point of mounting a resource at the root, and it means anything more specific must be
defined above it. `route/dynamic/` is included after `route/web.php`, so a one-segment route
there can never win against a root resource.

```php
php terminal make controller PostController --resource   # emits exactly these seven methods
```

### Named Routes

```php
Route::get('/posts/{id}/edit', [PostController::class, 'edit'])->name('posts.edit');

route('posts.edit', ['id' => 5]);        // returns full URL: https://example.com/posts/5/edit
Route::find('posts.edit', ['id' => 5]);  // same
Route::find('posts.edit', [], true);     // returns bool — does this route exist?
```

### Groups

`pre()` sets a URL prefix **and** a route name prefix. Both accumulate when nested.

```php
Route::pre('/admin')
    ->middleware([Auth::class, IsAdmin::class], fn($declines) => abort(403))
    ->noCSRF()
    ->group(function () {

        // URL: /admin/dashboard  — name: admin.dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // URLs: /admin/posts, /admin/posts/{id}, ...
        // Names: admin.posts.index, admin.posts.store, admin.posts.show, ...
        Route::resource('/posts', PostController::class);

        // Nested pre — prefix keeps accumulating
        Route::pre('/settings')->group(function () {
            // URL: /admin/settings/general  — name: admin.settings.general
            Route::get('/general', [SettingsController::class, 'general'])->name('general');
        });
    });
```

`route()` always expects the full name:

```php
route('admin.posts.show', ['id' => 5]);   // https://example.com/admin/posts/5
route('admin.settings.general');
```

```php
Route::has('/admin');   // true if the current URI contains '/admin'
```

**Splitting the name from the URL.** `pre()` takes a second argument that replaces the name
segment for that level, so a URL can change without touching a single `route()` call site:

```php
Route::pre('/devices', '/assets')->group(fn() => Route::get('/list', …)->name('list'));
// URL /devices/list  —  name assets.list

Route::pre('/panel', '')->group(...);   // URL prefix that adds nothing to the name
```

That is what makes a translated URL practical:

```php
Route::pre('/' . _l('routes.admin.route'), '/admin')->group(function () {
    Route::resource('/posts', Admin\PostController::class);   // always admin.posts.*
});
```

The global middlewares run before the route files, so `_l()` already knows the visitor's
locale. **This cannot be cached** — the route cache stores URLs as literal strings, so it
would freeze whatever language the CLI had. Put groups like this in `route/dynamic/`, or leave
`route.caching` off if most of the routing is dynamic.

### Forms

```html
<!-- POST -->
<form method="POST">
    <?= csrf() ?>
    ...
</form>

<!-- PUT / PATCH / DELETE via hidden field -->
<form action="/posts/1" method="POST">
    <?= csrf() ?>
    <?= inputMethod('PATCH') ?>
    ...
</form>
```

`inputMethod()` renders `<input type="hidden" name="_method" value="PATCH" />`.

---

## 2. Model & DB

### Model Definition

```php
class Post extends Model
{
    use softDelete;

    public $table      = 'posts';
    public $db         = 'local';        // connection name from database/connections.php; defaults to first
    public $guard      = ['secret'];     // columns left out when the query names none itself
    public $primary    = 'id';           // auto-detected from schema if omitted
    public $deleted_at = 'deleted_at';   // used by softDelete trait
    // created_at / updated_at are not set by the model: the migration's `timestamps`
    // gives them DEFAULT CURRENT_TIMESTAMP / ON UPDATE. Their names are global,
    // in config/model.php (`consts`), not per model.
}
```

**`$guard` only applies while the query names no columns of its own.** It works by
expanding `SELECT *` into the column list minus the guarded ones, so an explicit
`select('*')` - or `select('id, password')` - is sent as written and the guarded
column comes back. Guard is a default, not an access rule: for anything that must never
leave the table, leave it out of the select.

### CRUD

```php
$p = new Post;

$p->get();                              // all rows, each one an array
$p->first();                            // first row or empty array
$p->firstOrFail();                      // first row or abort(404)
$p->firstOrFail('Custom message');      // first row or abort(404) with message
$p->find(1);                            // find by primary key
$p->findOrFail(1);                      // find or abort(404)
$p->count();                            // row count (int)
$p->where('slug', $slug)->updateOrInsert(['title' => 'Hi']);
// the where() decides both: is there a row (update it) or not (insert). Without
// one it finds the first row and the update runs unfiltered - every row rewritten

// insert() returns the full inserted row, or the affected row count
$row = $p->insert(['title' => 'Hello', 'user_id' => 1]);
$p->insert(['title' => 'Hello'], just_insert: true);   // skip re-fetch, returns int

$p->where('id', 1)->update(['title' => 'Hi']);   // returns affected rows (int)
$p->where('id', 1)->delete();                    // returns affected rows (int)
```

### WHERE

```php
// Equality
$p->where('status', 'published')->get();
$p->where('views', '>', 100)->get();           // any comparison operator

// OR connector
$p->where('status', 'published')->whereOr('status', 'featured')->get();

// Negation
$p->whereNot('status', 'deleted')->get();       // status != 'deleted'
$p->whereNot('name', 'LIKE', '%test%')->get();  // name NOT LIKE '%test%'
$p->whereOrNot('status', 'hidden')->get();      // OR status != 'hidden'

// IN / NOT IN
$p->whereIn('id', [1, 2, 3])->get();
$p->whereNotIn('id', [1, 2, 3])->get();
$p->whereIn('id', [1, 2, 3], 'OR')->get();   // OR id IN (...)
$p->whereIn('id', [])->get();               // empty list: matches nothing (whereNotIn([]): everything)

// BETWEEN / NOT BETWEEN
$p->whereBetween('views', 10, 100)->get();
$p->whereNotBetween('created_at', '2024-01-01', '2024-12-31')->get();

// Raw SQL — use named bindings
$p->whereRaw('(id = :a OR id = :b)', ['a' => 1, 'b' => 2])->get();
$p->whereRaw('YEAR(created_at) = :y', ['y' => 2024])->get();

// Grouped WHERE — array of conditions wrapped in parentheses
$p->where([
    ['status', 'published'],
    ['views',  '>',  50, 'OR'],   // 4th element sets the connector inside the group
])->get();
// generates: WHERE (status = 'published' OR views > 50)
```

### Query Building

```php
// SELECT specific columns
$p->select('id, title, created_at')->get();
$p->select(['id', 'title'])->get();

// ORDER BY — pass an associative array
$p->orderBy(['created_at' => 'DESC'])->get();
$p->orderBy(['views' => 'DESC', 'id' => 'ASC'])->get();

// GROUP BY — pass an array
$p->groupBy(['user_id'])->get();

// HAVING — same syntax as where
$p->groupBy(['user_id'])->having('total', '>', 5)->get();
$p->groupBy(['user_id'])->havingOr('total', '<', 2)->get();
$p->groupBy(['user_id'])->havingNot('total', 0)->get();   // total != 0
$p->groupBy(['user_id'])->havingOrNot('total', 0)->get();

// LIMIT — limit(offset, count) or limit(count)
$p->limit(10)->get();               // take 10
$p->limit(20, 10)->get();           // skip 20, take 10

// JOIN — type: INNER / LEFT / RIGHT / FULL OUTER
$p->join('LEFT', Comment::class, 'comments.post_id = posts.id')->get();
$p->join('INNER', User::class, 'users.id = posts.user_id')->select('posts.*, users.name as author')->get();

// Fetch type
$p->fetchType('unique')->get();     // keyed by the FIRST selected column (PDO::FETCH_UNIQUE) - put the key first in select()
$p->fetchType('keypair')->get();    // PDO::FETCH_KEY_PAIR (first col => second col)

// Disable relation closures on result rows (performance)
$p->closureMode(false)->get();

// Debug — dumps executed SQL + EXPLAIN ANALYZE to /db-debug/ and stdout
$p->sqlDebug(true)->where('id', 1)->first();
```

### Pagination

```php
$result = (new Post)
    ->where('status', 'published')
    ->orderBy(['created_at' => 'DESC'])
    ->paginate(
        per_page: 20,
        page_id:  'page',      // query string param name (?page=2)
        cache_id: 'pub_posts'  // cache the total count in session (optional)
    );

// $result keys:
// 'items'        → the rows of the current page
// 'item_count'   → total row count
// 'shown'        → e.g. "21 / 40" (range shown on this page)
// 'start'        → start index of current page
// 'per_page'     → rows per page
// 'page_count'   → total number of pages
// 'current_page' → current page number
// 'links'        → Closure — call $result['links']() to render pagination view
```

**Real order** — once a list is sorted by the user, the per-page counter
(`$result['start']++`) no longer tells you where a row actually sits.
`withRealOrder()` adds the row's position in the table's default order as a
column:

```php
$result = (new Post)
    ->withRealOrder()                          // adds 'real_order'
    ->orderBy(['title' => 'ASC'])
    ->paginate(20);

// in the view
foreach ($result['items'] as $item) echo $item['real_order'];
```

```php
$p->withRealOrder();                  // 'real_order', newest row = 1 (id DESC)
$p->withRealOrder('rank');            // custom column name
$p->withRealOrder('rank', 'ASC');     // oldest row = 1
```

It compiles to an index-only correlated count over the primary key, so only
the index is read, never the table data:

```sql
(SELECT COUNT(*) FROM posts zf_ro WHERE zf_ro.id >= posts.id) AS real_order
```

Position in the chain does not matter, and it composes with `select()`.
`paginate()` strips it before running its COUNT query.

Note that the subquery repeats no `WHERE` clause on purpose: the number is the
row's position in the whole table, not within the active filter. Ranking inside
a filter would mean duplicating every condition into the subquery.

```php
// In the view:
echo $result['links']();                            // uses framework.pagination.default-view
echo $result['links']('partials.my-pagination');    // custom view
```

### Row Access

Every row returned by `get()`, `first()`, `find()`, and `insert()` is a plain PHP
array (`PDO::FETCH_ASSOC`). Relation methods and the row-level `update`/`delete`
are added to it as closures under their own keys.

```php
$post = (new Post)->find(1);

// Columns
$post['title'];
$post['user_id'];

// Relation closures defined on the model
$post['comments']();     // invokes Post::comments(array $row)
$post['author']();       // invokes Post::author(array $row)

// Row-level update / delete (scoped to the primary key of this row)
$post['update'](['title' => 'Updated']);
$post['delete']();
```

There is no object syntax: `$post->title` reads a property off an array and gives
you `null` with a warning. Use `closureMode(false)` for a query whose rows you only
want to read — it skips binding the closures entirely.

Watch out when serialising. `json_encode()` does not fail on the closures, it
encodes each one as `{}`, so an API response would carry `"update":{}` alongside
the real columns. Drop them first:

```php
json_encode(array_filter($post, fn($v) => !$v instanceof Closure));
```

### Column Introspection

```php
$p = new Post;
$p->columns();              // ['id', 'title', 'body', ...] — respects $guard
$p->columnsLength();        // ['title' => 200, 'body' => 65535, ...]
$p->compareColumnsLength(['title' => str_repeat('x', 300)]);
// returns ['title' => ['length' => 300, 'excess' => 100, 'max' => 200]]
```

---

### 2.1. Auth

```php
// Attempt login — checks credentials against the users table
Auth::attempt(['email' => 'user@example.com', 'password' => 'secret']);
Auth::attempt(['email' => 'user@example.com', 'password' => 'secret'], staymein: true);
// staymein: true sets a persistent cookie (auth-stay-in): the user's api_token plus a
// trace of the password hash, so the cookie stops working when the password changes

// Login directly from a user row (e.g. after OAuth)
Auth::login($userRow);

// Login via api_token value
Auth::token_login('api_token_string');

// Logout — clears session/cookie tokens
Auth::logout();

Auth::check();   // bool — is a user currently authenticated?
Auth::user();    // the authenticated user's row, or false
Auth::id();      // int|null — authenticated user's id

// Hash a password using the configured method (bcrypt / md5 / crypter)
Auth::encodePassword('plain-password');

// Drop the cached user row after updating the current user (no-op without Redis)
Auth::forgetCache();
```

**Three things about `attempt()`:**

1. The key named by `special_columns['password']` is verified; **every other key becomes a
   `where()`** on the users table. So the array means "find the user by these columns, then
   check this password".
2. **With no password key there is no password check** — `Auth::attempt(['id' => 7])` logs that
   user in. That is the impersonation path, and it means you must never pass unfiltered input:
   `Auth::attempt(request())` with a body of `{"id":1}` logs the caller in as user 1. Name the
   fields explicitly.
3. **It returns `false` when someone is already logged in.** A false result is not always bad
   credentials; call `Auth::logout()` first if you mean to switch users.

Always write passwords through `Auth::encodePassword()` rather than `password_hash()` directly,
or changing `passwordencode` silently breaks every login.

**Password encode method** is configured via `App\Models\User::$special_columns['passwordencode']`:

```php
// bcrypt (recommended)
public $special_columns = ['email' => 'email', 'password' => 'password', 'passwordencode' => 'bcrypt'];

// md5
public $special_columns = [..., 'passwordencode' => 'md5'];

// Crypter (default)
public $special_columns = [..., 'passwordencode' => 'crypter'];
```

---

### 2.2. Relations

Relation methods are defined on the model and accept `array $row` (the current row). They are automatically bound as closures onto every row, callable as `$row['posts']()`.

```php
class User extends Model
{
    // One-to-many: user has many posts
    public function posts(array $row): array
    {
        return $this->hasMany(Post::class, $row['id'], 'user_id');
    }

    // One-to-one: user has one profile
    public function profile(array $row): ?array
    {
        return $this->hasOne(Profile::class, $row['id'], 'user_id');
    }

    // Count without loading
    public function postsCount(array $row): int
    {
        return $this->hasManyCount(Post::class, $row['id'], 'user_id');
    }

    // Check existence without loading
    public function hasPosts(array $row): bool
    {
        return $this->hasRelation(Post::class, $row['id'], 'user_id');
    }

    // Has-many through: Country -> User -> Post
    public function posts(array $row): array
    {
        return $this->hasManyThrough(Post::class, User::class, $row['id'], 'country_id', 'user_id');
    }
}

class Post extends Model
{
    // belongsTo: post belongs to a user
    public function author(array $row): ?array
    {
        return $this->belongsTo(User::class, $row['user_id']);
    }

    // Many-to-many through pivot table
    public function tags(array $row): array
    {
        return $this->belongsToMany(Tag::class, 'post_tag', $row['id'], 'post_id', 'tag_id');
    }

    // Many-to-many with pivot columns included in result
    public function tagsWithMeta(array $row): array
    {
        return $this->belongsToManyWithPivot(
            Tag::class, 'post_tag', $row['id'],
            'post_id', 'tag_id',
            ['assigned_at', 'weight']   // pivot columns returned as pivot_assigned_at, pivot_weight
        );
    }

    // Polymorphic: post has many comments (via commentable)
    public function comments(array $row): array
    {
        return $this->morphMany(Comment::class, 'commentable', $row['id']);
    }

    // Polymorphic many-to-many: post has many tags via taggables pivot
    public function polyTags(array $row): array
    {
        return $this->morphToMany(Tag::class, 'taggable', $row['id']);
    }
}

class Comment extends Model
{
    // Inverse of morphMany — resolves the parent model dynamically from _type / _id columns
    public function commentable(array $row): ?array
    {
        return $this->morphTo($row, 'commentable');
        // reads $row['commentable_type'] and $row['commentable_id']
    }
}
```

**Calling relations on rows:**

```php
$user = (new User)->find(1);
$posts   = $user->posts();       // calls User::posts(['id' => 1, ...])
$profile = $user->profile();

$post = (new Post)->find(1);
$tags = $post->tags();
$author = $post->author();
```

**Eager loading — `with()`**

Calling a relation closure per row is one query per row. On a 20-row listing
that is 20 extra queries; on a 100-row one, 100. `with()` collects what every
row would ask for and answers all of them in a single query:

```php
$users = (new User)->with('profile', 'posts')->paginate(20);

// in the view - a value, not a call
foreach ($users['items'] as $user) {
    echo $user['profile']['bio'];
    foreach ($user['posts'] as $post) echo $post['title'];
}
```

```
without with() : SELECT users … + 20 × profile + 20 × posts   = 41 queries
with()         : SELECT users … + WHERE user_id IN (…) × 2    =  3 queries
```

An eager loaded relation is replaced by its **value**, so `$user['posts']` is
the array itself — no `()`. Rows with no match get `[]` for a to-many relation
and `null` for a to-one.

Works with `hasOne`, `hasMany` and `belongsTo`. Pivot, morph and through
relations stay lazy and keep working through their closure exactly as before,
so `with()` on one of those is harmless — just not batched.

---

### 2.3. Pivot Operations

```php
// attach — insert a pivot record
$user->attach('user_roles', 'user_id', $userId, 'role_id', $roleId);
$user->attach('user_roles', 'user_id', $userId, 'role_id', $roleId, ['assigned_at' => date('Y-m-d')]);

// detach — remove a specific pivot record
$user->detach('user_roles', 'user_id', $userId, 'role_id', $roleId);

// detach all — remove all pivot records for this model
$user->detach('user_roles', 'user_id', $userId);

// sync — replace all existing pivot records with the given IDs (runs in a transaction)
$user->sync('user_roles', 'user_id', $userId, 'role_id', [1, 2, 3]);
$user->sync('user_roles', 'user_id', $userId, 'role_id', [1, 2, 3], ['assigned_at' => date('Y-m-d')]);

// toggleAttach — attach if missing, detach if present
$user->toggleAttach('user_roles', 'user_id', $userId, 'role_id', $roleId);
```

---

### 2.4. Observers

```php
// App/Models/Post.php
class Post extends Model
{
    public $observe = PostObserver::class;
}

// App/Observers/PostObserver.php
class PostObserver extends Observer
{
    // called before insert — return modified $args to change what gets inserted
    public function oninsert(array $args): array  { return $args; }

    // called after successful insert — $args is the inserted row
    public function oninserted(array $args): void { }

    // called before update — return modified $args to change what gets updated
    public function onupdate(array $args): array  { return $args; }

    // called after successful update
    public function onupdated(array $args): void  { }

    // called before delete. A soft delete fires this pair too - not the
    // update pair, although it writes through an UPDATE
    public function ondelete(array $args): void   { }

    // called after successful delete
    public function ondeleted(array $args): void  { }
}
```

```bash
php terminal make observer PostObserver
```

---

### 2.5. Migrations

```php
// database/migrations/Posts.php
namespace Database\Migrations;

class Posts
{
    static $storageEngine = 'InnoDB';
    static $charset       = 'utf8_general_ci';
    static $table         = 'posts';
    static $db            = 'local';

    public static function columns(): array
    {
        return [
            'id'         => ['primary'],
            'user_id'    => ['bigint', 'required'],
            'title'      => ['varchar:200', 'charset:utf8mb4_general_ci'],
            'body'       => ['text', 'nullable'],
            'status'     => ['varchar:20', 'default:draft'],
            'views'      => ['int', 'default:0'],
            'score'      => ['decimal', 'nullable'],
            'published_at' => ['datetime', 'nullable'],
            'timestamps',     // shorthand: adds created_at + updated_at
            'softDelete',     // shorthand: adds deleted_at
        ];
    }
}
```

**Column options:**

| Option | SQL equivalent |
|---|---|
| `primary` | INT AUTO_INCREMENT PRIMARY KEY |
| `primary:noincrement` | PRIMARY KEY without AUTO_INCREMENT (aliases: `noai`, `false`) |
| `bigint` | BIGINT |
| `int` | INT |
| `smallint` | SMALLINT |
| `tinyint` | TINYINT |
| `bool` | TINYINT(1) |
| `varchar` / `varchar:N` | VARCHAR(255) / VARCHAR(N) |
| `char` / `char:N` | CHAR(50) / CHAR(N) |
| `uuid` | CHAR(36) — textual uuid |
| `text` | TEXT |
| `longtext` | LONGTEXT |
| `json` | JSON |
| `decimal` / `float` / `real` | DECIMAL / FLOAT / REAL |
| `date` / `datetime` / `time` | DATE / DATETIME / TIME |
| `required` | NOT NULL |
| `nullable` | NULL |
| `default:VALUE` | DEFAULT VALUE (quoted unless numeric or CURRENT_TIMESTAMP) — bare `default` is DEFAULT NULL; `default:NULL` would be the 4-letter string |
| `default:(EXPRESSION)` | DEFAULT (EXPRESSION) verbatim — MySQL 8.0.13+ expression default, e.g. `default:(UUID())` |
| `onupdate` | appends ON UPDATE CURRENT_TIMESTAMP — place it **after** a `default:` option, it extends that clause |
| `unique` | UNIQUE KEY |
| `unique:group_name` | composite UNIQUE (groups columns with the same name) |
| `index` | INDEX |
| `index:group_name` | composite INDEX |
| `charset:utf8mb4_general_ci` | per-column CHARACTER SET + COLLATE |
| `timestamps` | adds `created_at DATETIME` + `updated_at DATETIME` |
| `softDelete` | adds `deleted_at DATETIME NULL` |

**Non auto-increment primary keys:**

```php
// uuid primary key, populated by MySQL itself
'id'  => ['uuid', 'primary:noincrement', 'required', 'default:(UUID())'],

// natural key (product code, iso code, slug ...)
'code' => ['varchar:32', 'primary:noincrement', 'required'],
```

`AUTO_INCREMENT` only applies to integer columns, so a bare `primary` on a
`uuid` / `char` / `varchar` column drops the increment automatically instead of
failing the migration with error 1063. Writing `primary:noincrement` is still
preferred — it states the intent. `insert()` on such a table returns the row by
the key you supplied, since there is no `lastInsertId()` to read.

Column names are compared case-insensitively, as MySQL does: renaming `email` to
`Email` in `columns()` modifies the column, it does not drop and re-add it.

```bash
php terminal db migrate                   # apply pending migrations
php terminal db migrate --fresh           # drop every MIGRATED table and re-run (tables with no migration file stay)
php terminal db migrate --fresh --seed    # drop + migrate + seed
php terminal db migrate --module=blog     # only migrate the 'blog' module
php terminal db migrate --all             # include all modules
```

---

### 2.6. Seeders

```php
// database/seeders/PostsSeeder.php
namespace Database\Seeders;

class PostsSeeder
{
    public function destroy(): static
    {
        (new Post)->delete();
        return $this;
    }

    public function seed(): void
    {
        (new Post)->insert([
            'title'   => 'Hello World',
            'user_id' => 1,
            'status'  => 'published',
        ]);
    }
}
```

```bash
php terminal db seed
```

---

### 2.7. Transactions

Requires InnoDB storage engine.

```php
$user = new User;
$user->beginTransaction();
try {
    $user->insert(['name' => 'Alice', 'email' => 'alice@example.com']);
    $user->where('id', 99)->update(['status' => 'inactive']);
    $user->commit();
} catch (\Throwable $e) {
    $user->rollback();
    throw $e;
}
```

---

## 3. View

```php
// From a controller:
return view('app.pages.posts.index', compact('posts'));   // resource/views/app/pages/posts/index.php
return View::view('app.pages.posts.index', ['posts' => $posts]);
```

`resource/views/` has a shape worth keeping: one directory per interface layer with that
layer's only layout as `main.php`, and every page group a directory containing `index.php`.

```
resource/views/app/main.php                        the layout pages extend
resource/views/app/pages/posts/index.php           list
resource/views/app/pages/posts/edit-or-create.php  one file for both
resource/views/errors/app/{main,404}.php           per-layer error pages
```

A page and its layout compile into **one file with a single `extract()`**, so they share every
variable — a value set in the layout is visible inside the sections and the other way round,
and a layout assignment overwrites what the controller passed under the same key. Name view
data specifically (`$post`, not `$item`).

Anything outside a `@section` in a template that `@extends` is emitted **after** the layout's
output - the `@extends` token is replaced by the compiled layout and the rest of the file
follows it. A `<?php ?>` block above `@section('body')` therefore runs too late for the section
to see its variables, and stray markup lands after `</html>`. Per-page setup goes **inside**
the section.

### Directives

```
@if($condition)       @elseif($other)      @else      @endif
@foreach($items as $item)                             @endforeach
@forelse($items as $item)                  @empty     @endforelse
@isset($var)                                          @endisset
@empty($var)                                          @endempty
@php                                                  @endphp
@include('partials.nav')
@extends('app.main')
@section('body')                                      @endsection
@yield('body')
@json($var)              — outputs json_encode($var)
@dump($var)              — var_dump($var); does not die
@dd($var)                — print_r($var); does not die either
@@if                     — a literal `@if` in the output; `@@` is the escape
{{ $var }}               — escaped echo (compiles to <?= e($var) ?>)
{!! $var !!}             — raw echo, for markup
{{-- comment --}}        — stripped before anything else parses
{{/* comment */}}        — the same thing, other spelling
```

`{{ }}` escapes, so it is the one to reach for without thinking. Anything that **returns**
markup — `inputMethod()`, `$posts['links']()`, a rendered partial — has to say so with
`{!! !!}`. `csrf()` is not one of them: it echoes its input tag and returns void, so both
spellings emit the same thing and `<?= csrf() ?>` is what the shipped views write.

> **Changed in 3.1.2.** `{{ }}` used to be a bare `<?= ?>` that escaped nothing, and `{!! !!}`
> did not exist. Upgrading an older project: every `{{ }}` that prints markup needs to become
> `{!! !!}`, or it will render as visible text. Plain values need no change.

**These do not exist** and are printed into the page verbatim if you write them:
`@for` `@while` `@switch` `@csrf` `@method` `@auth` `@guest` `@push` `@stack`
`@component` `@each`. Use `<?php for (…): ?>`, `<?= csrf() ?>`, `<?= inputMethod('PATCH') ?>`.

A comment is removed before any other parsing, so it may safely contain a `{{ }}` echo or an
`@include` that you do not want to run.

### Custom Directives

The callback receives **one** argument — the single-quoted string inside the parentheses, or
`null` when the directive is written without any. Opening and closing tags are two separate
registrations.

```php
// App/Middlewares/ViewDirectives.php
View::directive('page', fn($page) => '<?php if (($_GET["page"] ?? null) === ' . var_export($page, true) . '): ?>');
View::directive('endpage', fn() => '<?php endif; ?>');

// Usage in a view:
// @page('2') ... @endpage
```

Matching is by prefix, so registering `pag` would also swallow `@page`.

### View::bind — ViewProvider

Inject variables automatically into specific views without passing them from every controller.
Bind the **layout** and every page that `@extends` it is covered — the bind runs when the
parent is compiled, and again on a cache hit, so the data is never stale.

```php
// App/Providers/ViewProvider.php
View::bind('app.main', fn() => [
    'lang_list' => Lang::list(),
    'user'      => Auth::user(),
]);
```

This is where data the layout needs belongs. A layout that queries or computes cannot be
rendered from a second controller without repeating the work.

---

### 3.1. Page Caching

`Page::cache()` declares a page cacheable. Two layers behind the one call: the HTTP headers,
which the browser and any CDN honour, and a server-side store that replays the rendered output
without running the route at all.

```php
use zFramework\Core\Facades\Page;

Page::cache();                      // response.cache-ttl seconds (default 600), shared
Page::cache(600);                   // 10 minutes
Page::cache(600, shared: false);    // this visitor's browser only, never a CDN
Page::cache(600, name: 'post-5');   // tagged, so it can be dropped by name
Page::vary('Cookie');               // the response depends on the request's cookies
Page::noCache();                    // back to live
```

**A response nobody declared is live.** `no-store` goes out at bootstrap, before anything else
runs, so it still applies when something fatals later. Guessing the other way serves one
visitor's page to the next.

Usually declared in a controller constructor, which covers every method on it:

```php
class BlogController extends Controller
{
    public function __construct()
    {
        Page::cache(600, name: 'blog-index');
    }
}
```

**What is never stored**, whatever the page declared. For the first four rows the
cache headers still go out; a non-200 and a body carrying a csrf token also have
their headers reset to no-store, so the browser does not keep them either:

| | Why |
|---|---|
| anything but GET | never the same for the next visitor |
| a request with an auth cookie | a logged-in page must not be handed to someone else |
| a response that is not 200 | `Page::cache()` in a constructor runs before the method aborts |
| a body containing a csrf token | per-session; the copy breaks every form that receives it |
| `shared: false` | "for this visitor" and "one copy for everyone" are opposites |
| after `Page::vary(...)` | the store's key is fixed and cannot hold variants |

The csrf case is caught by the framework and, with `debug` on, logged with the url — the
failure is otherwise remote from its cause: the page renders fine and only the *next*
visitor's POST breaks.

**Per-visitor fragments** — a header showing who is logged in, a cart count:

```php
Page::cache(300, shared: false);
Page::vary('Cookie');
```

The browser keeps it and nothing else does. You cannot reach into a browser and delete what it
stored, and with `Vary: Cookie` you do not need to: signing in or out changes the cookie, the
cookie is part of the cache key, so the old entry stops matching by itself.

**Invalidation:**

```php
Page::forget('post-5');            // every url tagged 'post-5'; returns how many
Page::forgetUrl('/blog/hello');    // by url, when it was never tagged
Page::clear();                     // everything
php terminal cache clear pages     // the same, from the CLI (views|sessions|pages|ratelimit|logs)
```

The key is `method | url | language cookie | Accept-Language[0:2]` — the language is in there
because a hit is served before the middlewares resolve the locale, and without it an English
visitor would get the Turkish copy. Anything else that varies per visitor and is not in the
url — a tenant, a currency — has to use `Page::vary()`, which keeps the response out of the
shared store.

Prefer the tag — rebuilding the url with its query string where a model is saved is the part
nobody gets right, and one tag can cover several urls. An observer is the tidy place:

```php
public function onupdated(array $row)
{
    Page::forget('post-' . $row['id']);
}
```

```php
// config/framework.php
'response' => [
    'cache-ttl'  => 600,
    'page-cache' => true,   // a kill switch; nothing is stored unless a page declares it
],
```

`X-Page-Cache: HIT` is sent on a served entry only while `debug` is on. A hit ends the
request before middlewares, matching and the session — measured at 21.7 ms → 16.2 ms on the
welcome page. With nothing cached, the cost is one `is_dir()` per request and the class is
never loaded.

---

## 4. Controller

Generate one with `php terminal make controller PostController --resource`. The only base class
is `zFramework\Core\Abstracts\Controller` and it is deliberately empty — there is no CRUD
parent to build and no interface to implement.

```php
use zFramework\Core\Abstracts\Controller;

#[\AllowDynamicProperties]
class PostController extends Controller
{
    public function __construct()
    {
        $this->post = new Post;
    }

    public function index(): mixed
    {
        $posts = $this->post
            ->where('status', 'published')
            ->orderBy(['created_at' => 'DESC'])
            ->paginate(20, 'page');

        return view('app.pages.posts.index', compact('posts'));
    }

    public function show(int $id): mixed
    {
        return view('app.pages.posts.show', [
            'post' => $this->post->findOrFail($id),
        ]);
    }

    public function store(): mixed
    {
        $this->post->insert([
            'title'   => request('title'),
            'body'    => request('body'),
            'user_id' => Auth::id(),
        ]);
        Alerts::success('Post created.');
        return redirect(route('posts.index'));
    }

    public function update(int $id): mixed
    {
        $this->post->where('id', $id)->update([
            'title' => request('title'),
            'body'  => request('body'),
        ]);
        return back();
    }

    public function delete(int $id): mixed
    {
        $this->post->where('id', $id)->delete();
        return redirect(route('posts.index'));
    }
}
```

### Validating the input

Two ways, both fine — pick per case rather than generating a Request class for every action.

**A Request class** when the rules are endpoint-specific. Type-hint it and it is built and
injected; calling `validated()` is what runs the rules, and it returns only the listed keys,
so passing it straight to `insert()` is safe from mass assignment.

```php
php terminal make request Post/StoreRequest
```
```php
public function store(StoreRequest $request)
{
    $post = $this->post->insert($request->validated());
    Alerts::success('Post created.');
    return redirect(route('posts.index'));
}
```

**A `setAll()` method** when store and update validate the same columns and share derived
fields. `$except` is the point — on update the row being edited would otherwise collide with
its own value on a `unique` rule:

```php
public function setAll($except = null)
{
    $v = Validator::validate($_REQUEST, [
        'title' => ['required'],
        'slug'  => ['nullable', 'unique:App\Models\Post' . ($except ? ";ex:$except" : '')],
    ]);

    $v['slug']    = Str::slug($v['title']);
    $v['user_id'] = Auth::id();
    return $v;
}

public function store()          { $this->post->insert($this->setAll()); … }
public function update($id)      { $this->post->where('id', $id)->update($this->setAll($id)); … }
```

Either way, keep the derived fields in one place — never duplicate them across `store()` and
`update()`.

---

## 5. Validator

```php
Validator::validate($_REQUEST, [
    'email'    => ['required', 'email', 'unique:' . User::class . ';key:email'],
    'password' => ['required', 'min:8', 'max:72'],
    'confirm'  => ['required', 'same:password'],
    'age'      => ['nullable', 'type:int', 'min:18', 'max:120'],
    'role_id'  => ['required', 'exists:' . Role::class . ';key:id'],
]);

// Custom attribute names in error messages
Validator::validate($_REQUEST, ['email' => ['required', 'email']], ['email' => 'E-mail Address']);

// With a callback — custom logic when validation fails.
// It receives ($errors, $passed). Its return value is discarded and execution
// CONTINUES, so send a response from the calling code, not from in here.
Validator::validate($_REQUEST, ['title' => ['required']], [], function (array $errors, array $passed) {
    Alerts::danger('Could not save.');
});
```

On failure an `Alerts::danger()` is raised where the rule failed, then one of three things
happens:

| Situation | What happens |
|---|---|
| AJAX request | `abort(400, Response::json($errors))` |
| Normal request | `back()` — redirect to the referer, alerts waiting |
| A callback was passed | the callback runs and **execution continues** |

Without a callback the request is already over, which is why controllers do not check the
result. **With one, check `$errors` yourself** — the returned array collects a field as soon as
*any* rule on it passes, so a value that failed a later rule is still in there:

```php
$r = Validator::validate(['age' => '150'], ['age' => ['required', 'max:100']], [], fn($e, $s) => null);
// $r === ['age' => '150'] — 'required' passed and wrote it, 'max' failed afterwards
```

**Rules:**

| Rule | Description |
|---|---|
| `required` | Field must be present and non-empty |
| `nullable` | Field may be empty or absent. Most rules pass on an empty value anyway; `same`, `confirmed` and `unique` still evaluate it |
| `type:string` / `type:int` / `type:float` / `type:bool` / `type:array` | Declares the type, and asserts the value can be read as it |
| `min:N` | Minimum value for a number, minimum length for a string/array |
| `max:N` | Maximum value for a number, maximum length for a string/array |
| `same:other_field` | Must exactly match the value of `other_field` |
| `email` | Must be a valid e-mail address |
| `unique:Model;key:column` | Value must not already exist in the model's column |
| `exists:Model;key:column` | Value must exist in the model's column |
| `in:a,b,c` | Must be one of these, compared as strings |
| `not-in:a,b` | Must not be one of these |
| `regex:"^[a-z0-9_]+$"` | Quoted, and without delimiters - they are added for you |
| `url` | A valid **http or https** address |
| `date` / `date:Y-m-d` | Parseable, or that exact format and a real date in it |
| `between:18,65` | The same measure min/max use, both ends inclusive |
| `confirmed` / `confirmed:field` | Equal to `<field>_confirmation`, or the named field |

A few that are not obvious:

- **`url` rejects anything but http and https.** `FILTER_VALIDATE_URL` on its own accepts
  `javascript:` and `data:`, which is how a "website" field becomes an XSS hole.
- **`date:Y-m-d` re-formats what it parsed and compares**, or `2026-02-31` parses happily and
  becomes the 3rd of March.
- **`confirmed` goes on the field itself** and defaults to `<field>_confirmation`, where `same`
  goes on the second field and names the first - so the error lands where the user is looking.
- **`regex` needs the pattern quoted**: unquoted, the rule parser stops at the first space.

`unique` and `exists` also take `ex:` to exclude a row — `unique:App\Models\User;key:email;ex:5`
is the update-form spelling, without which editing a row collides with its own value.

**`type:` decides how `min`/`max` read the value.** Everything arriving from a form is a
string, so `'150'` is detected as a number and `max:100` rejects it; `type:string` makes the
same rule mean "at most 100 characters" and it passes. `int`, `str`, `bool` and `double` are
accepted spellings.

**`required` and `nullable` together throw** — an exception, not a validation failure. Pick one.

---

## 6. Middleware

```php
// App/Middlewares/Auth.php
class Auth
{
    public function attempt()
    {
        if (\Auth::check()) return true;
        // return false / nothing → middleware declined
    }

    public function error(): void
    {
        abort(401);
    }
}
```

**Standalone middleware check:**

```php
// Pass — all middlewares must return true
Middleware::middleware([Auth::class, IsAdmin::class]);

// With callback — $declined is the list of failed middleware classes
Middleware::middleware([Auth::class, IsAdmin::class], function (array $declined) {
    if (count($declined)) abort(403, implode(', ', $declined));
});
```

**On routes** (via group middleware):

```php
Route::pre('/admin')
    ->middleware([Auth::class, IsAdmin::class], fn($declines) => abort(403))
    ->group(function () { ... });
```

Two things to know before relying on this:

- **Pass the fallback closure.** On a route group, `error()` is never called — the router
  supplies its own callback, and `error()` only runs in the no-callback branch, i.e. when you
  call `Middleware::middleware()` yourself. A decline with no fallback simply leaves the route
  unmatched, so the request ends as a plain **404**.
- **Every middleware in the list runs**, even after one declines. There is no short-circuit;
  the ones that failed arrive together in `$declines`.

Group settings only apply through `->group()`, and they accumulate inward: a nested group
inherits the outer prefix and middleware list, and the outer settings are restored afterwards.
A `pre()` or `middleware()` written without a `->group()` stays pending and is picked up by the
next group. Two `middleware()` calls chained at the same level accumulate their class lists;
only the last call's fallback closure is kept.

---

### 6.1. Rate Limiting

Opt-in per route group. **Which routes are limited is where you attach the middleware; how
hard, in config.**

```php
Route::throttle(?int $limit = null, ?int $window = null, ?string $by = null, ?int $block = null)
```

```php
Route::pre('/api')->throttle(120)->middleware([API::class])->noCSRF()->group(...);
Route::throttle(5, 300)->group(fn() => Route::post('/sign-in', ...));
Route::pre('/search')->throttle(100, 10, block: 600)->group(...);
```

Every argument is optional — anything left out comes from the config defaults, so a bare
`->throttle()` means "limit this group the usual amount".

`throttle()` attaches the middleware as well, so it is the only call needed, and the number
sits next to the routes it governs. There is deliberately **no url-prefix table in config** —
that is a second copy of the routing, and it stops matching the moment a url changes, which
with a translated prefix is every request.

**`block` is the answer to a flood.** Without it, passing the limit means "wait for the next
window", so someone hammering an endpoint gets a fresh allowance every window forever. With
it, passing the limit means refused for that long — answered on a single read, counter left
alone, before the controller runs (throttle is a group middleware: the route is matched
first, then the block answers).

```php
// config/framework.php - only the fallback, for a group that names no number
'throttle' => [
    'enabled' => true,       // false turns every limit off, wherever it was declared
    'limit'   => 60,
    'window'  => 60,         // seconds
    'by'      => 'ip',       // ip | token - `token` counts a logged-in caller by identity,
                             // `ip` is REMOTE_ADDR unless framework.trusted-proxies names
                             // the address the request arrived from,
                             // so one account cannot spread its quota across addresses
    'block'   => 0,
],
```

**Ordering matters, and it is the one real decision here.**

| `by` | Where Throttle goes | Why |
|---|---|---|
| `ip` (default) | **first** | the 429 unwinds out of the middleware loop, so a refused caller never reaches what follows — on the API group that skips the `Auth-Token` lookup entirely |
| `token` | **after whatever authenticates** | otherwise `Auth` has resolved nobody yet and every caller is counted by ip |

The `token` mis-ordering **degrades silently** — the limit still works, it is just not
per-account. Measured: Throttle first gives the key `ip:::1|/x`, Throttle after the API
middleware gives `user:8|/x`. So `by: 'token'` also costs the identity lookup for a caller you
are about to refuse. Use it when one account must not spread its quota across addresses;
otherwise `ip` is cheaper and harder to get wrong.

`Throttle` **answers 429 itself** rather than declining, because a declined middleware with no
fallback closure ends as a 404 and that is the wrong answer to "you are going too fast". The
body is JSON — there is no `errors/*/429` view, and a 429 is read by retry logic more often
than by a person:

```json
{"status": 429, "message": "Too many requests. Try again in 59 seconds.", "try_again_in": 59}
```

`X-RateLimit-Limit`, `X-RateLimit-Remaining` and `Retry-After` go with it.

Measured: with the limit at 2 and four requests sent, `API::attempt()` ran exactly twice.

The counter underneath is usable directly:

```php
$hit = RateLimit::hit('login:' . ip(), 5, 300);
if (!$hit['allowed']) abort(429);          // $hit: allowed, blocked, count, remaining, retry_after

RateLimit::clear('login:' . ip());   // after a successful login
```

Backed by redis `INCR` when redis is configured, otherwise one `flock`'d file per key under
`storage/ratelimit`. Fixed window, not sliding: a caller can send up to twice the limit across
a boundary, which is the standard trade.

---

## 7. Mail

```php
Mail::to('user@example.com')
    ->cc('manager@example.com')
    ->bcc('archive@example.com')
    ->send([
        'subject'      => 'Welcome!',
        'message'      => view('emails.welcome', compact('user')),
        'altbody'      => 'Plain-text fallback for email clients that do not support HTML.',
        'attachements' => ['storage/uploads/invoice.pdf'],
    ]);

// Clear recipient lists between sends
Mail::clearTo();
Mail::clearCc();
Mail::clearBcc();
```

SMTP settings are configured in `config/mail.php`.

**Do not make the user wait for it.** An SMTP handshake plus delivery is
typically 100–1000 ms, and `send()` runs inside the request, holding a PHP
worker for all of it.

The fix is one config line:

```php
// config/mail.php
'queue' => true,
```

`Mail::send()` then pushes the mail to the [queue](#82-queue) and returns; a
worker delivers it:

```bash
php terminal queue work
```

Nothing else in your code changes. What does change is the return value —
`true` now means *queued*, not *delivered*, so a failed delivery surfaces in
the worker's output (and the error handler after `--tries` attempts) rather
than as a `false` in the request.

Requires Redis. Without it — shared hosting, local development — mails are
sent inline exactly as before, so the same code runs in both places. To send
inline while the queue is on, call `Mail::sendNow()` instead.

If Redis is not an option, [`Defer`](#71-defer) at least gets the wait out of
the user's way:

```php
Defer::after(fn() => Mail::to($user['email'])->send([...]), 'welcome-mail');
```

---

### 7.1. Defer

Runs a closure **after the response has been sent**. The user gets the page
immediately; the work happens with the connection already closed.

```php
Defer::after(fn() => Mail::to($user['email'])->send([...]), 'welcome-mail');
Defer::after(fn() => (new Stats)->insert($row), 'stats');

Defer::pending();   // is anything queued?
```

Jobs run in registration order once the route is done. Each is isolated: one
throwing does not stop the others, it is passed to the error handler. Anything
slower than a second is logged by label — that log is the signal it belongs in
a real queue.

**Know what this is not.** It is not a queue:

- **The worker stays busy.** The wait is hidden from the user, not removed —
  server capacity is unchanged, and the saturation is now harder to see.
- **Nothing is persisted.** If the process dies after responding (deploy, FPM
  recycle via `pm.max_requests`, a fatal, `request_terminate_timeout` — which
  counts this time too) the job is gone. No retry, no trace.
- **PHP-FPM only.** Under `php terminal run` the jobs still run, the user just
  waits for them.

So it fits work whose loss is survivable: stats, logs, cache warming. For work
that must not be lost — mail, payment notifications — what you defer should
eventually be a queue push rather than the task itself.

---

## 8. Cache

### Session Cache (per-user)

```php
// Returns cached value if not expired, otherwise runs the closure and caches the result
$posts = Cache::cache('recent_posts', fn() => (new Post)->limit(10)->get(), timeout: 300);

Cache::remove('recent_posts');   // invalidate one key
Cache::clear();                  // clear all session cache
```

### Global Cache (APCu — shared across all requests)

```php
$stats = GlobalCache::cache('site_stats', fn() => computeStats(), timeout: 3600);

GlobalCache::remove('site_stats');
GlobalCache::clear();
```

**Which one to use.** `Cache` stores the value in the current user's session, so
it is for data that belongs to *that* user. Using it for something shared —
"top 20 products", a site-wide counter — means the query runs once per visitor
and a copy is kept in every session file. Shared data belongs in `GlobalCache`.

**How `GlobalCache` stores things.** Two layers, cheapest first:

| Layer | Store | Scope |
|---|---|---|
| L1 | APCu | this server, `redis.l1_ttl` seconds (default 5) |
| L2 | Redis | every server, the TTL you passed |

With neither installed the closure simply runs every time — correct, uncached.
With only APCu it behaves as before. With Redis configured, `remove()` reaches
every server through L2; other servers' L1 copies age out within `l1_ttl`, which
is the window in which they may briefly disagree.

---

### 8.1. Redis

Redis is what lets more than one application server share state. It is off by
default — everything works without it, on one machine.

```php
// config/framework.php, under the 'redis' key
'enabled'  => true,
'host'     => '127.0.0.1',
'database' => ['cache' => 0, 'session' => 1, 'queue' => 2],
```

Requires the `redis` (phpredis) extension. Enabling it changes four things:

| | Without Redis | With Redis |
|---|---|---|
| `GlobalCache` | APCu, per server | APCu (L1) + Redis (L2) |
| Sessions | files on local disk | shared, when `session.driver` is `redis` |
| `Auth` | id + password hash in the cookie | opaque token, revocable server-side |
| `Queue::push()` | runs the job inline | queued for a worker |

**Keep sessions and cache on separate instances.** A cache instance evicts keys
when it fills; if sessions live there too, a full cache logs everyone out.

```ini
# session instance          # cache instance
maxmemory-policy noeviction  maxmemory-policy allkeys-lru
appendonly yes               appendonly no
```

Redis being unreachable does not take a request down: `GlobalCache` falls back to
APCu, `Queue::push()` runs the job inline, `Auth` keeps using cookie mode. The
exception is sessions — those are handled by PHP itself, and a Redis outage there
is an outage.

**What `Auth` does differently with Redis.** The cookie stops carrying the user's
id and password hash and carries only a random token; the id and hash live in
Redis, and the user row is cached for a minute:

```
cookie mode : one SELECT on users per request, session cannot be revoked
token mode  : one SELECT per user per minute, logout kills the session server-side
```

Changing the password still ends other sessions — the hash is compared on every
request, just server-side now. After updating the logged in user, call
`Auth::forgetCache()` so the change is visible before the minute is up.

---

### 8.2. Queue

For work that must not be lost and must not be waited for. Unlike
[`Defer`](#71-defer) the web worker is freed and the job outlives the request.

```php
Queue::push([SendWelcomeMail::class, 'handle'], ['user_id' => $user['id']]);
Queue::size();          // jobs waiting
```

```php
class SendWelcomeMail
{
    public function handle(array $payload) { /* ... */ }
}
```

```bash
php terminal queue work            # process until stopped
php terminal queue work emails     # a named queue
php terminal queue work --once     # one job, then exit
php terminal queue work --tries=5  # attempts before a job is dropped
php terminal queue size
```

Run workers under `supervisor` (or systemd) so they come back after a crash or a
deploy.

Jobs are a class and a method, never a closure — closures cannot be serialised, so
a queue that accepted them would only appear to work. The payload must survive
`serialize()`.

**Without Redis, `push()` runs the job immediately.** The same code works in
development and on shared hosting; it simply does not get the benefit.

---

### 8.3. Application Log

```php
use zFramework\Core\Facades\Log;

Log::debug('...');
Log::info('Order paid', ['order' => $id]);
Log::warning('Webhook arrived twice', ['id' => $webhookId]);
Log::error('Gateway refused', ['code' => $e->getCode()]);
```

One file per day at `storage/logs/Y-m-d.log`, appended with `LOCK_EX` so concurrent requests
do not interleave a line. Context is written as JSON:

```
[2026-08-15 07:02:22] WARNING: Webhook arrived twice {"id":"evt_129"}
```

**This is not the error handler.** Uncaught throwables still go through `errorHandler()` and
its error page. `Log` is for what you want to read at 03:00 that was never an exception.

```php
// config/framework.php
'log' => [
    'enabled' => true,
    'level'   => 'debug',   // debug | info | warning | error - below it is dropped before
                            // the message is formatted
    'days'    => 14,        // day files kept; pruned on the first write of a process,
                            // 0 keeps everything
],
```

A request that never logs pays nothing: the class is referenced from nowhere in the request
path, so it is never loaded.

---

## 9. Alerts

Flash messages stored in session. Cleared at the end of the request that consumed them —
render or `Response::json()` — and they survive one redirect to be shown there.

```php
Alerts::success('Record saved.');
Alerts::danger('An error occurred.');
Alerts::warning('This action cannot be undone.');
Alerts::info('Your session expires in 5 minutes.');
```

```html
<?php foreach (Alerts::get() as [$type, $message]): ?>
    <div class="alert alert-<?= $type ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endforeach ?>
```

---

## 10. CSRF

CSRF tokens are automatically verified on every non-GET route (unless `noCSRF()` is used).

```php
csrf()                   // echoes: <input type="hidden" name="_token" value="..."> (returns void)
Csrf::get()              // returns current token string
Csrf::set()              // generates and stores a new token
Csrf::unset()            // destroys all tokens
Csrf::remainTimeOut()    // seconds remaining until token rotation
```

---

## 11. Language

```
resource/lang/
  en/
    lang.php      // ['greeting' => 'Hello, {name}!']
    auth.php
  tr/
    lang.php      // ['greeting' => 'Merhaba, {name}!']
    auth.php
```

The placeholder is `{name}` in braces — `Lang::get()` does a plain `str_replace` on
`"{" . $key . "}"`. A `:name` is left in the string exactly as written.

```php
Lang::locale('tr');                              // set active locale
Lang::get('lang.greeting', ['name' => 'Ali']);   // "Merhaba, Ali!"
_l('lang.greeting', ['name' => 'Ali']);          // shortcut
Lang::list();                                    // the locales themselves: ['en', 'tr']

// Default locale set in config/app.php:
'lang' => 'en'
```

`Lang::list()` reads the directory names under `resource/lang`, so it answers which
locales exist — it is what a language switcher iterates over. A missing file makes
`Lang::get()` return null rather than raising anything.

---

## 12. Crypter

Reversible encoding for tokens and cookies. **Not intended for passwords** — use bcrypt for passwords.

```php
$encoded = Crypter::encode('value');
$decoded = Crypter::decode($encoded);

$encodedArr = Crypter::encodeArray(['a', 'b', 'c']);
$decodedArr = Crypter::decodeArray($encodedArr);
```

Key and salt live in their own file, `config/crypt.php`, which `security key --regen`
rewrites in place:

```php
// config/crypt.php
return ['key' => 'your-key', 'salt' => 'your-salt'];
```

Changing either invalidates every value already encoded with the old one — cookies
included, since `Cookie::set()` encrypts the name as well as the value.

Regenerate:

```bash
php terminal security key --regen
```

---

## 13. Config

```php
Config::get('app');              // returns entire config/app.php array
Config::get('app.title');        // returns a single key (dot notation)
Config::debug();                 // framework.debug, or app.debug if not moved - the one debug switch
Config::set('app', [...]);       // overwrite the entire file
config('app.title');             // shortcut for Config::get()
```

**config/app.php**

```php
return [
    'lang'         => 'en',
    'public'       => '/public',
];
```

**config/framework.php** — how the framework itself behaves

```php
return [
    'debug'        => true,    // the one switch: error page, query log, analyzer, unminified compile
    'error'        => ['logging' => true, 'keep_days' => 14, 'stream' => false, 'mask' => [], 'previous' => 10, 'callback' => fn($path, $html) => null],
    'force-https'  => false,   // redirect http → https
    'x-powered-by' => true,    // false hides the header
    'pagination'   => ['default-view' => 'layouts.pagination.default'],
    'view'     => ['caching' => true, 'minify' => true],
    'route'    => ['caching' => true, 'auto-check' => false],
    'log'      => ['enabled' => true, 'level' => 'debug', 'days' => 14],
    'throttle' => ['enabled' => true, 'limit' => 60, 'window' => 60, 'by' => 'ip', 'block' => 0],
    'trusted-proxies' => [],   // addresses ip() may read a forwarded header from
    'session'  => ['driver' => 'file', 'gc_probability' => 1],
    'response' => ['ajax' => ['include-alerts' => true], 'cache-ttl' => 600, 'page-cache' => true],
    'cache'    => ['apcu' => true],
    'redis'    => ['enabled' => false, 'host' => '127.0.0.1', /* ... */],

    'profiling' => [
        'enabled'      => false,   // write a record per request to analysis/profiling/
        'rate'         => 1,       // or a fraction: 0.05 records one request in twenty
        'keep'         => 200,     // stop writing once this many records exist
        'queryAnalyze' => false,   // EXPLAIN every SELECT (needs debug; re-runs the query)
        'queryStore'   => 'file',  // analysis/queries/<id>.jsonl, one line per query
    ],
];
```

These were once six files — `view.php`, `route.php`, `session.php`,
`response.php`, `cache.php`, `redis.php` — plus `analyze` in `app.php`. They are
read together and changed together, so they live together. The old files are
still read for anything `framework.php` does not carry, so an application can
move across when it suits.

**database/connections.php**

```php
$databases = [
    'local' => ['mysql:host=localhost;dbname=mydb;charset=utf8mb4', 'root', ''],
    // Multiple connections:
    'logs'  => ['mysql:host=localhost;dbname=logs;charset=utf8mb4', 'root', ''],
];

// Or create programmatically:
MySQLcreateDatabase('localhost', 'mydb', 'root', 'pass', 'local');
```

---

## 14. Terminal

```bash
# Scaffolding
php terminal make controller PostController
php terminal make controller PostController --resource    # generates all 7 resource methods
php terminal make controller PostController --module=blog
php terminal make model Post --table=posts
php terminal make migration CreatePostsTable
php terminal make seeder PostsSeeder
php terminal make observer PostObserver
php terminal make middleware AuthMiddleware
php terminal make request StorePostRequest

# Database
php terminal db migrate
php terminal db migrate --fresh
php terminal db migrate --fresh --seed
php terminal db migrate --module=blog
php terminal db migrate --all             # include all modules
php terminal db seed
php terminal db backup
php terminal db backup --compress
php terminal db restore

# Modules
php terminal module create blog          # creates modules/Blog - the directory is capitalised, the
                                          # /blog url is not; views are view('Blog.views.…')

# Cache
php terminal cache clear views
php terminal cache clear sessions
php terminal cache clear pages            # any directory that already exists under
                                          # zFramework/storage/ — on a clean install
                                          # `pages` does not, and the command says so

# Scheduled tasks
php terminal schedule run                 # everything due this minute
php terminal schedule list

# Updating the framework
php terminal update --check               # list the branches; is there a newer version
php terminal update                       # choose a branch, install it; reports config drift
php terminal update --branch=main         # or --branch=v3.0.0-release; --branch=3.0.0 works too
php terminal update --config              # also write the merged config files
php terminal update --rollback            # restore the last backup

# Security
php terminal security key --regen         # regenerate crypt key + salt

# Push notifications
php terminal push-notification keys app   # generate a VAPID key pair
php terminal push-notification test       # check encryption against the RFC vectors

# Release
php terminal release make --name=v1.2 --minify

# Dev server
php terminal run
php terminal run --host=127.0.0.1 --port=8080

# Help
php terminal help
```

---

### 14.1. Scheduled Tasks

One crontab line drives everything:

```
* * * * * /usr/bin/php /path/to/app/terminal schedule run >> /dev/null 2>&1
```

Tasks live under `schedule/` at the project root, in code you can read. Every `.php` file
there is loaded, so split them by subject or by module the way `route/` is split:

```php
use zFramework\Core\Facades\Schedule;

Schedule::everyMinute(fn() => ..., 'heartbeat');
Schedule::everyMinutes(5, fn() => ..., 'queue-drain');
Schedule::hourly(15, fn() => ..., 'sync');            // every hour at :15
Schedule::daily('03:00', fn() => ..., 'backup');
Schedule::weekly(1, '09:00', fn() => ..., 'report');  // Mondays; 0 = Sunday
Schedule::monthly(1, '00:30', fn() => ..., 'invoice');
Schedule::cron('*/5 9-17 * * 1-5', fn() => ..., 'business-hours');
```

Five standard cron fields with `*`, `*/n`, `a,b`, `a-b` and names (`jan`-`dec`, `sun`-`sat`);
day-of-week 0-6 with 0 as Sunday, and 7 also accepted. Day-of-month and day-of-week combine as
in crontab(5): when both are restricted the task runs when **either** matches.

```bash
php terminal schedule run     # everything due this minute - also how you test a task
php terminal schedule list    # what is registered, and when each next runs
```

Two things a raw crontab does not do: a task still running from the previous tick is **skipped
rather than started again** — two copies of a backup are worse than a late one — and a task
**will not run twice in the same minute**, however many times `schedule run` is invoked. A task
that throws is logged through `Log::error` and does not stop the others.

`schedule/` is included by the terminal command and by nothing else, so a served request
pays nothing for it.

---

### 14.2. Cron Scripts

The other way to run something on a schedule, and the older one: a standalone PHP file that
boots the framework and does one job. `cron/cron.php` is the header that boots it:

```php
// cron/nightly-report.php
<?php
include(__DIR__ . '/cron.php');

use App\Models\Order;
use zFramework\Core\Facades\Mail;

$rows = (new Order)->where('created_at', '>', date('Y-m-d', strtotime('-1 day')))->get();
Mail::to('boss@example.com')->send(['subject' => 'Yesterday', 'message' => view('mails.report', compact('rows'))]);
```

Then one crontab entry per script, in cPanel or wherever the host keeps them:

```
0 6 * * * /usr/bin/php /home/user/app/cron/nightly-report.php
```

**What `cron.php` gives you**, verified by running one:

| | |
|---|---|
| config | `config('app.title')` works |
| helpers | `base_path()`, `view()`, `_l()` … |
| autoloading | `App\Models\User` resolves |
| database | queries run |
| facades | `Log`, `Mail`, `Auth`, `Cache` … |

**What it deliberately leaves out:** it sets `$cron_mode`, which makes `bootstrap.php` skip the
session setup and the `force-https` redirect — neither means anything without a browser. It
also never loads routes, providers or modules, so the route table is empty.

**Errors follow `config/framework.php` like everywhere else.** `cron.php` installs the same handler
`terminal` does, so an uncaught throwable in a cron script is written to `error_logs/` when
`error.logging` is on. Without that it went to stderr only — and a crontab line ending in
`>> /dev/null 2>&1`, which is how they are usually written, threw that away too.

**Which of the two to use**

| | `cron/` | `schedule/` (§14.1) |
|---|---|---|
| crontab entries | one per job | one, total |
| where the timing lives | the host's panel | in code, versioned |
| overlap protection | none | a still-running task is skipped |
| double fire in a minute | not handled | not repeated |
| a job that throws | kills that script | logged; the others still run |
| seeing what is scheduled | read the crontab | `php terminal schedule list` |
| isolation | its own process per job | one process per tick |

`schedule/` is the better default when the host lets you run cron every minute. **Many shared
hosts do not** — a five, fifteen or thirty minute minimum is common, and then `everyMinute()`
and `everyMinutes(5)` quietly never fire on time, because they only run when the host's tick
happens to land on them. On such a host, either set the crontab to what the host allows and
schedule nothing finer, or use `cron/` and let the panel own the timing.

`cron/` also stays the right answer when a job needs its own process — something long, or
something you do not want sharing a tick with anything else.

---

### 14.3. Updating the Framework

```bash
php terminal update --check                  # list the branches; is a newer version out
php terminal update                          # list the branches, ask which, install it; report config drift
php terminal update --branch=main            # install main as it stands (development)
php terminal update --branch=v3.0.0-release  # install a release; --branch=3.0.0 is the same
php terminal update --config                 # also write the merged config files
php terminal update --rollback               # restore the last backup
php terminal update --force                  # install the same version again, or an older one
```

Every release is a branch, `vX.Y.Z-release`, and `main` is what is being worked on. Without
`--branch` the branches are listed - newest first, the installed one marked - and asked
for; from the welcome page's terminal there is nobody to ask, so `--branch` is required
there. Going to an older release is allowed, since that is what the branches are for, but
only with `--force`.

**What is replaced, and what is not.** The repository archive carries a whole project — `App/`,
`config/`, `route/`, `resource/`, `public_html/` — and all of that is your application. Only
the core is touched:

| Replaced | Never touched |
|---|---|
| `zFramework/bootstrap.php` | `zFramework/vendor/` — composer's, not in the repository |
| `zFramework/run.php` | `zFramework/storage/` — sessions, caches, logs, locks |
| `zFramework/Core/` | `App/`, `config/`, `route/`, `resource/`, `public_html/` |
| `zFramework/Kernel/` | `modules/`, `cron/`, `schedule/` |
| `zFramework/modules/` | |

`vendor/` and `storage/` matter especially: neither is in the repository, so replacing
`zFramework/` wholesale would delete composer's packages and every session and cache with them.

**The steps**, in order, each one able to stop before anything is lost:

1. read the version from one remote file, rather than downloading an archive to find out
2. download, and check the first two bytes are `PK` — a failed request arrives as an HTML page
3. extract to `storage/update`, and refuse to continue unless it really contains a framework
4. back up the five core paths to `storage/update-backup/<version>-<timestamp>`
5. replace the core
6. report the config differences, or apply them with `--config`
7. say so if `composer.json` changed
8. clear the compiled views and the route cache — both were built against the old core

**Config is merged, never overwritten.** `config/app.php` gains keys between versions, and your
settings live in the same file. The merge takes the file the new version ships and splices only
the values you had changed, located with PHP's tokenizer rather than a regex — so comments,
indentation and `[]` all survive, and `var_export`'s `array (` never appears.

Per section:

| | |
|---|---|
| you changed a value | your value is kept |
| you changed a list (`trusted-proxies`, `error.mask`, mail's `from`) | kept whole, like a value |
| the update added a key | it arrives with its default |
| you added keys to a section | that whole section is taken from your file |
| you filled in a section the update ships empty (`'redis' => []`) | taken from your file |
| you both added keys to the same section | the update's version is kept and yours is **reported** to re-add by hand |
| a key is a keyed section in the update and a value in your file | the update's version is kept and it is **reported** |

The reported rows are the honest ones: no merge can resolve them, so it says so rather than guessing.
Nothing is written without `--config`, and the file it replaces is kept as
`<name>.php.before-update`.

**A setting that moved to another file follows.** When a key stops being shipped in one
file and starts in another — `error`, `debug` and the rest went from `app.php` to
`framework.php` in 3.2 — the value the application had is written into the new place
(`moved error.stream from app.php, kept your 'syslog'`) rather than the shipped default.
That covers a customised closure too: a value is the whole expression between the arrow
and the comma, so `-1`, `60 * 60 * 24`, `BASE_PATH . '/x'` and a closure body all come
through whole. Verified on a 3.1.2 checkout updated to 3.2.0 with `error.logging`,
`error.stream` and a custom `error.callback` set: all three landed in `framework.php`
unchanged, and `--rollback` put 3.1.2 back.

**After updating**, run `composer install` if it said so, and re-check anything the config
report flagged. If something is wrong, `php terminal update --rollback` puts the previous core
back.

### Upgrading to 3.2.0

Nothing needs rewriting; these are the changes an existing project will notice.

- **`debug`, `error`, `force-https`, `x-powered-by` and `pagination` moved from `config/app.php`
  to `config/framework.php`** - `app.php` is now only what the application is (title, version,
  lang, public); `error` gained `mask` and `previous`. The old place is still read for each of
  them, so an untouched `app.php` keeps working; move them when you next open the file. Read
  the switch with `Config::debug()` rather than `config('app.debug')`.
- **The error page is new** (§19). It shows everything - request values included - unless
  `error.mask` names keys to hide. Reports are named after the exception class; a client that
  asks for JSON gets JSON; a fatal (out of memory, time limit) is reported too.
- **Templates compile without minify while `debug` is on**, so an error can name the
  template line. Compiled views carry `/*#zf:file:line*/` markers. Clear the view cache once
  (`php terminal cache clear views`) so nothing compiled by 3.1 lingers.
- **`route cache` no longer refuses a table with closures**: their files stay live and the rest
  is cached. Run it again after upgrading.
- **API mode no longer touches the session** - a header-authenticated request leaves no session
  file behind. Nothing to do unless something of yours read `Session` inside an API request.
- **`DB::$queryLog`** records every query while `debug` is on; production pays nothing.
- **AutoSSL accounts live in `storage/AutoSSL/accounts/<id>/`**; an existing `account.key` at
  the root is moved there on first use, same account. `PUBLIC_DIR` is defined from cron now, so
  `renewAll()` runs from a cron script at all.
- **The module directory is capitalised** - `modules/Blog`, namespace `modules\Blog`, views
  `Blog.views.…`. Windows never noticed; Linux did. Rename yours if you made one under 3.1.
- `Terminal::begin()` takes a line as well as an argv; `File::upload()` returns a list for a
  `multiple` input whatever survived; `updateOrInsert()` updates every matching row.

### 14.4. Tests

No PHPUnit. A test file is plain PHP in `tests/` at the project root, and the
harness is two small pieces: `test()` plus a handful of assertions
(`Kernel/Helpers/TestKit.php`), and a runner that executes **one file per
process** (`Kernel/test-runner.php`) — a test may define `ZF_WORKER`, flip
config or die fatally, and the next file starts clean.

```bash
php terminal tests                       # run everything in tests/
php terminal tests run db                # one file
php terminal tests run --filter=csrf     # only tests whose name contains it
php terminal tests run --db=local        # which connection DB tests build on
php terminal tests list                  # files and their test counts
php terminal tests make posts            # writes a tests/posts.php skeleton
```

A file reads top to bottom, like a route file:

```php
test('empty IN lists mean none / all', function () {
    same(0, count((new Post)->whereIn('id', [])->get()));
    truthy((new Post)->whereNotIn('id', [])->get());
});
```

Assertions: `same($expect, $got)` (strict), `truthy()` / `falsy()`,
`contains($needle, $haystack)`, `throws(Class::class, fn)` — each takes an
optional note that prefixes the failure message. `skip('reason')` ends the
test as skipped when a service it needs is not there. A failed assertion stops
that test and the next one still runs; the failure names the `tests/file:line`
it happened on, and whatever the test printed comes back with it.

**DB tests are safe on a real database** by convention: build on the
connection `Test::db()` names (the `--db=` key, else the first in
`connections.php`), call every table `Test::table('posts')` → `zf_test_posts`,
and drop them in a `Test::cleanup()` — cleanups run after failures and fatals
alike. `Test::pdo()` hands you the raw handle for setup SQL. A model pins both
in its constructor:

```php
class TPost extends Model
{
    public function __construct()
    {
        $this->db    = Test::db();
        $this->table = Test::table('posts');
        parent::__construct();
    }
}
```

The exit code is 1 when anything failed, so `php terminal tests` is a CI step
as it stands. The shipped files (`db`, `validator`, `view`, `helpers`, `http`)
double as examples — `tests/http.php` boots `php -S` on a free port and
drives the real sign-in flow, cookies and csrf included, through curl.

---

## 15. API



```php
// route/api.php
Route::pre('/api')->middleware([App\Middlewares\API::class])->noCSRF()->group(function () {
    Route::pre('/v1')->group(function () {
        Route::get('/user', [Api\UserController::class, 'show'])->name('api.user.show');
        Route::post('/posts', [Api\PostController::class, 'store'])->name('api.posts.store');
    });
});
```

Authenticate via request header:

```
Auth-Token: {api_token}
```

The token is matched against the `api_token` column in the `users` table — but **only because
`App\Middlewares\API` is on the group.** That middleware puts `Auth` in api mode, drops any
inherited login, and calls `Auth::token_login()` with the header. Without it the header is
ignored and `Auth::check()` is false. Do not hand-roll a token check.

It always passes, because it authenticates rather than authorises. To require a logged-in
caller, add the auth middleware after it and give the group a fallback:

```php
Route::pre('/api')->middleware([API::class, App\Middlewares\Auth::class], fn($d) => Response::status(401))
     ->noCSRF()->group(...);
```

`noCSRF()` and `middleware()` are **group** settings — they take effect through `->group()`.
Chaining `noCSRF()` onto a single route definition does nothing to that route, and leaves the
setting pending for the next group that runs.

---

## 16. Helper Methods

```php
// Paths
base_path('/config/app.php');   // absolute path from project root
public_path('/images');         // absolute path to public directory
public_dir('/images');          // same — returns real filesystem path
asset('/assets/app.css');       // full URL with ?v= cache-busting (filemtime)

// HTTP / Navigation
redirect('/login');             // Location header, via ResponseSignal (no die)
back();                         // redirect to HTTP_REFERER - only when it is on this host, else '/'
back('?saved=1');               // redirect to REFERER with suffix
refresh();                      // Refresh:0 header, via ResponseSignal (no die)
abort(404);                     // abort with HTTP status code
abort(403, 'Forbidden');        // JSON when the client wants it (X-Requested-With, or Accept: application/json), HTML page otherwise

// Request
uri();                          // current URI path (strips script name)
method();                       // HTTP method (reads _method override from POST)
ip();                           // REMOTE_ADDR; forwarded headers only when it is in framework.trusted-proxies
request('field');               // $_REQUEST['field'] ?? false
request();                      // full $_REQUEST array
request('field', 'value');      // set $_REQUEST['field'] = 'value'
getQuery(['page' => 2]);        // current query string merged with additions — returns string
getQuery(['page' => 2], ['sort']); // merge additions, remove 'sort' key
getQuery([], [], false);        // returns array instead of string

// Response
return Response::json(['key' => 'value']);   // sets Content-Type and RETURNS the encoded string; the route runner outputs it

// View / Route shortcuts
view('app.pages.posts.index', compact('posts'));
route('posts.show', ['id' => 1]);
csrf();
_l('lang.key', ['name' => 'Ali']);
Config::debug();

// HTML helpers
e($value);             // htmlspecialchars; returns '-' if empty (with $emptycheck = true)
inputMethod('PATCH');  // <input type="hidden" name="_method" value="PATCH">

// Globals
globals('myKey');              // read $GLOBALS['myKey']
globals('myKey', $value);      // write $GLOBALS['myKey']

// Browser detection
$b = getBrowser();
// ['name' => 'Google Chrome', 'version' => '...', 'platform' => 'windows', ...]

// Date
Date::now();                           // current datetime string
Date::timestamp();                     // MySQL datetime string, now; Date::timestamp('-1 day') - use time() for a unix timestamp
Date::format(time(), 'd.m.Y H:i');
Date::setLocale('Europe/Istanbul');

// File
File::upload('/uploads', $_FILES['photo'], [
    'accept' => ['jpg', 'png', 'webp'],
    'size'   => 5 * 1024 * 1024,       // max bytes
]);
File::upload('/uploads', $_FILES['photos']);      // multiple file input → array of paths
// with or without `accept`, a name the server would run (php, phtml, phar, cgi, sh,
// .htaccess, html, svg …) is refused; every dotted segment counts, so x.php.jpg too
File::save('/uploads', 'https://example.com/image.jpg');  // download remote file
File::resizeImage('photo.jpg', ['width' => 800, 'height' => 600, 'desired_sizes' => true], 'out.jpg');
File::convertImage('photo.jpg', 'webp');
File::delete('uploads/photo.jpg');               // inside public_dir only: '../' resolves outside and is refused
```

---

## 17. AutoSSL

Implements the ACME v2 protocol (Let's Encrypt). Supports `http-01` and `dns-01` challenges.

```php
use zFramework\Core\Helpers\AutoSSL;

$ssl = new AutoSSL(AutoSSL::PROD);
// On Windows with custom OpenSSL binary:
$ssl = new AutoSSL(AutoSSL::PROD, 'D:\xampp\apache\conf\openssl.cnf');

// Staging (for testing):
$ssl = new AutoSSL(AutoSSL::STAGING);
```

### Accounts

Each ACME account lives in its own directory, `zFramework/storage/AutoSSL/accounts/<id>/`,
holding `account.key` and `account.kid`. The id is a number — the date and time it was
created plus three random digits — so a listing sorts by age.

```php
$ssl = new AutoSSL(AutoSSL::PROD);                       // the oldest account registered with THIS CA, or a new one
$ssl = new AutoSSL(AutoSSL::PROD, null, '20260902195802774');   // a specific one

$ssl->account();          // id of the account this instance signs with
$ssl->accounts();         // every account on disk, oldest first:
                          //   [id => ['id', 'kid', 'registered', 'ca', 'usable', 'created', 'current']]
                          //   ca = host of the kid; usable = registered with this instance's directory (or not yet registered)
$id = $ssl->createAccount();   // registers a new account and switches to it; returns its id
$ssl->useAccount($id);    // switch to an existing one
$ssl->unlinkAccount();    // forget the current account (or unlinkAccount($id)) - local files only
```

Certificates are stored per domain, not per account, so switching accounts does not
move or hide them. An installation that had the single `account.key` at the root is
moved into `accounts/<id>/` on first use — same key, same registration, nothing re-issued.

`AutoSSL` works from a cron script and the terminal too: `PUBLIC_DIR` is derived from
`app.public` when the entry point has not defined it.

### Listing & Auto-renew

```php
$ssl->list();                    // list all locally tracked certificates
$ssl->checkSSL('example.com');   // check days remaining on a certificate
$ssl->renewAll();                // renew all certs with less than 20 days remaining
```

### http-01 (automatic, no wildcard)

The framework places the challenge file into `.well-known/acme-challenge/` automatically.

```php
$cert = $ssl->issue(['example.com', 'www.example.com'], 'http-01');
// $cert → ['cert' => '...', 'ca_bundle' => '...', 'private' => '...']
```

### dns-01 (supports wildcards)

Requires manually adding TXT records to your DNS before finalizing.

```php
$order   = $ssl->newOrder(['example.com', '*.example.com']);
$records = $ssl->challenge($order['authorizations'], 'dns-01');

// $records is an array of challenges to create in DNS:
// [['domain' => '*.example.com', 'record' => '_acme-challenge.example.com', 'value' => '...', 'url', 'token', 'authUrl'], ...]
// the TXT record goes under `record`; `domain` is the name on the order

// 1. Add each record as a TXT entry in your DNS
// 2. Wait for propagation
// 3. Notify ACME

foreach ($records as $challenge)          $ssl->notifyChallenge($challenge);
foreach ($order['authorizations'] as $a)  $ssl->challengeAuth($a['url']);

$finalized = $ssl->finalize($order, ['example.com', '*.example.com']);
$cert      = $ssl->getCertificate($order, $finalized['domainKey']);
// $cert → ['certificate' => '...', 'ca_bundle' => '...', 'private' => '...']
```

---

## 18. cPanel

Wraps the cPanel UAPI (port 2083, `Authorization: cpanel user:token` — an API token, not
Bearer). TLS verification is off in the client, so keep the hostname on a network you trust.

```php
use zFramework\Core\Helpers\cPanel\{API, Domain, Cron, Database, DatabaseUser, Email, Fileman, SSL};

// Configure once (e.g. in a service provider or bootstrap file)
API::$domain   = 'example.com';      // cPanel hostname
API::$username = 'cpanel_user';      // cPanel account username
API::$apiToken = 'TOKEN_STRING';     // cPanel → Security → Manage API Tokens
```

### Domain & Subdomains

```php
Domain::list();                      // list all domains on the account
Domain::addSubdomain('blog');        // creates blog.example.com
Domain::deleteSubdomain('blog');
```

### DNS Records

```php
Domain::listDNSRecords('example.com');

Domain::addDNSRecord('example.com', 'A',     '@',    '1.2.3.4');
Domain::addDNSRecord('example.com', 'CNAME', 'www',  'example.com');
Domain::addDNSRecord('example.com', 'MX',    '@',    'mail.example.com');
Domain::addDNSRecord('example.com', 'TXT',   '@',    'v=spf1 include:_spf.google.com ~all');
Domain::addDNSRecord('example.com', 'TXT',   '_acme-challenge', 'acme-token-here', ttl: 300);

// $line is the line number returned by listDNSRecords
Domain::editDNSRecord('example.com', $line, 'A', '@', '5.6.7.8');
Domain::deleteDNSRecord('example.com', $line);
```

### Cron Jobs

```php
Cron::list();
Cron::create('0 * * * *', '/usr/bin/php /home/user/public_html/terminal schedule');
Cron::edit($lineKey, '0 */6 * * *', '/usr/bin/php /home/user/public_html/terminal schedule');
Cron::delete($lineKey);
```

### Databases

```php
Database::list();
Database::create('mydb');                       // creates user_mydb
Database::rename('user_mydb', 'newname');
Database::repair('user_mydb');
Database::delete('user_mydb');
Database::update_privileges();
```

### Database Users

```php
DatabaseUser::list();
DatabaseUser::create('dbuser', 'password');     // creates user_dbuser
DatabaseUser::setPassword('user_dbuser', 'newpassword');
DatabaseUser::grantPrivileges('user_dbuser', 'user_mydb');                      // ALL PRIVILEGES
DatabaseUser::grantPrivileges('user_dbuser', 'user_mydb', ['SELECT', 'INSERT']); // specific
DatabaseUser::revokePrivileges('user_dbuser', 'user_mydb');
DatabaseUser::delete('user_dbuser');
```

### Email Accounts

```php
Email::list();
Email::create('info@example.com', 'password', quota: 500);   // quota in MB; 0 = unlimited
Email::changePassword('info@example.com', 'newpassword');
Email::delete('info@example.com');

// Forwarders
Email::listForwarders();
Email::addForwarder('contact@example.com', 'info@example.com');
Email::deleteForwarder('contact@example.com', 'info@example.com');
```

### File Manager

```php
Fileman::list('/public_html');
Fileman::create_folder('/public_html/uploads');
Fileman::upload('/public_html/uploads', [
    'photo.jpg' => ['path' => '/tmp/uploaded.jpg', 'mime' => 'image/jpeg'],
]);
Fileman::delete_file('/public_html/old.php');
```

### SSL

```php
SSL::AutoSSLStatus();       // check if AutoSSL check is in progress
SSL::StartAutoSSLCheck();   // trigger an immediate AutoSSL check
SSL::install('example.com', $cert, $key, $caBundle);
```

---

## 19. Going to Production

### config/framework.php

```php
// framework.php
'debug'        => false,   // REQUIRED. Also gates the query analyzer, the query log and stack-trace args.
'x-powered-by' => false,   // stop leaking the framework version
'force-https'  => true,
```

`debug => false` is the single most important switch. Besides hiding the error
page, it turns off three things that are expensive or unsafe on production:

- the **query analyzer**, which re-executes every analysed SELECT through
  `EXPLAIN ANALYZE`,
- the **query log** the error page reads (`DB::$queryLog`, one array entry per query),
- **`zend.exception_ignore_args`**, which otherwise keeps every call argument
  attached to exceptions.

### The error page

With `debug` on, an uncaught throwable renders a page: the exception class and its
`getPrevious()` chain, every frame with the function running in it and that function's
arguments (name, type and value, named by reflection - and all of them together on an
Arguments tab, grouped by area), the code around each frame coloured by PHP's own lexer,
request / headers / cookies / session / matched route / middlewares, every query the
request ran with bindings and timing (the failed one marked), memory and elapsed time, and
links to the previous reports. A frame inside a template names the template file and line
it came from, not the compiled cache, and "Open in editor" opens that. Framework frames
start hidden ("app only"); `j`/`k` move between frames, `o` opens the editor, `f` shows or
hides the framework's, `t` flips the theme.

A client that asks for JSON (`X-Requested-With`, or `Accept: application/json`) gets the
same report as JSON; the terminal gets it as text. **With `debug` off** every shape is a
500 with one sentence, and the report still goes to `error_logs/` for you.

**Nothing is hidden by default.** A password field or a cookie is as likely as anything
to be where the problem is - an injection attempt arrives in exactly those fields - and
whoever can read `error_logs/` can read the database too. If a policy asks for it,
`framework.error.mask` names keys to show as `••••••`, and one list covers the request,
session, cookies, headers, `$_SERVER` and frame arguments alike. Long strings are cut
with their length noted; the user block is id and email from what `Auth` already loaded -
the page never runs a query of its own.

`config/framework.php` → `error`: `logging`, `keep_days` (reports older than this are
swept, at most once an hour, only on a request that already failed), `stream` for a
one-line summary to `error_log` / `stderr` / `syslog`, `mask`, `previous` and `callback`.
Reports are named `Y-m-d-H-i-s-<hex>-<ExceptionClass>.html` and open on their own — no
fonts or scripts fetched from anywhere.

### config/framework.php — the `view` key

```php
'view' => [
    'caching' => true,   // compile templates once
    'minify'  => true,
],
```

View, session, redis, route caching and profiling all live in this one file. There is no
`config/view.php`, `config/session.php` or `config/redis.php` — a `Config::get('view.…')`
looks for that file, warns and returns `false`. Read them with `Config::framework('view.caching')`,
which returns the value and `null` when unset.

Compiled views live in `zFramework/storage/views`. The manifest tracks every
file a template depends on — layouts and nested includes as well — so a changed
partial invalidates the cache on its own. Clear it on deploy anyway:
`View::clearCache()`.

### PHP settings

```ini
opcache.enable = 1
opcache.memory_consumption = 512
opcache.max_accelerated_files = 50000
opcache.validate_timestamps = 0   ; reload FPM on deploy for this to be safe

realpath_cache_size = 4M
expose_php = Off
display_errors = Off
```

opcache is not optional: without it PHP recompiles every included file on every
request. Measured on the skeleton — same machine, same requests, 25-request
medians:

| | opcache off | opcache on |
|---|---|---|
| `/` | 19.5 ms | 7.4 ms |
| 404 | 15.2 ms | 8.1 ms |

The framework loads ~55 files and ~190 KB of code per request; compiling that is
most of the difference. It also changes what is worth optimising — file count
matters with opcache off, round-trips (connections, queries) with it on.

`validate_timestamps = 0` means PHP stops checking files for changes — reload
PHP-FPM as part of your deploy, or code changes will not be picked up.

### Database connection

```php
// database/connections.php
[\PDO::ATTR_TIMEOUT, 2],   // seconds to wait for the server to answer
```

Set a connect timeout, and write the host as an address. Without a timeout the
driver's default applies, and a database that is down costs **every request** its
full wait before the 503 goes out — measured at 2s per request with the server
stopped, and up to the server's own `connect_timeout` (ten seconds and more) for
a host that is unreachable rather than refusing. A host written as `localhost`
doubles it: the name is tried over IPv6 first, then again over IPv4.

This is a ceiling on connecting, not on queries. Lower it to `1` when the
database is on the same machine or the same rack.

### Compiled routes

```bash
php terminal route cache    # compile the route table
php terminal route clear    # drop it - run after changing a route file
php terminal route list     # list the registered routes — see the caveat below
```

Route files are parsed **and executed** on every request: with 1000 routes that
is 1000 `Route::get()` calls before the one being served is even found. Caching
the table replaces all of it with a single `include`.

```php
// config/framework.php
'route' => [
    'caching'    => true,   // false: ignore the cache file even if one exists
    'auto-check' => true,   // ignore it when a route file changed
],
```

`auto-check` records the route files the cache was built from and compares their
modification times on each request. Edit a route and the next request rebuilds
the table by itself — no `route clear`, no stale routes, no first-request-only
surprise. Directories are watched too, so adding or deleting a route file counts
as a change. `route/dynamic/` is not watched, since it never enters the cache.

The rebuild writes to a temporary file and renames it into place, so a request
reading the cache never sees a partial one and two requests racing to refresh it
converge on the same table. opcache is invalidated for that file, which matters
under the recommended `opcache.validate_timestamps = 0`.

**`route list` is not the whole table.** Routes registered conditionally — behind a module's
`status`, inside `route/dynamic/`, or behind any runtime condition — may not appear. To know
what is really registered, read `route/web.php`, `route/api.php`, `route/dynamic/*` and each
enabled module's `route/web.php`.

**URLs are stored as literal strings**, so a prefix built from request state — a locale, a
tenant — is frozen at build time. Those groups belong in `route/dynamic/`; if most of the
routing is dynamic, leave `caching` off rather than maintaining the split.

**The assumption this makes:** a route is declared unconditionally, or under a
condition identical for every request. A route wrapped in something
request-dependent — `if (Auth::check())`, a tenant flag — would be captured as it
looked for whichever request happened to trigger the rebuild, then served to
everyone. Put those in `route/dynamic/`, or better, express them as middleware.

Routes handled by a closure are never written, whether from the CLI or a
refresh — the request still works, there is simply no cache until they are
replaced with `[Controller::class, 'method']`.

Cost is one `stat()` per route file per request. In production the deploy script
rebuilds the cache, so it can be `false` there.

**A cached table is a snapshot.** Anything declared inside a condition that
varies per request is frozen as it was when the cache was built — and no CLI
process is logged in:

```php
if (Auth::check()) Route::get('/panel', [PanelController::class, 'index']);
// cached as: not registered at all -> 404 for everyone, forever
```

Two ways out, in order of preference:

**1. Move the decision to middleware.** The route always exists; access is
decided per request. Works with caching and with any long-running setup:

```php
Route::middleware([Auth::class])->group(fn() => Route::get('/panel', [PanelController::class, 'index']));
```

**2. `route/dynamic/`** — never cached, always executed. For definitions that
genuinely cannot be static (per-tenant feature flags, licence-dependent
modules):

```php
// route/dynamic/tenant.php
if (tenant()->hasFeature('reports')) Route::resource('/raporlar', ReportController::class);
```

The cached table and this directory are merged on every request, so the two mix
freely.

A closure cannot be written to the cache — `var_export()` has no way to. It used
to keep the whole table out; now it keeps only its own file live. The table is
cached with a note in the closure's place (`['live' => file, 'at' => 'line/n']`), and
at boot that file alone is included again — after the providers and modules, as on
an uncached boot — and the closure at that line put back where the note is; same
position, same key, so route order is untouched. `route cache`
lists the live files. With six closures in two files that is ~0.24 ms per
request against ~0.95 ms for parsing every route file; move the closures to
`[Controller::class, 'method']` and it is ~0.

A live file that no longer defines what the cache expects — a closure route added
or removed without `route cache` being run again — is reported as a stale cache
on the next request rather than served as a 404.

### APCu (recommended)

With the `apcu` extension installed, the table scheme is held in shared memory
instead of being read and JSON-decoded from `storage/db/<db>/scheme.json` on
every connection. It also backs [`GlobalCache`](#8-cache). Without it everything
still works — the disk path is simply used instead.

### Measuring, on the machine that serves

```bash
php terminal bench run
```

Reports what a request actually pays for on *this* server: opening a database
connection and reopening it from the pool, building the table scheme against
reading it from cache, config lookups, the route table and what matching one
costs — then your own global middlewares, which is usually where the rest of the
time is. It ends with the two totals side by side.

Everything the framework does is measured without being changed: nothing is
written and no cache is cleared. The middlewares are the exception, because a
middleware cannot be timed without being run — so whatever yours do, they do. It
warns before it starts. Under the terminal there is no session, so anything
behind `Auth::check()` is neither reached nor measured, and the figure comes back
as a floor rather than the whole cost.

Run it there rather than locally, because the numbers do not travel. `host=localhost`
is a unix socket on Linux and a TCP connection on Windows, and those differ by an
order of magnitude; opcache changes what boot costs and nothing about what a
connection costs. Optimising against local figures is how an afternoon goes into
the wrong 0.2 ms.

The connection lines are the ones to read first. If *reopen* is no faster than
*first open*, connection pooling is not working and everything else is noise
beside it. And if *your global middlewares* dwarfs *framework overhead*, the
framework is not what the request is waiting for.

### Recording real requests

`bench run` measures one moment on demand. The **Profiling** module under
`modules/` records what actual requests cost, one file per request under
`analysis/profiling/`, and `/profiling` compares them.

```php
// config/framework.php
'profiling' => [
    'enabled' => true,
    'rate'    => 0.05,   // one request in twenty
    'keep'    => 200,
],
```

Two switches on purpose: the module can be disabled in its `info.php` and then
nothing is recorded whatever the config says, and the config can be off while the
module stays installed.

Each record holds boot, handle and total, peak memory, files loaded, and whether
opcache and apcu were on — because what a number means depends on those. The
report groups by url and leads with the **median**: one request that waited on a
busy disk drags a mean somewhere no request actually went. The gap between best
and worst is how much the machine is interfering rather than the code.

*boot* is everything before the route was matched; *handle* is matching, the
controller and rendering. Those two are not split further, which would mean
timing code inside `Route::run()` and `View::view()` — read by everyone, useful
to almost nobody.

### Checklist

- [ ] `framework.php`: `debug => false`, `x-powered-by => false`, `force-https => true`
- [ ] `view.caching => true`, view cache cleared on deploy
- [ ] opcache on, FPM reloaded by the deploy script
- [ ] `zFramework/storage/` and `error_logs/` writable by the web user, and not
      reachable over HTTP — only `public_html/` should be served
- [ ] no `sqlDebug(true)` left in the code (it writes to `/db-debug/` on every query)
- [ ] `database/connections.php` credentials outside version control
- [ ] `error.stream` set once there is more than one app server, so errors land
      somewhere central instead of on each machine's disk
- [ ] `php terminal route cache` run by the deploy script, after the new code is
      in place
- [ ] `php terminal db migrate` run before the new code goes live

---

## 20. RoadRunner

**Optional.** zFramework runs on PHP-FPM, shared hosting and the built-in dev
server exactly as before — `public_html/index.php` is still the entry point and
nothing on this page is required. RoadRunner is an alternative application
server for when a single machine has to do more.

### Why

Under FPM every request rebuilds the application: include the framework, load
the providers, register the routes, then answer. RoadRunner boots once and keeps
the process alive, so a request only costs the request.

```
FPM          : boot + handle, per request
RoadRunner   : boot once, then handle only
```

### Running it

**1. PHP packages.** They are suggestions rather than requirements, because they
need PHP 8.2 and `ext-sockets` while the framework itself runs on 8.1 — pulling
them in by default would break installs on hosts that cannot run RoadRunner
anyway.

```bash
composer require spiral/roadrunner-cli spiral/roadrunner-http spiral/roadrunner-worker nyholm/psr7
```

`spiral/roadrunner-cli` is what provides the `rr` helper below — without it
there is no `get-binary` command.

**2. The server binary.** RoadRunner itself is a single Go executable, not a PHP
package. `get-binary` downloads the right build for your platform into the
project root (`rr` on Linux/macOS, `rr.exe` on Windows, ~64 MB):

```bash
php zFramework/vendor/bin/rr get-binary
```

Note the path: this project sets `vendor-dir` to `zFramework/vendor/`, so it is
not the usual `./vendor/bin/rr`. On Windows the file has no shebang, hence the
leading `php`. Alternatively grab a release from
[github.com/roadrunner-server/roadrunner](https://github.com/roadrunner-server/roadrunner/releases)
and drop the binary in the project root yourself.

The binary is gitignored — it is platform specific, so each machine fetches its
own.

**3. Run it.**

```bash
php terminal run roadrunner         # serve
php terminal run roadrunner reset   # reload workers - run this after a deploy
php terminal run roadrunner workers # pid, memory, requests served
php terminal run roadrunner stop
```

`run roadrunner` looks for the binary in the project root, then in
`zFramework/vendor/bin/`, then on PATH, and tells you what is missing if it
cannot find it or the PHP packages.

Configuration is `.rr.yaml`; the worker is `zFramework/Kernel/worker.php`. Keep
RoadRunner behind nginx in production and let nginx handle TLS and static files —
the same vhost as with FPM, `proxy_pass` instead of `fastcgi_pass`.

`reset` is the one to remember: workers hold the old code in memory until they
are told otherwise, so a deploy that skips it serves the previous version.

### What changes for your code

**State does not reset by itself.** Under FPM the process dies and takes
everything with it; here it does not. `Run::resetState()` clears what belongs to
a request — the logged in user, session, language, mail recipients, the matched
route — after every one. Framework classes handle their own through
`flushRequestState()`; **static properties in your own code are yours to clear.**

A static that survives a request is not a slow leak, it is one visitor being
served another's data.

```bash
php terminal state check    # statics that would leak between requests
```

Every leak found in this framework so far was the same mistake: somebody added a
static and nobody remembered to clear it. So it is checked rather than watched
for. The command walks `zFramework/Core`, reads each `flushRequestState()` to see
what it actually assigns, and reports anything that is neither cleared nor
declared as deliberate boot state in `Kernel/Modules/State.php`.

Nothing it reports is automatically a bug — the route table and database handles
survive on purpose, and that is the whole point of booting once. What it gives
you is the difference between a decision and an oversight. Worth running before a
release, and after adding a static to a framework class.

**`die()` and `exit` kill the worker, not the request.** The framework no longer
uses them: `abort()`, `redirect()`, `refresh()` and file downloads throw a
`ResponseSignal` that `Run::handle()` turns into the response. Behaviour under
FPM is unchanged. Avoid `die()`/`exit` in application code for the same reason.

**`header()` and `setcookie()` do nothing under CLI**, which is what a worker
runs as. `Response::header()` and `Cookie::set()` collect them instead and the
worker attaches them to the response. Calling PHP's `header()` directly works
under FPM and silently disappears under RoadRunner.

**Routes are registered at boot.** `route/web.php`, `route/api.php` and the module
route files are read once, when the worker starts. `route/dynamic/` is the exception:
`Run::handle()` restores the booted table and re-reads that directory on every
request, under a worker as under FPM — so a definition there can depend on request
state. Anything in the other files cannot; conditions that vary per request belong
in middleware, not in a route file.

**Errors and `force-https` under a worker.** The error page is rendered into the
worker's response with a real 500 status, exactly as under FPM. `force-https` is
enforced per request in `Run::handle()` (bootstrap's redirect only runs under a web
SAPI), so a plain http request gets a 301 to the https url from the worker too.

**Database connections live for hours.** MySQL closes an idle one after
`wait_timeout`; the first query afterwards fails with "server has gone away".
The framework reconnects and retries that query once. A real SQL error is not
retried.

### Before switching

- [ ] Own static properties audited, cleared where they hold request state
- [ ] No `die()` / `exit` in application code
- [ ] `header()` / `setcookie()` calls replaced with `Response::header()` / `Cookie::set()`
- [ ] Conditional routes moved to middleware
- [ ] `max_jobs` and `max_worker_memory` set in `.rr.yaml` — a worker recycled
      periodically bounds any leak you missed
- [ ] Deploy script runs `run roadrunner reset`
- [ ] Watched in staging: a rising worker restart rate means a leak or a fatal loop

A useful test: send two requests as different users through the same worker and
assert the second response contains nothing of the first.

---

## 21. Push Notifications

Reaching a user who is not on the site — with the site closed, the tab gone,
the phone in a pocket. Web push is a browser standard: no account with a
vendor, no SDK, no native app. Chrome, Firefox, Edge and Safari 16.4+.

```php
PushNotification::toUser($user['id'])->send([
    'title' => 'Order shipped',
    'body'  => 'Tracking number 123456789',
    'url'   => '/orders/812',
]);
```

### Setting it up

**1. Generate a key pair** — once per application, and keep it out of the
repository:

```bash
php terminal push-notification keys app
```

Paste both keys into `config/push-notification.php` and set `subject` to a `mailto:` url a
push service can reach you at. Replacing a key pair invalidates every
subscription taken with the old one.

**2. Create the table:**

```bash
php terminal db migrate
```

**3. Subscribe from the browser:**

```html
<script src="/assets/js/push-notification.js"></script>
<button id="notify">Notify me</button>

<script>
document.getElementById('notify').onclick = async () => {
    const result = await PushNotification.subscribe({ topics: ['orders'] });
    if (!result.status) console.log('not subscribed:', result.reason);
};
</script>
```

`push-notification.js` registers `/service-worker.js`, asks for permission, subscribes and posts the
result to `/push-notification/subscribe` with the page's csrf token. The public key comes
from `/push-notification/config`, so it is never pasted into javascript by hand.

**Ask at the right moment.** A browser gives a site one good chance at the
permission prompt — a user who says no is not asked again by anything until
they change it in settings. Call `subscribe()` from a click on something that
says what will be sent, never on page load.

### Choosing who to notify

The `to*` methods are filters and combine:

```php
PushNotification::toUser($id)->send('Your report is ready');   // one user's devices
PushNotification::toUser([3, 9])->toTopic('billing')->send([...]); // both must match
PushNotification::toTopic('news')->send([...]);                // whoever asked for it
PushNotification::toAll()->send([...]);                        // every subscriber
PushNotification::toSubscription($row)->send([...]);           // one known device
```

Topics are what the device subscribed with, not what the message is about — a
device that asked for nothing is not reached by a topic send. That is what
makes "only tell me about billing" work without a preferences table.

### The payload

```php
PushNotification::toAll()
    ->urgency('high')       // very-low | low | normal | high
    ->ttl(3600)             // how long the push service holds it for an offline device
    ->collapse('basket')    // a newer message with this topic replaces the undelivered one
    ->send([
        'title'  => 'Back in stock',
        'body'   => 'The thing you wanted is available again',
        'icon'   => '/assets/img/icon.png',
        'url'    => '/products/12',
        'tag'    => 'stock-12',            // replaces a notification already on screen
        'data'   => ['product_id' => 12],  // reaches the service worker untouched
    ]);
```

`icon`, `badge` and `url` come from the application's `defaults` in
`config/push-notification.php` when not given. A string payload is a title:
`PushNotification::toAll()->send('Deploy finished')`.

**Payloads are small.** The limit is 4078 bytes after encryption headers —
send an identifier and let the service worker fetch the rest.

### Several applications

An installation can serve several products, each with its own key pair and its
own subscribers, and none of them able to notify another's users — a browser
only accepts a message signed by the key it subscribed with.

```php
// config/push-notification.php
'apps' => [
    'app'   => ['channel' => 'webpush', 'subject' => 'mailto:...', 'public_key' => '...', 'private_key' => '...'],
    'admin' => ['channel' => 'webpush', 'subject' => 'mailto:...', 'public_key' => '...', 'private_key' => '...'],
],
```

```php
PushNotification::app('admin')->toTopic('errors')->send([...]);
```

```js
PushNotification.subscribe({ app: 'admin', topics: ['errors'] });
```

### Sending in the background

A push is one HTTPS request per subscriber, 100–400 ms each — a broadcast to
50.000 devices is not something a visitor waits for. With `push-notification.queue` on and
Redis available, `send()` hands the work over in chunks and returns:

```bash
php terminal queue work push-notification
```

The return value says what happened either way:

```php
['queued' => 0, 'sent' => 128, 'failed' => 3, 'removed' => 2, 'errors' => [...]]
```

`removed` is subscriptions the push service reported as gone — an uninstalled
browser, cleared site data — deleted on the spot. Ones that merely keep failing
are dropped after `push-notification.max_failures` attempts. Without that a subscriber table
is eventually mostly browsers that no longer exist.

### Terminal

```bash
php terminal push-notification keys {app}          # generate a VAPID key pair
php terminal push-notification test                # check encryption against the RFC vectors
php terminal push-notification send {app} --all --title=Hello --body=Text
php terminal push-notification subscribers {app}   # who is subscribed
php terminal push-notification prune {app}         # delete subscriptions that keep failing
```

`test` is the one to run first on a new server: it encrypts the RFC 8291 §5
worked example and compares it byte for byte, which is the only way to find out
that openssl is wrong before a browser silently discards a message it could not
decrypt.

### What can go wrong

- **https is required**, except on `localhost`. A service worker will not
  register otherwise, so nothing subscribes.
- **iOS only pushes to installed web apps.** Safari 16.4+ supports web push,
  but the user has to Add to Home Screen first; a page in a Safari tab cannot
  subscribe.
- **`/service-worker.js` has to be at the document root.** A service worker only controls
  pages at or below its own path, and one served from `/assets/js/` controls
  nothing.
- **The endpoint is a credential.** Anyone holding it can push to that device
  through your key. It is stored, never rendered.
- **The endpoint's host must be a DNS name or a canonical IP that resolves to a
  public address.** Numeric spellings (`2130706433`, `0x7f000001`, `127.1`), NAT64
  wrappers and names that resolve nowhere are refused - the server POSTs to that
  url, so the guard is what keeps a subscription from becoming an SSRF probe.

### Another channel

Web push is one implementation of
[`Channel`](zFramework/Core/Facades/PushNotification/Channel.php) — deliver one
message, validate one subscription, tell the client what it needs. An
application that later wants FCM for its android build extends that class and
names it in config; nothing in the call sites changes.
