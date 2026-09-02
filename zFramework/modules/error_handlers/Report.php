<?php

namespace zFramework\modules\error_handlers;

use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Config;
use zFramework\Core\Facades\DB;
use zFramework\Core\Facades\Session;
use zFramework\Core\Route;
use zFramework\Core\View;

/**
 * Everything the error page knows, gathered once and handed to a renderer.
 *
 * Collecting is kept apart from rendering so the same report can go out as a
 * page, as JSON to a client that asked for it, or as text to a terminal - and so
 * the masking of secrets happens in one place, before any renderer sees a value.
 */
class Report
{
    /**
     * Keys whose values are never shown. Matched case-insensitively against the
     * key name, as a substring, in request data, session, cookies, headers,
     * server variables and frame arguments alike. framework.error.mask adds to it.
     */
    private const MASK = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'auth', 'csrf', 'cookie', 'authorization', 'private', 'salt', 'credential'];

    private const MASKED = '••••••';

    /**
     * Longest string shown in full inside a frame argument.
     */
    private const ARG_LENGTH = 200;

    /**
     * @param \Throwable|array $thrown A Throwable, or the (array) cast of one that older callers pass.
     * @return array
     */
    public static function build(\Throwable|array $thrown): array
    {
        if (is_array($thrown)) {
            $values = array_values($thrown);
            $thrown = new \ErrorException((string) ($values[0] ?? 'Unknown error'), (int) ($values[2] ?? 0), E_ERROR, (string) ($values[3] ?? '?'), (int) ($values[4] ?? 0));
        }

        $chain = [];
        for ($e = $thrown; $e !== null; $e = $e->getPrevious()) $chain[] = self::exception($e);

        $started  = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $mask     = self::maskList();

        return [
            'chain'    => $chain,
            'class'    => $chain[0]['class'],
            'message'  => $chain[0]['message'],
            'code'     => $chain[0]['code'],
            'file'     => $chain[0]['file'],
            'line'     => $chain[0]['line'],
            'request'  => self::request($mask),
            'route'    => self::route(),
            'user'     => self::user(),
            'queries'  => self::queries($mask),
            'env'      => [
                'php'       => PHP_VERSION,
                'framework' => defined('FRAMEWORK_VERSION') ? FRAMEWORK_VERSION : 'dev',
                'sapi'      => PHP_SAPI,
                'worker'    => defined('ZF_WORKER'),
                'opcache'   => function_exists('opcache_get_status') && (@opcache_get_status(false)['opcache_enabled'] ?? false),
                'memory'    => memory_get_peak_usage(true),
                'elapsed'   => round((microtime(true) - $started) * 1000, 1),
                'time'      => date('Y-m-d H:i:s'),
                'timezone'  => date_default_timezone_get(),
                'debug'     => (bool) Config::get('app.debug'),
            ],
            'previous' => self::previousReports(),
        ];
    }

    /**
     * One exception of the chain, with its frames resolved.
     *
     * @param \Throwable $e
     * @return array
     */
    private static function exception(\Throwable $e): array
    {
        $trace  = $e->getTrace();
        $frames = [];

        # The throw site is not in getTrace(); it is the exception's own file and
        # line, and the function running there is the one trace[0] names. For every
        # later frame the location is trace[i] and the function running in it is
        # trace[i+1] - the entry that made the call - which is why each frame keeps
        # the index of the entry that describes it. An internal frame has no file
        # and is not shown, but it still names the function of the frame above it.
        $frames[] = self::frame($e->getFile(), $e->getLine(), $trace[0] ?? [], 0, 0);

        foreach ($trace as $i => $t) {
            if (!isset($t['file'])) continue;

            # An internal function that throws - strtoupper([]) - is reported at its
            # call site, and trace[0] is that same call. One frame, not two; the
            # throw-site frame above already carries the function and its arguments.
            if ($i === 0 && $t['file'] === $e->getFile() && ($t['line'] ?? null) === $e->getLine()) continue;

            $frames[] = self::frame($t['file'], $t['line'] ?? 0, $trace[$i + 1] ?? [], count($frames), $i + 1);
        }

        return [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'code'    => $e->getCode(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'frames'  => self::pairArguments($frames, $trace),
        ];
    }

    /**
     * A frame: where the code was, what it was doing, and which kind of file.
     *
     * @param string $file
     * @param int    $line
     * @param array  $called The trace entry naming the function running here.
     * @param int    $index
     * @param int    $traceIndex Where $called sits in the trace; arguments are read from there.
     * @return array
     */
    private static function frame(string $file, int $line, array $called, int $index, int $traceIndex): array
    {
        $frame = [
            'index'    => $index,
            'trace'    => $traceIndex,
            'file'     => str_replace('\\', '/', $file),
            'line'     => $line,
            'function' => isset($called['function']) ? (($called['class'] ?? '') . ($called['type'] ?? '') . $called['function']) : null,
            'args'     => [],
            'kind'     => 'app',
            'compiled' => null,
        ];

        # A template frame. PHP names the file `View.php(290) : eval()'d code`;
        # the compiled text View kept for that eval is mapped back to the template.
        if (preg_match('/^(.*)\((\d+)\) : eval\(\)\'d code$/', $frame['file'], $m)) {
            $frame['compiled'] = ['file' => $m[1], 'line' => $line];
            $frame['kind']     = 'view';
            $frame['file']     = $m[1];
            $frame['line']     = (int) $m[2];
            return $frame;   # resolved in pairArguments(), which knows the eval order
        }

        # A cached template, served by include: the file is real, the source is not it.
        if (str_ends_with($frame['file'], '.compiled.php') && is_file($frame['file'])) {
            $source = class_exists(View::class, false) ? View::sourceOf((string) file_get_contents($frame['file']), $line) : null;
            if ($source) {
                $frame['compiled'] = ['file' => $frame['file'], 'line' => $line];
                $frame['file']     = str_replace('\\', '/', $source['file']);
                $frame['line']     = $source['line'];
                $frame['kind']     = 'view';
                return $frame;
            }
        }

        $frame['kind'] = self::kind($frame['file']);

        return $frame;
    }

    /**
     * Whose file this is - by prefix, not by whether the path contains a word.
     *
     * The old test looked for "zframework" anywhere in the path, and a project
     * checked out into a directory called zFramework had every file of its own
     * marked as vendor code.
     *
     * @param string $file
     * @return string app|framework|vendor|internal
     */
    private static function kind(string $file): string
    {
        $framework = defined('FRAMEWORK_PATH') ? str_replace('\\', '/', FRAMEWORK_PATH) . '/' : null;

        if ($framework && str_starts_with($file, $framework . 'vendor/')) return 'vendor';
        if ($framework && str_starts_with($file, $framework)) return 'framework';
        if (!is_file($file)) return 'internal';

        return 'app';
    }

    /**
     * Give each frame the arguments of the function running in it, masked and cut
     * to size, and resolve template frames against the eval stack.
     *
     * @param array $frames
     * @param array $trace
     * @return array
     */
    private static function pairArguments(array $frames, array $trace): array
    {
        $mask = self::maskList();

        # Eval frames come innermost first, and View::$evaluating is innermost last.
        $evaluating = class_exists(View::class, false) ? array_reverse(View::$evaluating) : [];
        $nthEval    = 0;

        foreach ($frames as &$frame) {
            $entry = $trace[$frame['trace']] ?? [];
            $args  = $entry['args'] ?? [];

            # Named by parameter where the function can be reflected, so a password
            # argument is recognised by its name and not only by its value.
            $names = self::parameterNames($entry);

            foreach (array_values($args) as $n => $value) {
                $label = $names[$n] ?? "#$n";
                $frame['args'][$label] = self::sanitize($value, $mask, $label);
            }

            if ($frame['kind'] === 'view' && $frame['compiled'] && !str_ends_with($frame['compiled']['file'], '.compiled.php')) {
                $compiled = $evaluating[$nthEval++] ?? null;
                $source   = $compiled !== null ? View::sourceOf($compiled, $frame['compiled']['line']) : null;

                if ($source) {
                    $frame['file'] = str_replace('\\', '/', $source['file']);
                    $frame['line'] = $source['line'];
                } else {
                    # Nothing to map it with: show the compiled text itself.
                    $frame['snippet'] = $compiled;
                    $frame['line']    = $frame['compiled']['line'];
                }
            }
        }

        return $frames;
    }

    /**
     * Parameter names of the function a trace entry names, when it can be found.
     *
     * @param array $t
     * @return string[]
     */
    private static function parameterNames(array $t): array
    {
        if (empty($t['function'])) return [];

        try {
            $reflection = isset($t['class'])
                ? new \ReflectionMethod($t['class'], $t['function'])
                : (function_exists($t['function']) ? new \ReflectionFunction($t['function']) : null);
        } catch (\Throwable) {
            return [];
        }

        if (!$reflection) return [];

        return array_map(fn($p) => '$' . $p->getName(), $reflection->getParameters());
    }

    /**
     * A value made safe to show: secrets masked by key, long strings cut,
     * objects reduced to their class, depth bounded.
     *
     * @param mixed       $value
     * @param array       $mask
     * @param string|null $key   The name the value sits under, if any.
     * @param int         $depth
     * @return mixed
     */
    public static function sanitize(mixed $value, array $mask, ?string $key = null, int $depth = 0): mixed
    {
        if ($key !== null && self::sensitive($key, $mask)) return $value === null || $value === '' ? $value : self::MASKED;

        if (is_string($value)) {
            return mb_strlen($value) > self::ARG_LENGTH ? mb_substr($value, 0, self::ARG_LENGTH) . '… (' . mb_strlen($value) . ' chars)' : $value;
        }

        if ($value instanceof \Closure) return '{closure}';
        if (is_object($value)) {
            if ($depth >= 3) return '{' . get_class($value) . '}';
            $out = ['{class}' => get_class($value)];
            foreach (get_object_vars($value) as $k => $v) $out[$k] = self::sanitize($v, $mask, (string) $k, $depth + 1);
            return $out;
        }

        if (is_array($value)) {
            if ($depth >= 4) return '[array(' . count($value) . ')]';
            $out = [];
            $n   = 0;
            foreach ($value as $k => $v) {
                if ($n++ >= 50) { $out['…'] = '(' . (count($value) - 50) . ' more)'; break; }
                $out[$k] = self::sanitize($v, $mask, (string) $k, $depth + 1);
            }
            return $out;
        }

        if (is_resource($value)) return '{resource}';

        return $value;
    }

    /**
     * @param string $key
     * @param array  $mask
     * @return bool
     */
    private static function sensitive(string $key, array $mask): bool
    {
        $key = strtolower($key);
        foreach ($mask as $needle) if ($needle !== '' && str_contains($key, $needle)) return true;
        return false;
    }

    /**
     * The built-in list plus framework.error.mask.
     *
     * @return array
     */
    private static function maskList(): array
    {
        $extra = self::setting('mask');
        return array_map('strtolower', array_merge(self::MASK, is_array($extra) ? $extra : []));
    }

    /**
     * One key of the error configuration, wherever the application keeps it.
     *
     * It lives under framework.php's `error` now; older applications still have it
     * in app.php, and both are read so a move between versions breaks nothing.
     *
     * @param string $key
     * @return mixed
     */
    public static function setting(string $key): mixed
    {
        $framework = Config::framework("error.$key");
        if ($framework !== null) return $framework;

        $app = Config::get("app.error.$key");

        # Config::get() answers a missing key with its parent array; that is not a value.
        return is_array($app) && isset($app['logging']) ? null : $app;
    }

    /**
     * @param array $mask
     * @return array
     */
    private static function request(array $mask): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
            elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $k))))] = $v;
        }

        $server = array_filter($_SERVER, fn($k) => !str_starts_with($k, 'HTTP_'), ARRAY_FILTER_USE_KEY);

        # Cookies are stored under encrypted names; shown under the names they were
        # set with where those can be recovered, and values stay masked either way.
        $cookies = [];
        foreach (array_keys($_COOKIE) as $name) $cookies[$name] = self::MASKED;

        $session = [];
        try {
            if (class_exists(Session::class, false) && PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_DISABLED)
                $session = Session::callback(fn() => $_SESSION ?? []);
        } catch (\Throwable) {
        }

        return [
            'method'  => $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET'),
            'url'     => $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? implode(' ', $_SERVER['argv'] ?? []) : '/'),
            'scheme'  => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http',
            'host'    => $_SERVER['HTTP_HOST'] ?? gethostname(),
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            'get'     => self::sanitize($_GET, $mask),
            'post'    => self::sanitize($_POST, $mask),
            'files'   => array_map(fn($f) => is_array($f) ? array_intersect_key($f, array_flip(['name', 'type', 'size', 'error'])) : $f, $_FILES),
            'cookies' => $cookies,
            'session' => self::sanitize($session, $mask),
            'headers' => self::sanitize($headers, $mask),
            'server'  => self::sanitize($server, $mask),
        ];
    }

    /**
     * What the router matched, if it got that far.
     *
     * @return array|null
     */
    private static function route(): ?array
    {
        if (!class_exists(Route::class, false) || Route::$calledRoute === null) return null;

        $called   = Route::$calledRoute;
        $callback = $called['callback'] ?? null;

        $handler = match (true) {
            $callback instanceof \Closure               => '{closure}',
            is_array($callback) && isset($callback[1]) => (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . $callback[1],
            is_array($callback) && isset($callback['redirect']) => 'redirect → ' . $callback['redirect'],
            is_string($callback)                        => $callback,
            default                                     => null,
        };

        $middlewares = [];
        foreach ((array) (Route::$matchedGroups['middlewares'][0] ?? []) as $m) $middlewares[] = is_string($m) ? $m : (is_object($m) ? get_class($m) : gettype($m));

        return [
            'name'        => is_int($called['name'] ?? null) ? null : ($called['name'] ?? null),
            'handler'     => $handler,
            'parameters'  => $called['parameters'] ?? [],
            'middlewares' => $middlewares,
            'prefix'      => Route::$matchedGroups['pre'] ?? null,
        ];
    }

    /**
     * Who was logged in - only what is already loaded, never a query from here.
     *
     * The old page called Auth::user() to fill this, which opened a database
     * connection while reporting an error that was, often enough, the database.
     * And it printed the row whole, hash and api token included, into a file.
     *
     * @return array|null
     */
    private static function user(): ?array
    {
        if (!class_exists(Auth::class, false) || !is_array(Auth::$user)) return null;

        $user = Auth::$user;
        $out  = [];
        foreach (['id', 'username', 'name', 'email'] as $key) if (isset($user[$key]) && is_scalar($user[$key])) $out[$key] = $user[$key];

        return $out ?: ['id' => $user['id'] ?? '?'];
    }

    /**
     * @param array $mask
     * @return array
     */
    private static function queries(array $mask): array
    {
        if (!class_exists(DB::class, false)) return [];

        return array_map(fn($q) => array_merge($q, ['bindings' => self::sanitize($q['bindings'] ?? [], $mask)]), DB::$queryLog);
    }

    /**
     * The most recent reports on disk, newest first, so one failure can be read
     * next to the ones before it.
     *
     * @return array
     */
    private static function previousReports(): array
    {
        if (!defined('ERROR_LOG_DIR') || !is_dir(ERROR_LOG_DIR)) return [];

        $limit = (int) (self::setting('previous') ?? 10);
        if ($limit < 1) return [];

        $files = glob(ERROR_LOG_DIR . '/*.html') ?: [];
        rsort($files);

        $out = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $name = basename($file, '.html');
            # Y-m-d-H-i-s-hex[-Class]
            $out[] = [
                'file'  => $file,
                'name'  => $name,
                'time'  => preg_match('/^(\d{4}-\d{2}-\d{2})-(\d{2})-(\d{2})-(\d{2})/', $name, $m) ? "$m[1] $m[2]:$m[3]:$m[4]" : $name,
                'class' => preg_match('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-[0-9a-f]{6}-(.+)$/', $name, $m) ? str_replace('.', '\\', $m[1]) : null,
            ];
        }

        return $out;
    }
}
