---
name: zframework
description: Use when working with zFramework (a custom Laravel-like PHP framework, v3.0.0, PHP >=8.1). Read this BEFORE writing any application code on top of it - scaffolding a new project, adding routes/models/controllers/migrations/views, or answering "how do I do X here". Purpose - stop re-implementing features the framework already ships, and use the correct API signatures. Triggers - zFramework, zFramework\Core, Route::, Abstracts\Model, php terminal make, resource/views, App/Controllers, modules/, migration, Auth::attempt, view(), Alerts::, AutoSSL, cPanel API, push notification.
---

# zFramework Working Guide

A custom PHP framework written by a solo developer. It looks like Laravel but **is not Laravel** —
the APIs read similarly and behave differently. The big one: **rows are arrays, not objects.**

## The golden rule

**Before writing anything, check whether it already exists here.** This framework is broad;
everything in the inventory below is shipped and working. Do not write your own helper, your own
query builder, your own upload routine, your own cache layer.

## Five mistakes people make

1. `$post->title` — **wrong**. Rows are arrays: `$post['title']`.
2. Echoing a view instead of returning it — controllers `return view(...)`.
3. Hand-rolled `mkdir`/`move_uploaded_file` — `File::upload()` exists (MIME + size + path-traversal
   checks already done).
4. Touching `$_SESSION` directly — `Session::set/get` reads once and writes once per request;
   going around it breaks that.
5. Creating classes by hand — `php terminal make ...` has templates for all of them.

## Directory map

```
App/            Controllers, Middlewares, Models, Observers, Providers, Requests   ← application code
config/         app, framework, mail, model, languages, crypt, push-notification
database/       connections.php, migrations/, seeders/
route/          web.php, api.php, dynamic/
resource/       views/  (view('a.b') → resource/views/a/b.php), lang/{tr,en}/
modules/        self-contained modules (own routes/models/migrations/views)
public_html/    document root, assets/
zFramework/     the framework core — touch ONLY to fix a framework bug
terminal        CLI entry point: php terminal <module> <command>
README.md       73 KB full reference; section numbers below
```

## Inventory — "this already exists, don't write it"

| Need | Use | README |
|---|---|---|
| Routing, groups, prefixes, resource, named routes | `Route::` | §1 |
| Query builder + models + relations | `Model` / `DB` | §2 |
| Authentication, sessions, api_token | `Auth::` | §2.1 |
| Relations (hasMany, belongsToMany, morph*, through) | `RelationShips` trait | §2.2 |
| Pivot operations (attach/detach/sync/toggle) | same trait | §2.3 |
| Model events (before/after insert/update/delete) | `Observer` | §2.4 |
| Schema management | migration classes + `php terminal db migrate` | §2.5 |
| Transactions | `beginTransaction/commit/rollback` | §2.7 |
| Template engine, directives, layouts | `view()` / `View::` | §3 |
| Validation | a `Request` class, or `Validator::validate` | §5 |
| Middleware | `Middleware::` / route `->middleware([])` | §6 |
| Mail (SMTP, queueable) | `Mail::` | §7 |
| Work after the response is sent | `Defer::after()` | §7.1 |
| Cache (per-session / APCu global / Redis) | `Cache::` `GlobalCache::` `Redis::` | §8 |
| Queue + worker | `Queue::` + `php terminal queue work` | §8.2 |
| Flash messages | `Alerts::` | §9 |
| CSRF | automatic + `csrf()` | §10 |
| Localisation | `_l()` / `Lang::` | §11 |
| Reversible encoding (tokens, cookies) | `Crypter::` | §12 |
| Reading/writing config | `config()` / `Config::` | §13 |
| Scaffolding, migrate, backup, bench, release | `php terminal ...` | §14 |
| JSON API + `Auth-Token` header | `route/api.php` + `Response::json` | §15 |
| File upload/download/resize/convert | `File::` | §16 |
| Dates, folders, array paginate, cURL, HTTP | `Date:: Folder:: _Array:: cURL:: Http::` | §16 |
| Let's Encrypt certificates (http-01/dns-01, wildcard) | `AutoSSL` | §17 |
| cPanel management (domains, dns, cron, db, mail, ssl) | `cPanel\*` | §18 |
| Web push notifications (VAPID) | `PushNotification::` | §21 |
| MySQL backup/restore | `php terminal db backup/restore` | §14 |
| Seeding | `database/seeders/` + `oncreateSeeder()` on a migration | §2.6 |
| Query analysis (EXPLAIN, missing indexes) | `profiling.queryAnalyze`, `sqlDebug(true)` | §19 |
| Profiling / recording real requests | `modules/Profiling` | §19 |
| Long-running workers | RoadRunner + `php terminal state check` | §20 |
| Routes that must not be cached | `route/dynamic/` | §1 |

