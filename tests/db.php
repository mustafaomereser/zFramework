<?php

/**
 * php terminal tests run db [--db=local]
 *
 * The query builder against a real database: placeholders, empty IN lists,
 * booleans under STRICT mode, null foreign keys, non-increment primary keys,
 * soft delete + observers, unique/exists.
 */

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Abstracts\Observer;
use zFramework\Core\Facades\DB;

try {
    $pdo = Test::pdo();
} catch (\Throwable $e) {
    // Every test below needs the database; report one skip instead of a wall of failures.
    test('database connection', fn() => skip('no database on `' . Test::db() . '`: ' . $e->getMessage()));
    return;
}

// This file speaks MySQL DDL. Pointed at another driver (--db=, or a non-MySQL
// first connection) it skips; tests/pgsql.php covers the same ground there.
if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    test('database connection', fn() => skip('`' . Test::db() . '` is ' . $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) . ', this file tests MySQL - see tests/pgsql.php'));
    return;
}

$users = Test::table('users');
$items = Test::table('items');
$uuid  = Test::table('uuid');

Test::cleanup(function () use ($pdo, $users, $items, $uuid) {
    foreach ([$items, $users, $uuid] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    (new DB(Test::db()))->forgetScheme();
});

foreach ([$items, $users, $uuid] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE $users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20), email VARCHAR(50))");
$pdo->exec("CREATE TABLE $items (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, title VARCHAR(20), active TINYINT NOT NULL DEFAULT 1, deleted_at DATETIME NULL)");
$pdo->exec("CREATE TABLE $uuid (id CHAR(36) PRIMARY KEY, name VARCHAR(20))");
$pdo->exec("INSERT INTO $users (name, email) VALUES ('ali', 'ali@x.y'), ('veli', 'veli@x.y')");
$pdo->exec("INSERT INTO $items (user_id, title) VALUES (1, 'a'), (1, 'b'), (NULL, 'orphan'), (2, 'c')");
(new DB(Test::db()))->forgetScheme();

$GLOBALS['zf_test_observer'] = [];

class ZfTestObserver extends Observer
{
    public function onupdate(array $sets)
    {
        $GLOBALS['zf_test_observer'][] = 'onupdate';
        return $sets + ['title' => 'REWRITTEN'];
    }
    public function onupdated(array $a = [])
    {
        $GLOBALS['zf_test_observer'][] = 'onupdated';
    }
    public function ondelete(array $a = [])
    {
        $GLOBALS['zf_test_observer'][] = 'ondelete';
    }
    public function ondeleted(array $a = [])
    {
        $GLOBALS['zf_test_observer'][] = 'ondeleted';
    }
}

class ZfTestItem extends Model
{
    use \zFramework\Core\Traits\DB\softDelete;
    public $observe = ZfTestObserver::class;
    public function __construct()
    {
        $this->db    = Test::db();
        $this->table = Test::table('items');
        parent::__construct();
    }
    public function user($row)
    {
        return $this->belongsTo(ZfTestUser::class, $row['user_id']);
    }
}

class ZfTestUser extends Model
{
    public function __construct()
    {
        $this->db    = Test::db();
        $this->table = Test::table('users');
        parent::__construct();
    }
    public function items($row)
    {
        return $this->hasMany(ZfTestItem::class, $row['id'], 'user_id');
    }
}

class ZfTestUuid extends Model
{
    public function __construct()
    {
        $this->db    = Test::db();
        $this->table = Test::table('uuid');
        parent::__construct();
    }
}

test('having on an expression binds cleanly', function () {
    same(1, count((new ZfTestItem)->select('user_id, COUNT(*) as c')->groupBy(['user_id'])->having('COUNT(*)', '>', 1)->get()));
});

test('whereIn stays small and correct at 400 values', function () {
    $q = (new ZfTestItem)->whereIn('id', range(1, 400));
    truthy(strlen($q->buildSQL('select')) < 20000, 'SQL size');
    same(4, count((new ZfTestItem)->whereIn('id', range(1, 400))->get()));
});

test('empty IN lists mean none / all', function () {
    same(0, count((new ZfTestItem)->whereIn('id', [])->get()));
    same(4, count((new ZfTestItem)->whereNotIn('id', [])->get()));
});

test('booleans bind as ints under STRICT mode', function () use ($pdo, $items) {
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
    try {
        same('0', (string) ((new ZfTestItem)->insert(['title' => 'f', 'active' => false])['active'] ?? 'missing'));
        same(1, (new ZfTestItem)->where('title', 'f')->update(['active' => true]));
    } finally {
        $pdo->exec("SET SESSION sql_mode = ''");
        # Hard delete: the observer rewrote the title on update, and a soft
        # delete would leave the REWRITTEN row for the observer test to find.
        $pdo->exec("DELETE FROM $items WHERE title IN ('f', 'REWRITTEN')");
    }
});

test('a null foreign key is no relation, not a TypeError', function () {
    same(null, (new ZfTestItem)->user((new ZfTestItem)->where('title', 'orphan')->first()));
    $rows   = (new ZfTestItem)->with('user')->get();
    $orphan = array_values(array_filter($rows, fn($r) => $r['title'] === 'orphan'))[0];
    same(null, $orphan['user']);
    same('ali', $rows[0]['user']['name'] ?? null, 'eager load still resolves real keys');
});

test('insert returns the row on a non-increment primary key', function () {
    same('u', (new ZfTestUuid)->insert(['id' => '00000000-0000-0000-0000-000000000001', 'name' => 'u'])['name'] ?? null);
});

test('soft delete fires the delete pair, never the update pair', function () use ($pdo, $items) {
    $GLOBALS['zf_test_observer'] = [];
    (new ZfTestItem)->where('title', 'c')->delete();
    same(['ondelete', 'ondeleted'], $GLOBALS['zf_test_observer']);
    same(false, $pdo->query("SELECT id FROM $items WHERE title = 'REWRITTEN'")->fetch(), 'onupdate must not have rewritten the title');
});

test('a real update still fires the update pair', function () {
    $GLOBALS['zf_test_observer'] = [];
    (new ZfTestItem)->where('title', 'a')->update(['title' => 'a2']);
    same(['onupdate', 'onupdated'], array_slice($GLOBALS['zf_test_observer'], 0, 2));
});

test('soft-deleted rows are invisible, whereOr included', function () {
    $titles = array_column((new ZfTestItem)->get(), 'title');
    falsy(in_array('c', $titles, true), 'deleted row listed');
    same(0, count((new ZfTestItem)->where('title', 'c')->whereOr('title', 'c')->get()));
});

test('unique honours ex: by the primary key', function () {
    $try = function (int $ex) {
        try {
            \zFramework\Core\Validator::validate(['email' => 'ali@x.y'], ['email' => ['unique:ZfTestUser;key:email;ex:' . $ex]]);
            return 'passed';
        } catch (\zFramework\Core\ResponseSignal) {
            return 'failed';
        }
    };
    same('passed', $try(1), 'its own row');
    same('failed', $try(2), 'someone else already has it');
});
