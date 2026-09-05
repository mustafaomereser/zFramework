# zFramework Recipes

End-to-end, copyable flows. Each one uses what the framework already ships.

---

## 1. A CRUD screen from scratch (Post)

```bash
php terminal make migration Posts --table=posts
php terminal make model Post --table=posts
php terminal make controller PostController --resource
php terminal make request Post/StoreRequest
php terminal make request Post/UpdateRequest
```

**`database/migrations/Posts.php`**
```php
namespace Database\Migrations;

class Posts
{
    static $storageEngine = "InnoDB";
    static $charset       = "utf8mb4_general_ci";
    static $table         = "posts";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'      => ['primary'],
            'user_id' => ['int:11', 'index'],
            'title'   => ['varchar:200', 'required'],
            'slug'    => ['varchar:200', 'unique:post_slug'],
            'body'    => ['longtext', 'nullable'],
            'status'  => ['tinyint:1', 'default:1'],
            'timestamps',
            'softDelete',
        ];
    }
}
```

**`App/Models/Post.php`**
```php
namespace App\Models;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class Post extends Model
{
    use softDelete;

    public $table = "posts";
    public $_not_found = 'Post not found.';

    public function beginQuery() { }

    public function author(array $row)
    {
        return $this->belongsTo(User::class, $row['user_id']);
    }
}
```

**`App/Requests/Post/StoreRequest.php`**
```php
namespace App\Requests\Post;

use zFramework\Core\Abstracts\Request;

class StoreRequest extends Request
{
    public function __construct()
    {
        $this->authorize      = false;
        $this->htmlencode     = true;
        $this->attributeNames = ['title' => 'Title', 'body' => 'Body'];
    }

    public function columns(): array
    {
        return [
            'title' => ['required', 'max:200'],
            'body'  => ['nullable'],
        ];
    }
}
```

**`App/Controllers/PostController.php`**
```php
namespace App\Controllers;

use App\Models\Post;
use App\Requests\Post\StoreRequest;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Str;

class PostController extends Controller
{
    public function __construct() { $this->post = new Post; }

    public function index()
    {
        $posts = $this->post->orderBy(['id' => 'DESC'])->paginate(20);
        return view('app.pages.posts.index', compact('posts'));
    }

    public function show($id)
    {
        return view('app.pages.posts.show', ['post' => $this->post->findOrFail($id)]);
    }

    public function create()  { return view('app.pages.posts.edit-or-create', ['post' => []]); }

    public function edit($id)
    {
        return view('app.pages.posts.edit-or-create', ['post' => $this->post->findOrFail($id)]);
    }

    public function store(StoreRequest $request)
    {
        $data = $request->validated();
        $this->post->insert($data + [
            'user_id' => Auth::id(),
            'slug'    => Str::slug($data['title']),
        ]);
        Alerts::success('Post created.');
        return redirect(route('posts.index'));
    }

    public function update($id, StoreRequest $request)
    {
        $this->post->where('id', $id)->update($request->validated());
        Alerts::success('Updated.');
        return back();
    }

    public function delete($id)
    {
        $this->post->where('id', $id)->delete();
        Alerts::success('Deleted.');
        return redirect(route('posts.index'));
    }
}
```

**`route/web.php`**
```php
Route::resource('/posts', App\Controllers\PostController::class);
```

**Views** — three files, in a directory named after the resource. Copy them from
`templates/views/pages/` rather than typing them out; the section names must match what
`app/main.php` yields (`header`, `body`, `footer`), and a mismatched name renders nothing.

```
resource/views/app/pages/posts/index.php            list
resource/views/app/pages/posts/edit-or-create.php   create AND edit, one file
resource/views/app/pages/posts/show.php             detail
```

```php
@extends('app.main')
@section('body')
    <?php foreach ($posts['items'] as $post) : ?>
        <article>
            <a href="<?= route('posts.show', ['id' => $post['id']]) ?>"><?= e($post['title']) ?></a>
            <small><?= $post['author']()['username'] ?? '-' ?></small>
        </article>
    <?php endforeach ?>

    <?php if (!$posts['item_count']) : ?>
        <p>No posts yet.</p>
    <?php endif ?>

    <?= $posts['links']() ?>
@endsection
```

Forms — `csrf()` always, `inputMethod()` for PATCH/DELETE, and the same file serves create and
edit by reading through `??`:

