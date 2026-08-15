# zFramework Infrastructure

The parts that are not day-to-day application code: certificates, hosting control panel,
query analysis, long-running workers, backups, releases.

---

## AutoSSL — ACME v2 / Let's Encrypt

`zFramework\Core\Helpers\AutoSSL`. Implements the protocol directly; no shell, no certbot.

```php
$ssl = new AutoSSL(AutoSSL::PROD);                    // or AutoSSL::STAGING while testing
$ssl = new AutoSSL(AutoSSL::PROD, 'D:\xampp\apache\conf\openssl.cnf');   // custom openssl.cnf
```

```php
// Account
ensureAccount(): string          // creates the ACME account if there is none
unlinkAccount(): void            // removes the local account files

// Inventory
list(): array                    // locally tracked certificates
checkSSL(string $domain): array  // days remaining
renewAll(): void                 // renews everything under 20 days

// The whole flow in one call (no wildcards)
issue(array $domains, string $challenge_type = "http-01"): array
// → ['cert' => ..., 'ca_bundle' => ..., 'private' => ...]

// Step by step (required for dns-01 / wildcards)
prepareDomain(string $domain): array
newOrder(array $domains = []): array
challenge(array $authorizations, string $type = "http-01"): array
publishChallenge(array $challenge): void       // writes the http-01 file itself
notifyChallenge(array $challenge): string
challengeAuth(string $authUrl, int $tries = 1): array
finalize(array $order, array $domains): array
getCertificate(array $order, string $domainKey, int $tries = 1): array
download(string $domain): array
```

**http-01** — no wildcard support; the framework drops the challenge file into
`.well-known/acme-challenge/` on its own:
```php
$cert = $ssl->issue(['example.com', 'www.example.com'], 'http-01');
```

**dns-01** — supports wildcards, TXT records must exist before finalising:
```php
$order   = $ssl->newOrder(['example.com', '*.example.com']);
$records = $ssl->challenge($order['authorizations'], 'dns-01');
// $records: [['domain' => '_acme-challenge.example.com', 'value' => '...'], ...]
// create each as a TXT record, wait for propagation, then:
foreach ($records as $c)                  $ssl->notifyChallenge($c);
foreach ($order['authorizations'] as $a)  $ssl->challengeAuth($a['url']);
$finalized = $ssl->finalize($order, ['example.com', '*.example.com']);
$cert      = $ssl->getCertificate($order, $finalized['domainKey']);
```

Use `AutoSSL::STAGING` while developing — production has rate limits that lock you out for a week.
Install the result on cPanel with `cPanel\SSL::install()` below.

---

## cPanel API — `zFramework\Core\Helpers\cPanel\*`

Everything goes through `API::request(string $endpoint, array $params = [], array $post = []): ?array`.
All methods are static and return `?array` (`null` on failure).

### Domain
```php
Domain::list()          Domain::data()          Domain::aliases()      Domain::primaryDomain()
Domain::addSubdomain(string $name, string $root = "/public_html")
Domain::deleteSubdomain(string $name)

Domain::listDNSRecords(string $domain)
Domain::addDNSRecord(string $domain, string $type, string $name, string $address, int $ttl = 3600)
Domain::editDNSRecord(string $domain, int $line, string $type, string $name, string $address, int $ttl = 3600)
Domain::deleteDNSRecord(string $domain, int $line)
```
DNS records are addressed by **line number**, and the numbers shift after a delete — re-list
before editing.

### Database / DatabaseUser
```php
Database::info()        Database::list()        Database::check(string $name)
Database::create(string $name)                  Database::createRandom(string $prefix = "")
Database::rename(string $old, string $new)      Database::repair(string $name)
Database::dump_schema(string $name)             Database::update_privileges()
Database::delete(string $name)

DatabaseUser::list()                            DatabaseUser::create(string $name, string $password)
DatabaseUser::rename(string $old, string $new)  DatabaseUser::delete(string $name)
DatabaseUser::setPassword(string $user, string $password)
DatabaseUser::privileges(string $user, string $database)
DatabaseUser::grantPrivileges(string $user, string $database, ?array $privileges = null)
DatabaseUser::revokePrivileges(string $user, string $database)
DatabaseUser::routines(?string $user = null)
```

