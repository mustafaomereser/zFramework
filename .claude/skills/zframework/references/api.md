# zFramework API Inventory

Signatures extracted from the source. Verify here instead of guessing.

## Global helpers (`zFramework/modules/Functions.php`)

```php
// Paths
base_path(?string $add)            // absolute path from the project root
public_dir(?string $add)           // filesystem path of the public directory
public_path(?string $add)
asset(string $file)                // full URL with ?v=filemtime
path_fix(string $path)
host()                             // scheme + host
script_name(); uri();              // current URI (script name stripped)

// Navigation (all of these die)
redirect(string $url = "/");
back(?string $add);                // back to REFERER, with an optional suffix
refresh();
abort(int $code = 418, $message = null);   // JSON on AJAX

// Request
method();                          // honours the _method override in POST
inputMethod(string $method = "GET");        // <input type="hidden" name="_method">
request(?string $name, $val = null);        // read / whole array / write
getQuery(array $adds = [], array $except = [], bool $string = true);
ip();
getBrowser();                      // ['name','version','platform',...]

// Shortcuts
view(...); route(...); config(...); _l(...); csrf();
e($value, bool $emptycheck = false);        // htmlspecialchars, '-' when empty
globals(string $name, $value = null);

// Filesystem
findFile($file, $ext = null, $path = null);
scan_dir($dir); rrmdir($dir);
file_put_contents2($file, $content, $flags = 0);   // creates the directory

// Misc
dump(...$vars); hl(mixed $v, int $d = 0);
secondsToHours($seconds);
is_https_supported(string $host);
MySQLcreateDatabase($host, $dbname, $user, $pass, $name);
```

## Route — `zFramework\Core\Route`

```php
Route::any|get|post|patch|put|delete(string $url, $callback)   // chainable with ->name()
Route::resource(string $url, string $controller)
Route::redirect(string $url, string $to, int $status = 302)
Route::name(string $name)                    // names the last route
Route::find(string $name, array $data = [], bool $return_bool = false)   // = route()
Route::has(string $keyword): bool                 // current request URI contains $keyword (not a table lookup)
Route::pre(string $prefix, ?string $namePrefix = null)          // url + name prefix
Route::middleware(array $list, $callback = null)
Route::noCSRF()
Route::throttle(?int $limit = null, ?int $window = null, ?string $by = null, ?int $block = null)
Route::group(\Closure $callback)
```

Url parameters are `{id}` (required) or `{?id}` (optional), and may carry a type:
`{id:int}` - `int uint float alpha alnum slug uuid`. Omitting it is exactly what it was.
See `references/routing.md`.

**Groups, prefixes, the middleware contract and the two ways a group leaks are in
`references/routing.md`.** Signatures here; behaviour there.

Resource mapping:

| Method | URL | Controller |
|---|---|---|
| GET | `/` | `index` |
| GET | `/create` | `create` |
| GET | `/{id}` | `show` |
| GET | `/{id}/edit` | `edit` |
| POST | `/` | `store` |
| PATCH/PUT | `/{id}` | `update` |
| DELETE | `/{id}` | `delete` |

Controller methods receive route parameters as arguments; type-hinted `Request` subclasses are
validated and injected automatically.

## DB / Model — `zFramework\Core\Facades\DB`, `Abstracts\Model`

### Model properties

```php
public $table; public $db; public $primary; public $guard = [];
public $created_at; public $updated_at; public $deleted_at; public $deleted_at_type;
public $observe;                    // Observer class
public $special_columns;            // for Auth (email/password/passwordencode)
public $_not_found = 'Not found.';  // message for firstOrFail/findOrFail
public function beginQuery()        // prepended to every query
```

### Building a query

```php
select(string|array $select)
where(...)          whereOr(...)      whereNot(...)     whereOrNot(...)
having(...)         havingOr(...)     havingNot(...)    havingOrNot(...)
whereIn(string $column, array $in, string $prev = "AND")     // [] -> `1 = 0`, matches nothing
whereNotIn(string $column, array $in, string $prev = "AND")  // [] -> `1 = 1`, matches everything
whereBetween(string $column, $start, $stop, string $prev = 'AND')
whereNotBetween(string $column, $start, $stop, string $prev = 'AND')
whereRaw(string $sql, array $data = [], string $prev = "AND")     // named bindings
join(string $type, string $model, string $on = "")                // INNER/LEFT/RIGHT/FULL OUTER
orderBy(array $data)     // ['created_at' => 'DESC']
groupBy(array $data)
limit(int $startPoint = 0, $getCount = null)
withRealOrder(string $as = 'real_order', string $direction = 'DESC')
fetchType(?string $type)          // 'unique' | 'keypair'
closureMode(bool $mode = true)    // false → do not bind relation closures to rows
sqlDebug(bool $mode)
```

