<?php

/**
 * php terminal tests run profiling-queries
 *
 * The Queries tab of /profiling: what the analyzer recorded, grouped and suggested
 * back, escaped, and closed once debug is off. The records are written here in the
 * analyzer's shape; tests/analyzer.php covers the shape itself. Applying a suggestion
 * goes through the real db migrate.
 */

use modules\Profiling\Controllers\QueriesController;
use modules\Profiling\Queries;
use zFramework\Core\Facades\Config;

$directory = Queries::directory();
$had       = is_dir($directory);
$own       = $directory . '/zftest-' . uniqid() . '.jsonl';

Test::cleanup(function () use ($own, $directory, $had) {
    @unlink($own);
    if (!$had) {
        @rmdir($directory);
        @rmdir(dirname($directory));
    }
});

$record = fn(string $fingerprint, float $ms, array $extra = []) => $extra + [
    'fingerprint'       => $fingerprint,
    'query'             => "SELECT * FROM t_$fingerprint WHERE a = :a",
    'executed'          => "SELECT * FROM t_$fingerprint WHERE a = 'secret-value'",
    'url'               => 'GET /page',
    'query_time_ms'     => $ms,
    'tables'            => ["t_$fingerprint"],
    'used_indexes'      => [],
    'warnings'          => ["t_$fingerprint" => ['FULL_SCAN', "LARGE_TABLE_FULL_SCAN (~9000 rows)"]],
    'index_suggestions' => ["t_$fingerprint" => ['stable' => ["CREATE INDEX idx_{$fingerprint}_a ON t_$fingerprint (a)"]]],
    'row_stats'         => ["t_$fingerprint" => ['scanned' => 9000, 'returned' => 1, 'mode' => 'FULL_SCAN']],
];

test('grouped by shape, most total time first: many small runs above one slow one', function () use ($record) {
    $records = [$record('slow', 50)];
    for ($i = 0; $i < 30; $i++) $records[] = $record('often', 2, ['url' => $i < 20 ? 'GET /a' : 'GET /b']);

    $summary = Queries::summary($records);
    same(['often', 'slow'], array_column($summary, 'fingerprint'));
    same(30, $summary[0]['runs']);
    same(60.0, $summary[0]['total_ms']);
    same(2.0, $summary[0]['avg_ms']);
    same(['GET /a' => 20, 'GET /b' => 10], $summary[0]['urls']);
    same(9000, $summary[0]['scanned']);
    same(1, $summary[0]['returned']);
});

test('an index is suggested once, with all it would help; a small table waits to be asked', function () use ($record) {
    $shared = ['index_suggestions' => ['t' => ['stable' => ['CREATE INDEX i ON t (a)']]]];
    $small  = ['warnings' => ['t_tiny' => ['FULL_SCAN', 'SMALL_TABLE:t_tiny(~50 rows)']]];

    $summary = Queries::summary([$record('one', 10, $shared), $record('two', 5, $shared), $record('tiny', 90, $small)]);
    $list    = Queries::suggestions($summary);

    same(['CREATE INDEX i ON t (a)'], array_column($list, 'statement'));
    same(2, $list[0]['queries']);
    same(15.0, $list[0]['total_ms']);
    same(2, count(Queries::suggestions($summary, true)));
});

test('a record from before url existed, or with no measurement, still groups', function () {
    $summary = Queries::summary([['fingerprint' => 'old', 'query' => 'SELECT 1', 'query_time_ms' => 1, 'tables' => [], 'used_indexes' => []]]);

    same(['unknown' => 1], $summary[0]['urls']);
    same(null, $summary[0]['returned']);
    same(0, $summary[0]['scanned']);
});

test('the page shows the statement, escaped, and never its values', function () use ($record, $own, $directory) {
    @mkdir($directory, 0755, true);
    file_put_contents($own, json_encode($record('page', 3, ['url' => 'GET /<script>alert(1)</script>', 'query' => "SELECT '<img src=x>' FROM t_page"])) . PHP_EOL);

    $html = (string) (new QueriesController)->index();

    contains('t_page', $html);
    contains('CREATE INDEX idx_page_a ON t_page (a)', $html);
    falsy(str_contains($html, '<script>alert'), 'a url is whatever the client sent');
    falsy(str_contains($html, '<img src=x>'));
    falsy(str_contains($html, 'secret-value'), 'bound values stay in the store');
});

