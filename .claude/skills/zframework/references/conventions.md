# zFramework — Decisions, Traps, Performance

## Settled design decisions

These are deliberate. Do not "fix" them, do not propose refactors, do not report them as security
findings.

| Topic | Decision |
|---|---|
| `Crypter` | For API tokens and cookie obfuscation. Not for passwords — passwords use bcrypt. |
| `auth-password` cookie | Logs the session out when the password changes. Intentional. |
| SQL identifier interpolation | Column names come from developer code and cannot be manipulated from outside. |
| `uniqid()` in `whereBetween` | No collisions in request-scoped PHP. |
| `$GLOBALS` DB/schema cache | A performance decision, worth ~200 ms. |
| `DB.php` god object | Deliberately one file; it will not be split. |
| `eval()` and `extract()` in View | Templates are written by the developer, never user input. |
| `Str::rand()` / `rand()` | Good enough given the CSRF timeout and the form-based token flow; api_token is 60 chars and is not stored. |
| `@` operators | Deliberate. Do not clean them up. |
| `checkSSL()` domain in AutoSSL | The domain does not come from outside; the SSRF risk is accepted. |
| Validator `Unique`/`Exists` instantiation | `equivalent` is supplied by the developer. |
| PHPUnit suite | None, and none planned. |
| `scheme.json` | Deleted by the Kernel after migrations — that is normal. |

## Known traps

- **Never echo what `errorHandler()` returns.** `handle.php` already prints it, so
  `die(errorHandler($e))` renders the page twice. Correct form: `errorHandler($err); die;`
- **Rows are arrays.** `$row->col` does not work — it yields `null` plus a warning.
- **`json_encode($row)`** writes relation closures as `{}`. Use `closureMode(false)` or
  `array_filter(..., fn($v) => !$v instanceof Closure)`.
- **Closure routes** block `php terminal route cache`. Use the controller-array form.
- **`oninsert`/`onupdate` observers must return `$sets`**, otherwise the data is lost.
- **`Auth::model()` is lazy.** Do not construct the model inside `Auth::init()` — the model
  constructor opens a DB connection and loads the schema, and every visitor pays for it including
  those who are never asked for an identity. `special_columns` and `db` are read via
  `ReflectionClass::getDefaultProperties()` without instantiating. Keep this arrangement.
- **Writing to `$_SESSION` directly.** `Session` does one read and one write per request.
- **The host in `database/connections.php`.** Write an IP, not a name. Against a dead MySQL,
  `127.0.0.1` costs 2 s, `localhost` 4 s (IPv6 then IPv4), an unreachable IP 21 s.
- **Migrations are idempotent**, but dropping a column is destructive, and `--fresh` rebuilds
  every table.

## Performance notes

- **Boot cost** is 6–8 ms with opcache on (`/` and 404). Roughly 14 ms in a dev environment with
  opcache off.
- `class_exists(..., false)` guards keep `Profiler` and `Defer` from loading on requests that do
  not use them. Do not remove those guards.
- `error_handlers/loader.php` is a thin shell; the 68 KB `handle.php` loads only when an error
  actually happens.
- The Redis autoload short-circuit (`GlobalCache`, `Auth`): the `Redis::available()` check keeps
  `Facades/Redis.php` from loading at all. When adding new statics, update the allowlist in
  `State.php`.
- `$GLOBALS['framework_config']` is read once during bootstrap; `Config::framework()` inherits it.
- If long-running workers (RoadRunner) are a target: `php terminal state check` reports statics
  that leak across requests — give every class that adds a static a `flushRequestState()`.

## Code style

- Application code lives in `App/` and `modules/`; `zFramework/` is the core, touched only to fix
  a framework bug.
- PascalCase class names, filename matches the class, namespace mirrors the directory.
- Comments do not restate the code — they say **why**, not what.
- Commits are per-topic and separate; work happens on `main`.
- Explanations are peer-level: the developer has 14 years of experience and does not want a
  lecturing tone.
