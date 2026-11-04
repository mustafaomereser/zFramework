<?php

/**
 * php terminal tests run db-prune [--db=local]
 *
 * `db diff --prune` through the real command. It drops every table on a connection
 * that has no migration, so it never runs against a project database here: a child
 * process replaces the whole connection list with two keys on a scratch database
 * this file creates, and every other migration's connection stops existing.
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

$scratch = 'zf_test_prune_db';
$folder  = 'zftestprune';
$dir     = BASE_PATH . "/database/migrations/$folder";
$runner  = tempnam(sys_get_temp_dir(), 'zfprune');
$input   = tempnam(sys_get_temp_dir(), 'zfprune');
@mkdir($dir, 0777, true);

Test::cleanup(function () use ($pdo, $scratch, $dir, $runner, $input) {
    $pdo->exec("DROP DATABASE IF EXISTS $scratch");
    rrmdir($dir);
    @unlink($runner);
    @unlink($input);
});

# From the file: once connected, the entry in $GLOBALS is the PDO itself.
[$dsn, $user, $pass] = (include BASE_PATH . '/database/connections.php')[$testDb];
$connection = var_export([preg_replace('/dbname=[^;]*/', "dbname=$scratch", $dsn), $user, $pass], true);

file_put_contents($runner, "<?php\ndefine('BASE_PATH', " . var_export(BASE_PATH, true) . ");\ninclude BASE_PATH . '/zFramework/bootstrap.php';\n\$GLOBALS['databases']['connections'] = ['zf_prune' => $connection, 'zf_prune2' => $connection];\nzFramework\\Run::includer(FRAMEWORK_PATH . '/modules', false);\nzFramework\\Run::includer(FRAMEWORK_PATH . '/modules/error_handlers/loader.php');\nzFramework\\Kernel\\Terminal::begin(\$argv);\n");

$migration = function (string $name, string $table, string $db, string $columns) use ($dir, $folder) {
    file_put_contents("$dir/$name.php", "<?php\nnamespace Database\\Migrations\\$folder;\n\nclass $name\n{\n    static \$table = '$table';\n    static \$db = '$db';\n\n    public static function columns()\n    {\n        return $columns;\n    }\n}\n");
};

$migration('Keep', 'keep_me', 'zf_prune', "['id' => ['primary'], 'name' => ['varchar:50'], 'held_id' => ['int']]");
$migration('KeepMore', 'keep_me', 'zf_prune', "['extra' => ['varchar:50']]");
$migration('CaseDiffers', 'Mixed_Case', 'zf_prune', "['id' => ['primary']]");
$migration('Second', 'second_key', 'zf_prune2', "['id' => ['primary']]");

$setup = function () use ($pdo, $scratch) {
    $pdo->exec("DROP DATABASE IF EXISTS $scratch");
    $pdo->exec("CREATE DATABASE $scratch");
    foreach ([
        "CREATE TABLE $scratch.held (id INT PRIMARY KEY)",
        "CREATE TABLE $scratch.keep_me (id INT PRIMARY KEY, name VARCHAR(50), extra VARCHAR(50), held_id INT, Legacy VARCHAR(50), FOREIGN KEY (held_id) REFERENCES $scratch.held (id))",
        "INSERT INTO $scratch.keep_me (id, name, Legacy) VALUES (1, 'stays', 'goes')",
        "CREATE TABLE $scratch.mixed_case (id INT PRIMARY KEY)",
        "CREATE TABLE $scratch.second_key (id INT PRIMARY KEY)",
        "CREATE TABLE $scratch.gone (id INT PRIMARY KEY)",
        "CREATE TABLE $scratch.a_parent (id INT PRIMARY KEY)",
        "CREATE TABLE $scratch.b_child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES $scratch.a_parent (id))",
        "CREATE VIEW $scratch.a_view AS SELECT id FROM $scratch.gone",
    ] as $sql) $pdo->exec($sql);
};

$run = function (string $answers, string ...$flags) use ($runner, $input) {
    file_put_contents($input, $answers);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' db diff --prune ' . implode(' ', $flags) . ' < ' . escapeshellarg($input) . ' 2>&1';
    return preg_replace('/\e\[[\d;]*m/', '', (string) shell_exec($command));
};

$tables = function () use ($pdo, $scratch) {
    $names = array_map('strtolower', $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$scratch'")->fetchAll(\PDO::FETCH_COLUMN));
    sort($names);
    return $names;
};

$columns   = fn(string $table) => $pdo->query("DESCRIBE $scratch.$table")->fetchAll(\PDO::FETCH_COLUMN);
$untouched = ['a_parent', 'a_view', 'b_child', 'gone', 'held', 'keep_me', 'mixed_case', 'second_key'];
$fullKeep  = ['id', 'name', 'extra', 'held_id', 'Legacy'];

test('anything but Y drops nothing, and the database is named in the question', function () use ($setup, $run, $tables, $columns, $untouched, $fullKeep, $scratch) {
    $setup();
    foreach (["N\nN\n", "yes\nyes\n", ''] as $answer) {
        $out = $run($answer);
        same($untouched, $tables(), $out);
        same($fullKeep, $columns('keep_me'), $out);
    }

    contains("from `$scratch`", $run("N\nN\n"));
});

test('Y drops what has no migration, and nothing that has one', function () use ($setup, $run, $tables, $columns, $pdo, $scratch) {
    $setup();
    $out = $run("Y\nY\n");

    same(['a_view', 'held', 'keep_me', 'mixed_case', 'second_key'], $tables(), $out);
    same(['id', 'name', 'extra', 'held_id'], $columns('keep_me'), 'two migrations of one table both count');
    same('stays', $pdo->query("SELECT name FROM $scratch.keep_me")->fetchColumn(), 'the rows are still there');

    contains('Table stays: held', $out, 'a table still pointed at stays, with the reason');
    contains('a_view is a view', $out);
    contains('Dropped column: keep_me.Legacy', $out, 'by the name it has');
});

test('--path drops columns only: the rest of the tables say nothing', function () use ($setup, $run, $tables, $columns, $untouched, $folder) {
    $setup();
    $out = $run("Y\nY\n", "--path=$folder");

    same($untouched, $tables(), $out);
    same(['id', 'name', 'extra', 'held_id'], $columns('keep_me'), $out);
});

test('a migration that cannot be read refuses it all', function () use ($setup, $run, $tables, $columns, $untouched, $fullKeep, $dir) {
    $setup();
    file_put_contents("$dir/Broken.php", "<?php\n");
    $out = $run("Y\nY\n");
    unlink("$dir/Broken.php");

    contains('--prune refused', $out);
    same($untouched, $tables(), $out);
    same($fullKeep, $columns('keep_me'));
});

test('a stray file in the migrations folder is not a broken migration', function () use ($setup, $run, $tables, $dir) {
    $setup();
    touch("$dir/.gitkeep");
    $out = $run("Y\nY\n");
    unlink("$dir/.gitkeep");

    falsy(str_contains($out, '--prune refused'), $out);
    falsy(in_array('gone', $tables()), $out);
});

test('where nobody can answer, nothing is dropped', function () use ($setup, $run, $tables, $untouched) {
    $setup();
    $out = $run("Y\nY\n", '--web');

    contains('nobody can answer', $out);
    same($untouched, $tables(), $out);
});
