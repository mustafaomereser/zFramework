# zFramework Infrastructure

The parts that are not day-to-day application code: certificates, hosting control panel,
query analysis, long-running workers, backups, releases.

---

## AutoSSL — ACME v2 / Let's Encrypt

`zFramework\Core\Helpers\AutoSSL`. Implements the protocol directly; no shell, no certbot.

```php
$ssl = new AutoSSL(AutoSSL::PROD);                    // or AutoSSL::STAGING while testing
$ssl = new AutoSSL(AutoSSL::PROD, 'D:\xampp\apache\conf\openssl.cnf');   // custom openssl.cnf
$ssl = new AutoSSL(AutoSSL::PROD, null, $accountId);  // sign as a specific account
```

```php
// Accounts - one directory each under zFramework/storage/AutoSSL/accounts/<id>/
// (account.key + account.kid). The id is numeric, date-based, so listings sort by age.
// With no id given the constructor takes the oldest account, or registers one if none.
account(): string                // id this instance signs with
accounts(): array                // [id => [id, kid, registered, ca, usable, created, current]], oldest first;
                                 // the constructor picks the oldest `usable` one - a staging account is never used against PROD
createAccount(): string          // register a new account, switch to it, return its id
useAccount(string $id): self     // switch to an existing one; unknown id throws
ensureAccount(): string          // the kid; registers the current key if it never was
unlinkAccount(?string $id = null): void   // forget the current (or given) account, local files only
// Certificates are per domain, not per account: switching does not move them. A root-level
// account.key from older versions is moved into accounts/<id>/ on first use - same account.

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

One file per day at `zFramework/storage/logs/Y-m-d.log`, appended with `LOCK_EX` so concurrent requests
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

Tasks live under `schedule/` at the project root. Every `.php` file there is loaded, so split
them by subject or by module the way `route/` is split:

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

The cron parser takes the five standard fields with `*`, `*/n`, `a,b`, `a-b` and names
(`jan`-`dec`, `sun`-`sat`); day-of-week is 0-6 with 0 as Sunday, and 7 also accepted. Day-of-month
and day-of-week are OR-ed when both are restricted, as crontab(5) does (`0 0 1 * 1` = the 1st and
every Monday).

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

Nothing here is reachable from a web request - `schedule/` is included by the terminal
command and by nothing else, so a served request pays nothing for it.

## Cron scripts — `cron/`

The older of the two scheduling routes, and still the right one sometimes. A standalone PHP
file that boots the framework and does one job; `cron/cron.php` is the header:

```php
// cron/nightly-report.php
<?php
include(__DIR__ . '/cron.php');

