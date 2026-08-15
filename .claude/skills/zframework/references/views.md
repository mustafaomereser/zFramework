# zFramework Views

Engine: `zFramework\Core\View` (a static class, **not** a facade — there is no
`Core/Facades/View.php`). It is a regex/string-replace compiler, not Blade. Anything
Blade does that is not listed here does not exist.

Copyable skeletons live in `templates/views/` next to this file. Read and copy them
instead of writing a layout or a page from memory.

## The directory contract

```
resource/views/
  <app>/                          one directory per interface layer: app, admin, panel
    main.php                      that layer's ONLY layout
    pages/<resource>/index.php    list
    pages/<resource>/edit-or-create.php
    pages/<resource>/show.php
    layouts/<name>/content.php    fragment, does not @extends
    modals/<name>.php
  errors/<app>/main.php
  errors/<app>/404.php
  layouts/pagination/default.php  overridable via config/app.php
```

Real files following it: `resource/views/app/main.php`,
`modules/blog/views/admin/pages/index.php`,
`modules/blog/views/admin/pages/categories/{index,edit-or-create}.php`.

Rules, in order of how often they get broken:

1. **A page group is a directory with `index.php` in it.** `pages/welcome.php` is wrong,
   `pages/welcome/index.php` is right. This holds even when the group has exactly one page
   today — the second page arrives later and must not force a move.
2. **The directory under `pages/` matches the `Route::resource` URL.**
   `Route::resource('/posts', ...)` → `pages/posts/{index,edit-or-create,show}.php`.
3. **Create and edit share one file**, `edit-or-create.php`. The model variable is absent on
   create, so read it as `$post['title'] ?? ''`. Do not write `create.php` + `edit.php`.
4. **One layout per layer.** `app/main.php` is the app layer's layout; a second layer gets
   `admin/main.php`, not a variant of the first.
5. Fragments that do not `@extends` go under `layouts/` or `modals/`, never under `pages/`.

### Opening a second interface layer

An `admin` (or `panel`) layer is three things that ship together — none of them is optional:

```
resource/views/admin/main.php                  the layer's layout
resource/views/errors/admin/{main.php,404.php} the layer's error pages
Route::pre('/admin')->middleware([...])        the authorisation guard
```

The error view set is chosen by `Http::$error_view` (default `errors.app`).
`App/Middlewares/ViewDirectives.php` switches it to `errors.admin` when the request is in the
admin layer — copy that pattern for any new layer, otherwise a 404 inside `/admin` renders
the public layout.

The guard is a middleware group, not per-route:

```php
Route::pre('/admin')
    ->middleware([App\Middlewares\Auth::class], fn($declines) => abort(403))
    ->group(function () {
        Route::resource('/posts', App\Controllers\Admin\PostController::class);
    });
```

`Route::pre()` prefixes the **name** as well, so those routes are `admin.posts.index` and so on.

## Where a template's data comes from

**Templates render. They do not fetch and they do not calculate.**

No `new Post`, no `->where(...)->get()`, no aggregation, no sorting, no business rules inside
a view file. The controller queries and hands finished data to `view()`; the template loops
over it and prints it. Formatting is fine — `Date::format()`, `e()`, `number_format()`,
a ternary picking a CSS class. Deciding *what* the data is, is not.

The reason is not purity. A template that queries cannot be rendered from a second controller
without repeating the query, it cannot be tested or cached independently, and the query hides
in the one place nobody greps for one. Model calls scattered across views are also how a page
ends up issuing forty queries nobody can account for.

A genuinely niche exception exists and should be rare enough to justify in a comment.

### The layout is not an exception — bind it

`main.php` renders on every request, so it is the most tempting place to fetch. Do not.
Register the data in `App/Providers/ViewProvider.php`:

```php
use zFramework\Core\Facades\Lang;
use zFramework\Core\View;

class ViewProvider
{
    public function __construct()
    {
        View::bind('app.main', fn() => [
            'lang_list' => Lang::list(),
        ]);
    }
}
```

```php
{{-- resource/views/app/main.php --}}
@foreach($lang_list as $lang) … @endforeach   {{-- just consumes it --}}
```

Verified behaviour, both of which are what make this work:

- **A bind on the layout fires even when the request rendered a page that `@extends` it.**
  `parseExtends` compiles the parent through `compile()`, which applies the parent's binds and
  merges them back into the child's data. You do not bind per page.
- **Binds re-run on a cache hit.** The manifest records which binds the chain used and
  `View::view()` re-applies them before including the compiled file, so bound data is never
  stale even though the template is not recompiled.

Providers are auto-loaded — everything in `App/Providers/*.php` is instantiated at boot, so
the constructor is where registration goes. Bind the layout by the name pages extend
(`app.main`), not by the page name.

## Directives

### Required — always use these

`@extends('app.main')`, `@section('body') … @endsection`, `@yield('body')`, `@include('a.b')`

A page without `@extends` + `@section` is wrong even if it renders. The layout is how the
layer stays one layer.

### Does not exist — writing these prints them literally into the HTML

`@for` · `@while` · `@switch` · `{!! !!}` · `@auth` · `@guest` · `@csrf` · `@method` ·
`@push` · `@stack` · `@component` · `@each` · `@endphp` inside a nested `@php` ·
`@section` nested in another `@section`

