---
name: zframework
description: Use when working with zFramework, a self-written PHP framework (PHP >=8.1). It borrows Laravel's vocabulary and does NOT behave like Laravel - writing Laravel from memory produces broken code here (rows are arrays not objects, most Blade directives do not exist, Route::resource takes no options, the destroy method is called delete). Read this BEFORE writing any application code on top of it - scaffolding a project, adding routes/models/controllers/migrations/views, or answering "how do I do X here" - and check references/api.md for a signature rather than assuming. Triggers - zFramework, zFramework\Core, Route::, Abstracts\Model, php terminal make, resource/views, App/Controllers, modules/, migration, Auth::attempt, view(), Alerts::, AutoSSL, cPanel API, push notification.
---

# zFramework Working Guide

A self-written PHP framework. It borrows Laravel's vocabulary — `Route::resource`, `@extends`,
`Auth::attempt` — and **behaves differently behind every one of those names.**

Treat the resemblance as a hazard, not a shortcut. Recalling how Laravel does something is not
evidence about this framework; it is the most common way to write broken code here. The names
that read as familiar are exactly the ones that differ:

| Looks like Laravel | Here |
|---|---|
| `$post->title` | rows are **arrays** — `$post['title']` |
| `Route::resource(…)->only([…])` | no options at all; and it is `delete`, not `destroy` |
| `{{ $x }}` escapes | it does, and `{!! $x !!}` is the raw form — but plain `<?= ?>` is the house style |
| `@for`, `@csrf`, `@method`, `@push` | do not exist; they print into the page |
| `prefix()` / `where()` on routes | `Route::pre()`; constraints are types in the url — `{id:int}` |
| `$request->validated()` returns only valid data | it also aborts the request on failure — and via `Validator` directly it can return a value that failed |

**When you are unsure of a signature, read it — `references/api.md`, or the source under
`zFramework/Core/`.** Guessing from Laravel is what this skill exists to prevent.

## The golden rule

**Before writing anything, check whether it already exists here.** This framework is broad;
everything in the inventory below is shipped and working. Do not write your own helper, your own
query builder, your own upload routine, your own cache layer.

## Mistakes people make

1. `$post->title` — **wrong**. Rows are arrays: `$post['title']`.
2. Echoing a view instead of returning it — controllers `return view(...)`.
3. Hand-rolled `mkdir`/`move_uploaded_file` — `File::upload()` exists (MIME + size + path-traversal
   checks already done).
4. Touching `$_SESSION` directly — `Session::set/get` reads once and writes once per request;
   going around it breaks that.
5. Creating classes by hand — `php terminal make ...` has templates for all of them.

The next four are the ones that keep happening. They are not style preferences; each one
produces code that fights the framework.

6. **Writing the seven CRUD routes by hand**, or building a `$crud` array and looping it to
   simulate resource routing. `Route::resource('/posts', PostController::class)` exists and
   registers all of them, named. Never generate routes from a data structure.
7. **Inventing a controller hierarchy.** The only base class is `zFramework\Core\Abstracts\Controller`
   and it is deliberately empty. There is no `AbstractCrudController` to write, no interface to
   implement, no shared CRUD parent. Every controller extends `Controller` directly.
8. **Hand-writing the controller** instead of `php terminal make controller X --resource`, which
   emits exactly the seven methods `Route::resource` dispatches to — including `delete()`, which
   is not called `destroy()` here.
9. **Putting views wherever.** `resource/views/` has a contract: one directory per interface
   layer, `main.php` as that layer's only layout, and every page group a directory with
   `index.php` in it. See `references/views.md` — it is the most-violated part of this skill.

And one that is not a mistake but a rule:

10. **Never run `php terminal update` unless the user asked for it in words.** It replaces the
    framework core, and so do `--config`, `--force` and `--rollback`. Noticing that a newer
    version exists is not permission to install it — say so and wait. `--check` is read-only,
    but even that belongs at most once or twice in a session, when the version actually
    matters to the question.

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
cron/           standalone cron scripts; cron.php boots the framework for one job
schedule/       scheduled tasks; read only by `php terminal schedule run`
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
| Page caching, HTTP cache headers | `Page::` | §3.1 |
| Application log | `Log::` | §20.1 |
| Rate limiting | `RateLimit::` + `Throttle` middleware | §6.1 |
| Scheduled tasks, one crontab line | `Schedule::` + `schedule/` | §14.1 |
| A cron job in its own process | `cron/` + `cron/cron.php` | §14.2 |
| Updating the framework core | `php terminal update` — **only when asked** | §14.3 |

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

Then register the route and add the views. Order:
migration → model → request → controller → route → view.

Views are the step people improvise; do not. Copy the skeletons in `templates/` —
`templates/views/main.php`, `templates/views/pages/{index,edit-or-create,show}.php`,
`templates/ResourceController.php` — into place and edit them. `references/views.md` explains
the directory contract they follow.

## Core API — quick recall

### Route (`route/web.php`)

