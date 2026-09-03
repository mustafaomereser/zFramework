<?php

/**
 * Runs ONE test file in its own process and reports as one machine-readable
 * line. Started by `php terminal tests run` (Kernel/Modules/Tests.php), never
 * by hand - though `php zFramework/Kernel/test-runner.php tests/db.php` works
 * and prints the same line.
 *
 * One process per file is the isolation model: a test may define ZF_WORKER,
 * flip config, break a static or die fatally, and the next file starts clean.
 * The report is the last line, prefixed #ZFTESTS# - everything the file
 * printed before it is carried back verbatim for the failure report.
 *
 *   argv: <file> [--db=key] [--filter=substring]
 */

define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__, 2)));

$file   = null;
$db     = null;
$filter = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--db=')) $db = substr($arg, 5);
    elseif (str_starts_with($arg, '--filter=')) $filter = substr($arg, 9);
    elseif ($file === null) $file = $arg;
}

$report = function () use (&$file) {
    \zFramework\Kernel\Helpers\TestKit::flushCleanups();

    echo PHP_EOL . '#ZFTESTS#' . json_encode([
        'file'     => $file,
        'results'  => \zFramework\Kernel\Helpers\TestKit::$results,
        'filtered' => \zFramework\Kernel\Helpers\TestKit::$filtered,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
};

# A fatal - a parse error in the test file, an OOM - skips everything below
# the include. The shutdown hook still emits what ran plus the fatal itself,
# so the module reports "died at test N" instead of a blank crash.
register_shutdown_function(function () use ($report, &$reported) {
    if ($reported) return;

    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true))
        \zFramework\Kernel\Helpers\TestKit::$results[] = ['name' => '(fatal)', 'status' => 'fail', 'message' => trim($error['message']), 'at' => str_replace(BASE_PATH . '/', '', str_replace('\\', '/', $error['file'])) . ':' . $error['line'], 'output' => '', 'ms' => 0];

    $report();
});

if (!$file || !is_file(BASE_PATH . '/' . $file)) {
    fwrite(STDERR, "test-runner: no such file `$file`" . PHP_EOL);
    exit(2);
}

# The same environment a cron script gets: config, helpers, autoloading, DB -
# and no session, no force-https, no routes. A test that wants routes boots
# them itself (tests/http.php starts a real server instead).
$cron_mode = true;
require BASE_PATH . '/zFramework/bootstrap.php';
zFramework\Run::includer(FRAMEWORK_PATH . '/modules', false);
require FRAMEWORK_PATH . '/Kernel/Helpers/TestKit.php';

use zFramework\Kernel\Helpers\TestKit;

# Test files say `Test::db()`, `Test::table()`, `Test::pdo()`, `Test::cleanup()`.
class_alias(TestKit::class, 'Test');

TestKit::$db     = $db;
TestKit::$filter = $filter !== null && $filter !== '' ? $filter : null;

include BASE_PATH . '/' . $file;

# The session writes at shutdown and needs the headers unsent; the report
# below is output, so flush it first - quietly, a test that used the session
# is normal and a test that did not makes this a no-op.
if (class_exists(\zFramework\Core\Facades\Session::class, false)) @\zFramework\Core\Facades\Session::flush();

# Before the call, so a throw inside the report cannot make shutdown emit a
# second, half-duplicated line.
$reported = true;
$report();

exit(array_filter(TestKit::$results, fn($r) => $r['status'] === 'fail') ? 1 : 0);
