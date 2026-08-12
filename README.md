<a href="https://buymeacoffee.com/mustafaomereser" target="_blank"><img src="https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png" alt="Buy Me A Coffee" style="height: 41px !important;width: 174px !important;" ></a>

# zFramework v3.0.0

**Easiest, fastest PHP framework. (Simple)**

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)
![Version](https://img.shields.io/badge/version-3.0.0-green)
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

## Table of Contents

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
- [4. Controller](#4-controller)
- [5. Validator](#5-validator)
- [6. Middleware](#6-middleware)
- [7. Mail](#7-mail)
  - [7.1. Defer](#71-defer)
- [8. Cache](#8-cache)
  - [8.1. Redis](#81-redis)
  - [8.2. Queue](#82-queue)
- [9. Alerts](#9-alerts)
- [10. Csrf](#10-csrf)
- [11. Language](#11-language)
- [12. Crypter](#12-crypter)
- [13. Config](#13-config)
- [14. Terminal](#14-terminal)
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
Route::get('/posts', fn() => view('posts.index'));
Route::post('/posts', [PostController::class, 'store']);
Route::put('/posts/{id}', [PostController::class, 'update']);
Route::patch('/posts/{id}', [PostController::class, 'update']);
Route::delete('/posts/{id}', [PostController::class, 'delete']);
Route::any('/ping', fn() => 'pong');   // matches any HTTP method
```

### Controller Syntax

Both forms are equivalent:

```php
Route::get('/', [HomeController::class, 'index']);
Route::get('/', 'HomeController@index');
```

The controller is resolved by `findFile()` — it searches recursively in `App/Controllers/`.

### URL Parameters

```php
Route::get('/user/{id}', fn($id) => ...);                         // required
Route::get('/user/{id}/{?name}', fn($id, $name = null) => ...);   // optional
```

### Dependency Injection

Class-typed parameters in route callbacks are automatically resolved via Reflection:

```php
Route::get('/posts', function (PostRepository $repo) {
    return $repo->all();
});
```

### Redirect

```php
Route::redirect('/old-url', '/new-url');   // issues a 302
```

### Resource

```php
Route::resource('/posts', PostController::class);
```

Registers 7 routes automatically:

| URL | HTTP Method | Controller Method | Route Name |
|---|---|---|---|
| /posts | GET | index() | posts.index |
| /posts | POST | store() | posts.store |
| /posts/create | GET | create() | posts.create |
| /posts/{id} | GET | show($id) | posts.show |
| /posts/{id}/edit | GET | edit($id) | posts.edit |
| /posts/{id} | PUT / PATCH | update($id) | posts.update |
| /posts/{id} | DELETE | delete($id) | posts.delete |

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

### Forms

```html
<!-- POST -->
<form method="POST">
    {{ csrf() }}
    ...
</form>

<!-- PUT / PATCH / DELETE via hidden field -->
<form action="/posts/1" method="POST">
    {{ csrf() }}
    {{ inputMethod('PATCH') }}
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
    public $guard      = ['secret'];     // columns excluded from get() / first() results
    public $primary    = 'id';           // auto-detected from schema if omitted
    public $created_at = 'created_at';   // set to null to disable auto-timestamping
    public $updated_at = 'updated_at';
    public $deleted_at = 'deleted_at';   // used by softDelete trait
}
```

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
$p->updateOrInsert(['title' => 'Hi']);  // update if row found, otherwise insert

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
$p->fetchType('unique')->get();     // keyed by primary key (PDO::FETCH_UNIQUE)
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
echo $result['links']();                            // uses config('app.pagination.default-view')
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
// staymein: true sets a persistent cookie (auth-stay-in) using the user's api_token

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
```

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

    // called before delete
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
| `default:VALUE` | DEFAULT VALUE — use `default:NULL` for null default |
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
preferred — it states the intent.

```bash
php terminal db migrate                   # apply pending migrations
php terminal db migrate --fresh           # drop all tables and re-run
php terminal db migrate --fresh --seed    # drop + migrate + seed
php terminal db migrate --module=blog     # only migrate the 'blog' module
php terminal db migrate --all             # include all modules
```

---

### 2.6. Seeders

```php
// database/seeders/PostsSeeder.php
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
return view('posts.index', compact('posts'));        // resolves to App/Views/posts/index.php
return View::view('posts.index', ['posts' => $posts]);
```

### Directives

```
@if($condition)       @elseif($other)      @else      @endif
@foreach($items as $item)                             @endforeach
@forelse($items as $item)                  @empty     @endforelse
@isset($var)                                          @endisset
@empty($var)                                          @endempty
@php                                                  @endphp
@include('partials.nav')
@extends('layouts.app')
@section('content')                                   @endsection
@yield('content')
@json($var)              — outputs json_encode($var)
@dump($var)              — visual dump (does not die)
@dd($var)                — visual dump + die
{{ $var }}               — escaped output (htmlspecialchars)
{!! $var !!}             — raw unescaped output
```

### Custom Directives

```php
// App/Providers/ViewProvider.php
View::directive('alert', fn($type, $msg) => "<div class='alert alert-{$type}'>{$msg}</div>");

// Usage in .php view file:
// @alert('success', 'Saved!')
```

### View::bind — ViewProvider

Inject variables automatically into specific views without passing them from every controller:

```php
// App/Providers/ViewProvider.php
View::bind('layouts.app', fn() => [
    'user'   => Auth::user(),
    'locale' => Lang::locale(),
]);
```

---

## 4. Controller

```php
class PostController
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

        return view('posts.index', compact('posts'));
    }

    public function show(int $id): mixed
    {
        return view('posts.show', [
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

// With a callback — custom logic when validation fails
Validator::validate($_REQUEST, ['title' => ['required']], [], function (array $errors) {
    return Response::json($errors);
});
```

On failure: adds `Alerts::danger()` for each error and redirects back. On AJAX requests, aborts with 400 + JSON errors.

**Rules:**

| Rule | Description |
|---|---|
| `required` | Field must be present and non-empty |
| `nullable` | Field may be empty or absent; skips further rules if empty |
| `type:string` / `type:int` / `type:float` / `type:array` | PHP type check |
| `min:N` | Minimum value (int/float) or minimum string/array length |
| `max:N` | Maximum value (int/float) or maximum string/array length |
| `same:other_field` | Must exactly match the value of `other_field` |
| `email` | Must be a valid e-mail address |
| `unique:Model;key:column` | Value must not already exist in the model's column |
| `exists:Model;key:column` | Value must exist in the model's column |

---

## 6. Middleware

```php
// App/Middlewares/Auth.php
class Auth
{
    public function __construct()
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
$posts = Cache::cache('recent_posts', fn() => (new Post)->limit(10)->get(), ttl: 300);

Cache::remove('recent_posts');   // invalidate one key
Cache::clear();                  // clear all session cache
```

### Global Cache (APCu — shared across all requests)

```php
$stats = GlobalCache::cache('site_stats', fn() => computeStats(), ttl: 3600);

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
// config/redis.php
'enabled'  => true,
'host'     => '127.0.0.1',
'database' => ['cache' => 0, 'session' => 1, 'queue' => 2],
```

Requires the `redis` (phpredis) extension. Enabling it changes three things:

| | Without Redis | With Redis |
|---|---|---|
| `GlobalCache` | APCu, per server | APCu (L1) + Redis (L2) |
| Sessions | files on local disk | shared, via `config/session.php` |
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

## 9. Alerts

Flash messages stored in session. Displayed once and cleared on the next request.

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
csrf()                   // renders: <input type="hidden" name="_token" value="...">
Csrf::get()              // returns current token string
Csrf::set()              // generates and stores a new token
Csrf::unset()            // destroys all tokens
Csrf::remainTimeOut()    // seconds remaining until token rotation
```

---

## 11. Language

```
lang/
  en/
    lang.php      // ['greeting' => 'Hello, :name!']
    auth.php
  tr/
    lang.php      // ['greeting' => 'Merhaba, :name!']
    auth.php
```

```php
Lang::locale('tr');                              // set active locale
Lang::get('lang.greeting', ['name' => 'Ali']);   // "Merhaba, Ali!"
_l('lang.greeting', ['name' => 'Ali']);          // shortcut
Lang::list();                                    // returns all keys for active locale

// Default locale set in config/app.php:
'lang' => 'en'
```

---

## 12. Crypter

Reversible encoding for tokens and cookies. **Not intended for passwords** — use bcrypt for passwords.

```php
$encoded = Crypter::encode('value');
$decoded = Crypter::decode($encoded);

$encodedArr = Crypter::encodeArray(['a', 'b', 'c']);
$decodedArr = Crypter::decodeArray($encodedArr);
```

Configure key and salt in `config/app.php`:

```php
'crypt' => ['key' => 'your-key', 'salt' => 'your-salt'],
```

Regenerate:

```bash
php terminal security key --regen
```

---

## 13. Config

```php
Config::get('app');              // returns entire config/app.php array
Config::get('app.debug');        // returns a single key (dot notation)
Config::set('app', [...]);       // overwrite the entire file
config('app.debug');             // shortcut for Config::get()
```

**config/app.php**

```php
return [
    'debug'        => true,
    'force-https'  => false,
    'x-powered-by' => false,
    'lang'         => 'en',
    'public'       => '/public',
    'crypt'        => ['key' => '...', 'salt' => '...'],
    'error'        => ['logging' => true, 'callback' => null],
    'pagination'   => ['default-view' => 'partials.pagination'],
];
```

**config/framework.php** — how the framework itself behaves

```php
return [
    'view'     => ['caching' => true, 'minify' => true],
    'route'    => ['caching' => true, 'auto-check' => false],
    'session'  => ['driver' => 'file', 'gc_probability' => 1],
    'response' => ['ajax' => ['include-alerts' => true]],
    'cache'    => ['apcu' => true],
    'redis'    => ['enabled' => false, 'host' => '127.0.0.1', /* ... */],

    'profiling' => [
        'enabled'      => false,   // write a record per request to analysis/profiling/
        'rate'         => 1,       // or a fraction: 0.05 records one request in twenty
        'keep'         => 200,     // stop writing once this many records exist
        'queryAnalyze' => false,   // EXPLAIN every SELECT (needs app.debug; re-runs the query)
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
php terminal module create blog

# Cache
php terminal cache clear views
php terminal cache clear sessions

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

## 15. API

```php
// route/api.php
Route::get('/user', fn() => Response::json(['user' => Auth::user()]));
Route::post('/posts', [PostController::class, 'store'])->noCSRF();
```

Authenticate via request header:

```
Auth-Token: {api_token}
```

The token is matched against the `api_token` column in the `users` table.

---

## 16. Helper Methods

```php
// Paths
base_path('/config/app.php');   // absolute path from project root
public_path('/images');         // absolute path to public directory
public_dir('/images');          // same — returns real filesystem path
asset('/assets/app.css');       // full URL with ?v= cache-busting (filemtime)

// HTTP / Navigation
redirect('/login');             // Location header + die
back();                         // redirect to HTTP_REFERER
back('?saved=1');               // redirect to REFERER with suffix
refresh();                      // Refresh:0 header + die
abort(404);                     // abort with HTTP status code
abort(403, 'Forbidden');        // abort with message (JSON on AJAX)

// Request
uri();                          // current URI path (strips script name)
method();                       // HTTP method (reads _method override from POST)
ip();                           // client IP (checks X-Forwarded-For, HTTP_CLIENT_IP)
request('field');               // $_REQUEST['field'] ?? false
request();                      // full $_REQUEST array
request('field', 'value');      // set $_REQUEST['field'] = 'value'
getQuery(['page' => 2]);        // current query string merged with additions — returns string
getQuery(['page' => 2], ['sort']); // merge additions, remove 'sort' key
getQuery([], [], false);        // returns array instead of string

// Response
Response::json(['key' => 'value']);   // sets Content-Type: application/json and echoes

// View / Route shortcuts
view('posts.index', compact('posts'));
route('posts.show', ['id' => 1]);
csrf();
_l('lang.key', ['name' => 'Ali']);
config('app.debug');

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
Date::timestamp();                     // current unix timestamp
Date::format(time(), 'd.m.Y H:i');
Date::setLocale('Europe/Istanbul');

// File
File::upload('/uploads', $_FILES['photo'], [
    'accept' => ['jpg', 'png', 'webp'],
    'size'   => 5 * 1024 * 1024,       // max bytes
]);
File::upload('/uploads', $_FILES['photos']);      // multiple file input → array of paths
File::save('/uploads', 'https://example.com/image.jpg');  // download remote file
File::resizeImage('photo.jpg', ['width' => 800, 'height' => 600, 'desired_sizes' => true], 'out.jpg');
File::convertImage('photo.jpg', 'webp');
File::delete('uploads/photo.jpg');
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

### Account Management

```php
$ssl->ensureAccount();   // creates ACME account if none exists
$ssl->unlinkAccount();   // delete local account files
```

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
// [['domain' => '_acme-challenge.example.com', 'value' => '...'], ...]

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

Wraps the cPanel UAPI (port 2083, Bearer token auth).

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

### config/app.php

```php
'debug'        => false,   // REQUIRED. Also gates the query analyzer and stack-trace args.
'x-powered-by' => false,   // stop leaking the framework version
'force-https'  => true,
'error'        => ['logging' => true],
```

`debug => false` is the single most important switch. Besides hiding the error
page, it turns off two things that are expensive or unsafe on production:

- the **query analyzer**, which re-executes every analysed SELECT through
  `EXPLAIN ANALYZE`,
- **`zend.exception_ignore_args`**, which otherwise keeps every call argument
  attached to exceptions — a failed login would write the plain password into
  the error log next to the trace.

### config/view.php

```php
'caching' => true,   // compile templates once
'minify'  => true,
```

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
php terminal route list     # show every registered route
```

Route files are parsed **and executed** on every request: with 1000 routes that
is 1000 `Route::get()` calls before the one being served is even found. Caching
the table replaces all of it with a single `include`.

```php
// config/route.php
'caching'    => true,   // false: ignore the cache file even if one exists
'auto-check' => true,   // ignore it when a route file changed
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

Routes handled by a closure cannot be cached — `var_export()` cannot write one.
`route cache` refuses the whole table and names them rather than caching half of
it, since the missing half would 404. Use `[Controller::class, 'method']`.

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
timing code inside `Route::run()` and `View::make()` — read by everyone, useful
to almost nobody.

### Checklist

- [ ] `debug => false`, `x-powered-by => false`
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

**Routes are registered at boot.** `route/dynamic/` is re-evaluated per request
under FPM, but a worker executes it once at startup like everything else — so
conditions that vary per request belong in middleware, not in a route file.

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
50.000 devices is not something a visitor waits for. With `push.queue` on and
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
are dropped after `push.max_failures` attempts. Without that a subscriber table
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

### Another channel

Web push is one implementation of
[`Channel`](zFramework/Core/Facades/PushNotification/Channel.php) — deliver one
message, validate one subscription, tell the client what it needs. An
application that later wants FCM for its android build extends that class and
names it in config; nothing in the call sites changes.
