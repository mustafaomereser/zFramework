<?php

namespace zFramework\Kernel\Helpers {

/**
 * The assertions and bookkeeping a test file runs against.
 *
 * Loaded only by Kernel/test-runner.php, one process per test file - a test
 * that defines ZF_WORKER, breaks a static or corrupts config cannot touch the
 * file after it. Nothing here is autoloaded during a request; the class name
 * is aliased to `Test` for the test files' convenience.
 *
 * A test file is plain PHP: call test('name', fn () => ...) as many times as
 * it likes, assert inside with same()/truthy()/contains()/throws(). No
 * classes to extend, no attributes, no discovery - the file runs top to
 * bottom like a route file does.
 */
class TestKit
{
    /**
     * One entry per test() call that ran:
     * ['name', 'status' => pass|fail|skip, 'ms', 'message', 'at', 'output'].
     */
    public static array $results = [];

    /**
     * test() calls whose name did not match --filter; counted, never run.
     */
    public static int $filtered = 0;

    /**
     * Case-insensitive substring a test's name must contain to run.
     */
    public static ?string $filter = null;

    /**
     * The connection key tests should build their tables on (--db=, else the
     * first key in database/connections.php).
     */
    public static ?string $db = null;

    /**
     * Closures to run when the file is done, failures included - table drops
     * belong here, so a failing assertion cannot leave zf_test_* behind.
     */
    private static array $cleanups = [];

    /**
     * Run one test.
     *
     * @param string   $name
     * @param \Closure $fn Assert inside; return value is ignored.
     * @return void
     */
    public static function case(string $name, \Closure $fn): void
    {
        if (self::$filter !== null && stripos($name, self::$filter) === false) {
            self::$filtered++;
            return;
        }

        $start  = hrtime(true);
        $result = ['name' => $name, 'status' => 'pass', 'message' => '', 'at' => ''];

        # The test's own echoes are kept aside: they would corrupt the report
        # stream, and they are only interesting when the test fails.
        ob_start();

        try {
            $fn();
        } catch (TestSkipped $e) {
            $result['status']  = 'skip';
            $result['message'] = $e->getMessage();
        } catch (TestFailure $e) {
            $result['status']  = 'fail';
            $result['message'] = $e->getMessage();
            $result['at']      = self::at($e);
        } catch (\Throwable $e) {
            $result['status']  = 'fail';
            $result['message'] = get_class($e) . ': ' . $e->getMessage();
            $result['at']      = self::at($e) ?: ($e->getFile() . ':' . $e->getLine());
        }

        $result['output'] = mb_strimwidth(trim((string) ob_get_clean()), 0, 500, '…');
        $result['ms']     = round((hrtime(true) - $start) / 1e6, 2);

        self::$results[] = $result;
    }

    /**
     * The test-file line an assertion failed on, read from the trace - the
     * first frame that sits under tests/.
     *
     * @param \Throwable $e
     * @return string
     */
    private static function at(\Throwable $e): string
    {
        $tests = str_replace('\\', '/', BASE_PATH . '/tests/');

        foreach (array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace()) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if (str_starts_with($file, $tests)) return substr($file, strlen($tests) - 6) . ':' . ($frame['line'] ?? '?');
        }

        return '';
    }

    // ─────────────────────────────────────────────
    // Conveniences for the test files
    // ─────────────────────────────────────────────

    /**
     * The connection key this run was pointed at.
     *
     * @return string
     */
    public static function db(): string
    {
        return self::$db ??= (string) (array_keys($GLOBALS['databases']['connections'] ?? [])[0] ?? 'local');
    }

    /**
     * The name a test table must carry: zf_test_<name>. The prefix is the
     * contract that makes tests safe on a real database - they create and
     * drop only names nothing else uses.
     *
     * @param string $name
     * @return string
     */
    public static function table(string $name): string
    {
        return 'zf_test_' . $name;
    }

