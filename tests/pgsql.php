<?php

/**
 * php terminal tests run pgsql
 *
 * The whole query surface against a real PostgreSQL - the same ground
 * tests/db.php covers on MySQL, through Drivers/pgsql.php.
 *
 * Where the server comes from, in order:
 *   1. a connection in database/connections.php whose DSN starts with pgsql:
 *   2. the ZF_PGSQL_TEST env var - "pgsql:host=...;dbname=...|user|pass"
 * Neither present (or pdo_pgsql missing): one skip line, not failures.
 */

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Facades\DB;

$key = null;
foreach ($GLOBALS['databases']['connections'] as $name => $connection)
    if (is_array($connection) && str_starts_with((string) $connection[0], 'pgsql:')) $key = $name;

if ($key === null && ($env = getenv('ZF_PGSQL_TEST'))) {
    [$dsn, $user, $pass] = array_pad(explode('|', $env, 3), 3, '');
    $GLOBALS['databases']['connections'][$key = 'zf_test_pgsql'] = [$dsn, $user, $pass];
}

if (!in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
    test('postgresql', fn() => skip('pdo_pgsql is not loaded (php.ini: extension=pdo_pgsql)'));
    return;
}

if ($key === null) {
    test('postgresql', fn() => skip('no pgsql connection in connections.php and no ZF_PGSQL_TEST'));
    return;
}

try {
    $pdo = (new DB($key))->connection();
    if (!$pdo instanceof \PDO) throw new \Exception('connection() returned ' . var_export($pdo, true));
} catch (\Throwable $e) {
    test('postgresql', fn() => skip("`$key` did not connect: " . $e->getMessage()));
    return;
}

$GLOBALS['zf_pg_key'] = $key;

$users = Test::table('users');
$items = Test::table('items');
$uuid  = Test::table('uuid');

