<?php

/**
 * php terminal tests run analyzer [--db=local]
 *
 * The query analyzer against the server's real plans, on whichever of MySQL and
 * MariaDB the connection reaches: their plans are spelled differently, and a parser
 * that only ever met one of them is what this file exists to prevent.
 */

use zFramework\Core\Facades\DB;
use zFramework\Core\Facades\DB\Analyzer\Analyze;
use zFramework\Core\Facades\DB\Analyzer\DbCollector;

$testDb = Test::db();

try {
    $pdo = Test::pdo();
} catch (\Throwable $e) {
    test('database connection', fn() => skip("no database on `$testDb`: " . $e->getMessage()));
    return;
}

if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    test('database connection', fn() => skip("`$testDb` is not MySQL; the analyzer skips other drivers"));
    return;
}

$a = Test::table('analyze_a');
$b = Test::table('analyze_b');
$directory = BASE_PATH . '/analysis/queries';

# The directory is only removed if this file is what made it.
$hadDirectory = is_dir(dirname($directory));

$clearRecords = function () use (&$written) {
    foreach ($written ?? [] as $file) @unlink($file);
    $written = [];
};

Test::cleanup(function () use ($pdo, $a, $b, $clearRecords, $directory, $hadDirectory) {
    $pdo->exec("DROP TABLE IF EXISTS $b, $a");
    $clearRecords();
    if (!$hadDirectory) {
        @rmdir($directory);
        @rmdir(dirname($directory));
    }
});

$pdo->exec("DROP TABLE IF EXISTS $b, $a");
$pdo->exec("CREATE TABLE $a (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(50), status INT, name VARCHAR(50))");
$pdo->exec("CREATE TABLE $b (id INT AUTO_INCREMENT PRIMARY KEY, a_id INT, note VARCHAR(50))");
$rows = $joined = [];
for ($i = 0; $i < 300; $i++) {
    $rows[]   = "('u$i@x'," . ($i % 3) . ",'n$i')";
    $joined[] = "($i,'t$i')";
}
$pdo->exec("INSERT INTO $a (email, status, name) VALUES " . implode(',', $rows));
$pdo->exec("INSERT INTO $b (a_id, note) VALUES " . implode(',', $joined));
$pdo->query("ANALYZE TABLE $a, $b")->fetchAll();

$db = new DB($testDb);
$db->ignoreAnalyze = true;

/** Runs the analyzer on one query with a fresh id and hands back what it recorded. */
$analyze = function (string $sql, array $data = [], ?int $returned = null) use ($db, $directory, &$written) {
    Analyze::flushRequestState();
    DbCollector::analyze($db, $sql, $data, 0.001, $returned);
    $file = $directory . '/' . Analyze::id() . '.jsonl';
    if (!is_file($file)) return null;
    $written[] = $file;
    return json_decode(trim(file($file)[0]), true);
};

test('a join reports every table, the one scanned too', function () use ($analyze, $a, $b) {
    $r = $analyze("SELECT x.email, y.note FROM $a x JOIN $b y ON y.a_id = x.id WHERE x.status = :s", ['s' => 1]);

    truthy($r, 'recorded');
    $tables = $r['tables'];
    sort($tables);
    $names = [$a, $b];
    sort($names);
    same($names, $tables, json_encode($r));
    truthy(array_filter(array_keys($r['warnings']), fn($t) => in_array('FULL_SCAN', $r['warnings'][$t])), json_encode($r));
});

test('the rows really read are measured, on this server\'s own form', function () use ($analyze, $a) {
    $r = $analyze("SELECT * FROM $a WHERE status = 1");

    same(300, $r['metrics'][$a]['actual_rows'] ?? null, json_encode($r));
    truthy(isset($r['metrics'][$a]['actual_time_ms']), json_encode($r));
    falsy(isset($r['warnings']['query']), json_encode($r));
    same(300, $r['row_stats'][$a]['scanned'], json_encode($r));
});

test('an alias is the table it stands for', function () use ($analyze, $a) {
    $r = $analyze("SELECT u.* FROM $a u WHERE u.status = 1");

    same([$a], $r['tables'], json_encode($r));
    same(300, $r['metrics'][$a]['estimated_rows'], json_encode($r));
    contains("SMALL_TABLE:$a(~300 rows)", implode(' ', $r['warnings'][$a]));
    falsy(str_contains(implode(' ', $r['warnings'][$a]), 'BAD_ESTIMATION'), json_encode($r));
});

