# zFramework Validation

Two entry points, both fine. A Request class per endpoint when the rules are endpoint-specific;
a `setAll()` method on the controller when store and update share them. Pick per case — do not
generate a Request class reflexively for every action.

## A Request class

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

## `Validator::validate` — the `setAll()` pattern

```php
Validator::validate(?array $data, array $rules, array $attributeNames = [], ?Closure $callback = null): array
```

**A Request class per endpoint is not required.** When store and update validate the same
columns and both need the same derived fields, one method on the controller is less code and
keeps the derivation next to the rules. `modules/Blog` does this and it is a good pattern to
copy:

```php
public function setAll($except = null)
{
    $validate = Validator::validate($_REQUEST, [
        'title'   => ['required'],
        'content' => ['required'],
        'slug'    => ['nullable', 'unique:Modules\Blog\Models\Blogs' . ($except ? ";ex:$except" : '')],
        'publish' => ['nullable'],
    ]);

    # Everything the row needs but the form does not send.
    $validate['publish'] = $validate['publish'] ? 1 : 0;
    $validate['slug']    = Str::slug($validate['title']);
    $validate['user_id'] = Auth::id();

    if (isset($_FILES['image']['name']) && strlen($_FILES['image']['name']))
        $validate['image'] = File::upload('/uploads/blog', $_FILES['image']);

    return $validate;
}

public function store()
{
    $post = $this->posts->insert($this->setAll());
    …
}

public function update($id)
{
    $this->posts->where('id', $id)->update($this->setAll($id));   // ← excludes this row
    …
}
```

The `$except` parameter is the point: on update the row being edited would otherwise collide
with its own `slug` on the `unique` rule. Passing the id appends `;ex:$id`, which is exactly
what the rule's `ex` parameter is for. Without it, every update of an unchanged title fails.

Reach for a Request class instead when the rules differ per endpoint, when you want
`authorize`/`htmlencode`, or when the same rules are used from more than one controller.
Either way the derived fields belong in one place — never duplicate them across `store()` and
`update()`.

Note the callback argument changes the control flow — see "What happens on failure" below.

## Rules

| Rule | Means |
|---|---|
| `required` | present and non-empty. `0` and `0.0` count as present |
| `nullable` | may be absent or empty |
| `email` | a valid address |
| `url` | a valid **http or https** address |
| `date` / `date:Y-m-d` | parseable, or that exact format and a real date in it |
| `min:N` / `max:N` | the value for a number, the length for a string or array |
| `between:A,B` | the same measure, both ends inclusive |
| `type:x` | declares the type and asserts the value can be read as it |
| `in:a,b,c` / `not-in:a,b` | membership, compared as strings |
| `regex:"^[a-z]+$"` | quoted, without delimiters |
| `same:other` | equal to another field in the input |
| `confirmed` / `confirmed:field` | equal to `<field>_confirmation`, or the named field |
| `unique:Model;key:col;ex:5` | not already in the table, optionally excluding one row (`ex` compares the model's primary key). Runs through the model, so soft-deleted rows are invisible to it |
| `exists:Model;key:col` | present in the table |

One class per rule under `zFramework/Core/Validator/Rules/`, registered in `Validator::$ruleMap`.
Adding a rule means adding a class — there is no closure form. A kebab-case name works
(`not-in`); the parser accepts `-` in a rule name.

Behaviour that is not obvious:

- **`url` rejects anything but http and https.** `FILTER_VALIDATE_URL` on its own accepts
  `javascript:` and `data:`, which is how a "website" field becomes an XSS hole.
- **`date:Y-m-d` re-formats what it parsed and compares.** Without that, `2026-02-31` parses
  happily and becomes the 3rd of March.
- **`regex` wants the pattern quoted and without delimiters.** Unquoted, the rule parser stops
  at the first space or semicolon; the delimiters are added for you, so a `/` in the pattern
  needs no escaping.
- **`confirmed` goes on the field itself**, where `same` goes on the second field and names the
  first. The error then lands on the field the user is looking at.
- **`between` reads the same measure `min`/`max` do**, so `type:` decides whether it is
  comparing a number or a length.
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

## Where a rule value ends

An unquoted value runs to the first space or semicolon. The semicolon is what lets one
string carry several rules — `exists:User;key:email` is `exists` plus a `key` parameter —
and the space is what lets a list stay readable.

So a value that contains a space has to be quoted:

```php
'regex:"^[a-z ]+$"'      // quoted: the whole pattern
'regex:^[a-z ]+$'        // unquoted: cut at the space, `^[a-z` is the pattern
```

By design, not a bug (`Validator.php:91`) — the quoted branch is matched first for exactly
this reason. It only ever bites `regex`, `in` and `not-in` with spaced values.