### Email
```php
Email::list()                                   Email::create(string $email, string $password, int $quota = 250)
Email::changePassword(string $email, string $password)
Email::delete(string $user)
Email::listForwarders()                         Email::addForwarder(string $email, string $destination)
Email::deleteForwarder(string $email, string $destination)
```

### Cron
```php
Cron::list()                                    Cron::create(string $time, string $command)
Cron::edit(int $lineKey, string $time, string $command)
Cron::delete(int $lineKey)
```
Same line-number caveat as DNS records.

### Fileman
```php
Fileman::list(string $path = "/")               Fileman::upload(string $dir, array $files = [])
Fileman::create_folder(string $path)            Fileman::delete_file(string $path)
```

### SSL
```php
SSL::AutoSSLStatus()                            SSL::StartAutoSSLCheck()
SSL::install(string $domain, string $cert, string $key, string $cabundle = "")
```
Pairs with AutoSSL: `$c = $ssl->issue([...]); SSL::install($domain, $c['cert'], $c['private'], $c['ca_bundle']);`

---

## Application log — `Log::`

```php
Log::debug(string $message, array $context = []): void
Log::info(...)   Log::warning(...)   Log::error(...)
```

One file per day at `storage/logs/Y-m-d.log`, appended with `LOCK_EX` so concurrent requests
do not interleave a line. Context is appended as JSON:

```
[2026-08-15 07:02:22] WARNING: Page not cached: it contains a csrf token. {"url":"/"}
```

**This is not the error handler.** Uncaught throwables still go through `errorHandler()` and
its error page. `Log` is for what you want to read at 03:00 that was never an exception - a
refused payment, a webhook that arrived twice, a scheduled task that failed.

```php
// config/framework.php
'log' => [
    'enabled' => true,
    'level'   => 'debug',   // debug | info | warning | error - below it is dropped
                            // before the message is formatted
    'days'    => 14,        // pruned on the first write of a process; 0 keeps everything
],
```

Costs a request that never logs nothing: the class is referenced from nowhere in the request
path, so it is never autoloaded. That is also why `Log::$config` and `Log::$dir` are boot
state rather than request state - putting `Log` in `REQUEST_STATE` would load the file on
every worker request to clear two values that are identical every time.

## Scheduled tasks — `Schedule::`

One crontab line drives everything:

```
* * * * * cd /path/to/app && php terminal schedule run >> /dev/null 2>&1
```

Tasks live in `schedule.php` at the project root:

```php
use zFramework\Core\Facades\Schedule;

Schedule::everyMinute(fn() => ..., 'name');
Schedule::everyMinutes(5, fn() => ..., 'name');
Schedule::hourly(15, fn() => ..., 'name');            // every hour at :15
Schedule::daily('03:00', fn() => ..., 'name');
Schedule::weekly(1, '09:00', fn() => ..., 'name');    // Mondays, 0 = Sunday
Schedule::monthly(1, '00:30', fn() => ..., 'name');   // the 1st
Schedule::cron('*/5 9-17 * * 1-5', fn() => ..., 'name');
```

The cron parser takes the five standard fields with `*`, `*/n`, `a,b` and `a-b`; day-of-week
is 0-6 with 0 as Sunday, and 7 also accepted.

```bash
php terminal schedule run     # everything due this minute - also how you test a task
php terminal schedule list    # what is registered, and when each next runs
```

Two things a raw crontab does not do:

- **A task still running from the previous tick is skipped**, not started again. Two copies of
  a backup are worse than a late one.
- **A task will not run twice in the same minute**, however many times `schedule run` is
  invoked.

A task that throws is caught, logged through `Log::error` and does not stop the others.

Nothing here is reachable from a web request - `schedule.php` is included by the terminal
command and by nothing else, so a served request pays nothing for it.