```php
Route::get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');
Route::resource('/posts', PostController::class);   // index/show/create/edit/store/update/delete

Route::pre('/admin')            // one name segment per level, built top-down: admin.posts.index
                                // Route::pre('/devices', '/assets') renames without touching the url
                                // Route::pre('/panel', '')          url prefix, no name segment
    ->middleware([Auth::class], fn($declines) => abort(403))
    ->group(function () {
        Route::resource('/posts', AdminPostController::class);  // admin.posts.index ...
    });

Route::pre('/api')->noCSRF()->group(fn() => ...);    // turn CSRF off for the API
route('admin.posts.show', ['id' => 5]);              // always the full name
```

**Write the root and resource routes last.** `Route::resource('/', …)` registers `/{id}`,
which owns every one-segment url by design - anything more specific goes above it, and a
one-segment route in `route/dynamic/` (included last) can never win.

**Do not write closure routes** — they block the route cache (`php terminal route cache`).
Use `[Controller::class, 'method']`.

`Route::resource` takes exactly two arguments. There is no `->only()`, `->except()`,
`->names()`, no `apiResource`, no `Route::where()`, and the prefix helper is `Route::pre()`,
not `prefix()`. If you need a subset, write those routes individually. The destroy method is
**`delete`**, and the PUT route is intentionally left unnamed (route names are array keys, so
naming both PATCH and PUT would overwrite one).

**Do not trust `php terminal route list` as the full picture.** Routes registered
conditionally — behind a module's `status`, inside `route/dynamic/`, or behind any runtime
condition — may not appear in it. To know what is actually registered, read `route/web.php`,
`route/api.php`, `route/dynamic/*` and each enabled module's `route/web.php`.

Groups inherit inward as you would expect — a nested group gets the outer prefix, name prefix
and middleware list, and the outer settings are restored afterwards. Two traps, both verified:
a `pre()`/`middleware()` left **without any `->group()`** stays pending and is picked up by the
*next* group, and two `middleware()` calls chained at the same level keep only the second.
And a declined middleware with **no fallback closure gives a 404** — the middleware's own
`error()` never runs on the routing path. Pass `fn($declines) => abort(403)` if you want
anything else. Full detail in `references/routing.md`.

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

**Read `references/views.md` before writing a template.** It is not Blade; it is a
regex compiler, and the differences bite. Summary:

`view('app.pages.posts.index')` → `resource/views/app/pages/posts/index.php`.

The directory contract, which is not optional:

```
resource/views/<app>/main.php                    one layout per interface layer
resource/views/<app>/pages/<resource>/index.php  a page group is a DIRECTORY + index.php
resource/views/<app>/pages/<resource>/edit-or-create.php   create and edit share one file
resource/views/errors/<app>/{main,404}.php       every layer ships its own error views
```

Always use `@extends('app.main')`, `@section('body') … @endsection`, `@yield`, `@include`.

Prefer plain PHP for output and control flow — `<?= $x ?>`, `<?php foreach (…): ?>`,
`<?php if (…): ?>` — which is how the surrounding code is written. `{{ }}` and `@foreach`
work too; if the user asks for them, use them.

`{{ $x }}` **escapes** (compiles to `<?= e($x) ?>`) and `{!! $x !!}` is the raw form. Anything
emitting markup must use the raw one or it renders as visible text: `{!! csrf() !!}`,
`{!! inputMethod('PATCH') !!}`, `{!! $posts['links']() !!}`.

These do **not** exist and will be printed literally into the page:
`@for` `@while` `@switch` `@auth` `@guest` `@csrf` `@method` `@push`
`@stack` `@component` `@each`. Use `<?= csrf() ?>` and `<?= inputMethod('PATCH') ?>`.

What does exist: `@if @elseif @else @endif`, `@foreach @endforeach`,
`@forelse @empty @endforelse`, `@isset @endisset`, `@empty @endempty`, `@php @endphp`,
`@json($x)`, `@dump($x)`, `@dd($x)`, and `{{-- comment --}}` (stripped before anything else
parses, so it may contain directives).

**Templates render; they do not fetch or calculate.** No `new Post`, no `->where()->get()`,
no aggregation or business rules in a view file. The controller queries and passes finished
data to `view()`. Formatting (`Date::format`, `e()`, a ternary picking a class) is fine.

The layout is not an exception. What `main.php` needs on every render is registered in
`App/Providers/ViewProvider.php`:

```php
View::bind('app.main', fn() => ['lang_list' => Lang::list()]);
```

The bind fires even when the request rendered a page that `@extends('app.main')`, and it
re-runs on a cache hit — so bind once, on the layout, never per page.

A page and its layout compile into **one file with a single `extract()`** — one shared scope.
A variable set in the layout is visible inside the sections and the other way round, and a
layout assignment overwrites what the controller passed under that key. Name view data
specifically (`$post`, not `$item`) and prefix what the layout owns.

Custom directive: `View::directive('page', fn($x) => ...)` in `App/Middlewares/ViewDirectives.php`.
Clear compiled views with `php terminal cache clear views`.

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