`where()` is variadic: `where('a', 1)`, `where('a', '>', 1)`, or grouped
`where([['status','published'], ['views','>',50,'OR']])`.

### Running it

```php
get(): array            first(): array          count(): int
find(string $value)      findOrFail(string $value)
firstOrFail(mixed $exception = null)
insert(array $sets, bool $just_insert = false): array|int
update(array $sets): int          delete(): int
updateOrInsert(array $sets)     // scope it with where() first: no where = first row found, every row updated
getPrimary(): ?string           // the table's primary key column (model $primary, else schema)
paginate(int $per_page = 20, string $page_id = 'page', ?string $cache_id = null): array
prepare(string $sql, array $data = []): object     // raw PDO statement
```

`paginate()` returns: `items, item_count, shown, start, per_page, page_count, current_page,
links` (`links` is a closure: `$r['links']()` or `$r['links']('partials.pagination')`).

### Schema / connection

```php
table(string $table)     columns(): array        columnsLength(): array
compareColumnsLength(array $data): array         forgetScheme()
connection(): object|bool
beginTransaction()  commit()  rollback()          // requires InnoDB
```

### Relations (`Traits\DB\RelationShips`)

```php
with(string ...$relations)
hasOne / hasMany (string $model, $value, ?string $column = null)   // $value null -> null / []
belongsTo(string $model, $value, ?string $column = null)          // $value null (nullable FK) -> null
belongsToMany(...)            belongsToManyWithPivot(...)
hasManyThrough(...)           hasOneThrough(...)
morphOne / morphMany (string $model, string $morphName, $value, ?string $type = null)
morphTo(array $values, string $morphName)
morphToMany(...)              morphedByMany(...)
hasManyCount(string $model, $value, ?string $column = null): int
hasRelation(string $model, $value, ?string $column = null): bool
findRelation(string $model, string $value, ?string $column = null)

// Pivot
attach($pivotTable, $foreignKey, $foreignValue, $relatedKey, $relatedValue, array $extra = [])
detach($pivotTable, $foreignKey, $foreignValue, ?$relatedKey = null, ?$relatedValue = null)
sync($pivotTable, $foreignKey, $foreignValue, $relatedKey, array $relatedValues, array $extra = [])
toggleAttach($pivotTable, $foreignKey, $foreignValue, $relatedKey, $relatedValue, array $extra = [])
```

`use zFramework\Core\Traits\DB\softDelete;` enables soft deletes. Behaviour comes from
`config/model.php` (`deleted_at_type`: `'date'` or `'bool'`).

## Facades

Most of what follows is `zFramework\Core\Facades\<Name>`. Thirteen are not, and the
autoloader turns the namespace straight into a path, so the wrong one is a fatal:
`Csrf`, `Crypter`, `GlobalCache`, `Cache`, `Validator` and `Middleware` sit in `zFramework\Core\`,
while `File`, `Folder`, `Assets`, `_Array`, `Http`, `Date` and `AutoSSL` are `zFramework\Core\Helpers\`.

### Auth

**`Auth::attempt` semantics, `special_columns`, the two session modes and the API
middleware are in `references/auth.md`.** Signatures here; behaviour there.

```php
Auth::attempt(array $fields = [], bool $staymein = false): bool
Auth::login(array $user): bool          Auth::token_login(string $token): bool
Auth::check(): bool                     Auth::user()
Auth::id(): ?int                        Auth::logout(): bool
Auth::model(): User                     Auth::encodePassword(?string $plain)
Auth::forgetCache(string|int|null $id = null)
```

### Session / Alerts / Cookie / JustOneTime
```php
Session::set(string $key, mixed $value): self
Session::get(string $key): mixed        Session::delete(string $key): self
Session::flush()                        Session::callback(\Closure $cb): mixed

Alerts::success|danger|warning|info(string $text): self
Alerts::name(string $name): self        // a separate channel
Alerts::get(bool $unset_after_get = false): array    // [[$type, $message], ...]
Alerts::unset()

