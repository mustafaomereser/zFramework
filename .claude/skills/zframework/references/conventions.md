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
- **`@include` is inlined, so `return` in a partial kills the rest of the page.** The compiler
  merges every partial into one compiled file, so a guard clause like
  `if (!$show) return;` at the top of an included view aborts the *parent* view too — the table
  below it, the buttons, everything, silently and with a 200. Wrap the partial body in
  `<?php if ($show) : ?> … <?php endif ?>` instead. Measured: a list screen rendered empty while
  the query behind it returned 15 rows.
- **A model's static method shadows a column of the same name.** Row-level closures are bound
  for every public static method, so `Content::data()`, `::type()` and `::lang()` overwrote the
  `data`, `type` and `lang` keys of every row read *with* closure mode — `$row['data']` came back
  as a Closure and `json_decode` on it silently produced `[]`, i.e. all JSON fields vanished.
  Name helpers so they cannot collide (`field()`, `config()`, `langCode()`), or read with
  `closureMode(false)`.
- **`Str::slug()` keeps Latin only.** An Arabic or Cyrillic title reduces to the divider alone
  (`"-"`), and `Str::slug($t) ?: 'fallback'` does *not* catch it because `"-"` is truthy. Strip
  the dividers before testing, and keep `\p{L}` if you want non-Latin slugs.
- **`Route` matches the raw `REQUEST_URI`.** Route parameters arrive percent-encoded, so a
  non-ASCII slug has to be `rawurldecode()`d before it is compared with a database value.
- **`json_encode($row)`** writes relation closures as `{}`. Use `closureMode(false)` or
  `array_filter(..., fn($v) => !$v instanceof Closure)`.
- **Closure routes** block `php terminal route cache`. Use the controller-array form.
- **Root and resource routes are written last, deliberately.** `Route::resource('/', …)`
  registers `/{id}`, which matches every one-segment url and lets `show()` claim it. That is
  the intended behaviour, not a bug — so the rule is ordering: every other route is defined
  **above** the root resource. A one-segment static route placed after it (or in
  `route/dynamic/`, which is included last) is out-ranked and served by `show($id)`.
- **`oninsert`/`onupdate` observers return the sets they want written.** A returned array
  replaces `$sets`; an empty or falsy return leaves the original data intact — it is *not*
  lost (`if ($new_sets = $this->trigger(…)) $sets = $new_sets;`). The generated stub declares
  `: array` though, so a body that returns nothing at all is a TypeError. Both measured.
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

## Keeping this skill current

These files are hand-written prose, not generated. Nothing regenerates them, so a change to the
framework's public surface that does not reach them leaves the skill quietly wrong — and a wrong
signature is worse than a missing one, because it gets used without checking.

**When a change touches the framework's public surface, update the matching reference in the same
commit.** That means: a new or renamed facade/helper/model method, a changed signature or default,
a new terminal command or flag, a new config key, a new view directive, a new validator rule.

**Do not write framework version numbers into this skill.** It documents the code as it stands
in the working tree — whatever is checked out is current. A version in the text only goes stale
and invites "is this still true for 3.2?", which is never the right question here.

| Changed | Update |
|---|---|
| A method on a facade, helper, or the DB layer | `references/api.md` |
| A terminal command or its flags | `references/api.md` (Terminal section) |
| A config key, or a `connections.php` option | `references/config.md` |
| AutoSSL, cPanel, analyzer, worker runtime | `references/infrastructure.md` |
| Route registration, group/prefix/middleware behaviour, the middleware contract | `references/routing.md` |
| Auth, `special_columns`, session modes, the API middleware | `references/auth.md` |
| A validator rule, or how validation fails and returns | `references/validation.md` |
| Model behaviour, relations, migration column syntax, observers | `references/models.md` |
| Page caching, cache headers, invalidation | `references/caching.md` |
| Log, Schedule, `cron/`, RateLimit, AutoSSL, cPanel, workers | `references/infrastructure.md` |
| A view directive, or anything about how `resource/views` is laid out | `references/views.md`, and `templates/` if the skeletons change |
| A new subsystem worth knowing exists at all | the inventory table in `SKILL.md` |
| A decision that should not be "fixed" later, or a trap worth warning about | this file |

Application code — a new controller, model, migration, view — changes nothing here. This is about
`zFramework/` only.

Two habits that keep it honest: read the signature off the source when writing it down rather than
from memory, and delete an entry when the thing it describes goes away. A stale entry outlives the
code it documented and there is nothing to catch it.

## Code style

- Application code lives in `App/` and `modules/`; `zFramework/` is the core, touched only to fix
  a framework bug.
- PascalCase class names, filename matches the class, namespace mirrors the directory.
- Comments do not restate the code — they say **why**, not what.
- Commits are per-topic and separate; work happens on `main`.
- Explanations are peer-level: the developer has 14 years of experience and does not want a
  lecturing tone.
