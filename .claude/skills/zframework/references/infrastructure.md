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
