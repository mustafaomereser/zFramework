<?php

/**
 * php terminal tests run mongo
 *
 * MongoModel against a live server. Where it comes from, in order:
 *   1. an entry in database/mongoconnections.php
 *   2. the ZF_MONGO_TEST env var - "mongodb://127.0.0.1:27017|databasename"
 * Neither present (or ext-mongodb missing): one skip line.
 */

use zFramework\Core\Abstracts\MongoModel;
use zFramework\Core\Abstracts\Observer;
use zFramework\Core\Facades\Mongo;

if (!extension_loaded('mongodb')) {
    test('mongodb', fn() => skip('ext-mongodb is not loaded'));
    return;
}

if (!Mongo::available() && ($env = getenv('ZF_MONGO_TEST'))) {
    [$uri, $database] = array_pad(explode('|', $env, 2), 2, 'zf_test');
    $GLOBALS['databases']['mongo'] = ['zf_test_mongo' => ['uri' => $uri, 'database' => $database]];
}

if (!Mongo::available()) {
    test('mongodb', fn() => skip('no entry in database/mongoconnections.php and no ZF_MONGO_TEST'));
    return;
}

try {
    Mongo::command(['ping' => 1]);
} catch (\Throwable $e) {
    test('mongodb', fn() => skip('no server: ' . $e->getMessage()));
    return;
}

$GLOBALS['zf_mongo_observer'] = [];

class ZfMongoObserver extends Observer
{
    public function oninsert(array $sets)
    {
        $GLOBALS['zf_mongo_observer'][] = 'oninsert';
        $sets['stamped'] = true;
        return $sets;
    }
    public function oninserted(array $a = [])
    {
        $GLOBALS['zf_mongo_observer'][] = 'oninserted';
    }
    public function ondelete(array $a = [])
    {
        $GLOBALS['zf_mongo_observer'][] = 'ondelete';
    }
    public function ondeleted(array $a = [])
    {
        $GLOBALS['zf_mongo_observer'][] = 'ondeleted';
    }
}

class ZfMongoLog extends MongoModel
{
    public $observe = ZfMongoObserver::class;
    public function __construct()
    {
        $this->collection = Test::table('logs');
        parent::__construct();
    }
    public function indexes(): array
    {
        return [['key' => ['level' => 1, 'at' => -1]], ['key' => ['email' => 1], 'unique' => true]];
    }
}

class ZfMongoGuarded extends MongoModel
{
    public $guard = ['secret'];
    public function __construct()
    {
        $this->collection = Test::table('logs');
        parent::__construct();
    }
}

Test::cleanup(function () {
    try {
        Mongo::command(['drop' => Test::table('logs')]);
    } catch (\Throwable) {
        # a run that never created it
    }
});

try {
    Mongo::command(['drop' => Test::table('logs')]);
} catch (\Throwable) {
}

test('insert fills _id, returns the row, fires the observer', function () {
    $GLOBALS['zf_mongo_observer'] = [];
    $row = (new ZfMongoLog)->insert(['level' => 'error', 'email' => 'a@x.y', 'at' => 3, 'secret' => 's1']);
    same(24, strlen($row['_id'] ?? ''), '_id is the hex string');
    same(true, $row['stamped'] ?? null, 'oninsert rewrote the sets');
    same(['oninsert', 'oninserted'], $GLOBALS['zf_mongo_observer']);

    (new ZfMongoLog)->insert(['level' => 'info', 'email' => 'b@x.y', 'at' => 1, 'secret' => 's2']);
    (new ZfMongoLog)->insert(['level' => 'error', 'email' => 'c@x.y', 'at' => 2, 'secret' => 's3']);
});

test('where / whereOr / orderBy / limit read like the SQL side', function () {
    same(2, count((new ZfMongoLog)->where('level', 'error')->get()));
    same(3, (new ZfMongoLog)->count());
    same(2, (new ZfMongoLog)->where('at', '>=', 2)->count());

    $rows = (new ZfMongoLog)->orderBy(['at' => 'DESC'])->limit(2)->get();
    same([3, 2], array_column($rows, 'at'), 'sorted, limited');

    $rows = (new ZfMongoLog)->orderBy(['at' => 'ASC'])->limit(1, 2)->get();
    same([2, 3], array_column($rows, 'at'), 'limit(1, 2) is offset 1, two rows');

    same(3, count((new ZfMongoLog)->where('level', 'error')->whereOr('level', 'info')->get()), 'or group AND-ed in');
    same(3, count((new ZfMongoLog)->where('at', '>', 1)->whereOr('level', 'info')->whereOr('at', 1)->get()), '(at>1) OR info OR at=1 - the SQL reading');
});

