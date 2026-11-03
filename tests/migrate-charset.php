<?php

/**
 * php terminal tests run migrate-charset [--db=local]
 *
 * `db migrate` and existing tables' collations, through the real command: a
 * MODIFY keeps to the migration instead of the table default, a table that
 * differs is only reported until --convert-charset, the bytes stay the same, and
 * a change of character set that could lose data is refused. MySQL 8 belongs in
 * denenecek.md if this runs on MariaDB.
 */

$testDb = Test::db();

try {
    $pdo = Test::pdo();
} catch (\Throwable $e) {
    test('database connection', fn() => skip("no database on `$testDb`: " . $e->getMessage()));
    return;
}

if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    test('database connection', fn() => skip("`$testDb` is not MySQL; collations are a MySQL concept"));
    return;
}

$table  = Test::table('charset');
$folder = 'zftestcharset';
$dir    = BASE_PATH . "/database/migrations/$folder";
@mkdir($dir, 0777, true);

# The command records the table it migrates. Forgotten between tests, or an
# unchanged column is skipped; and at the end, so the project's record stays clean.
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

Test::cleanup(function () use ($pdo, $table, $dir, $forget) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
    rrmdir($dir);
    $forget();
});

/** Writes the migration; a new mtime is what makes the command re-apply it. */
$migration = function (string $charset, array $columns) use ($dir, $folder, $table, $testDb) {
    $source = "<?php\nnamespace Database\\Migrations\\$folder;\n\nclass Probe\n{\n    static \$charset = '$charset';\n    static \$table = '$table';\n    static \$db = '$testDb';\n\n    public static function columns()\n    {\n        return " . var_export($columns, true) . ";\n    }\n}\n";
    file_put_contents("$dir/Probe.php", $source);
    touch("$dir/Probe.php", time() + 60);
};

$migrate = function (string ...$flags) use ($folder, $testDb) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/terminal') . " db migrate --path=$folder --db=$testDb " . implode(' ', $flags) . ' 2>&1';
    return preg_replace('/\e\[[\d;]*m/', '', (string) shell_exec($command));
};

/** Starts from a table the migration did not create. */
$start = function (string $default, array $columns, array $rows = []) use ($pdo, $table, $forget) {
    $forget();
    $pdo->exec("DROP TABLE IF EXISTS $table");
    $sql = [];
    foreach ($columns as $name => $definition) $sql[] = "$name $definition";
    [$set] = explode('_', $default);
    $pdo->exec("CREATE TABLE $table (id INT AUTO_INCREMENT PRIMARY KEY, " . implode(', ', $sql) . ") CHARACTER SET $set COLLATE $default");
    foreach ($rows as $row) {
        $keys = array_keys($row);
        $pdo->prepare("INSERT INTO $table (" . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($row));
    }
};

