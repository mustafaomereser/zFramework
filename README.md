<a href="https://buymeacoffee.com/mustafaomereser" target="_blank"><img src="https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png" alt="Buy Me A Coffee" style="height: 41px !important;width: 174px !important;" ></a>

# zFramework v2.9.0

**Easiest, fastest PHP framework. (Simple)**

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)
![Version](https://img.shields.io/badge/version-2.9.0-green)
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
| 🗃️ Cache — Session-based & APCu (global) | 🔍 Query Analyzer — EXPLAIN + EXPLAIN ANALYZE |
| 🎨 View / template engine (Blade-like directives) | 🔧 Terminal — Artisan-like CLI tool |

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
- [8. Cache](#8-cache)
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

$p->get();                              // all rows as array of ModelResult
$p->first();                            // first row or empty array
$p->firstOrFail();                      // first row or abort(404)
$p->firstOrFail('Custom message');      // first row or abort(404) with message
$p->find(1);                            // find by primary key
$p->findOrFail(1);                      // find or abort(404)
$p->count();                            // row count (int)
$p->updateOrInsert(['title' => 'Hi']);  // update if row found, otherwise insert

// insert() returns the full inserted row (ModelResult) or affected row count
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
// 'items'        → array of ModelResult for the current page
// 'item_count'   → total row count
// 'shown'        → e.g. "21 / 40" (range shown on this page)
// 'start'        → start index of current page
// 'per_page'     → rows per page
// 'page_count'   → total number of pages
// 'current_page' → current page number
// 'links'        → Closure — call $result['links']() to render pagination view
```

```php
// In the view:
echo $result['links']();                            // uses config('app.pagination.default-view')
echo $result['links']('partials.my-pagination');    // custom view
```

### ModelResult — Row Access

Every row returned by `get()`, `first()`, `find()`, and `insert()` is a `ModelResult` instance. It implements `ArrayAccess` and `JsonSerializable`.

```php
$post = (new Post)->find(1);

// Array access
$post['title'];
$post['user_id'];

// Object property access
$post->title;
$post->user_id;

// Call relation closures defined on the model
$post->comments();       // invokes Post::comments(array $row)
$post->author();         // invokes Post::author(array $row)

// Row-level update / delete (scoped to the primary key of this row)
$post->update(['title' => 'Updated']);
$post->delete();

// Serialization — closures are automatically excluded
json_encode($post);
$post->toArray();
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
Auth::user();    // ModelResult of the authenticated user, or false
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

Relation methods are defined on the model and accept `array $row` (the current row). They are automatically bound as closures on each `ModelResult`, callable as `$row->posts()`.

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
| `bigint` / `bigint:N` | BIGINT |
| `int` / `int:N` | INT |
| `smallint` | SMALLINT |
| `tinyint` | TINYINT |
| `varchar` / `varchar:N` | VARCHAR(255) / VARCHAR(N) |
| `char` / `char:N` | CHAR(50) / CHAR(N) |
| `text` | TEXT |
| `longtext` | LONGTEXT |
| `decimal` / `float` | DECIMAL / FLOAT |
| `date` / `datetime` / `time` | DATE / DATETIME / TIME |
| `required` | NOT NULL |
| `nullable` | NULL |
| `default:VALUE` | DEFAULT VALUE — use `default:NULL` for null default |
| `unique` | UNIQUE KEY |
| `unique:group_name` | composite UNIQUE (groups columns with the same name) |
| `index` | INDEX |
| `index:group_name` | composite INDEX |
| `charset:utf8mb4_general_ci` | per-column CHARACTER SET + COLLATE |
| `timestamps` | adds `created_at DATETIME` + `updated_at DATETIME` |
| `softDelete` | adds `deleted_at DATETIME NULL` |

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

Requires the `apcu` PHP extension.

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
    'analyze'      => false,   // enable query analyzer (DbCollector)
    'pagination'   => ['default-view' => 'partials.pagination'],
];
```

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