Three things about `attempt()` that catch people out: it returns **false if someone is already
logged in**; every key other than the password becomes a `where()`; and **with no password key
it logs the user in without checking one** — `Auth::attempt(['id' => 7])` succeeds. Name the
fields explicitly, never pass `request()` into it.

For an API, put `App\Middlewares\API::class` on the group — it flips Auth into api mode and
logs in from the `Auth-Token` header against `api_token`. Do not hand-roll a token check.
`noCSRF()` on the same group, or every POST aborts with 406. Detail in `references/auth.md`.

### Page caching

```php
Page::cache();                     // response.cache-ttl seconds, shared
Page::cache(600, name: 'post-5');  // tagged, so forget() can find it later
Page::cache(300, shared: false);   // this browser only
Page::vary('Cookie');              // per-visitor; takes it out of the shared store
Page::noCache();                   // back to live
Page::forget('post-5');            // drop every entry with that tag
```

**A page nobody declared is live** — `no-store` goes out at bootstrap. Declaring sets the HTTP
headers always, and stores the rendered output when the response is eligible. Never stored: a
non-GET, a request with an auth cookie, a non-200, a body with a csrf token, anything private
or varying. Detail in `references/caching.md`.

### Log

```php
Log::info('Order paid', ['order' => $id]);
Log::error('Gateway refused', ['code' => $e->getCode()]);
```

`storage/logs/Y-m-d.log`. Not the error handler — uncaught throwables still go through
`errorHandler()`. This is for what was never an exception.

### Rate limiting

```php
Route::throttle(?int $limit = null, ?int $window = null, ?string $by = null, ?int $block = null)
```

```php
Route::pre('/api')->throttle(120)->middleware([API::class])->noCSRF()->group(...);
Route::throttle(5, 300)->group(fn() => Route::post('/sign-in', ...));
Route::pre('/search')->throttle(100, 10, block: 600)->group(...);
```

A group setting, like `pre()` and `noCSRF()` — it attaches the middleware itself, so the limit
sits with the routes it governs. Config carries only the fallback; there is no url-prefix
table, because that is a second copy of the routing that stops matching when a url changes.

`block` refuses a flood outright for that long instead of letting the next window in, answered
on one read with no route matched. Answers 429 as JSON itself, so no fallback closure is
needed — and put it first in the list, since the response unwinds past whatever follows.

### Scheduled tasks

```php
// schedule/tasks.php, driven by: * * * * * php terminal schedule run
Schedule::daily('03:00', fn() => ..., 'nightly-backup');
Schedule::everyMinutes(5, fn() => ..., 'queue-drain');
```

`php terminal schedule list` shows what is registered and when each next runs. A task still
running is skipped rather than started twice.

**`cron/` is the other route and is not obsolete.** A standalone script per job, booted by
`include(__DIR__ . '/cron.php')`, with its own crontab entry. Use it when the host will not run
cron every minute — a 5/15/30-minute minimum is common on shared hosting, and `everyMinute()`
then never fires on time — or when a job wants its own process. `references/infrastructure.md`
compares them.

## Reference files

- **`references/api.md`** — exact signatures for every facade, helper, DB method, job and CLI
  helper. Check here when unsure about parameter order.
- **`references/routing.md`** — groups and prefixes (`Route::pre`, `middleware`, `noCSRF`),
  how they nest, the two ways a group leaks, the middleware contract, and what a declined
  middleware actually does. Read it before building a protected area.
- **`references/models.md`** — rows as arrays, the closures every row carries (`update`,
  `delete`, one per relation), `closureMode(false)`, migration column syntax and observers.
- **`references/caching.md`** — `Page::cache()`, what is never stored and why, per-visitor
  caching with `Vary`, and invalidation by tag. Read it before caching anything.
- **`references/validation.md`** — Request classes, every rule and what it really compares,
  and the three different things a validation failure does. Read it before adding a rule.
- **`references/auth.md`** — `Auth::attempt` and the three ways it surprises people,
  `special_columns` on the user model, cookie vs Redis session mode, and the `API` middleware
  that turns a route group into a header-authenticated API. Read it before touching login or
  writing an endpoint.
- **`references/views.md`** — the `resource/views` directory contract, which directives exist and
  which only look like they do, and how the compiler actually behaves. Read it before writing
  a template.
- **`templates/`** — working skeletons to copy rather than retype: a layer layout
  (`views/main.php`), the three page files (`views/pages/*.php`), and a filled-in resource
  controller.
- **`references/recipes.md`** — end-to-end recipes: CRUD screen, protected area, JSON API,
  creating a module, file upload, mail + queue, push notifications, localisation, going to production.
- **`references/config.md`** — every key in every config file, plus `database/connections.php`
  and what each PDO option costs.
- **`references/infrastructure.md`** — the application log, scheduled tasks, rate limiting,
  AutoSSL (ACME), the cPanel classes, the query analyzer,
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