test('a derived table is not reported as an empty one', function () use ($analyze, $a) {
    $r = $analyze("SELECT * FROM (SELECT status, COUNT(*) c FROM $a GROUP BY status) x");

    truthy(in_array($a, $r['tables']), json_encode($r));
    foreach ($r['tables'] as $table) if ($table !== $a) {
        same(null, $r['metrics'][$table]['estimated_rows'] ?? null, json_encode($r));
        falsy(str_contains(implode(' ', $r['warnings'][$table] ?? []), 'SMALL_TABLE'), json_encode($r));
    }
});

test('the builder\'s own SQL gets an index suggestion, on the table it belongs to', function () use ($analyze, $a, $b, $testDb) {
    $suggest = fn(array $r, string $table) => $r['index_suggestions'][$table]['stable'][0] ?? null;

    $sql = "SELECT $a.* FROM $a  WHERE  email = :email  ";
    same("CREATE INDEX idx_{$a}_email ON $a (email)", $suggest($analyze($sql, ['email' => 'x']), $a));

    $sql = "SELECT * FROM $a WHERE status = :s AND name > :n ORDER BY email DESC LIMIT 5";
    same("CREATE INDEX idx_{$a}_status_name_email ON $a (status, name, email)", $suggest($analyze($sql, ['s' => 1, 'n' => 'a']), $a));

    same("CREATE INDEX idx_{$a}_name ON $a (name)", $suggest($analyze("SELECT * FROM $a ORDER BY name ASC"), $a));
    same("CREATE INDEX idx_{$a}_email ON $a (email)", $suggest($analyze("SELECT * FROM $a WHERE email LIKE 'u5%'"), $a));

    $r = $analyze("SELECT x.email, y.note FROM $a x JOIN $b y ON y.a_id = x.id WHERE x.status = 1");
    foreach ($r['index_suggestions'] as $table => $kinds) foreach ($kinds['stable'] ?? [] as $statement) contains(" ON $table (", $statement);
    same("CREATE INDEX idx_{$b}_a_id ON $b (a_id)", $suggest($r, $b), json_encode($r));

    $r = $analyze("SELECT * FROM $a WHERE email = 'a = b AND status = 1'");
    same("CREATE INDEX idx_{$a}_email ON $a (email)", $suggest($r, $a), 'a value is not read as SQL');
});

test('no suggestion for what an index already answers', function () use ($analyze, $a) {
    $r = $analyze("SELECT * FROM $a WHERE id = 5");

    same([], $r['index_suggestions'], json_encode($r));
    truthy(in_array('PRIMARY', $r['used_indexes']), json_encode($r));
});

test('returned is what the caller says came back, not the LIMIT', function () use ($analyze, $a) {
    same(0, $analyze("SELECT * FROM $a WHERE status = 99 LIMIT 10", [], 0)['row_stats'][$a]['returned']);
    same(10, $analyze("SELECT * FROM $a LIMIT 5, 10", [], 10)['row_stats'][$a]['returned']);
});

test('a query the framework runs reports its real row count', function () use ($pdo, $a, $testDb, $directory, &$written) {
    $rc = new \ReflectionProperty(\zFramework\Core\Facades\Config::class, 'frameworkCache');
    $rc->setAccessible(true);
    \zFramework\Core\Facades\Config::framework('profiling.keep');
    $saved = $rc->getValue();

    $config = $saved;
    $config['profiling']['queryAnalyze'] = true;
    $config['debug'] = true;
    $rc->setValue(null, $config);

    Analyze::flushRequestState();
    try {
        $db = new DB($testDb);
        $db->prepare("SELECT * FROM $a WHERE status = :s LIMIT 10", ['s' => 99]);
    } finally {
        $rc->setValue(null, $saved);
    }

    $file = $directory . '/' . Analyze::id() . '.jsonl';
    if (!is_file($file)) skip('debug is off in this application, the analyzer does not run');
    $written[] = $file;
    same(0, json_decode(trim(file($file)[0]), true)['row_stats'][$a]['returned']);
});

test('a fingerprint is the shape, whatever the values and names', function () {
    $same = fn(string $x, string $y) => same(DbCollector::fingerprint($x), DbCollector::fingerprint($y), "$x | $y");

    $same('SELECT * FROM t WHERE id IN (:a, :b)', 'SELECT * FROM t WHERE id IN (:a, :b, :c)');
    $same('SELECT * FROM t WHERE id IN (1, 2)', 'SELECT * FROM t WHERE id IN (3, 4, 5)');
    $same('SELECT * FROM t WHERE id BETWEEN :start_6aea9520f35bb AND :stop_6aea9520f35bb', 'SELECT * FROM t WHERE id BETWEEN :start_6aea952104d35 AND :stop_6aea952104d35');
    $same("SELECT * FROM t WHERE a = 'o\\'brien'", "SELECT * FROM t WHERE a = :a");
    $same('SELECT * FROM t WHERE a = "x"', "SELECT * FROM t WHERE a = 'y'");

    truthy(DbCollector::fingerprint('SELECT * FROM t1') !== DbCollector::fingerprint('SELECT * FROM t2'), 'a table name is not a value');
});

