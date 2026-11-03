<?php

/**
 * php terminal tests run db-diff [--db=local]
 *
 * `db diff` through the real command: what the database has and the migrations
 * do not, and the other way round, prefix and shorthands counted - and nothing
 * touched.
 */

$testDb = Test::db();

try {
    $pdo = Test::pdo();
} catch (\Throwable $e) {
    test('database connection', fn() => skip("no database on `$testDb`: " . $e->getMessage()));
    return;
}

if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    test('database connection', fn() => skip("`$testDb` is not MySQL; this file sets up its tables in MySQL DDL"));
    return;
}

$table  = Test::table('diff');
$orphan = Test::table('diff_orphan');
$absent = Test::table('diff_absent');
$folder = 'zftestdiff';
$dir    = BASE_PATH . "/database/migrations/$folder";
@mkdir($dir, 0777, true);

Test::cleanup(function () use ($pdo, $table, $orphan, $dir) {
    foreach ([$table, $orphan] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    rrmdir($dir);
});

foreach ([$table, $orphan] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE $table (id INT AUTO_INCREMENT PRIMARY KEY, x_Email VARCHAR(50), x_legacy VARCHAR(50), created_at DATETIME, updated_at DATETIME)");
$pdo->exec("CREATE TABLE $orphan (id INT)");

$class = fn(string $name, string $t, string $prefix, string $columns) => "<?php\nnamespace Database\\Migrations\\$folder;\n\nclass $name\n{\n    static \$table = '$t';\n    static \$db = '$testDb';\n    static \$prefix = '$prefix';\n\n    public static function columns()\n    {\n        return $columns;\n    }\n}\n";
file_put_contents("$dir/Probe.php", $class('Probe', $table, 'x', "['email' => ['varchar:50'], 'pending' => ['int'], 'timestamps']"));
file_put_contents("$dir/Absent.php", $class('Absent', $absent, '', "['id' => ['primary']]"));

$diff = fn(string ...$flags) => preg_replace('/\e\[[\d;]*m/', '', (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/terminal') . " db diff --db=$testDb " . implode(' ', $flags) . ' 2>&1'));

$section = function (string $out, string $title) {
    return preg_match('/^' . preg_quote($title, '/') . '.*?\R((?:-> .*\R?)*)/m', $out, $m) ? $m[1] : '';
};

test('both directions are listed, by the column the table will really have', function () use ($diff, $section, $table, $orphan, $absent) {
    $out = $diff();

    contains("-> $orphan", $section($out, 'Tables with no migration'), $out);
    contains("-> $table.id", $section($out, 'Columns with no migration'), 'not declared in this migration');
    contains("-> $table.x_legacy", $section($out, 'Columns with no migration'));
    contains("-> $absent", $section($out, 'Tables not in the database'));
    contains("-> $table.x_pending", $section($out, 'Columns not in the database'));

    falsy(str_contains($out, "$table.x_email"), 'a difference of case is the same column');
    falsy(str_contains($section($out, 'Columns with no migration'), 'created_at'), 'the timestamps shorthand counts');
    falsy(str_contains($section($out, 'Tables with no migration'), "-> $table\n"), 'a migrated table is not an orphan');
});

test('--path does not call the tables outside it orphans', function () use ($diff, $section, $orphan, $folder) {
    same('', $section($diff("--path=$folder"), 'Tables with no migration'));
});

test('it changes nothing', function () use ($pdo, $table, $orphan, $absent) {
    same(['id', 'x_Email', 'x_legacy', 'created_at', 'updated_at'], $pdo->query("DESCRIBE $table")->fetchAll(\PDO::FETCH_COLUMN));
    same('id', $pdo->query("DESCRIBE $orphan")->fetchColumn());
    same(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = database() AND TABLE_NAME = '$absent'")->fetchColumn());
});