test('find round-trips the hex id, update and delete count', function () {
    $row = (new ZfMongoLog)->where('email', 'a@x.y')->first();
    $found = (new ZfMongoLog)->find($row['_id']);
    same($row['email'], $found['email'] ?? null, 'find by the string id');

    same(2, (new ZfMongoLog)->where('level', 'error')->update(['level' => 'warn']));
    same(0, (new ZfMongoLog)->where('level', 'error')->count());

    $GLOBALS['zf_mongo_observer'] = [];
    same(1, (new ZfMongoLog)->where('email', 'b@x.y')->delete());
    same(['ondelete', 'ondeleted'], $GLOBALS['zf_mongo_observer']);
    same(2, (new ZfMongoLog)->count());
});

test('guard hides fields, select overrides it', function () {
    $row = (new ZfMongoGuarded)->first();
    falsy(array_key_exists('secret', $row), 'guarded field leaked');
    truthy(array_key_exists('email', $row));

    $row = (new ZfMongoGuarded)->select('secret, email')->first();
    truthy(array_key_exists('secret', $row), 'select overrides the guard');
});

test('whereIn semantics and the aggregate escape hatch', function () {
    same(0, count((new ZfMongoLog)->whereIn('level', [])->get()), 'empty IN matches nothing');
    same(2, count((new ZfMongoLog)->whereIn('level', ['warn', 'info'])->get()));

    $grouped = (new ZfMongoLog)->aggregate([
        ['$group' => ['_id' => '$level', 'n' => ['$sum' => 1]]],
        ['$sort' => ['n' => -1]],
    ]);
    same('warn', $grouped[0]['_id'] ?? null);
    same(2, $grouped[0]['n'] ?? null);
});

test('validator unique/exists answer through a MongoModel', function () {
    $try = function (string $rule, string $value) {
        try {
            \zFramework\Core\Validator::validate(['email' => $value], ['email' => [$rule . ':ZfMongoLog;key:email']]);
            return 'passed';
        } catch (\zFramework\Core\ResponseSignal) {
            return 'failed';
        }
    };
    same('failed', $try('unique', 'a@x.y'), 'taken email fails unique');
    same('passed', $try('unique', 'new@x.y'));
    same('passed', $try('exists', 'a@x.y'), 'exists finds it');
    same('failed', $try('exists', 'ghost@x.y'));
});

test('mongo indexes creates what the model declares', function () {
    # The command scans App/Models, so the model goes there for a moment.
    $stub = base_path('App/Models/ZfTestMongoIndexStub.php');
    Test::cleanup(fn() => @unlink($stub));
    file_put_contents($stub, '<?php namespace App\Models; class ZfTestMongoIndexStub extends \zFramework\Core\Abstracts\MongoModel { public $collection = "' . Test::table('logs') . '"; public function indexes(): array { return [["key" => ["level" => 1, "at" => -1]], ["key" => ["email" => 1], "unique" => true]]; } }');

    \zFramework\Kernel\Terminal::$terminate = true;
    ob_start();
    \zFramework\Kernel\Terminal::begin('mongo indexes');
    $out = ob_get_clean();
    @unlink($stub);
    contains(Test::table('logs'), $out, 'the command reported the collection');

    $names = array_column(Mongo::command(['listIndexes' => Test::table('logs')]), 'name');
    contains('level_1_at_-1', $names);
    contains('email_1', $names);
});

# ─────────────────────────────────────────────
# Relations, cross-store, paginate, the Mongo-only verbs, model-less use
# ─────────────────────────────────────────────

class ZfMongoPost extends MongoModel
{
    public function __construct()
    {
        $this->collection = Test::table('posts');
        parent::__construct();
    }
    public function comments($row)
    {
        return $this->hasMany(ZfMongoComment::class, $row['_id'], 'post_id');
    }
    public function firstComment($row)
    {
        return $this->hasOne(ZfMongoComment::class, $row['_id'], 'post_id');
    }
    public function author($row)
    {
        # crosses the store: the author lives in MySQL
        return $this->belongsTo(ZfMongoSqlUser::class, $row['user_id']);
    }
}