test('only what is a SELECT counts as one', function () {
    foreach (['SELECT 1', ' (SELECT 1) UNION (SELECT 2)', '/* c */ SELECT 1', "-- c\nSELECT 1", 'WITH t AS (SELECT 1) SELECT * FROM t', "SELECT 'UPDATE x'", "WITH t AS (SELECT 'DELETE') SELECT * FROM t"] as $sql) truthy(DbCollector::isSelect($sql), $sql);
    foreach (['UPDATE t SET a = 1', 'DELETE FROM t', 'WITH t AS (SELECT 1) UPDATE x JOIN t SET a = 1', 'INSERT INTO t SELECT 1', "/* SELECT */ DELETE FROM t"] as $sql) falsy(DbCollector::isSelect($sql), $sql);
});

test('what would act twice is explained, not run again', function () use ($pdo, $analyze, $a) {
    $pdo->exec("SET @zf_analyze := 0");
    $r = $analyze("SELECT @zf_analyze := @zf_analyze + 1 FROM $a LIMIT 1");
    same('0', (string) $pdo->query("SELECT @zf_analyze")->fetchColumn(), 'the variable did not move');
    truthy($r, 'and it is still recorded');

    Analyze::flushRequestState();
    $pdo->query("SELECT SQL_CALC_FOUND_ROWS * FROM $a LIMIT 1")->fetchAll();
    same(null, $analyze("SELECT SQL_CALC_FOUND_ROWS * FROM $a LIMIT 1"));
    $pdo->query("SELECT SQL_CALC_FOUND_ROWS * FROM $a LIMIT 1")->fetchAll();
    same(300, (int) $pdo->query("SELECT FOUND_ROWS()")->fetchColumn());
});

test('sqlDebug measures a SELECT on this server, and never runs a write twice', function () use ($pdo, $a, $testDb) {
    $folder = BASE_PATH . '/db-debug';
    $had    = is_dir($folder);
    $before = glob("$folder/*") ?: [];

    $db = new DB($testDb);
    $db->forgetScheme();
    $db->ignoreAnalyze = true;
    $db->table($a)->where('id', 1)->sqlDebug(true)->get();
    $db = new DB($testDb);
    $db->ignoreAnalyze = true;
    $db->table($a)->where('id', 1)->sqlDebug(true)->update(['name' => 'changed']);

    $files = array_diff(glob("$folder/*") ?: [], $before);
    $log   = implode('', array_map('file_get_contents', $files));
    array_map('unlink', $files);
    if (!$had) @rmdir($folder);

    falsy(str_contains($log, 'UNSUPPORTED'), $log);
    contains('NOT A SELECT, NOT RUN', $log);
    same('changed', $pdo->query("SELECT name FROM $a WHERE id = 1")->fetchColumn());
});

test('a worker\'s next request gets its own id, not the one before or none', function () {
    $first = Analyze::id();
    Analyze::flushRequestState();
    same(null, Analyze::$process_id);
    usleep(2000);
    $second = Analyze::id();
    truthy($second !== $first && $second !== '');
});

test('the table store holds the same record', function () use ($pdo, $db, $a, $testDb) {
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = database() AND table_name = 'system_db_collector'")->fetchColumn())
        skip('system_db_collector is not migrated on this connection');

    # The model takes the first connection.
    if ($testDb !== array_key_first($GLOBALS['databases']['connections'])) skip('the model is on another connection');

    $rc = new \ReflectionProperty(\zFramework\Core\Facades\Config::class, 'frameworkCache');
    $rc->setAccessible(true);
    \zFramework\Core\Facades\Config::framework('profiling.keep');
    $saved  = $rc->getValue();
    $config = $saved;
    $config['profiling']['queryStore'] = 'table';
    $rc->setValue(null, $config);

    $max = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM system_db_collector")->fetchColumn();
    try {
        Analyze::flushRequestState();
        DbCollector::analyze($db, "SELECT * FROM $a WHERE status = 1", [], 0.001, 100);
        $row = $pdo->query("SELECT * FROM system_db_collector WHERE id > $max")->fetchAll(\PDO::FETCH_ASSOC);
    } finally {
        $rc->setValue(null, $saved);
        $pdo->exec("DELETE FROM system_db_collector WHERE id > $max");
    }

    same(1, count($row));
    same([$a], json_decode($row[0]['tables'], true));
    same(Analyze::id(), $row[0]['analyze_id']);
});