## Rate limiting — `RateLimit::` and the `Throttle` middleware

```php
RateLimit::hit(string $key, int $limit, int $window): array   // allowed, count, remaining, retry_after
RateLimit::clear(string $key): void
```

Counters go to redis when it is configured and reachable, where `INCR` makes the count atomic
across every worker and machine; otherwise to one `flock`'d file per key under
`storage/ratelimit`, which is what a shared host has. Fixed window, not sliding - a caller can
send up to twice the limit across a boundary, and buying accuracy past that costs a sorted set
and a read of it on every request.

**Which routes are limited is decided by where you attach the middleware:**

```php
Route::pre('/api')->middleware([Throttle::class, API::class])->noCSRF()->group(...);
Route::middleware([Throttle::class])->group(fn() => Route::post('/sign-in', ...));
```

**How hard, by config:**

```php
'throttle' => [
    'enabled' => true,
    'limit'   => 60,
    'window'  => 60,
    'by'      => 'ip',       // ip | token - `token` counts a logged-in caller by identity
    'rules'   => [           // per url prefix, longest match wins
        '/api'     => ['limit' => 120],
        '/sign-in' => ['limit' => 5, 'window' => 300],
    ],
],
```

`Throttle` **answers 429 itself** rather than declining. A declined middleware with no fallback
closure ends as a 404 (see `references/routing.md`), and a 404 is the wrong answer to "you are
going too fast". The body is JSON, because there is no `errors/*/429` view and a 429 is read by
retry logic more often than by a person:

```json
{"status": 429, "message": "Too many requests. Try again in 59 seconds.", "try_again_in": 59}
```

Sends `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `Retry-After` with it.

**Order it first in the list.** The response is a `ResponseSignal`, which unwinds out of the
middleware loop, so a caller over the limit never reaches whatever follows. On the API group
that skips the `Auth-Token` lookup entirely - measured: limit 2, four requests,
`API::attempt()` ran twice.

Call `RateLimit::clear('login:' . ip())` after a successful login, so a few failed attempts do
not keep counting against someone who then got it right.

## Query Analyzer

Runs `EXPLAIN` / `EXPLAIN ANALYZE` on SELECTs and records what they touch: tables scanned,
indexes used, missing-index suggestions.

```php
zFramework\Core\Facades\DB\Analyzer\Analyze::init()
DbCollector::isSelect(string $sql): bool
DbCollector::analyze(DB $db, string $sql, array $data, float $queryTime): void
DbCollector::fingerprint(string $sql): string     // groups the same query shape together
```

Driven entirely by `config/framework.php` → `profiling.queryAnalyze` (`true` / `false` / a
sampling fraction) and `profiling.queryStore`:

- `'file'` → `analysis/queries/<id>.jsonl`, one line per query, needs nothing to exist.
- `'table'` → rows in `system_db_collector`; run `php terminal db migrate` first, and note that it
  writes into the same database it is measuring.

Requires `app.debug`, so production is unaffected either way. **An analysed query is executed a
second time to measure it** — it costs roughly twice what it reports.

Per-query alternative, no config needed: `$model->sqlDebug(true)`.
Skip a model entirely with `$model->ignoreAnalyze = true`.

---

## Profiling module

`modules/Profiling` records real requests and serves them back at `/profiling`, grouped by URL.
Disabling the module stops recording regardless of config.

```php
Recorder::begin()   Recorder::write()   Recorder::all()   Recorder::summary()
Recorder::clear(): int          Recorder::directory(): string

