# zFramework Models, Relations, Migrations, Observers

Signatures are in `references/api.md`. This file is the behaviour that trips people up.

## Rows are arrays

`get()` and `first()` return plain arrays. `$post->title` does not work — it yields `null` and
a warning. It is `$post['title']` everywhere: controllers, views, observers.

`first()` returns `[]` when nothing matched, not `null`, so `?:` works but `??` does not:

```php
$post = $this->posts->where('id', $id)->first() ?: abort(404);
$post = $this->posts->where('id', $id)->firstOrFail('No such post.');   // clearer
```

## Every row carries closures

`setClosures()` attaches callables to each row before you get it:

- **one per relation method on the model**, named after the method
- **`update`** and **`delete`**, when the row has its primary key

```php
$post = (new Posts)->where('id', 5)->first();

$post['author']()                      // runs the relation query
$post['author']()['username']

$post['update'](['title' => 'New']);   // updates THIS row, no where() needed
$post['delete']();
```

A relation is only a query when you call it, so listing 50 rows does not run 50 relation
queries — but calling `$row['author']()` inside a loop does. Use `with()` when you need the
relation for every row.

### The two consequences

**1. `json_encode($row)` writes every closure as `{}`.** An API endpoint that returns a row
straight out of the model emits `"update":{},"delete":{},"author":{}` alongside the real
columns. Suppress the closures for anything you serialise or cache:

```php
$rows = (new Posts)->closureMode(false)->get();          // no closures attached at all
```

**2. Closures cannot be serialised**, so a row destined for Redis or a session must be fetched
with `closureMode(false)` too. `Auth` does exactly this before caching the user row, then
re-attaches with `setClosures()`.

## The model class

```php
class Post extends Model
{
    use softDelete;                    // zFramework\Core\Traits\DB\softDelete

    public $table      = 'posts';
    public $primary    = 'id';
    public $guard      = ['secret'];   // stripped from get()/first() results
    public $observe    = PostObserver::class;
    public $_not_found = 'Post not found.';   // message for firstOrFail/findOrFail

    public function beginQuery()       // prepended to EVERY query on this model
    {
        // return $this->where('publish', 1);
    }

    public function author(array $data)                       // $data is the row
    {
        return $this->belongsTo(User::class, $data['user_id']);
    }
}
```

**A relation method's last parameter is the row**, injected by the closure. Extra parameters
come first, so `$post['comments']('approved')` reaches
`comments(string $state, array $data)`.

`beginQuery()` runs on every query — convenient for a global scope, and easy to forget when a
query mysteriously returns nothing.

## Writing

```php
$row   = $model->insert($sets);                      // returns the INSERTED ROW (array)
$count = $model->insert($sets, just_insert: true);   // returns the affected row count
$model->where('id', $id)->update($sets);             // returns affected rows
$model->where('id', $id)->delete();
```

`insert()` returning the whole row (not an id) is the surprise — `$post['id']` after it.

## Migrations

```php
namespace Database\Migrations;

class Posts
{
    static $charset = "utf8mb4_general_ci";
    static $table   = "posts";
    static $db      = "local";           // key from database/connections.php

    public static function columns()
    {
        return [
            'id'      => ['primary'],
            'title'   => ['varchar:255', 'index:find_user'],
            'user_id' => ['int', 'index:find_user'],
            'content' => ['text'],
            'status'  => ['varchar:20', 'default:draft'],

            'timestamps',      // created_at + updated_at, with ON UPDATE CURRENT_TIMESTAMP
            'softDelete',      // deleted_at, shape from config/model.php deleted_at_type
        ];
    }

    public static function oncreateSeeder(?string $db = null)
    {
        // runs once, when the table is first created
    }
}
```

Types: `primary` `int` `bigint` `smallint` `tinyint` `bool` `varchar:N` `char:N` `text`
`longtext` `json` `uuid` `decimal` `float` `real` `date` `datetime` `time`.
Modifiers: `required` `nullable` `unique:<name>` `index:<name>` `default:<value>`.

`unique:user` and `index:find_user` are **named** — two columns sharing a name form one
composite index. That is what `title` + `user_id` above do, and what `username` + `email` do
in the shipped `Users` migration.

`default:(UUID())` — parentheses make it an expression rather than a literal.

```bash
php terminal db migrate [--fresh] [--seed] [--module=blog]
```

Migrations are **idempotent, not additive**: re-running adds the columns that are missing and
**drops the ones no longer in `columns()`**, data included - removing a column from a migration
removes it from the table on the next `db migrate`. `--fresh` rebuilds every table and loses
all the data.

## Observers

```bash
php terminal make observer PostObserver
```

Attach with `public $observe = PostObserver::class;` on the model. Hooks:
`oninsert` / `oninserted` / `onupdate` / `onupdated` / `ondelete` / `ondeleted` — anything not
defined is simply not called. A soft delete fires the delete pair only, never the update pair,
even though it writes through an UPDATE.

```php
public function oninsert(array $sets): array
{
    $sets['slug'] = Str::slug($sets['title']);
    return $sets;             // the returned array replaces $sets
}

public function oninserted(array $row)
{
    // $row is the row as written, primary key included
}
```

Measured behaviour:

- **A returned array replaces the sets.** Returning `[]` (or anything falsy) leaves the
  original data intact — it is *not* lost. The framework does
  `if ($new_sets = $this->trigger(…)) $sets = $new_sets;`.
- **The generated stub declares `: array`**, so a body that returns nothing at all is a
  TypeError. Fill it in or drop the hook.
- **Only three hooks are handed anything.** `oninsert` and `onupdate` get the sets,
  `oninserted` gets the written row. `onupdated`, `ondelete` and `ondeleted` are called
  with no arguments (see `DB::update()/delete()`), so their `array $args` is always `[]` -
  they can say that something happened, not what. To act on the row, read it in
  `onupdate`/`ondelete`, or do the work where the model is saved.
- `oninserted` receives the row after it was written, which is why it can see the new id;
  it needs a primary key on the table to fire at all.