class ZfMongoComment extends MongoModel
{
    public function __construct()
    {
        $this->collection = Test::table('comments');
        parent::__construct();
    }
    public function post($row)
    {
        return $this->belongsTo(ZfMongoPost::class, $row['post_id']);
    }
}

# An SQL model (MySQL) whose relation points INTO Mongo.
class ZfMongoSqlUser extends \zFramework\Core\Abstracts\Model
{
    public function __construct()
    {
        $this->db    = Test::db();
        $this->table = Test::table('mongo_users');
        parent::__construct();
    }
    public function posts($row)
    {
        return $this->hasMany(ZfMongoPost::class, $row['id'], 'user_id');
    }
}

Test::cleanup(function () {
    foreach (['posts', 'comments'] as $c) {
        try {
            Mongo::command(['drop' => Test::table($c)]);
        } catch (\Throwable) {
        }
    }
    try {
        Test::pdo()->exec('DROP TABLE IF EXISTS ' . Test::table('mongo_users'));
    } catch (\Throwable) {
    }
});

$post1 = (new ZfMongoPost)->insert(['title' => 'first', 'user_id' => 1, 'views' => 0, 'tags' => ['a']]);
$post2 = (new ZfMongoPost)->insert(['title' => 'second', 'user_id' => 2, 'views' => 5, 'tags' => []]);
(new ZfMongoPost)->insert(['title' => 'orphan', 'user_id' => null, 'views' => 1, 'tags' => []]);
(new ZfMongoComment)->insert(['post_id' => $post1['_id'], 'text' => 'c1']);
(new ZfMongoComment)->insert(['post_id' => $post1['_id'], 'text' => 'c2']);
(new ZfMongoComment)->insert(['post_id' => $post2['_id'], 'text' => 'c3']);

test('hasMany / hasOne / belongsTo between collections', function () use ($post1) {
    same(['c1', 'c2'], array_column((new ZfMongoPost)->comments($post1), 'text'));
    same('c1', (new ZfMongoPost)->firstComment($post1)['text'] ?? null);

    $comment = (new ZfMongoComment)->where('text', 'c3')->first();
    same('second', (new ZfMongoComment)->post($comment)['title'] ?? null, 'belongsTo through the hex _id');
});

test('with() eager-loads in one query per relation', function () {
    $posts   = (new ZfMongoPost)->orderBy(['title' => 'ASC'])->with('comments', 'firstComment')->get();
    $byTitle = array_column($posts, null, 'title');
    same(2, count($byTitle['first']['comments']));
    same(1, count($byTitle['second']['comments']));
    same([], $byTitle['orphan']['comments']);
    same('c1', $byTitle['first']['firstComment']['text'] ?? null);
    same(null, $byTitle['orphan']['firstComment']);
});

test('relations cross the store: Mongo post -> MySQL author, MySQL user -> Mongo posts', function () {
    $pdo = Test::pdo();
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') skip('needs the MySQL connection for the SQL half');

    $t = Test::table('mongo_users');
    $pdo->exec("DROP TABLE IF EXISTS $t");
    $pdo->exec("CREATE TABLE $t (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))");
    $pdo->exec("INSERT INTO $t (name) VALUES ('ali'), ('veli')");
    (new \zFramework\Core\Facades\DB(Test::db()))->forgetScheme();

    $post = (new ZfMongoPost)->where('title', 'first')->first();
    same('ali', (new ZfMongoPost)->author($post)['name'] ?? null, 'Mongo -> MySQL lazily');

    $posts = (new ZfMongoPost)->orderBy(['title' => 'ASC'])->with('author')->get();
    same(['ali', null, 'veli'], array_map(fn($p) => $p['author']['name'] ?? null, $posts), 'Mongo -> MySQL eagerly');

    $ali = (new ZfMongoSqlUser)->find(1);
    same(['first'], array_column((new ZfMongoSqlUser)->posts($ali), 'title'), 'MySQL -> Mongo lazily');

    $users = (new ZfMongoSqlUser)->with('posts')->get();
    same([1, 1], array_map(fn($u) => count($u['posts']), $users), 'MySQL -> Mongo eagerly, one $in query');
});