Full signatures: **`references/api.md`** — when unsure about a method's parameters, look there
rather than guessing.

## The path for a new feature

```bash
php terminal make model Post --table=posts        # App/Models/Post.php
php terminal make migration Posts --table=posts   # database/migrations/Posts.php
php terminal make controller PostController --resource
php terminal make request Post/StoreRequest
php terminal make observer PostObserver
php terminal db migrate                           # [--fresh] [--seed] [--module=blog]
```

With `--module=blog` everything lands under `modules/Blog/` instead.

Then register the route and add the view under `resource/views/`. Order:
migration → model → request → controller → route → view.

## Core API — quick recall

### Route (`route/web.php`)

```php
Route::get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');
Route::resource('/posts', PostController::class);   // index/show/create/edit/store/update/delete

Route::pre('/admin')                                 // prefixes BOTH url and name (accumulates)
    ->middleware([Auth::class], fn($declines) => abort(403))
    ->group(function () {
        Route::resource('/posts', AdminPostController::class);  // admin.posts.index ...
    });

Route::pre('/api')->noCSRF()->group(fn() => ...);    // turn CSRF off for the API
route('admin.posts.show', ['id' => 5]);              // always the full name
```

**Do not write closure routes** — they block the route cache (`php terminal route cache`).
Use `[Controller::class, 'method']`.

### Model

```php
class Post extends Model
{
    use softDelete;

    public $table   = 'posts';
    public $guard   = ['secret'];              // stripped from get()/first() results
    public $db      = 'local';                 // database/connections.php
    public $observe = PostObserver::class;

    public function beginQuery() {              // applied to every query
        // return $this->where('status', 1);
    }

    public function author(array $row) {        // becomes $post['author']() on the row
        return $this->belongsTo(User::class, $row['user_id']);
    }
}
```

```php
$p = new Post;
$p->where('status', 'published')->orderBy(['created_at' => 'DESC'])->get();
$p->where('views', '>', 100)->whereIn('id', [1,2,3])->limit(20, 10)->get();
$p->find(1); $p->findOrFail(1); $p->firstOrFail('Not found');
$p->insert([...]);                    // returns the inserted row
$p->where('id', 1)->update([...]);    // returns affected row count
$result = $p->paginate(20, 'page');   // items, item_count, page_count, current_page, links()
```

**Rows are arrays.** Relations and row-level `update`/`delete` are attached as closures:
`$post['comments']()`, `$post['update'](['title' => 'x'])`.
Strip the closures before encoding JSON:
`array_filter($post, fn($v) => !$v instanceof Closure)`.
For a read-only query, `closureMode(false)` skips binding them entirely.

### Migration

```php
class Posts
{
    static $table = "posts";
    static $db    = "local";
    static $charset = "utf8mb4_general_ci";

    public static function columns()
    {
        return [
            'id'      => ['primary'],
            'user_id' => ['int:11', 'index'],
            'title'   => ['varchar:200', 'required'],
            'slug'    => ['varchar:200', 'unique:post_slug'],
            'body'    => ['longtext', 'nullable'],
            'meta'    => ['json', 'nullable'],
            'status'  => ['tinyint:1', 'default:1'],
            'timestamps',
            'softDelete',
        ];
    }

    public static function oncreateSeeder(?string $db = null) { /* optional */ }
}
```