Cookie::set(string $key, string $value, ?int $expires = null): bool
    // HttpOnly, SameSite=Lax; Secure when the request is https (Cookie::$options['security'] forces it).
    // PHPSESSID gets the same flags. Nothing a framework cookie holds is readable from javascript.
Cookie::get(string $key)                Cookie::delete(string $key): bool

JustOneTime::set(string $name, mixed $value): self   // lives for one request
JustOneTime::get(string $name): mixed
```

### Response
```php
Response::json(array $data, ?string $flags = null)
Response::status(?int $code = null): int
Response::header(string $name, string $value, bool $replace = true): void
Response::headers(): array
Response::dropHeader(string $name): void
Response::cacheTtl(?int $set = null): int      // set by Page::cache(), read by the store
Response::cacheName(?string $set = null): ?string
Response::header(string $name, string $value, bool $replace = true)
Response::status(?int $code = null): int
Response::addination(string $key, mixed $data)     // attach an extra field to the JSON response
```

### Cache
```php
Cache::cache(string $name, $callback, int $timeout = 5)       // session-scoped (per user)
Cache::remove(string $name): bool      Cache::clear(): bool

GlobalCache::cache(string $name, \Closure $cb, ?int $timeout = null)   // APCu, all requests
GlobalCache::apcu(): bool              GlobalCache::remove(string $name): bool
GlobalCache::clear(): bool             // this install's entries only, L1 and L2 both

Redis::available(string $for = 'cache'): bool
Redis::get/set/delete(string $key, ..., string $for = 'cache')
Redis::push/pop/size(string $key, ..., string $for = 'queue')
```

### Page — full-page and HTTP caching
```php
Page::cache(?int $seconds = null, bool $shared = true, ?string $name = null): void
Page::noCache(): void
Page::vary(string ...$headers): void          // Vary header; takes the response out of the store
Page::forget(string $name): int               // every entry tagged $name
Page::forgetUrl(string $url, string $method = 'GET'): bool
Page::clear(): int

// A served entry replays the headers it was stored with, plus an Age telling the
// browser and any proxy how much of the max-age window is already spent.
// store() refuses a body carrying a csrf token in any shape it can see -
// name="_token", name='_token', or a `csrf-token` meta tag.
```
Live by default; nothing is stored unless a page declares it. Never stores a non-GET, a
request with an auth cookie, a non-200, a body with a csrf token, or anything private or
varying. Detail and the invalidation patterns: **`references/caching.md`**.

### Log
```php
Log::debug|info|warning|error(string $message, array $context = []): void
```
`zFramework/storage/logs/Y-m-d.log`. Not the error handler - see `references/infrastructure.md`.

### RateLimit
```php
RateLimit::hit(string $key, int $limit, int $window, int $block = 0): array
        // allowed, blocked, count, remaining, retry_after
RateLimit::clear(string $key): void
```
Usually reached through `Route::throttle()`, which carries the numbers and attaches the
`App\Middlewares\Throttle` middleware in one call.

### Schedule
```php
Schedule::cron(string $expression, \Closure $job, string $name): void
Schedule::everyMinute|everyMinutes|hourly|daily|weekly|monthly(...)
Schedule::run(?callable $report = null): int      // used by `php terminal schedule run`
Schedule::due(string $expression, ?int $at = null): bool
Schedule::tasks(): array
```
Registered under `schedule/` at the project root - every `.php` file there is loaded - and
driven by one crontab line.

### Queue / Defer
```php
Queue::push(array|string $job, array $payload = [], string $queue = 'default'): bool
Queue::pop(string $queue = 'default', int $timeout = 5): ?array
Queue::size(string $queue = 'default'): int
Queue::run(array $entry)                Queue::retry(array $entry, int $maxAttempts = 3)
// worker: php terminal queue work {queue}
// shipped jobs: zFramework\Core\Jobs\SendMail, SendPushNotifications

Defer::after(\Closure $job, string $label = '')   // runs after the response is sent
Defer::pending(): bool                  Defer::flush()
```

### Mail
```php
Mail::to(string $mail): self   ->cc(...)  ->bcc(...)  ->clearTo()/clearCc()/clearBcc()
Mail::set(array $mailConfig)                     // override config/mail.php
Mail::send(array $data): bool                    // queues it when queueing is enabled
Mail::sendNow(array $data): bool                 // always immediate
```

### Str / Date / Lang / Config / Crypter / Csrf
```php
Str::limit($text, 50, '...')      Str::wordLimit($text, 3, '...')
Str::rand(int $length = 5, bool $unique = false)
Str::slug($text, '-')             Str::base64UrlEncode($binary)   Str::base64UrlDecode($text)