```php
<?php $editing = isset($post['id']); ?>
<form action="<?= route('posts.' . ($editing ? 'update' : 'store'), ['id' => $post['id'] ?? null]) ?>" method="POST">
    <?= csrf() ?>
    <?= $editing ? inputMethod('PATCH') : null ?>
    <input name="title" value="<?= e($post['title'] ?? '') ?>">
    <button>Save</button>
</form>
```

Last step: `php terminal db migrate`

---

## 2. An area behind login

```php
// route/web.php
Route::pre('/admin')
    ->middleware([App\Middlewares\Auth::class], fn($declines) => abort(403))
    ->group(function () {
        Route::resource('/posts', App\Controllers\Admin\PostController::class);
    });
```

**Pass the fallback closure.** Without it a declined middleware produces a 404, and the
middleware's own `error()` method does not run — it is only reached when you call
`Middleware::middleware()` yourself. See `references/routing.md` for that and for the two ways
a group leaks into the next one.

Your own middleware:
```bash
php terminal make middleware IsAdmin
```
```php
namespace App\Middlewares;

class IsAdmin
{
    public function attempt()
    {
        return (bool) (\zFramework\Core\Facades\Auth::user()['is_admin'] ?? false);
    }

    public function error() { abort(403); }
}
```

Also usable outside routing:
```php
Middleware::middleware([Auth::class, IsAdmin::class], fn(array $declined) => abort(403));
```

---

## 2b. A second interface layer (admin, panel, …)

"Behind login" above is a route group. A second *interface layer* is more than that: it has its
own chrome, its own error pages and its own guard. Those three ship together — a layer missing
any one of them leaks the public layout into itself.

**1. Its own layout.** Copy `templates/views/main.php` to `resource/views/admin/main.php` and
give it the admin chrome. Do not reuse or fork `app/main.php`; one layout per layer.

**2. Its own error views.**

```
resource/views/errors/admin/main.php    layout for error pages in this layer
resource/views/errors/admin/404.php     @extends('errors.admin.main')
```

Which set gets used is `Http::$error_view` (default `errors.app`). Switch it in a middleware
that runs for this layer — `App/Middlewares/ViewDirectives.php` already does this for `/admin`:

```php
if (Route::has('/admin') && Auth::check()) Http::$error_view = "errors.admin";
```

Without this, a 404 inside `/admin` renders in the public layout, which usually also means it
renders the public navigation to someone who should not see it.

**3. Its guard**, as a group — never per route:

```php
Route::pre('/admin')
    ->middleware([App\Middlewares\Auth::class, App\Middlewares\IsAdmin::class], fn($declines) => abort(403))
    ->group(function () {
        Route::resource('/posts', App\Controllers\Admin\PostController::class);
    });
```

`Route::pre()` prefixes names too, so these are `admin.posts.index`, `admin.posts.edit`, …

**4. Its pages**, under the layer's own directory, extending the layer's own layout:

```
resource/views/admin/pages/posts/{index,edit-or-create,show}.php   @extends('admin.main')
```

Controllers go under `App/Controllers/Admin/` — `php terminal make controller Admin/PostController --resource`
creates the sub-namespace for you.

---

## 3. A JSON API endpoint

```php
// route/api.php
Route::pre('/api')->middleware([App\Middlewares\API::class])->noCSRF()->group(function () {
    Route::pre('/v1')->group(function () {
        Route::get('/posts', [Api\PostController::class, 'index'])->name('api.posts.index');
    });
});
```

```php
public function index()
{
    $posts = (new Post)->closureMode(false)      // closures would serialise as "{}"
        ->select('id, title, created_at')
        ->orderBy(['id' => 'DESC'])
        ->limit(50)->get();

    return Response::json(['status' => 1, 'data' => $posts]);
}
```

If you did not use `closureMode(false)`, strip the closures off each row:
```php
$clean = array_map(fn($r) => array_filter($r, fn($v) => !$v instanceof Closure), $rows);
```

Client authentication: an `Auth-Token: {api_token}` header matched against `users.api_token`,
which populates `Auth::user()`.

---

## 4. Creating a module

```bash
php terminal module create shop
php terminal make model Product --module=shop --table=products
php terminal make controller ProductController --module=shop --resource
php terminal make migration Products --module=shop --table=products
php terminal db migrate --module=shop
```

Skeleton: `modules/Shop/{info.php, route/web.php, Controllers/, Models/, migrations/, views/}`