$rows = (new App\Models\Order)->where('created_at', '>', date('Y-m-d', strtotime('-1 day')))->get();
```

One crontab entry per script:

```
0 6 * * * /usr/bin/php /home/user/app/cron/nightly-report.php
```

Verified by running one: config, global helpers, autoloading of `App\*`, the database and the
facades all work. `$cron_mode` makes `bootstrap.php` skip session setup and the `force-https`
redirect - neither means anything without a browser - and routes, providers and modules are
never loaded, so the route table is empty.

`cron.php` installs the same error handler `terminal` does, so a cron script obeys
`framework.error.logging` and an uncaught throwable lands in `error_logs/`. It did not
until recently: the throwable went to stderr, and a crontab line ending in `>> /dev/null 2>&1`
threw that away as well, so an unattended job could fail nightly and leave nothing behind.

### Choosing between `cron/` and `schedule/`

| | `cron/` | `schedule/` |
|---|---|---|
| crontab entries | one per job | one, total |
| where the timing lives | the host's panel | in code, versioned |
| overlap protection | none | a still-running task is skipped |
| double fire in a minute | not handled | not repeated |
| a job that throws | kills that script | logged; the others still run |
| seeing what is scheduled | read the crontab | `php terminal schedule list` |
| isolation | its own process per job | one process per tick |

`schedule/` is the better default **when the host allows a per-minute cron**. Many shared hosts
do not - a five, fifteen or thirty minute minimum is common - and then `everyMinute()` and
`everyMinutes(5)` quietly never fire on time, because they only run when the host's tick lands
on them. There, either match the crontab to what the host allows and schedule nothing finer, or
use `cron/` and let the panel own the timing.

`cron/` also stays right when a job wants its own process: something long, or something that
should not share a tick with anything else.

## Updating the framework — `php terminal update`

> **Never run this unless the user asked for it, in this conversation, in words.**
>
> It replaces the framework core. "The version looks old", "there is an update available" and
> "it would fix this bug" are not permission — say so and wait. The same goes for `--config`,
> `--force` and `--rollback`: all four change files.
>
> `--check` is read-only and safe. Even so, run it at most once or twice in a session, when the
> version is actually relevant to what is being asked. It is not a thing to do on arrival, and
> not a thing to do again because the conversation got long.
>
> **After the user runs an update, reconciling the application is your job.** Read the config
> report and act on it: a setting that moved to another file, a key the new version added that
> the application should set, a key it dropped that is still being read. The merge deliberately
> stops where it would have to guess, and what it leaves is exactly the part that needs someone
> who can read the code.

```bash
php terminal update --check                  # list the branches; is a newer version out
php terminal update                          # list the branches, ask which, install; report config drift
php terminal update --branch=main            # install main (development) - or v3.0.0-release / 3.0.0
php terminal update --config                 # also write the merged config files
php terminal update --rollback               # restore the last backup
php terminal update --force                  # same version again, or an older release
```

Releases are branches named `vX.Y.Z-release`; `main` is development. `--branch` is required
where nobody can be asked (the welcome page's terminal). A downgrade needs `--force`.

**Config merge follows a key that moved between files.** A key no longer shipped in one file
and new in another is written to the new place with the application's value, closures
included - a value is the whole expression up to the comma, not its first token. What is
still left to a human: a container where both sides added keys (reported as `!`).

The archive carries a whole project, so most of it is the application. Only the core is
replaced: `bootstrap.php`, `run.php`, `Core/`, `Kernel/`, `modules/`.

**`zFramework/vendor/` and `zFramework/storage/` are never touched.** Neither is in the
repository, so replacing `zFramework/` wholesale would delete composer's packages and every
session, cache, log and lock. Verified across a real run: vendor stayed at 1961 files and
storage kept everything.

Each step can stop before anything is lost - version read from one remote file, a `PK`
signature check because a failed request arrives as HTML, a sanity check that the archive
really contains a framework, then a backup to `zFramework/storage/update-backup/<version>-<timestamp>`
before the replace. `--rollback` restores it.

### Config is merged, never overwritten

`Kernel/Helpers/ConfigMerge`. The shipped file is taken verbatim and only the bytes of values
the application changed are spliced, located with `token_get_all()`. Comments, indentation and
`[]` survive because nothing is regenerated - `var_export()` is never involved, so its
`array (` never appears.

| | |
|---|---|
| a value was changed | the application's value is kept |
| a list was changed (`trusted-proxies`, `error.mask`, mail's `from`) | kept whole, like a value - `locate()` types it `list` |
| the update added a key | it arrives with its default |
| the application added keys to a section | that whole section is taken from its file |
| the application filled in a section the update ships empty | taken from its file |
| both added keys to the same section | the update's version is kept, the application's extras are **reported** |
| keyed section in the update, plain value in the application | the update's version is kept and it is **reported** |

The reported rows cannot be resolved automatically, so they are reported rather than guessed.
Nothing is written without `--config`; the replaced file is kept as `<name>.php.before-update`.

**A setting that moved between files follows** - `Update::configs()` sees every config file at
once, so a key that stopped shipping in one and started in another (`error`, `debug` and the
rest went from `app.php` to `framework.php` in 3.2) is written into its new place with the
application's value. `Config::framework()` also falls back to the old standalone files at
runtime, which is why they keep working unmerged.

### Writing a terminal command that replaces core files

Load everything it needs before touching the filesystem. The replace step deletes `Kernel/`, so
a class autoloaded after that is looked for in a directory that no longer holds it - which is
how the first version of this died half way through, with the core already swapped and
`ConfigMerge` gone.

## Rate limiting — `RateLimit::` and the `Throttle` middleware

```php
RateLimit::hit(string $key, int $limit, int $window, int $block = 0): array
        // allowed, blocked, count, remaining, retry_after
RateLimit::clear(string $key): void
```

Counters go to redis when it is configured and reachable, where `INCR` makes the count atomic
across every worker and machine; otherwise to one `flock`'d file per key under
`zFramework/storage/ratelimit`, which is what a shared host has. Fixed window, not sliding - a caller can
send up to twice the limit across a boundary, and buying accuracy past that costs a sorted set
and a read of it on every request.

**The limit lives on the route group**, next to the routes it governs:

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

`throttle()` attaches the middleware as well, so it is the only call needed. There is
deliberately **no url-prefix table in config** - that is a second copy of the routing, and it
stops matching the moment a url changes, which with a translated prefix
(`pre('/' . _l('routes.admin.route'), '/admin')`) is every request.

`config/framework.php` carries only the fallback, for a group that attaches the middleware
without naming a number:

```php
'throttle' => [
    'enabled' => true,   // false turns every limit off, wherever it was declared
    'limit'   => 60,
    'window'  => 60,
    'by'      => 'ip',   // ip | token - `token` counts a logged-in caller by identity
                         //   `ip` is REMOTE_ADDR unless framework.trusted-proxies
                         //   names the address the request came from
    'block'   => 0,
],
```

**`block` is the answer to someone hammering an endpoint.** Without it, passing the limit means
"wait for the next window", and a flood gets a fresh allowance every window forever. With it,
passing the limit means refused for that long - answered on a single read, with the counter
left alone, before any route is matched or session touched:

```php
Route::pre('/search')->throttle(100, 10, block: 600)->group(...);
// 100 requests in 10 seconds is not a person - the next 10 minutes are refused outright
```

Measured with `throttle(3, 10, block: 15)`: requests 1-3 pass, the 4th returns 429 with
`try_again_in: 15`, the counter stops moving while blocked, the wait counts 15 → 10 → 5, and
the request after that is served.

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

**Behind a proxy, list it in `framework.trusted-proxies`.** `ip()` reads `REMOTE_ADDR` and nothing
else until the request arrives from an address on that list; only then does it read
`CF-Connecting-IP`, `Client-IP` or the first entry of `X-Forwarded-For`. The headers are set by
whoever sent the request, so trusting them unconditionally - which is what it used to do - let a
caller take a fresh bucket on every request by changing one header, or spend somebody else's
address until it was blocked. The cost of leaving the list empty on a proxied site is the
opposite mistake: every visitor counts as the proxy, so they share one bucket.

`Throttle` **answers 429 itself** rather than declining. A declined middleware with no fallback
closure ends as a 404 (see `references/routing.md`), and a 404 is the wrong answer to "you are
going too fast". The body is JSON, because there is no `errors/*/429` view and a 429 is read by
retry logic more often than by a person:

```json
{"status": 429, "message": "Too many requests. Try again in 59 seconds.", "try_again_in": 59}
```

Sends `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `Retry-After` with it.

Measured on the ordering above: with the limit at 2 and four requests sent, `API::attempt()`
ran exactly twice - the refused pair never reached it.

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

Requires `debug`, so production is unaffected either way. **An analysed query is executed a
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

Config `.rr.yaml`, worker `zFramework/Kernel/worker.php`, which defines `ZF_WORKER`. So does
`php terminal queue work`: the constant means "this process outlives one unit of work", not
"this is the HTTP worker".

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
- **Routes are registered at boot** — `route/web.php`, `route/api.php` and the modules',
  once. `route/dynamic/` is the exception and is re-read on every request in a worker too:
  `handle()` restores the booted table and includes it on top (`Run::serveRequest()`), so those
  definitions see request state and never accumulate. That is the whole reason the directory
  exists, and why nothing in it can be cached.

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

- `zFramework/modules/error_handlers/loader.php` defines a thin `errorHandler()`; nothing else
  loads until an error occurs. Then `handle.php` builds a report (`Report.php`) and renders it
  with `render/html.php`, `render/json.php` or `render/text.php`; `Highlighter.php` colours
  code with `token_get_all()`.
- **Never echo what `errorHandler()` returns** — it has printed already where printing was
  right. Correct form: `errorHandler($err); die;`
- **What the caller gets, with `debug` on (`Config::debug()` - `framework.debug`, or `app.debug`
  if not moved):** a browser gets the page; a client that sends
  `X-Requested-With` or `Accept: application/json` (`Http::wantsJson()`) gets JSON with the
  chain, frames, arguments and queries; the cli gets coloured text. **With debug off** every
  shape is a 500 with one sentence, and the HTML report still goes to disk. The status is 500
  in all cases unless something set one first (`DB::connection()` answers 503).
- **The report** carries the exception class, the `getPrevious()` chain ("caused by"), every
  frame with the function running in it and that function's arguments named by reflection,
  request/headers/cookies/session, the matched route and middlewares, the query log (see
  below), timing and memory, the user (id and email, from what `Auth` already loaded — it
  never queries), and links to the last `error.previous` reports.
- **Nothing is hidden by default.** A password field or a cookie is as likely as anything to
  be where the problem is - an injection attempt arrives in exactly those fields - and
  whoever reads `error_logs/` can read the database too. `framework.error.mask` names keys
  to show as `••••••` (case-insensitive substring of the key) if a policy asks for it, and
  one list covers `$_POST`, the session, cookies, headers, `$_SERVER` and frame arguments
  alike. Strings over 2000 characters are cut with their length noted.
- **Template frames name the template.** A frame in `eval()`'d code or a `*.compiled.php` file
  is mapped through `View::sourceOf()` to the `resource/views/...` file and line it came from,
  and "Open in editor" opens that. See `views.md` → "Errors point at the template".
- **Frames are tagged by prefix:** app, view, framework (`zFramework/`, and the entry point),
  vendor (`zFramework/vendor/`). The first app or view frame opens by default; "app only"
  (on unless switched off, remembered) hides the rest. Each frame shows the function running
  in it with its arguments as name · type · value; an internal function that threw
  (`PDOStatement::execute()`) is a note on the frame, and the frame carries the caller -
  `DB::prepare()` and its SQL. The **Arguments** tab lists every call that had arguments,
  grouped by area (Application, a module, View, Database, Validation, Auth & Session,
  Mail & Queue, Routing, Framework, Vendor). **User** is the row `Auth::user()` answers.
- **`DB::$queryLog`** — while `debug` is on, every query of the request with bindings,
  duration, connection and the driver's message when it failed (capped at 500). Read it
  yourself if useful; it is cleared per request. Production pays one boolean per query.
- `config/framework.php` → `error.logging` writes the report under `error_logs/` as
  `Y-m-d-H-i-s-<hex>-<ExceptionClass>.html`; `error.stream` (`false` | `'error_log'` |
  `'stderr'` | `'syslog'`) also emits a one-line summary, which is what you want as soon as
  more than one machine serves the site. `error.keep_days` (14) sweeps old reports - only on
  a request that already failed, and at most once an hour; 0 keeps everything. The block
  used to live in `config/app.php` and is still read from there.
- `suggestions/<code>.php` is included into the page for a matching exception code (1000,
  1001, 42S02 ship) — arbitrary PHP behind the debug gate, by design.
- **A fatal is reported too, by a shutdown handler, not by `errorHandler()`.** Running out of
  memory, passing `max_execution_time` or a parse error in a runtime include never reaches
  `set_exception_handler`, and there is no `set_error_handler` here at all - so those used to
  produce nothing anywhere. The shutdown handler writes one plain-text line to
  `error_logs/*.fatal.txt` and to `error.stream`, and sets a 500 if nothing has been sent. It
  deliberately does not load the renderer: a process that just died for want of memory cannot
  build 68 KB of markup.
- `error.callback` receives `($log_path, $log)`. The shipped one dies on CLI **unless `ZF_WORKER`
  is defined** — dying in a long-running process would kill it rather than end one unit of work.
  `worker.php` and `php terminal queue work` both define it. If you write your own callback and
  it can exit, guard it the same way.
- Suggestion files map driver error codes to advice:
  `zFramework/modules/error_handlers/suggestions/<code>.php` (1000, 1001, 42S02 exist).

## Tests — `php terminal tests`

The framework's own harness, no PHPUnit. `tests/*.php` at the project root,
one process per file (`Kernel/test-runner.php`) so a file may define
`ZF_WORKER`, break a static or die fatally without touching the next.
Underscore-prefixed files (`tests/_helpers.php`) are never run directly.

```bash
php terminal tests                        # run all (bare `tests` = `tests run`)
php terminal tests run db --db=local --filter=csrf
php terminal tests list
php terminal tests make posts             # skeleton
```

Inside a file (vocabulary from `Kernel/Helpers/TestKit.php`, loaded only by
the runner): `test('name', fn)` — `same($expect, $got, $note?)` strict —
`truthy/falsy($got)` — `contains($needle, string|array)` — `throws(Class::class, fn)`
returns the caught throwable — `skip('reason')`. `Test::` is an alias of
TestKit: `Test::db()` (the --db key), `Test::table('x')` → `zf_test_x`
(THE naming contract — nothing else may be created), `Test::pdo()`,
`Test::cleanup(fn)` (runs after failures and fatals too).

Exit code 1 on any failure. Writing a DB-backed model for a test: set
`$this->db = Test::db(); $this->table = Test::table('x');` in the constructor
before `parent::__construct()` — see tests/db.php.
