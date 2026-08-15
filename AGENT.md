# zFramework — Agent Rules

It borrows Laravel's vocabulary and **behaves differently behind every one of those
names**. Recalling how Laravel does something is not evidence about this framework — it
is the most common way to write broken code here. The biggest single difference:
**rows are arrays, not objects** — `$post['title']`, never `$post->title`.

Work from the code that is checked out, not from a version number.

Before writing application code, load the `zframework` skill
(`.claude/skills/zframework/`) — the API inventory, the correct signatures and the
"this already exists, don't rewrite it" list live there. What follows holds even in
sessions that never load it, because these are the rules that keep getting broken.

## Views

- **The location is fixed.** `resource/views/<app>/main.php` is that layer's only layout.
  Every page group is a **directory with `index.php` in it** — `pages/welcome.php` is
  wrong, `pages/welcome/index.php` is right. Create and edit share one file:
  `edit-or-create.php`.
- **A new interface layer (admin, panel) is three things shipping together:**
  `<layer>/main.php`, `errors/<layer>/{main,404}.php`, and the middleware guarding it.
  `Http::$error_view` selects which error views render; without switching it, a 404
  inside `/admin` renders the public layout.
- **Always use `@extends` / `@section` / `@yield` / `@include`.** A page without a layout
  is wrong even when it renders.
- **Prefer plain PHP for output and control flow:** `<?= $x ?>`, `<?php foreach (…): ?>`,
  `<?php if (…): ?>` — plain PHP is the house style and parses without a regex in between.
  `{{ $x }}` escapes and `{!! $x !!}` does not, so anything printing markup (`csrf()`,
  `inputMethod()`, a rendered partial) must use `{!! !!}`. **If the user asks for `{{ }}`,
  use it**; this is a default, not a ban.
- **These directives do not exist** and will be printed literally into the page:
  `@for` `@while` `@switch` `@csrf` `@method` `@auth` `@guest` `@push`
  `@stack` `@component` `@each`. Use `<?php for (…): ?>`, `<?= csrf() ?>`,
  `<?= inputMethod('PATCH') ?>`.
- **Templates render; they do not fetch or calculate.** No `new Post`, no
  `->where()->get()`, no aggregation or business rules inside a view file — the controller
  queries and hands finished data to `view()`. Formatting (`Date::format`, `e()`, a ternary
  picking a CSS class) is fine. A genuinely niche exception should be rare enough to justify
  in a comment.
- **The layout is not an exception.** Whatever `main.php` needs on every render is registered
  in `App/Providers/ViewProvider.php`, not fetched inside the template:

  ```php
  View::bind('app.main', fn() => ['lang_list' => Lang::list()]);
  ```

  The bind fires even when the request rendered a page that `@extends('app.main')`, and it
  re-runs on a cache hit. Bind once on the layout, never per page.
- **Code outside a `@section` is discarded** in a template that `@extends`. Per-page setup
  goes inside the section, or the variable comes out undefined.
- **A page and its layout share one variable scope** — they compile to one file with a single
  `extract()`. A variable set in the layout is visible inside the sections and vice versa, and
  an assignment in the layout overwrites what the controller passed under the same key. Name
  view data specifically (`$post`, not `$item`) and prefix anything the layout owns.
- Clear compiled views with `php terminal cache clear views`.

## Routes and controllers

- **Never hand-write CRUD.** `Route::resource('/posts', PostController::class)` registers
  all seven routes, named. Building a `$crud` array and looping it to generate routes is
  not allowed.
- `Route::resource` takes two arguments — there is no `->only()`, `->except()`,
  `->names()`, no `apiResource`, no `Route::where()`. The destroy method is **`delete`**,
  not `destroy`. The prefix helper is `Route::pre()`, not `prefix()`.
- **Never hand-write the controller:** `php terminal make controller X --resource` emits
  exactly the seven methods `Route::resource` dispatches to.
- **The only base class is `zFramework\Core\Abstracts\Controller`**, and it is empty by
  design. Do not invent an `AbstractCrudController` or an interface layer above it.
- **`php terminal route list` is not the full table.** Routes registered conditionally,
  behind a module's `status`, or under `route/dynamic/` may not appear. To know what is
  registered, read `route/web.php`, `route/api.php`, `route/dynamic/*` and each enabled
  module's `route/web.php`.

## General

- Controllers `return view(...)`; they do not echo it.
- File uploads go through `File::upload()` — no hand-rolled `mkdir` /
  `move_uploaded_file`.
- Do not touch `$_SESSION` directly. `Session::set/get` reads once and writes once per
  request; going around it breaks that.
- Never echo the return value of `errorHandler()` — it prints the page a second time.
- **Any change to the public surface under `zFramework/` updates the skill in the same
  commit.** Which change goes to which file:
  `.claude/skills/zframework/references/conventions.md` → "Keeping this skill current".

Full rules and copyable skeletons: `.claude/skills/zframework/references/views.md`
and `.claude/skills/zframework/templates/`.