`info.php`:
```php
return [
    'status'            => true,
    'name'              => 'shop',
    'description'       => '',
    'author'            => '',
    'created_at'        => '2026-01-01 00:00:00',   // filled in by `module create`
    'framework_version' => '…',                     // filled in by `module create`
    'module_version'    => '0.0.0',
    'sort'              => 3,
    'callback'          => function () {
        $GLOBALS['menu']['shop'] = ['icon' => 'fad fa-store', 'title' => 'Shop', 'route' => route('shop.index')];
    }
];
```

Disable a module with `'status' => false`.
Module views: `view('Shop.views.pages.index')` - the module segment is capitalised, as the directory is (Linux is case-sensitive).

---

## 5. File upload

```php
$path = File::upload('/uploads/posts', $_FILES['cover'], [
    'accept' => ['jpg', 'jpeg', 'png', 'webp'],
    'size'   => 5 * 1024 * 1024,
]);
if ($path === false) { Alerts::danger('Upload failed.'); return back(); }

File::resizeImage(public_dir($path), ['width' => 1200, 'desired_sizes' => true]);
File::convertImage(public_dir($path), 'webp');
```

A multiple input (`name="photos[]"`) returns an array. Delete with `File::delete($path)`.
Fetch a remote file: `File::save('/uploads', 'https://.../a.jpg')`.

Do not hand-roll `move_uploaded_file` — MIME validation, path-traversal protection and directory
permissions are already handled inside `File::upload()`.

---

## 6. Mail + queue

```php
Mail::to('user@example.com')->cc('manager@example.com')->send([
    'subject' => 'Welcome',
    'body'    => view('mails.welcome', ['user' => $user]),   // view returns the markup
]);
```

With `'queue' => true` in `config/mail.php`, `send()` enqueues and returns `true` meaning
**"queued"**, not "delivered"; `php terminal queue work` does the delivery (needs Redis). Use
`Mail::sendNow()` to bypass it.

For heavy work inside a request, push it past the response:
```php
Defer::after(function () use ($id) { /* heavy work */ }, 'post-indexing');
```

---

## 7. Push notifications (web push)

```bash
php terminal push-notification test              # run this first (RFC 8291 check)
php terminal push-notification keys app          # paste the output into config/push-notification.php
```

```php
PushNotification::toUser($userId)->send([
    'title' => 'New comment',
    'body'  => 'Someone commented on your post.',
    'url'   => route('posts.show', ['id' => $postId]),
]);

// Filters combine; all of them must match
PushNotification::app('shop')->toTopic('billing')->toUser([3, 9])->send([...]);
PushNotification::toAll()->urgency('high')->ttl(3600)->collapse('basket')->send([...]);
PushNotification::toAll()->send('Deploy finished');        // a string is the title
```

Chain methods: `app(string) toUser(int|array) toTopic(string|array) toSubscription(array)
toAll() ttl(int) urgency('very-low|low|normal|high') collapse(string) send(array|string)`.
The payload limit is **4078 bytes** after encryption — send an identifier and let the service
worker fetch the rest.

Endpoints already exist: `/push-notification/config|subscribe|unsubscribe`.
Client side: `public_html/assets/js/push-notification.js` + `service-worker.js`.
A service worker only registers over **https** or on **localhost**. Details: README §21.

---

## 8. Localisation

```
resource/lang/tr/lang.php   ['greeting' => 'Merhaba, {name}!']
resource/lang/en/lang.php   ['greeting' => 'Hello, {name}!']      // placeholders are {name}, not :name
```
```php
_l('lang.greeting', ['name' => 'Ali']);
Lang::locale('tr');            // switch the active locale (also writes the cookie)
Lang::currentLocale();
```
New language: add it to `config/languages.php` and create `resource/lang/{code}/`.
Default comes from `config/app.php` → `'lang'`.

---

## 9. Model events with an Observer

```bash
php terminal make observer PostObserver
```
```php
class PostObserver extends Observer
{
    public function oninsert(array $sets): array
    {
        $sets['slug'] = Str::slug($sets['title']);
        return $sets;                       // RETURN the array, otherwise the data is lost
    }

    public function oninserted(array $args) { GlobalCache::remove('post_count'); }
    public function onupdate(array $sets): array { return $sets; }
    public function onupdated(array $args) { }
    public function ondelete(array $args) { }
    public function ondeleted(array $args) { }
}
```
Attach it on the model: `public $observe = PostObserver::class;`