    /**
     * The selected connection's raw PDO handle, for setup and teardown SQL.
     *
     * @return \PDO
     */
    public static function pdo(): \PDO
    {
        return (new \zFramework\Core\Facades\DB(self::db()))->connection();
    }

    /**
     * Register teardown work. Runs when the file is done - after a failure,
     * a fatal, or ctrl+c alike (the runner flushes these from shutdown).
     *
     * @param \Closure $fn
     * @return void
     */
    public static function cleanup(\Closure $fn): void
    {
        self::$cleanups[] = $fn;
    }

    /**
     * Run and forget the registered teardowns. A teardown that itself throws
     * is reported as a failed pseudo-test rather than silencing the rest.
     *
     * @return void
     */
    public static function flushCleanups(): void
    {
        foreach (self::$cleanups as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                self::$results[] = ['name' => '(cleanup)', 'status' => 'fail', 'message' => get_class($e) . ': ' . $e->getMessage(), 'at' => '', 'output' => '', 'ms' => 0];
            }
        }

        self::$cleanups = [];
    }
}

/**
 * An assertion did not hold. Caught per test; everything after it in that
 * test is skipped, the next test still runs.
 */
class TestFailure extends \Exception
{
}

/**
 * The test asked to be skipped - a service it needs is not here.
 */
class TestSkipped extends \Exception
{
}

} // namespace zFramework\Kernel\Helpers

namespace {

use zFramework\Kernel\Helpers\TestKit;
use zFramework\Kernel\Helpers\TestFailure;
use zFramework\Kernel\Helpers\TestSkipped;

// ─────────────────────────────────────────────
// The vocabulary test files are written in. Defined here so a single require
// brings the whole kit; guarded because nothing stops an application from
// having its own helper with one of these names - the application wins.
// ─────────────────────────────────────────────

if (!function_exists('test')) {
    /**
     * One test: a name and a closure full of assertions.
     */
    function test(string $name, \Closure $fn): void
    {
        TestKit::case($name, $fn);
    }
}

if (!function_exists('same')) {
    /**
     * Strict equality, expected first. The order matters only for the message.
     */
    function same(mixed $expect, mixed $got, string $note = ''): void
    {
        if ($got === $expect) return;
        throw new TestFailure(($note !== '' ? "$note - " : '') . 'expected ' . json_encode($expect, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ', got ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('truthy')) {
    function truthy(mixed $got, string $note = ''): void
    {
        if ($got) return;
        throw new TestFailure(($note !== '' ? "$note - " : '') . 'expected truthy, got ' . json_encode($got));
    }
}

if (!function_exists('falsy')) {
    function falsy(mixed $got, string $note = ''): void
    {
        if (!$got) return;
        throw new TestFailure(($note !== '' ? "$note - " : '') . 'expected falsy, got ' . json_encode($got));
    }
}

if (!function_exists('contains')) {
    /**
     * Substring in a string, or value in an array (strict).
     */
    function contains(mixed $needle, string|array $haystack, string $note = ''): void
    {
        $found = is_array($haystack) ? in_array($needle, $haystack, true) : str_contains($haystack, (string) $needle);
        if ($found) return;
        throw new TestFailure(($note !== '' ? "$note - " : '') . json_encode($needle, JSON_UNESCAPED_UNICODE) . ' not found in ' . mb_strimwidth(json_encode($haystack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 200, '…'));
    }
}

if (!function_exists('throws')) {
    /**
     * The closure must throw the given class (or a subclass). Returns the
     * caught throwable for further asserts on its message.
     */
    function throws(string $class, \Closure $fn, string $note = ''): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof TestFailure || $e instanceof TestSkipped) throw $e;
            if ($e instanceof $class) return $e;
            throw new TestFailure(($note !== '' ? "$note - " : '') . "expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }

        throw new TestFailure(($note !== '' ? "$note - " : '') . "expected $class, nothing was thrown");
    }
}

if (!function_exists('skip')) {
    /**
     * End this test as skipped - a service it needs is not available here.
     */
    function skip(string $reason): never
    {
        throw new TestSkipped($reason);
    }
}

} // global namespace
