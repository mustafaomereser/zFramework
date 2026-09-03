<?php

/**
 * php terminal tests run mongo
 *
 * MongoModel against a live server. Where it comes from, in order:
 *   1. config (framework.mongo.enabled with its uri)
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
    $GLOBALS['framework_config']['mongo'] = ['enabled' => true, 'uri' => $uri, 'database' => $database];
    \zFramework\Core\Facades\Config::clearCache();
}

if (!Mongo::available()) {
    test('mongodb', fn() => skip('mongo is not enabled and no ZF_MONGO_TEST'));
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