---

## 10. Picking a cache

| Situation | Use |
|---|---|
| Per-user, short lived | `Cache::cache('key', fn() => ..., 60)` |
| Shared by all users, single server | `GlobalCache::cache('key', fn() => ..., 300)` (APCu) |
| Multiple servers / queue needed | `Redis::` |
| Page counts (paginate) | `paginate(20, 'page', cache_id: 'pub_posts')` |

---

## 11. Going to production (short list)

```bash
php terminal security key --regen        # on a fresh install
php terminal db migrate --seed
php terminal route cache                 # closure routes keep only their own file live
php terminal cache clear views
```
- `config/framework.php` → `'debug' => false`, `'force-https' => true`, `'x-powered-by' => false`
- `config/framework.php` → `view.caching = true`, `view.minify = true`, `route.caching = true`
- `database/connections.php` → write an **IP, not a hostname** (`localhost` tries IPv6 before
  IPv4 and doubles the wait on a failed connection), and lower `ATTR_TIMEOUT`
- Enable APCu (GlobalCache uses it)
- Full list: README §19, and §20 for RoadRunner

---

## 12. Measuring and diagnosing

```bash
php terminal bench run       # boot + request cost
php terminal route list      # the registered route table
php terminal state check     # statics that would leak across requests in a worker
```
`$model->sqlDebug(true)` dumps the executed SQL plus `EXPLAIN ANALYZE`.
`@dump($x)` / `@dd($x)` in views; `dump()` in PHP.
The Profiling module records real requests (`modules/Profiling`).

## 13. PostgreSQL instead of MySQL

Change the DSN, nothing else: `'local' => ['pgsql:host=127.0.0.1;port=5432;dbname=app', 'user', 'pass', 'options' => [...]]`
(no `ATTR_EMULATE_PREPARES` - inserts come back via RETURNING). `php terminal db migrate --fresh --seed`
writes the same migrations in PostgreSQL's dialect (JSONB, IDENTITY, TIMESTAMP, an `onupdate` trigger).
`db backup`/`db restore` stay MySQL-only: use `pg_dump`/`psql`. Run `php terminal tests run pgsql`
against the connection to see the whole query surface pass.

## 14. A collection beside the tables (MongoDB)

```php
// database/mongoconnections.php
return ['mongo' => ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'app']];
```

```bash
php terminal make mongomodel Log --table=logs
```

```php
class Log extends MongoModel
{
    public $collection = 'logs';
    public function user($row) { return $this->belongsTo(User::class, $row['user_id']); }   // User is the MySQL model
    public function indexes(): array { return [['key' => ['user_id' => 1, 'at' => -1]]]; }
}

(new Log)->insert(['user_id' => Auth::id(), 'at' => time(), 'text' => '...']);
(new Log)->where('user_id', Auth::id())->orderBy(['at' => 'DESC'])->with('user')->paginate(20);
```

`php terminal mongo indexes` after deploy. The other direction works too - `User::logs()` as
`hasMany(Log::class, $row['id'], 'user_id')` - relations cross the store.

## 15. Writing a test for the feature you just built

```bash
php terminal tests make posts        # tests/posts.php skeleton
php terminal tests run posts
```

Plain PHP: `test('name', fn)` with `same()/truthy()/contains()/throws()`; DB work on `Test::db()`
with `Test::table('x')` (= `zf_test_x`) tables dropped in `Test::cleanup()`. See `tests/db.php`
and `tests/http.php` (a real `php -S` + curl round-trip) for the two shapes.

## 16. Live updates on the open page (Pusher Channels)

```php
Pusher::trigger('orders', 'created', $order);                        // default app, sent after the response
Pusher::trigger('orders', 'created', $order, request('socket_id')); // not back to the page that caused it
Pusher::app('admin')->trigger('audit', 'login', ['who' => Auth::id()]);
```

```html
<script src="/assets/js/pusher.js"></script>
<script>
LivePusher.on('orders', 'created', o => addRow(o));
LivePusher.on('private-orders-<?= Auth::id() ?>', { shipped: fn });   // signed by /pusher/auth (Auth middleware)
</script>
```

Credentials in `config/pusher.php` `apps`; `php terminal pusher status` and `pusher test` before
blaming the page. Private/presence policy lives in `App/Controllers/PusherController.php::auth()`.
Not to be confused with push notifications (`PushNotification::`, README §21), which reach a user
whose tab is closed.