test('paginate answers in the SQL shape', function () {
    $_REQUEST['page'] = '2';
    $page = (new ZfMongoPost)->orderBy(['title' => 'ASC'])->paginate(2);
    unset($_REQUEST['page']);

    same(3, $page['item_count']);
    same(2, $page['page_count']);
    same(2, $page['current_page']);
    same(1, count($page['items']), 'page 2 of 3 items at 2 per page');
    same('second', $page['items'][0]['title']);
    truthy($page['links'] instanceof \Closure);
});

test('increment, push, pull, updateOrInsert, distinct, exists', function () {
    same(1, (new ZfMongoPost)->where('title', 'first')->increment('views', 3));
    same(3, (new ZfMongoPost)->where('title', 'first')->first()['views']);
    same(1, (new ZfMongoPost)->where('title', 'first')->decrement('views'));
    same(2, (new ZfMongoPost)->where('title', 'first')->first()['views']);

    (new ZfMongoPost)->where('title', 'first')->push('tags', 'b');
    same(['a', 'b'], (new ZfMongoPost)->where('title', 'first')->first()['tags']);
    (new ZfMongoPost)->where('title', 'first')->pull('tags', 'a');
    same(['b'], (new ZfMongoPost)->where('title', 'first')->first()['tags']);

    same(1, (new ZfMongoPost)->where('title', 'upserted')->updateOrInsert(['views' => 9]), 'inserted when missing');
    same(9, (new ZfMongoPost)->where('title', 'upserted')->first()['views'], 'the filter equality became a field');
    same(1, (new ZfMongoPost)->where('title', 'upserted')->updateOrInsert(['views' => 10]), 'updated when present');
    same(4, (new ZfMongoPost)->count());

    $titles = (new ZfMongoPost)->distinct('title');
    sort($titles);
    same(['first', 'orphan', 'second', 'upserted'], $titles);
    truthy((new ZfMongoPost)->where('title', 'orphan')->exists());
    falsy((new ZfMongoPost)->where('title', 'ghost')->exists());
});

test('model-less: new Mongo()->collection() reads like new DB()->table()', function () {
    same(4, (new Mongo)->collection(Test::table('posts'))->count());
    same('second', (new Mongo)->collection(Test::table('posts'))->where('views', 5)->first()['title'] ?? null);
});

class ZfMongoGuardedComment extends MongoModel
{
    public $guard = ['post_id'];
    public function __construct()
    {
        $this->collection = Test::table('comments');
        parent::__construct();
    }
}

class ZfMongoPostGuardedRel extends MongoModel
{
    public function __construct()
    {
        $this->collection = Test::table('posts');
        parent::__construct();
    }
    public function comments($row)
    {
        return $this->hasMany(ZfMongoGuardedComment::class, $row['_id'], 'post_id');
    }
}

test('review fixes: guarded FK eager, empty select keeps the guard, aggregate honours where, no eager leak', function () {
    $posts = (new ZfMongoPostGuardedRel)->orderBy(['title' => 'ASC'])->with('comments')->get();
    $first = array_column($posts, null, 'title')['first'];
    same(2, count($first['comments']), 'eager through a guarded FK');
    truthy(array_key_exists('post_id', $first['comments'][0]), 'the FK is what groups the rows, so it is handed back - as the SQL trait does');

    $row = (new ZfMongoGuardedComment)->select([])->first();
    falsy(array_key_exists('post_id', $row), 'select([]) must not switch the guard off');
    $row = (new ZfMongoGuardedComment)->select('')->first();
    falsy(array_key_exists('post_id', $row), 'select(\'\') must not switch the guard off');

    $grouped = (new ZfMongoPost)->where('title', 'first')->aggregate([['$group' => ['_id' => null, 'n' => ['$sum' => 1]]]]);
    same(1, $grouped[0]['n'] ?? null, 'the where() chain became the first $match');

    $model = new ZfMongoPost;
    same([], $model->where('title', 'nothing-here')->with('comments')->get());
    $rows = $model->where('title', 'first')->get();
    falsy(array_key_exists('comments', $rows[0]), 'a relation queued for an empty result must not leak into the next query');
});
