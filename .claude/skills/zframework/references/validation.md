# zFramework Validation

Two entry points. Prefer the first.

## A Request class — the normal way

```bash
php terminal make request Post/StoreRequest      # App/Requests/Post/StoreRequest.php
```

```php
namespace App\Requests\Post;

use zFramework\Core\Abstracts\Request;

class StoreRequest extends Request
{
    public function __construct()
    {
        $this->authorize      = false;   // true → abort(401) unless Auth::check()
        $this->htmlencode     = false;   // true → htmlspecialchars every string that passes
        $this->attributeNames = [];      // ['email' => 'E-mail'] for messages
    }

    public function columns(): array
    {
        return [
            'title'       => ['required', 'max:255'],
            'content'     => ['required'],
            'category_id' => ['required', 'exists:App\Models\Category'],
            'publish'     => ['nullable'],
        ];
    }
}
```

Type-hint it on the controller method and the router builds and injects it (a bare `new`, no
container). `validated()` runs the rules and returns the data:

```php
public function store(StoreRequest $request)
{
    $post = $this->posts->insert($request->validated());
    …
}
```

**`validated()` is a whitelist.** Only keys listed in `columns()` come back, so passing its
result straight into `insert()` is safe from mass assignment. Input is read from `$_REQUEST`.

A rule set can depend on context: `validated()` calls `$this->columns(func_get_args())`, so
anything passed to `validated()` arrives as **one array** argument. Declare it to use it —
that is how an update request excludes the current row from a `unique` check:

```php
public function columns(array $args = []): array
{
    $id = $args[0] ?? null;
    return ['email' => ['required', 'email', 'unique:App\Models\User' . ($id ? ";ex:$id" : '')]];
}
```
```php
$request->validated($id);
```

Nothing in the repo does this yet; the mechanism is in `Abstracts/Request::validated()`.

## `Validator::validate` — when there is no Request class

```php
Validator::validate(?array $data, array $rules, array $attributeNames = [], ?Closure $callback = null): array
```

Used directly in `modules/blog`, and fine for a small case. Note the callback changes the
control flow — see below.

## Rules

`required` · `nullable` · `email` · `min:N` · `max:N` · `type:x` · `same:field` ·
`unique:Model` · `exists:Model`

One class per rule under `zFramework/Core/Validator/Rules/`. Adding a rule means adding a
class there — there is no closure form.

Behaviour that is not obvious:

- **`min` / `max` compare a *length* that depends on the type.** For a string it is
  `strlen()`; for a number it is **the value itself**. So `max:100` rejects the string
  `str_repeat('x', 150)` and also rejects the number `150`, and accepts `80`. Declare the type
  when the input is ambiguous — a numeric string is auto-detected as a number, so `'150'` is
  compared as 150, not as 3 characters. `type:string` forces the other reading.
- **Every rule except `required` passes on an empty value.** `email`, `min`, `max`, `type` all
  begin with `if (!strlen($value)) return true;`. That is what makes `['nullable', 'email']`
  mean "optional, but a valid address if present".
- **`required` and `nullable` together throw.** Not a validation failure — an exception. Pick
  one.
- `required` accepts `0` and `0.0`, which a naive emptiness check would reject.
- **`unique` and `exists` take a model class and query it**: `unique:App\Models\User`. The
  column defaults to the field name; override with `key`, and exclude a row with `ex`:
  `unique:App\Models\User;key:email;ex:5` — that is the update-form form.
- `same:password` compares against another field in the *input*, not the database.

Messages come from `resource/lang/<locale>/validator.php` — `validator.errors.<rule>` for the
text and `validator.attributes.<field>` for the field name, with `attributeNames` as the
fallback.

## What happens on failure

The alert is raised where the rule fails, then one of three exits:

| Situation | What happens |
|---|---|
| AJAX request | `abort(400, Response::json($errors))` |
| Normal request | `back()` — redirect to the referer, alerts waiting |
| A `$callback` was passed | `$callback($errors, $statics)` runs, **execution continues** |

So through a Request class a failure never returns to your controller; the request is already
over. That is why controllers do not check the result of `validated()`.

**With a callback, you must check `$errors` yourself — and do not trust the returned array.**
The return value collects a field as soon as *any* rule on it passes, so a field that failed a
later rule is still present:

```php
$r = Validator::validate(['age' => '150'], ['age' => ['required', 'max:100']], [], fn($e, $s) => null);
// $r === ['age' => '150']   — 'required' passed and wrote it; 'max' failed afterwards
```

Measured, not inferred. In the normal (no-callback) flow this never surfaces, because the
request has already redirected or aborted.