test('with debug off nothing is read out and nothing is cleared', function () use ($record, $own, $directory) {
    @mkdir($directory, 0755, true);
    file_put_contents($own, json_encode($record('page', 3)) . PHP_EOL);

    $cache = new \ReflectionProperty(Config::class, 'debugCache');
    $cache->setAccessible(true);
    Config::debug();
    $saved = $cache->getValue();
    $cache->setValue(null, false);

    try {
        falsy(Config::debug(), 'the switch this test relies on');
        $html = (string) (new QueriesController)->index();
        try {
            (new QueriesController)->clear();
        } catch (\Throwable) {
            # redirect() signals through an exception
        }
    } finally {
        $cache->setValue(null, $saved);
    }

    falsy(str_contains($html, 't_page'), $html);
    contains('needs', $html);
    truthy(is_file($own), 'the records are still there');
});

test('clear empties the store, only when it holds nothing of anybody else', function () use ($record, $own, $directory) {
    @mkdir($directory, 0755, true);
    file_put_contents($own, json_encode($record('page', 3)) . PHP_EOL);

    if (count(glob("$directory/*.jsonl")) > 1) skip('analysis/queries holds real records, not clearing them');
    if (Queries::store() !== 'file') skip('queryStore is table here');

    same(1, Queries::clear());
    same([], glob("$directory/*.jsonl"));
});

test('an index that is the start of another is not offered, nor one the table has', function () use ($record) {
    $short = ['index_suggestions' => ['t' => ['stable' => ['CREATE INDEX a ON t (x, y)']]]];
    $long  = ['index_suggestions' => ['t' => ['stable' => ['CREATE INDEX b ON t (x, y, z)']]]];

    $list = Queries::suggestions(Queries::summary([$record('short', 1, $short), $record('long', 1, $long)]));
    same(['CREATE INDEX b ON t (x, y, z)'], array_column($list, 'statement'));
});

# --- applying, on a real table and through the real command

$testDb = Test::db();
try {
    $pdo = Test::pdo();
    $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
} catch (\Throwable) {
    $mysql = false;
}

if (!$mysql) {
    test('applying a suggestion', fn() => skip('needs a MySQL connection'));
    return;
}

if (Queries::store() !== 'file') {
    test('applying a suggestion', fn() => skip('queryStore is table here; these write their records as a file'));
    return;
}

$table     = Test::table('apply');
$noFile    = Test::table('apply_nofile');
$folder    = 'zftestapply';
$migration = BASE_PATH . "/database/migrations/$folder";
@mkdir($migration, 0777, true);

$forget = function () use ($pdo, $table, $testDb) {
    $db    = new \zFramework\Core\Facades\DB($testDb);
    $name  = $pdo->query('SELECT database()')->fetchColumn();
    $file  = $db->cache_dir . "/$name/last-migrate.json";
    $state = json_decode((string) @file_get_contents($file), true);
    if (isset($state['tables'][$table])) {
        unset($state['tables'][$table]);
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
    }
    $db->forgetScheme();
};

Test::cleanup(function () use ($pdo, $table, $noFile, $migration, $forget) {
    $pdo->exec("DROP TABLE IF EXISTS $table, $noFile");
    rrmdir($migration);
    $forget();
});

$start = function () use ($pdo, $table, $noFile, $migration, $folder, $testDb, $forget) {
    $forget();
    $pdo->exec("DROP TABLE IF EXISTS $table, $noFile");
    $pdo->exec("CREATE TABLE $table (id INT AUTO_INCREMENT PRIMARY KEY, a INT, b INT)");
    $pdo->exec("CREATE TABLE $noFile (id INT AUTO_INCREMENT PRIMARY KEY, a INT)");
    file_put_contents("$migration/Probe.php", "<?php\nnamespace Database\\Migrations\\$folder;\n\nclass Probe\n{\n    static \$table = '$table';\n    static \$db = '$testDb';\n\n    public static function columns()\n    {\n        return ['id' => ['primary'], 'a' => ['int'], 'b' => ['int']];\n    }\n}\n");
};

$offer = function (string $name, string $on, string $columns) use ($record, $own, $directory, $testDb) {
    @mkdir($directory, 0755, true);
    file_put_contents($own, json_encode($record($name, 3, ['connection' => $testDb, 'index_suggestions' => ['t' => ['stable' => ["CREATE INDEX $name ON $on ($columns)"]]]])) . PHP_EOL, FILE_APPEND);
    return "CREATE INDEX $name ON $on ($columns)";
};

$indexes = fn(string $on) => $pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.statistics WHERE table_schema = database() AND table_name = '$on' AND INDEX_NAME != 'PRIMARY'")->fetchAll(\PDO::FETCH_COLUMN);
$dbMigrate = fn() => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/terminal') . " db migrate --force --path=$folder --db=$testDb 2>&1");

test('Run now creates it, says the migration will drop it, and db migrate does', function () use ($start, $offer, $indexes, $dbMigrate, $table, $own) {
    $start();
    @unlink($own);
    $statement = $offer('zf_now', $table, 'b, a');

    $result = Queries::apply([$statement], 'now')[0];
    truthy($result['ok'], json_encode($result));
    same(['zf_now'], $indexes($table));
    contains('drops', $result['message']);

    $dbMigrate();
    same([], $indexes($table), 'the warning was true');
});