$collation = function (string $column) use ($pdo, $table) {
    $s = $pdo->prepare('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = database() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $column]);
    return $s->fetchColumn();
};

$tableCollation = function () use ($pdo, $table) {
    $s = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = database() AND TABLE_NAME = ?');
    $s->execute([$table]);
    return $s->fetchColumn();
};

$bytes = fn(string $column) => $pdo->query("SELECT GROUP_CONCAT(HEX($column) ORDER BY id) FROM $table")->fetchColumn();

$general = 'utf8mb4_general_ci';
$unicode = 'utf8mb4_unicode_ci';
$cols    = fn(string $collation) => "VARCHAR(255) CHARACTER SET utf8mb4 COLLATE $collation";

test('MODIFY keeps a column at $charset, not at the table default', function () use ($start, $migration, $migrate, $collation, $tableCollation, $general, $unicode, $cols) {
    $start($unicode, ['email' => $cols($general), 'note' => $cols($general)]);
    $migration($general, ['id' => ['primary'], 'email' => ['varchar:255'], 'note' => ['varchar:50', "charset:$general"]]);

    $out = $migrate();
    same($general, $collation('email'), $out);
    same($general, $collation('note'));
    same($unicode, $tableCollation(), 'the table default waits for the flag');
    contains('--convert-charset', $out, 'and the difference is reported');
});

test('without the flag a table that drifted is reported, with the reminder at the end, and left alone', function () use ($pdo, $table, $dir, $start, $migration, $migrate, $collation, $tableCollation, $general, $unicode, $cols) {
    $start($unicode, ['email' => $cols($general)]);
    $migration($general, ['id' => ['primary'], 'email' => ['varchar:255']]);
    $migrate();
    $pdo->exec("ALTER TABLE $table MODIFY email " . $cols($unicode));
    touch("$dir/Probe.php", time() - 60);

    $out = $migrate();
    same($unicode, $collation('email'), $out);
    same($unicode, $tableCollation());
    contains("email $unicode -> $general", $out);
    truthy(strrpos($out, '--convert-charset') > strrpos($out, 'charset differs'), 'the reminder closes the run');
});
test('--convert-charset: columns and default converted, bytes the same, up-to-date tables too', function () use ($pdo, $table, $dir, $start, $migration, $migrate, $collation, $tableCollation, $bytes, $general, $unicode, $cols) {
    $text = 'Çağrı şöğüİı 😀 {"a":"é"}';
    $start($unicode, ['email' => $cols($unicode), 'body' => 'TEXT CHARACTER SET utf8mb4 COLLATE ' . $unicode], [['email' => $text, 'body' => $text]]);
    $migration($general, ['id' => ['primary'], 'email' => ['varchar:255'], 'body' => ['text']]);
    $migrate();
    $pdo->exec("ALTER TABLE $table MODIFY email " . $cols($unicode) . ", MODIFY body TEXT CHARACTER SET utf8mb4 COLLATE $unicode");
    touch("$dir/Probe.php", time() - 60);
    $before = [$bytes('email'), $bytes('body')];
    same($unicode, $collation('email'), 'drifted');

    # The migration is unchanged here: the flag has to reach an "up to date" table.
    $out = $migrate('--convert-charset');
    same([$general, $general, $general], [$collation('email'), $collation('body'), $tableCollation()], $out);
    same($before, [$bytes('email'), $bytes('body')], 'not one byte of the text changed');
    same(strtoupper(bin2hex($text)), $before[0], 'and it was the text that went in');
});

test('--convert-charset leaves a column\'s own charset: alone', function () use ($start, $migration, $migrate, $collation, $tableCollation, $general, $unicode, $cols) {
    $start($unicode, ['email' => $cols($unicode), 'code' => 'VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_general_ci']);
    $migration($general, ['id' => ['primary'], 'email' => ['varchar:255'], 'code' => ['varchar:20', 'charset:latin1_general_ci']]);

    $out = $migrate('--convert-charset');
    same(['latin1_general_ci', $general, $general], [$collation('code'), $collation('email'), $tableCollation()], $out);
});

test('a change of character set is refused, with the flag too, and nothing is lost', function () use ($start, $migration, $migrate, $collation, $tableCollation, $bytes, $unicode, $cols) {
    $start($unicode, ['email' => $cols($unicode)], [['email' => 'emoji 😀']]);
    $before = $bytes('email');
    $migration('latin1_general_ci', ['id' => ['primary'], 'email' => ['varchar:255']]);

    $out = $migrate('--convert-charset');
    same($unicode, $collation('email'), $out);
    same($unicode, $tableCollation(), 'the default is not moved either');
    same($before, $bytes('email'));
    contains('refused', $out);
});

test('utf8mb3 -> utf8mb4 is allowed: nothing can be lost', function () use ($start, $migration, $migrate, $collation, $bytes, $general) {
    $set = 'utf8mb3';
    $start('utf8mb3_general_ci', ['email' => "VARCHAR(100) CHARACTER SET $set COLLATE utf8mb3_general_ci"], [['email' => 'Çağrı']]);
    $migration($general, ['id' => ['primary'], 'email' => ['varchar:100']]);

    $out = $migrate('--convert-charset');
    same($general, $collation('email'), $out);
    same(strtoupper(bin2hex('Çağrı')), $bytes('email'));
});

test('a unique collision stops that column: data and collation stay, the default waits', function () use ($pdo, $table, $start, $migration, $migrate, $collation, $tableCollation, $general, $unicode, $cols) {
    $start($unicode, ['email' => $cols($unicode)], [['email' => 'ß'], ['email' => 's']]);
    $pdo->exec("CREATE UNIQUE INDEX unique_email ON $table (email(100))");
    $migration($general, ['id' => ['primary'], 'email' => ['varchar:255', 'unique']]);

    $out = $migrate('--convert-charset');
    same($unicode, $collation('email'), $out);
    same($unicode, $tableCollation());
    same(2, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
    contains('FAILED', $out);
});

test('with no $charset declared, MODIFY keeps the column\'s collation', function () use ($start, $dir, $folder, $table, $testDb, $migrate, $collation, $general, $unicode, $cols) {
    $start($unicode, ['email' => $cols($general)]);
    file_put_contents("$dir/Probe.php", "<?php\nnamespace Database\\Migrations\\$folder;\n\nclass Probe\n{\n    static \$table = '$table';\n    static \$db = '$testDb';\n\n    public static function columns()\n    {\n        return ['id' => ['primary'], 'email' => ['varchar:300']];\n    }\n}\n");
    touch("$dir/Probe.php", time() + 60);

    $out = $migrate('--force');
    same($general, $collation('email'), $out);
});

test('a new table and a json column take no clause they should not', function () use ($pdo, $table, $forget, $migration, $migrate, $collation, $tableCollation, $general) {
    $forget();
    $pdo->exec("DROP TABLE IF EXISTS $table");
    $migration($general, ['id' => ['primary'], 'email' => ['varchar:255'], 'meta' => ['json', 'nullable']]);

    $out = $migrate();
    same($general, $tableCollation(), $out);
    same($general, $collation('email'));
    contains('`meta` added', $out);
});