Date::now('d.m.Y H:i')            Date::format(string|int|null $date, 'd.m.Y')   // a date string, or a unix timestamp (int or numeric string)
Date::timestamp('-0 days')        Date::timeago($date)      Date::timediff($t1, $t2)
Date::setLocale(string $set)      Date::locale()

Lang::locale(?string $lang = null, bool $syncCookie = true)
Lang::currentLocale(): string     Lang::list(): array
Lang::get(string $name, array $data = [])        // = _l()

Config::get(string $config, bool $returnbool = true)   // 'app.title' dot notation
Config::debug(): bool                                   // framework.debug, or app.debug if not moved
Config::set(string $config, array $sets, bool $compare = false)
Config::exists(string $config)   Config::framework(string $key)   Config::clearCache()

Crypter::encode/decode(string)   Crypter::encodeArray(array, array $except = [])/decodeArray(array)

Csrf::get(): string   Csrf::set()   Csrf::unset()   Csrf::compare(string $token): bool
Csrf::check(bool $alwaysTrue = false): bool        Csrf::remainTimeOut(): int
```

### cURL / Http
```php
cURL::set(string $url)->post(mixed $fields = [])->headers(array $h)
    ->file($fieldname, $filename, $content, $mime = 'text/plain')
    ->options(array $opts)->send(?\Closure $callback = null)

Http::isAjax(): bool             Http::abort(int $code = 418, $message = null)
Http::wantsJson(): bool          // isAjax(), or Accept: application/json not led by text/html
```

### File / Folder / Assets / _Array
```php
File::upload(string $path, array $file, array $options = []): string|array|false
    // options: ['accept' => ['jpg','png'], 'size' => bytes]
    // a server-executable name is refused whatever accept says (File::executable())
    // shape follows the input, not the outcome: $_FILES['x'] from a single input
    // returns a string or false; from a `multiple` input, always a list.
File::save(string $path, string $file): string           // downloads a remote URL
File::download(string $file): never                     // relative to public_dir; '..' outside it -> 404
File::resizeImage(string $file, array $sizes = [], ?string $new_name = null)
File::convertImage(string $file, string $to)
File::delete(string $file): bool                        // '..' outside public_dir -> false
File::executable(string $name): bool                    // php/phtml/phar/cgi/sh/.htaccess/html/svg ..., any dotted segment
File::humanFileSize(float $bytes, int $decimals = 2)
File::removePublic(string $name): string

Folder::make/delete(string $path): bool        Folder::size(string $path): array|false

Assets::list(string $dir, array $extensions = [...])
Assets::cssMinify($css)          Assets::jsMinify($js)

_Array::paginate(array $data, int $per_page = 20, string $page_id = 'page')
_Array::compare(array $a1, array $a2, \Closure $callback): array
```

### Jobs, ResponseSignal, kernel helpers
```php
// A queue job is a class with handle(array $payload): void
zFramework\Core\Jobs\SendMail::handle(array $payload)
zFramework\Core\Jobs\SendPushNotifications::handle(array $payload)
// enqueue - a job is [Class::class, 'method'] or 'Class@method', never the class alone:
// Queue::push([SendMail::class, 'handle'], ['to' => ..., 'subject' => ...], 'default')
// Queue::run() rejects anything else with InvalidArgumentException.

// Thrown by abort()/redirect()/refresh()/downloads instead of die().
// Extends \Error, not \Exception, so a catch(\Exception) around a controller
// cannot swallow its own redirect.
new ResponseSignal(int $status = 0, array $headers = [], string $body = '')
$signal->send(): void

// CLI side
zFramework\Kernel\Helpers\Ask::do(string $question, object $callback)   // interactive prompt
zFramework\Kernel\Helpers\Module::getModules()
zFramework\Kernel\Helpers\Module::classMethods($class, $flags = ReflectionMethod::IS_PUBLIC)
zFramework\Kernel\Helpers\MySQLBackup::__construct($db, $config = []) ->backup()
zFramework\Kernel\Terminal::begin(array|string $args)  // run a command from PHP; --web for html output
    // an array is $argv (script name first); a string is one line, split like a shell would
    // (quotes group, \" escapes inside them). Only --flag=value tokens become parameters;
    // a positional argument may hold '=' and the value keeps everything past the first '=':
    // Terminal::begin('push-notification send --url=/orders?id=5 --title="two words"')