test('Write to migration changes nothing yet; db migrate creates it and keeps it', function () use ($start, $offer, $indexes, $dbMigrate, $pdo, $table, $migration, $own) {
    $start();
    @unlink($own);
    $statement = $offer('idx_zf_kept', $table, 'a, b');

    $result = Queries::apply([$statement], 'migration')[0];
    truthy($result['ok'], json_encode($result));
    same([], $indexes($table), 'nothing ran');

    $source = file_get_contents("$migration/Probe.php");
    contains("# added from profiling suggestions", $source);
    contains("static \$suggested_indexes = [", $source);
    contains("'zf_kept' => ['a', 'b'],", $source);

    $out = $dbMigrate();
    same(['idx_zf_kept'], $indexes($table), $out);
    same(['a', 'b'], $pdo->query("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema = database() AND table_name = '$table' AND INDEX_NAME = 'idx_zf_kept' ORDER BY SEQ_IN_INDEX")->fetchAll(\PDO::FETCH_COLUMN));

    $dbMigrate();
    same(['idx_zf_kept'], $indexes($table), 'and the next migrate keeps it');
});

test('a column from the timestamps shorthand can be indexed, and a prefix counts', function () use ($start, $offer, $indexes, $dbMigrate, $pdo, $table, $migration, $folder, $testDb, $own) {
    $start();
    @unlink($own);
    $pdo->exec("DROP TABLE $table");
    file_put_contents("$migration/Probe.php", "<?php\nnamespace Database\\Migrations\\$folder;\n\nclass Probe\n{\n    static \$table = '$table';\n    static \$db = '$testDb';\n    static \$prefix = 'x';\n\n    public static function columns()\n    {\n        return ['id' => ['primary'], 'a' => ['int'], 'timestamps'];\n    }\n}\n");
    $dbMigrate();

    $statement = $offer('idx_zf_shorthand', $table, 'x_a, updated_at');
    $result    = Queries::apply([$statement], 'migration')[0];
    truthy($result['ok'], json_encode($result));

    $out = $dbMigrate();
    same(['idx_zf_shorthand'], $indexes($table), $out);
});