Test::cleanup(function () use ($pdo, $users, $items, $uuid) {
    foreach ([$items, $users, $uuid] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    (new DB($GLOBALS['zf_pg_key']))->forgetScheme();
});

foreach ([$items, $users, $uuid] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE $users (id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name VARCHAR(20), email VARCHAR(50))");
$pdo->exec("CREATE TABLE $items (id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, user_id INT NULL, title VARCHAR(20), active SMALLINT NOT NULL DEFAULT 1, deleted_at TIMESTAMP NULL)");
$pdo->exec("CREATE TABLE $uuid (id CHAR(36) PRIMARY KEY, name VARCHAR(20))");
$pdo->exec("INSERT INTO $users (name, email) VALUES ('ali', 'ali@x.y'), ('veli', 'veli@x.y')");
$pdo->exec("INSERT INTO $items (user_id, title) VALUES (1, 'a'), (1, 'b'), (NULL, 'orphan'), (2, 'c')");
(new DB($key))->forgetScheme();

class ZfPgItem extends Model
{
    use \zFramework\Core\Traits\DB\softDelete;
    public function __construct()
    {
        $this->db    = $GLOBALS['zf_pg_key'];
        $this->table = Test::table('items');
        parent::__construct();
    }
    public function user($row)
    {
        return $this->belongsTo(ZfPgUser::class, $row['user_id']);
    }
}

class ZfPgUser extends Model
{
    public function __construct()
    {
        $this->db    = $GLOBALS['zf_pg_key'];
        $this->table = Test::table('users');
        parent::__construct();
    }
    public function items($row)
    {
        return $this->hasMany(ZfPgItem::class, $row['id'], 'user_id');
    }
}

class ZfPgUuid extends Model
{
    public function __construct()
    {
        $this->db    = $GLOBALS['zf_pg_key'];
        $this->table = Test::table('uuid');
        parent::__construct();
    }
}

test('the scheme cache reads tables, columns and primaries', function () use ($users) {
    $model = new ZfPgUser;
    contains('id', $model->columns());
    contains('email', $model->columns());
    same('id', $model->getPrimary());
});

test('select, where and count answer as on MySQL', function () {
    same(4, (new ZfPgItem)->count());
    same('a', (new ZfPgItem)->where('title', 'a')->first()['title'] ?? null);
    same(2, count((new ZfPgItem)->where('user_id', 1)->get()));
});

test('limit keeps its MySQL signature over OFFSET', function () {
    $rows = (new ZfPgItem)->orderBy(['id' => 'ASC'])->limit(2)->get();
    same(2, count($rows), 'limit(2) is two rows');
    same('a', $rows[0]['title']);

    $rows = (new ZfPgItem)->orderBy(['id' => 'ASC'])->limit(1, 2)->get();
    same(['b', 'orphan'], array_column($rows, 'title'), 'limit(1, 2) is offset 1, two rows');
});

test('insert returns the row in one round-trip (RETURNING)', function () {
    $row = (new ZfPgItem)->insert(['title' => 'ins', 'user_id' => 2]);
    same('ins', $row['title'] ?? null);
    truthy(($row['id'] ?? 0) > 4, 'the identity key came back');
    (new ZfPgItem)->where('title', 'ins')->delete();
});

test('insert returns the row on a non-serial primary key', function () {
    same('u', (new ZfPgUuid)->insert(['id' => '00000000-0000-0000-0000-000000000001', 'name' => 'u'])['name'] ?? null);
});

test('empty IN lists mean none / all', function () {
    same(0, count((new ZfPgItem)->whereIn('id', [])->get()));
    truthy(count((new ZfPgItem)->whereNotIn('id', [])->get()) >= 4);
});

test('whereIn at 400 values, having on an expression', function () {
    same(4, count((new ZfPgItem)->whereIn('id', range(1, 400))->get()));
    same(1, count((new ZfPgItem)->select('user_id, COUNT(*) as c')->groupBy(['user_id'])->having('COUNT(*)', '>', 1)->get()));
});

test('a null foreign key is no relation, eager included', function () {
    $rows   = (new ZfPgItem)->orderBy(['id' => 'ASC'])->with('user')->get();
    $orphan = array_values(array_filter($rows, fn($r) => $r['title'] === 'orphan'))[0];
    same(null, $orphan['user']);
    same('ali', $rows[0]['user']['name'] ?? null);
});

test('soft delete hides rows, update and paginate still work', function () {
    (new ZfPgItem)->where('title', 'c')->delete();
    falsy(in_array('c', array_column((new ZfPgItem)->get(), 'title'), true));

    same(1, (new ZfPgItem)->where('title', 'a')->update(['title' => 'a2']));

    $page = (new ZfPgItem)->orderBy(['id' => 'ASC'])->paginate(2);
    same(2, count($page['items']));
});

test('unique/exists run through a pgsql model', function () {
    $try = function (string $email) {
        try {
            \zFramework\Core\Validator::validate(['email' => $email], ['email' => ['unique:ZfPgUser;key:email']]);
            return 'passed';
        } catch (\zFramework\Core\ResponseSignal) {
            return 'failed';
        }
    };
    same('failed', $try('ali@x.y'));
    same('passed', $try('new@x.y'));
});

class ZfPgRole extends Model
{
    public function __construct()
    {
        $this->db    = $GLOBALS["zf_pg_key"];
        $this->table = Test::table("roles");
        parent::__construct();
    }
}

class ZfPgUserRel extends Model
{
    public function __construct()
    {
        $this->db    = $GLOBALS["zf_pg_key"];
        $this->table = Test::table("users");
        parent::__construct();
    }
    public function roles($row)
    {
        return $this->belongsToMany(ZfPgRole::class, Test::table("user_roles"), $row["id"], "user_id", "role_id");
    }
}

test("whereBetween, whereNot, fetchType behave", function () {
    same(2, count((new ZfPgItem)->whereBetween("user_id", 1, 2)->get()));
    truthy(count((new ZfPgItem)->whereNot("title", "a2")->get()) >= 2);

    $unique = (new ZfPgItem)->select("title, id")->fetchType("unique")->get();
    truthy(isset($unique["a2"]), "FETCH_UNIQUE keys by the first selected column");
});

test("withRealOrder ranks on PostgreSQL too", function () {
    $rows = (new ZfPgItem)->orderBy(["id" => "ASC"])->withRealOrder()->get();
    truthy(isset($rows[0]["real_order"]), "the ranking column came back");
    same(count($rows), (int) $rows[0]["real_order"], "first by id = highest rank DESC");
});

test("updateOrInsert scoped by where()", function () {
    (new ZfPgItem)->where("title", "uoi")->updateOrInsert(["title" => "uoi", "user_id" => 9]);
    same(1, (new ZfPgItem)->where("title", "uoi")->count(), "inserted when missing");
    (new ZfPgItem)->where("title", "uoi")->updateOrInsert(["user_id" => 10]);
    same("10", (string) (new ZfPgItem)->where("title", "uoi")->first()["user_id"], "updated when found");
    (new ZfPgItem)->where("title", "uoi")->delete();
});

test("pivot relation joins through the pivot table", function () use ($pdo) {
    $roles = Test::table("roles");
    $pivot = Test::table("user_roles");
    foreach ([$pivot, $roles] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    $pdo->exec("CREATE TABLE $roles (id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name VARCHAR(20))");
    $pdo->exec("CREATE TABLE $pivot (user_id INT, role_id INT)");
    Test::cleanup(function () use ($pdo, $pivot, $roles) {
        foreach ([$pivot, $roles] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    });
    $pdo->exec("INSERT INTO $roles (name) VALUES ('admin'), ('editor')");
    $pdo->exec("INSERT INTO $pivot VALUES (1, 1), (1, 2), (2, 2)");
    (new \zFramework\Core\Facades\DB($GLOBALS["zf_pg_key"]))->forgetScheme();

    $ali = (new ZfPgUserRel)->find(1);
    same(["admin", "editor"], array_column((new ZfPgUserRel)->roles($ali), "name"));
});

test("transactions commit and roll back", function () {
    $m = new ZfPgItem;
    $m->beginTransaction();
    $m->insert(["title" => "tx", "user_id" => 1]);
    $m->rollback();
    same(0, (new ZfPgItem)->where("title", "tx")->count(), "rolled back");

    $m = new ZfPgItem;
    $m->beginTransaction();
    $m->insert(["title" => "tx", "user_id" => 1]);
    $m->commit();
    same(1, (new ZfPgItem)->where("title", "tx")->count(), "committed");
    (new ZfPgItem)->where("title", "tx")->delete();
});