```

### Seeder — `database/seeders/`
```php
class Seeder
{
    public function __construct() { }
    public function seed()    { (new User)->insert([...]); }
    public function destroy() { (new User)->prepare('TRUNCATE users'); return $this; }  // must return $this
}
```
Run with `php terminal db seed`, or `db migrate --seed`. A migration can carry its own with
`public static function oncreateSeeder(?string $db = null)`, which runs when that table is created.

### PushNotification — `zFramework\Core\Facades\PushNotification\PushNotification`

The one facade with its own sub-namespace: the directory holds the channels and the
crypto beside it, so the class repeats its own name.

```php
PushNotification::app(string $app): self
PushNotification::toUser(int|array $users): self       toTopic(string|array $topics): self
PushNotification::toSubscription(array $subscription): self     toAll(): self
PushNotification::ttl(int $seconds): self              urgency(string $urgency): self
PushNotification::collapse(string $topic): self
PushNotification::send(array|string $payload): array
PushNotification::dispatch(string $app, array $subscriptions, array $payload, array $options = [])
PushNotification::subscribe(array $input, ?int $user_id = null, array $topics = [], ?string $app = null)
PushNotification::unsubscribe(string $endpoint, ?string $app = null): int
PushNotification::client(?string $app = null): array
```
Usage: `references/recipes.md` → "Push notifications" and README §21.

### AutoSSL, cPanel, Query Analyzer, RoadRunner
Signatures live in `references/infrastructure.md` — it covers ACME certificate issuance
(http-01 and dns-01/wildcard), the eight cPanel classes, query analysis, the worker runtime and
its state rules, backups and releases.

## View — `zFramework\Core\View`
```php
View::view(string $view_name, array $data = [])          // = view()
View::directive(string $key, $callback)
View::bind(string $view, \Closure $callback)
View::clearCache()
View::setSettings(array $config)                          // the framework sets this; leave it
```
Root: `resource/views`. `view('a.b.c')` → `resource/views/a/b/c.php`.
Module view: `Blog.views.client.pages.index` → `modules/Blog/views/client/pages/index.php` -
the module segment is spelled as the directory is, capitalised, or Linux does not find it.

**The directive list, the directory contract and the engine's real behaviour are in
`references/views.md`.** Do not write a template from memory of Blade — several directives that
look obvious (`@for`, `@csrf`, `@push`) do not exist here.

## Terminal — `php terminal <module> <command>`

```bash
# Scaffolding
php terminal make model|controller|request|middleware|migration|seeder|observer {Name}
       [--resource] [--module=blog] [--table=x] [--dbname=x]

# Database
php terminal db migrate [--fresh] [--force] [--seed] [--all] [--module=blog] [--db=x] [--path=x]
php terminal db seed [--db=x]
php terminal db backup [--compress] [--separate]
php terminal db restore [--db=x]

# Modules
php terminal module create {name}

# Routes
php terminal route cache | clear | list

# Cache
php terminal cache clear views|sessions|pages|logs|ratelimit|schedule
                                       # any directory under storage/

# Scheduled tasks (from schedule/*.php)
php terminal schedule run              # everything due this minute
php terminal schedule list             # what is registered, and when each next runs

# Framework updates - replaces the core only, never vendor/ or storage/
php terminal update [--check] [--config] [--force] [--rollback]

# Queue
php terminal queue work {queue}        php terminal queue size {queue}

# Push
php terminal push-notification keys {app} | test | send {app} --title= --body= --url= --user= --all
                              | subscribers {app} | prune {app} --failures=10

# Security / release / server
php terminal security key [--regen]
php terminal release make [--name=x] [--date=Y-m-d] [--minify]
php terminal run [--host=0.0.0.0] [--port=8080] [--opcache]
php terminal run roadrunner serve|reset|workers|stop
php terminal bench run                  # request cost measurement
php terminal state check                # reports statics that would leak in a worker
php terminal help
php terminal start                      # the banner; what runs with no command
php terminal clear                      # clear the terminal screen
php terminal test                       # print the colours Terminal::text accepts
```