test('the index has the suggested order, whatever order the columns are declared in', function () use ($start, $offer, $dbMigrate, $pdo, $table, $migration, $own) {
    $start();
    @unlink($own);

    $result = Queries::apply([$offer('idx_zf_order', $table, 'b, a')], 'migration')[0];
    truthy($result['ok'], json_encode($result));
    contains("'zf_order' => ['b', 'a'],", file_get_contents("$migration/Probe.php"));

    $out = $dbMigrate();
    same(['b', 'a'], $pdo->query("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema = database() AND table_name = '$table' AND INDEX_NAME = 'idx_zf_order' ORDER BY SEQ_IN_INDEX")->fetchAll(\PDO::FETCH_COLUMN), $out);

    $dbMigrate();
    same(1, (int) $pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.statistics WHERE table_schema = database() AND table_name = '$table' AND INDEX_NAME = 'idx_zf_order'")->fetchColumn(), 'and the next migrate keeps it');
});

test('a column later removed from the migration does not stop the migrate or the rest of the index', function () use ($start, $offer, $dbMigrate, $pdo, $table, $migration, $own) {
    $start();
    @unlink($own);
    Queries::apply([$offer('idx_zf_later', $table, 'a, b')], 'migration');

    $source = file_get_contents("$migration/Probe.php");
    file_put_contents("$migration/Probe.php", str_replace(", 'b' => ['int']]", ']', $source));

    $out = $dbMigrate();
    same(['a'], $pdo->query("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema = database() AND table_name = '$table' AND INDEX_NAME = 'idx_zf_later' ORDER BY SEQ_IN_INDEX")->fetchAll(\PDO::FETCH_COLUMN), $out);
    falsy(str_contains(strtolower($out), 'err:'), $out);
});

test('a column the migration does not have is refused', function () use ($start, $offer, $table, $migration, $own) {
    $start();
    @unlink($own);
    $before = file_get_contents("$migration/Probe.php");

    $result = Queries::apply([$offer('idx_zf_absent', $table, 'nosuch')], 'migration')[0];
    falsy($result['ok']);
    contains('no column', $result['message']);
    same($before, file_get_contents("$migration/Probe.php"));
});

test('a file it would break is left as it was, with the reason', function () use ($start, $offer, $table, $migration, $own) {
    $start();
    @unlink($own);
    $broken = rtrim(file_get_contents("$migration/Probe.php")) . "\nclass {\n";
    file_put_contents("$migration/Probe.php", $broken);

    $result = Queries::apply([$offer('idx_zf_broken', $table, 'a')], 'migration')[0];
    falsy($result['ok']);
    contains('would not parse', $result['message']);
    same($broken, file_get_contents("$migration/Probe.php"));
});

test('a commented-out table line is not the migration of that table', function () use ($start, $offer, $noFile, $migration, $own) {
    $start();
    @unlink($own);
    file_put_contents("$migration/Comment.php", "<?php\nnamespace Database\\Migrations\\zftestapply;\n\nclass Comment\n{\n    # static \$table = '$noFile';\n    static \$table = 'zf_test_something_else';\n\n    public static function columns()\n    {\n        return ['a' => ['int']];\n    }\n}\n");

    $result = Queries::apply([$offer('idx_zf_comment', $noFile, 'a')], 'migration')[0];
    unlink("$migration/Comment.php");

    falsy($result['ok']);
    contains('no migration', $result['message']);
});

test('a second index joins the first, in array() too, and asking twice writes once', function () use ($start, $offer, $table, $migration, $own) {
    $start();
    @unlink($own);
    $one = $offer('idx_zf_one', $table, 'a');
    $two = $offer('idx_zf_two', $table, 'b');

    Queries::apply([$one], 'migration');
    Queries::apply([$two], 'migration');
    $again = Queries::apply([$one], 'migration')[0];

    $source = file_get_contents("$migration/Probe.php");
    same(1, substr_count($source, 'static $suggested_indexes'), $source);
    same(1, substr_count($source, '# added from profiling suggestions'));
    same(1, substr_count($source, "'zf_one' =>"));
    contains("'zf_two' =>", $source);
    truthy($again['ok']);
    contains('already', $again['message']);
    truthy(str_contains((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$migration/Probe.php")), 'No syntax errors'), $source);

    $start();
    file_put_contents("$migration/Probe.php", str_replace("    static \$db", "    static \$suggested_indexes = array('zf_old' => array('a'));\n    static \$db", file_get_contents("$migration/Probe.php")));
    $three  = Queries::apply([$two], 'migration')[0];
    $source = file_get_contents("$migration/Probe.php");
    truthy($three['ok'], json_encode($three));
    same(1, substr_count($source, 'static $suggested_indexes'), $source);
    truthy(str_contains((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$migration/Probe.php")), 'No syntax errors'), $source);
});

test('a table no migration declares: Run now works, Write to migration says why not', function () use ($start, $offer, $indexes, $noFile, $own) {
    $start();
    @unlink($own);
    $statement = $offer('zf_free', $noFile, 'a');

    $written = Queries::apply([$statement], 'migration')[0];
    falsy($written['ok']);
    contains('no migration', $written['message']);
    same([], $indexes($noFile));

    $run = Queries::apply([$statement], 'now')[0];
    truthy($run['ok'], json_encode($run));
    same('created', $run['message']);
    same(['zf_free'], $indexes($noFile));
});

test('what was created stops being offered', function () use ($start, $offer, $table, $own, $testDb) {
    $start();
    @unlink($own);
    $statement = $offer('zf_gone', $table, 'a');

    Queries::apply([$statement], 'now');
    falsy(in_array($statement, array_column(Queries::suggestions(Queries::summary(Queries::all()), true), 'statement')));
});

test('only a current suggestion is accepted, whatever the form sends', function () use ($start, $pdo, $table, $own) {
    $start();
    @unlink($own);

    foreach (["DROP TABLE $table", "CREATE INDEX evil ON $table (a)"] as $statement) {
        $result = Queries::apply([$statement], 'now')[0];
        falsy($result['ok']);
        contains('not a current suggestion', $result['message']);
    }
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = database() AND table_name = '$table'")->fetchColumn());
});

test('one that fails leaves the others', function () use ($start, $offer, $indexes, $table, $own) {
    $start();
    @unlink($own);
    $bad  = $offer('zf_bad', $table, 'nosuchcolumn');
    $good = $offer('zf_good', $table, 'a');

    $results = Queries::apply([$bad, $good], 'now');
    same([false, true], array_column($results, 'ok'), json_encode($results));
    same(['zf_good'], $indexes($table));
});

test('a record from before connection was recorded is not run anywhere', function () use ($start, $record, $indexes, $table, $own, $directory) {
    $start();
    $statement = "CREATE INDEX zf_old ON $table (a)";
    file_put_contents($own, json_encode($record('old', 3, ['index_suggestions' => ['t' => ['stable' => [$statement]]]])) . PHP_EOL);

    $result = Queries::apply([$statement], 'now')[0];
    falsy($result['ok']);
    contains('without its connection', $result['message']);
    same([], $indexes($table));
});