Profiler::listen(?\Closure $collector)   Profiler::active(): bool
Profiler::mark(string $stage, float $nanoseconds)
```

Config lives in `config/framework.php` → `profiling`: `enabled`, `rate` (1 = every request,
0.05 = one in twenty), `keep` (stop after N records — old records are the baseline, so nothing is
deleted to make room; clear them at `/profiling` after a deploy).

`Profiler` is behind a `class_exists(..., false)` guard, so it does not load at all on requests
that do not profile. Do not remove that guard.

---

## RoadRunner / long-running workers

Config `.rr.yaml`, worker `zFramework/Kernel/worker.php`, which defines `ZF_WORKER`.

```bash
php terminal run roadrunner          # serve
php terminal run roadrunner reset    # reload workers - REQUIRED after a deploy
php terminal run roadrunner workers  # pid, memory, requests served
php terminal run roadrunner stop
```

The binary is looked up in the project root, then `zFramework/vendor/bin/`, then PATH.
Keep it behind nginx in production (TLS + static files); same vhost as FPM with `proxy_pass`
instead of `fastcgi_pass`.

### What changes for application code

- **State does not reset by itself.** `Run::resetState()` clears the request-scoped things (user,
  session, language, mail recipients, matched route) after each request; framework classes do it in
  their own `flushRequestState()`. **Statics in your code are yours to clear.** A static that
  survives a request is not a slow leak — it is one visitor being served another's data.
- **`die()` / `exit` kill the worker, not the request.** The framework throws `ResponseSignal`
  instead; `abort()`, `redirect()`, `refresh()` and downloads all go through it. Do not call
  `die`/`exit` in application code.
- **`header()` and `setcookie()` do nothing under CLI**, which is what a worker runs as. Use
  `Response::header()` and `Cookie::set()` — the worker attaches them to the response. A direct
  `header()` call works under FPM and silently disappears here.
- **Routes are registered at boot.** `route/dynamic/` is re-evaluated per request under FPM but
  runs once at worker startup, so per-request conditions belong in middleware.

```bash
php terminal state check    # statics that would leak between requests
```

Walks `zFramework/Core`, reads each `flushRequestState()` to see what it actually assigns, and
reports anything neither cleared nor declared as deliberate boot state in `Kernel/Modules/State.php`.
Not everything it reports is a bug — the route table and DB handles survive on purpose. Run it
before a release and after adding a static to a framework class.

`.rr.yaml` worth knowing: `pool.num_workers` ≈ cores × 2, `pool.max_jobs: 5000` recycles workers as
leak insurance, `supervisor.max_worker_memory: 256`, `exec_ttl: 30s`, metrics on `127.0.0.1:2112`
(watch the restart rate — a climbing one means a leak or a fatal loop).

---

## `route/dynamic/`

Executed on every request and **never written into the route cache**. The cache is a CLI-time
snapshot where nobody is logged in, so a route wrapped in `if (Auth::check())` would be frozen as
"not registered" and 404 for everyone.

Most conditions around a route are access control and belong in middleware — the route exists,
permission is decided per request, and the cache still works. Use this directory only when a route
genuinely must not exist for some requests: per-tenant flags, licence-gated modules. Keep the
conditions cheap; every request pays for them.

---

## Backup, release, benchmark

```bash
php terminal db backup [--compress] [--separate]    # --separate: one file per table
php terminal db restore [--db=x]                    # interactive
php terminal release make [--name=x] [--date=Y-m-d] [--minify]   # zip of changed files since a date
php terminal bench run                              # boot + request cost on this machine
```

`MySQLBackup` is usable directly: `(new MySQLBackup($db, $config))->backup()`.

---

## Error handling

- `zFramework/modules/error_handlers/loader.php` defines a thin `errorHandler()`; the 68 KB
  `handle.php` renderer loads only when an error actually occurs.
- **Never echo what `errorHandler()` returns** — `handle.php` already prints it. Correct form:
  `errorHandler($err); die;`
- `config/app.php` → `error.logging` writes HTML files under `error_logs/`; `error.stream`
  (`false` | `'error_log'` | `'stderr'` | `'syslog'`) also emits a one-line summary, which is what
  you want as soon as more than one machine serves the site.
- `error.callback` receives `($log_path, $log)`. The shipped one dies on CLI **unless `ZF_WORKER`
  is defined** — dying in a worker would kill the worker rather than end the request.
- Suggestion files map driver error codes to advice:
  `zFramework/modules/error_handlers/suggestions/<code>.php` (1000, 1001, 42S02 exist).