Types: `primary int bigint smallint tinyint bool varchar:N char:N text longtext json uuid
decimal float real date datetime time`.
Flags: `required nullable unique[:index_name] index default:VAL charset:X onupdate:X`.
Migrations are idempotent — they add and drop columns on an existing table.

### Controller + Request

```php
class PostController extends Controller
{
    public function __construct() { $this->post = new Post; }

    public function store(StorePostRequest $request)      // type hint = automatic validation
    {
        $data = $request->validated();
        $this->post->insert($data + ['user_id' => Auth::id()]);
        Alerts::success('Saved.');
        return redirect(route('posts.index'));
    }
}
```

```php
class StorePostRequest extends Request
{
    public function __construct() {
        $this->authorize      = false;   // true → run the authorisation check
        $this->htmlencode     = false;   // true → values pass through htmlspecialchars
        $this->attributeNames = ['title' => 'Title'];
    }

    public function columns(): array {
        return [
            'title' => ['required', 'max:200'],
            'email' => ['required', 'email', 'unique:' . User::class . ';key:email'],
            'age'   => ['nullable', 'type:int', 'min:18'],
        ];
    }
}
```

Rules: `required nullable type:x min:N max:N same:field email unique:Model;key:col
exists:Model;key:col`. On failure it adds `Alerts::danger` and redirects back; on AJAX it aborts
with 400 + JSON. Need a new rule? Add a class under `zFramework/Core/Validator/Rules/` — one class
per rule.

### View — `resource/views/`

`view('app.pages.welcome')` → `resource/views/app/pages/welcome.php`.

```
@extends('layouts.app')  @section('content') ... @endsection  @yield('content')
@if @elseif @else @endif   @foreach @endforeach   @forelse @empty @endforelse
@isset @endisset  @empty @endempty  @php @endphp  @include('partials.nav')
@json($x)  @dump($x)  @dd($x)
{{ $x }}  → escaped        {!! $x !!} → raw
```

Custom directive: `View::directive('alert', fn($t, $m) => ...)` in `App/Providers/ViewProvider.php`.
Auto-injected view data: `View::bind('layouts.app', fn() => ['user' => Auth::user()])`.

### Auth

```php
Auth::attempt(['email' => $e, 'password' => $p], staymein: true);
Auth::check(); Auth::user(); Auth::id(); Auth::logout();
Auth::encodePassword($plain);      // follows the model's special_columns.passwordencode
```

Model side (`App/Models/User.php`):
```php
public $special_columns = ['email' => 'email', 'password' => 'password', 'passwordencode' => 'bcrypt'];
```

`Auth::model()` is lazy — a request that never asks for an identity opens no DB connection.
Keep it that way (see `references/conventions.md`).

## Reference files

- **`references/api.md`** — exact signatures for every facade, helper, DB method, job and CLI
  helper. Check here when unsure about parameter order.
- **`references/recipes.md`** — end-to-end recipes: CRUD screen, protected area, JSON API,
  creating a module, file upload, mail + queue, push notifications, localisation, going to production.
- **`references/config.md`** — every key in every config file, plus `database/connections.php`
  and what each PDO option costs.
- **`references/infrastructure.md`** — AutoSSL (ACME), the cPanel classes, the query analyzer,
  profiling, RoadRunner workers and their state rules, `route/dynamic/`, backups, releases,
  error handling.
- **`references/conventions.md`** — deliberate design decisions (leave them alone), known traps,
  performance notes.

Still no answer: `README.md` (73 KB, numbered sections) and the source under `zFramework/Core/`.

## What this framework does not do

- **No PHPUnit suite, and none is planned.** If a test is needed, write an ad-hoc script.
- **Adding Composer packages is not the default** — the core runs without dependencies.
- Do not modify `zFramework/` to serve an application need; application code goes in `App/` and
  `modules/`.
- The `@` operators and `$GLOBALS` usage in the source are deliberate — do not "clean them up".