Counting loop → `<?php for ($i = 0; $i < $n; $i++): ?>`. CSRF field → `<?= csrf() ?>`.
Method spoof → `<?= inputMethod('PATCH') ?>`.

### Exists, but plain PHP is the default

| Instead of | Write |
|---|---|
| `@foreach($x as $y) … @endforeach` | `<?php foreach ($x as $y): ?> … <?php endforeach ?>` |
| `@if(…) … @endif` | `<?php if (…): ?> … <?php endif ?>` |
| `{{ $x }}` | `<?= $x ?>` |

Why, since it looks like a downgrade: **`{{ }}` does not escape.** `View.php:480-487`
compiles `{{ $x }}` to `<?= $x ?>` and nothing else — no `htmlspecialchars`, and there is no
`{!! !!}` to opt out of. So the directive buys zero safety over `<?= ?>` and costs
single-quote-only parsing plus regex fragility. When output must be escaped, that is
`<?= e($x) ?>` either way.

**If the user asks for `{{ }}` or `@foreach`, use them.** This is a default, not a ban. The
ban is only on directives the engine does not implement.

The rest of what exists, for reading other people's templates: `@elseif`, `@else`,
`@empty($x) … @endempty`, `@isset($x) … @endisset`, `@forelse … @empty … @endforelse`,
`@php … @endphp`, `@json($x)`, `@dump($x)`, `@dd($x)`.

### Engine behaviour worth knowing

- **In a template that `@extends`, anything outside a `@section` is discarded.** Sections are
  lifted into an array and the rest of the child is replaced wholesale by the compiled parent,
  so a setup block above `@section('body')`:

  ```php
  @extends('app.main')
  <?php $editing = isset($post['id']); ?>   {{-- never runs --}}
  @section('body') … <?= $editing ?> …      {{-- Undefined variable $editing --}}
  ```

  never executes. Put per-page setup **inside** the section. A layout may have a `<?php ?>`
  block at the top (`app/main.php` does) because a layout is the parent, not the child; a
  standalone partial rendered with `view()` may too, because it extends nothing.
- **`@yield` is resolved at compile time, not at runtime** (`View.php:600-607`). Sections are
  collected before the parent is compiled, which is why the order works — but it also means a
  section cannot be produced by runtime code.
- **Section names must match the layout's `@yield` names.** `app/main.php` yields `header`,
  `body` and `footer`; a `@section('content')` is silently dropped and the page renders empty.
- **`@extends($variable)` works and disables the view cache** for that template
  (`View.php:583-592`). Every request recompiles. Avoid unless the layout genuinely varies.
- The `@extends` regex is `[^)]+`, so a parenthesised expression breaks it:
  `@extends(layoutFor($x))` does not work.
- Inline section syntax is exact — `@section('title', 'Value')`, comma plus one space.
- `{{ }}` and `@include` accept **single quotes only**.
- `{{-- comment --}}` is stripped before anything else parses, so a comment may contain a
  `{{ }}` echo, an `@include`, or a half-written tag without any of it taking effect. It
  never reaches the compiled file, so unlike an HTML comment it costs nothing at runtime and
  does not show up in the page source. Multi-line is fine.
- `@include` splices the file's text in and compiles it with the parent, max depth 32
  (`View.php:504-557`). Nothing in this repo uses it; partials are called at runtime with
  `<?= view('app.layouts.auth.content') ?>`, which is also fine and keeps the partial's own
  scope.
- Custom directives are registered in `App/Middlewares/ViewDirectives.php` (a middleware, not
  the provider) via `View::directive('page', fn($page) => …)`; `@page`/`@endpage` live there.
  Opening and closing tags are two separate registrations. The match is prefix-based, so a
  new directive named `pag` would also swallow `@page`.
- `<style>` blocks are masked before parsing so CSS `@media`/`@keyframes` survive
  (`View.php:318-358`).
- Compiled output goes to `zFramework/storage/views/*.compiled.php` with an mtime manifest.
  Clear it with **`php terminal cache clear views`** — the `php terminal view clear` written
  in `config/framework.php:16` is not a real command.
- Caching and minify are both on by default (`config/framework.php:19-22`); turn caching off
  while writing templates.

## `view()` resolution

```php
view(string $name, array $data = [])   // = View::view(), zFramework/modules/Functions.php:136
```

Dot notation, plain `.php` extension (no `.blade.php`, no suffix configured). Three candidates
are tried in order (`View.php:529-536`):

1. `resource/views/<path>.php`
2. `modules/<path>.php`
3. `<path>.php` relative to the project root

So `view('app.pages.posts.index')` → `resource/views/app/pages/posts/index.php`, and
`view('blog.views.admin.pages.index')` → `modules/blog/views/admin/pages/index.php`.

`view()` returns the rendered markup as a string — that is how mail bodies are built.

Data is `extract()`ed into the template. To inject data into a layout on every render without
threading it through every controller:

```php
View::bind('app.main', fn() => ['user' => Auth::user()]);   // App/Providers/ViewProvider.php
```

Binds re-run on every request even when the compiled cache hits (`View.php:261`).

## Pagination

`paginate()` returns `['items' => [...], 'links' => callable, ...]`. In the template:

```php
<?= $posts['links']() ?>                        <!-- config/app.php: layouts.pagination.default -->
<?= $posts['links']('app.layouts.pagination') ?> <!-- per-call override -->
```
