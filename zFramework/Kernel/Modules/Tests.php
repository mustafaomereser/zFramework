<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

/**
 * The framework's own test harness. No PHPUnit: a test file is plain PHP in
 * tests/ at the project root, written with test()/same()/truthy()/throws()
 * (Kernel/Helpers/TestKit.php) and run one file per process by
 * Kernel/test-runner.php - full isolation for the price of one boot each.
 *
 * DB tests pick their connection with --db=<key from database/connections.php>
 * and build every table as zf_test_<name> (Test::table()), so they are safe
 * to run against a real database.
 */
class Tests
{
    public static function begin($methods)
    {
        # Bare `php terminal tests` means run - the thing everyone types.
        $method = @Terminal::$commands[1] ?: 'run';
        if (!in_array($method, $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{$method}();
    }

    /**
     * Description: Run the test files in tests/
     * Usage: php terminal tests run [file] [--db=local] [--filter=name-part]
     * @param file (optional) one file, without .php - `tests run db`
     * @param --db     (optional) connection key the tests build their zf_test_* tables on
     * @param --filter (optional) only tests whose name contains this
     */
    public static function run()
    {
        $target = @Terminal::$commands[2];
        $files  = self::files($target);

        if (!$files) return Terminal::text($target
            ? "[color=red]No tests/$target.php.[/color]"
            : '[color=red]tests/ is empty - `php terminal tests make <name>` writes a skeleton.[/color]');

        $args = '';
        foreach (['--db', '--filter'] as $flag)
            if (isset(Terminal::$parameters[$flag])) $args .= ' ' . escapeshellarg($flag . '=' . Terminal::$parameters[$flag]);

        $pass = $fail = $skip = $filtered = 0;
        $t0   = microtime(true);

        foreach ($files as $file) {
            $relative = 'tests/' . basename($file);
            $output   = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(FRAMEWORK_PATH . '/Kernel/test-runner.php') . ' ' . escapeshellarg($relative) . $args . ' 2>&1');

            # The report is the last #ZFTESTS# line; anything else is the file's
            # own noise, shown only when something went wrong.
            $report = null;
            if (preg_match_all('/^#ZFTESTS#(\{.*\})\s*$/m', $output, $m)) $report = json_decode(end($m[1]), true);

            Terminal::text('[color=cyan]' . $relative . '[/color]');

            if (!is_array($report)) {
                $fail++;
                Terminal::text('  [color=red]✗ crashed - no report[/color]');
                foreach (array_slice(array_filter(array_map('trim', explode("\n", $output))), -8) as $line) Terminal::text('    [color=dark-gray]' . $line . '[/color]');
                continue;
            }

            foreach ($report['results'] as $r) {
                match ($r['status']) {
                    'pass' => [$pass++, Terminal::text('  [color=green]✓[/color] ' . $r['name'] . ' [color=dark-gray](' . $r['ms'] . ' ms)[/color]')],
                    'skip' => [$skip++, Terminal::text('  [color=yellow]-[/color] ' . $r['name'] . ' [color=yellow]skipped[/color][color=dark-gray] - ' . $r['message'] . '[/color]')],
                    default => [$fail++, Terminal::text('  [color=red]✗ ' . $r['name'] . '[/color]' . ($r['at'] ? " [color=dark-gray]{$r['at']}[/color]" : ''))],
                };

                if ($r['status'] === 'fail') {
                    Terminal::text('    [color=red]' . $r['message'] . '[/color]');
                    if ($r['output'] !== '') Terminal::text('    [color=dark-gray]output: ' . $r['output'] . '[/color]');
                }
            }

            $filtered += (int) ($report['filtered'] ?? 0);
            if (!$report['results'] && $report['filtered']) Terminal::text('  [color=dark-gray]all ' . $report['filtered'] . ' filtered out[/color]');
        }

        $seconds = round(microtime(true) - $t0, 2);
        Terminal::text('');
        Terminal::text(($fail ? '[color=red]' : '[color=green]')
            . "$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . ($filtered ? ", $filtered filtered" : '')
            . "[/color] [color=dark-gray]({$seconds}s, " . count($files) . ' file' . (count($files) > 1 ? 's' : '') . ')[/color]');

        # CI reads the exit code; the interactive prompt must survive a red run.
        if ($fail && Terminal::$terminate) exit(1);
    }

    /**
     * Description: List the test files and how many tests each declares
     * Usage: php terminal tests list
     */
    public static function list()
    {
        $files = self::files(null);
        if (!$files) return Terminal::text('[color=dark-gray]tests/ is empty.[/color]');

        foreach ($files as $file)
            Terminal::text('[color=cyan]tests/' . basename($file) . '[/color] [color=dark-gray]'
                . preg_match_all('/^\s*test\(/m', (string) file_get_contents($file)) . ' test(s)[/color]');
    }

    /**
     * Description: Write a test file skeleton into tests/
     * Usage: php terminal tests make {name}
     * @param {name} (second argument) becomes tests/{name}.php
     */
    public static function make()
    {
        $name = strtolower((string) @Terminal::$commands[2]);
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) return Terminal::text('[color=red]Give it a name: `tests make posts`.[/color]');

        $path = BASE_PATH . "/tests/$name.php";
        if (is_file($path)) return Terminal::text("[color=red]tests/$name.php already exists.[/color]");

        if (!is_dir(BASE_PATH . '/tests')) mkdir(BASE_PATH . '/tests', 0755, true);
        file_put_contents($path, self::skeleton($name));

        Terminal::text("[color=green]tests/$name.php written.[/color] [color=dark-gray]php terminal tests run {$name}[/color]");
    }

    /**
     * The runnable files: tests/*.php, underscore-prefixed helpers excluded.
     *
     * @param string|null $only One name (no .php) to narrow to.
     * @return array
     */
    private static function files(?string $only): array
    {
        if ($only !== null) {
            $file = BASE_PATH . '/tests/' . basename($only, '.php') . '.php';
            return is_file($file) ? [$file] : [];
        }

        return array_values(array_filter((array) glob(BASE_PATH . '/tests/*.php'), fn($f) => !str_starts_with(basename($f), '_')));
    }

    /**
     * @param string $name
     * @return string
     */
    private static function skeleton(string $name): string
    {
        return <<<PHP
<?php

/**
 * php terminal tests run $name
 *
 * Plain PHP, top to bottom. Assert with same(\$expect, \$got), truthy(),
 * falsy(), contains(), throws(Class::class, fn) - or skip('reason') when a
 * service this file needs is not here.
 *
 * DB work goes on Test::db() (the --db= key) with zf_test_* tables only,
 * and every table you create is dropped in a Test::cleanup() - it runs even
 * when an assertion failed.
 */

test('it works', function () {
    same(4, 2 + 2);
});

/*
test('rows come back', function () {
    \$pdo = Test::pdo();
    \$table = Test::table('$name');                      // zf_test_$name
    Test::cleanup(fn () => \$pdo->exec("DROP TABLE IF EXISTS \$table"));

    \$pdo->exec("CREATE TABLE \$table (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))");
    \$pdo->exec("INSERT INTO \$table (name) VALUES ('a')");

    same('a', \$pdo->query("SELECT name FROM \$table")->fetchColumn());
});
*/

PHP;
    }
}
