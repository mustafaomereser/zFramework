<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use zFramework\Run;
use zFramework\Core\Route as RouteFacade;
use zFramework\Core\Facades\Config;

/**
 * `php terminal bench run` - where a request's time goes on this machine.
 *
 * Reports connection setup, table scheme, config, the route table and the
 * application's global middlewares, then totals what a request pays before its
 * own code runs.
 *
 * Run it on the server you care about. The numbers do not travel: host=localhost
 * is a unix socket on Linux and a TCP connection on Windows, an order of
 * magnitude apart, and opcache changes what boot costs while changing nothing
 * about what a connection costs.
 *
 * Everything is measured without being changed - nothing written, no cache
 * cleared - except the middlewares, which cannot be timed without being run.
 * That section says so before it starts.
 */
class Bench
{
    /**
     * Costs a request actually pays, collected as they are measured.
     * @var array<array{0:string,1:float,2:string}>
     */
    private static array $budget = [];

    /**
     * How long this process took to reach the command, in nanoseconds.
     */
    private static float $startup = 0;

    /**
     * The middlewares' second run - classes loaded, closest to a warm request.
     */
    private static float $middlewareWarm = 0;

    /**
     * When measuring started, so the cost of measuring can be reported too.
     */
    private static float $began = 0;

    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Measure what a request costs on this machine, framework and application
     * Usage: php terminal bench run
     */
    public static function run()
    {
        # Taken first: how long this process took to reach the command. Over HTTP
        # that is the request's real boot; on the CLI it is bootstrap and the
        # module includes.
        $started       = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        self::$began   = microtime(true);
        self::$startup = (self::$began - $started) * 1e9;   # seconds -> ns, as ms() expects

        self::environment();
        self::connections();
        self::scheme();
        self::boot();
        self::routes();
        self::middlewares();
        self::total();

        Terminal::text('');
        Terminal::text('[color=dark-gray]Read the connection figures first. Everything else is arithmetic on top[/color]');
        Terminal::text('[color=dark-gray]of them, and they are the part that differs most between machines.[/color]');
    }

    /**
     * The application's own global middlewares - usually where its time goes.
     *
     * Unlike everything above, this runs code rather than timing something the
     * framework was going to do anyway. There is no way to measure a middleware
     * without executing it, so it says as much before it starts.
     */
    private static function middlewares(): void
    {
        self::title('Global middlewares (your code)');

        $autoload = BASE_PATH . '/App/Middlewares/autoload.php';
        if (!is_file($autoload)) {
            self::line('autoload.php', 'not found', $autoload);
            return;
        }

        Terminal::text('  [color=dark-gray]These are run for real - whatever your middlewares do, they do now.[/color]');

        # Which branch is being measured, asked rather than assumed. Over HTTP
        # with a logged-in visitor these run their full path; from the CLI there
        # is no session and everything behind Auth::check() is skipped, which is
        # usually where the expensive half lives. Reporting the wrong one makes
        # the number mean something it does not.
        $authenticated = false;
        try {
            $authenticated = \zFramework\Core\Facades\Auth::check();
        } catch (\Throwable) {
        }

        Terminal::text('  [color=dark-gray]measured as: ' . ($authenticated
            ? 'a logged-in visitor - the full path'
            : 'a guest - anything behind Auth::check() is not reached') . '[/color]');
        Terminal::text('');

        # Middlewares read the request, and under the terminal there is not one.
        # Without these they warn about missing keys and take branches no visitor
        # would take.
        $_SERVER += [
            'REQUEST_URI'     => '/',
            'REQUEST_METHOD'  => 'GET',
            'SCRIPT_NAME'     => '/index.php',
            'PHP_SELF'        => '/index.php',
            'HTTP_HOST'       => 'localhost',
            'SERVER_NAME'     => 'localhost',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'QUERY_STRING'    => '',
            'HTTPS'           => 'off',
        ];

        # The whole stack, not one line per middleware. Timing them individually
        # would mean a hook inside Middleware::middleware() - measurement code
        # living in the framework, read by everyone, useful to almost nobody. The
        # total is what the summary needs; which middleware owns it is a question
        # for a profiler, not for this.
        $files = count(get_included_files());
        $t     = hrtime(true);
        try {
            Run::includer($autoload);
        } catch (\Throwable $e) {
            self::line('failed', get_class($e), substr($e->getMessage(), 0, 60));
        }
        $first = hrtime(true) - $t;
        $files = count(get_included_files()) - $files;

        self::line('first run', self::ms($first),
            $files ? $files . ' file(s) loaded with it' : 'no files - loaded during boot, above');

        # That first pass may have been loading classes. A served request finds
        # them in opcache, so run it again - the gap is the loading.
        [$warm, $worst] = self::best(function () use ($autoload) {
            try {
                Run::includer($autoload);
            } catch (\Throwable) {
            }
        });

        self::line('warm runs, best of 3', self::ms($warm), self::spread($warm, $worst) ?: 'a repeat - the first one is inside the boot figure');
        self::$middlewareWarm = $warm;
    }

    /**
     * What one request pays the framework, before any application code runs.
     *
     * Summed from the lines above, so the breakdown shows where to look rather
     * than only whether to care. Work a request does not actually do - building
     * the scheme from the server, opening a connection the pool already holds -
     * is left out, and lines that are only paid under some condition say which.
     */
    private static function total(): void
    {
        self::title('Per request, on this machine');

        $sum = 0;
        foreach (self::$budget as [$label, $ns, $note]) {
            self::line($label, self::ms($ns), $note);
            $sum += $ns;
        }

        Terminal::text('  ' . str_repeat('-', 48));
        self::line('framework overhead', self::ms($sum), 'before a single line of your code');

        if (self::$middlewareWarm > 0) {
            $authenticated = false;
            try {
                $authenticated = \zFramework\Core\Facades\Auth::check();
            } catch (\Throwable) {
            }

            self::line('your global middlewares', self::ms(self::$middlewareWarm),
                $authenticated ? 'warm, full path' : 'warm, guest path only');
            Terminal::text('  ' . str_repeat('-', 48));
            self::line('together', self::ms($sum + self::$middlewareWarm));
        }

        # Measured from REQUEST_TIME_FLOAT, so under a web SAPI this is the real
        # thing: bootstrap, boot() with its modules, providers and route table,
        # handle() with the global middlewares, and matching the route that got
        # here. Everything itemised above is a second, warm run of work this
        # figure already paid for once - which is why it is larger, and why it is
        # the number that matters.
        if (self::$startup <= 0) return;

        $served = PHP_SAPI !== 'cli';
        self::line($served ? 'this request, start to here' : 'this process, startup to here', self::ms(self::$startup),
            $served ? 'the real boot: everything above, paid once, for real' : 'CLI - a served request does not pay this');

        # What measuring itself cost, which is not small - the scheme is rebuilt,
        # the route files included three more times, matching run two hundred
        # times. A request pays none of it, but this page does, and without the
        # line the difference looks unexplained.
        $spent = (microtime(true) - self::$began) * 1e9;
        self::line('measuring cost', self::ms($spent), 'the benchmarks themselves - not a request cost');

        if (!$served) return;

        Terminal::text('  ' . str_repeat('-', 48));
        self::line('server time so far', self::ms(self::$startup + $spent), 'compare with TTFB in devtools');
        Terminal::text('  [color=dark-gray]Anything devtools shows beyond this is network: dns, tls, latency,[/color]');
        Terminal::text('  [color=dark-gray]and sending the response back.[/color]');
    }

    /**
     * Record a cost a request actually pays, for the summary above.
     */
    private static function budget(string $label, float $ns, string $note = ''): void
    {
        self::$budget[] = [$label, $ns, $note];
    }

    private static function title(string $text): void
    {
        Terminal::text('');
        Terminal::text("[color=green]== {$text}[/color]");
    }

    private static function line(string $label, string $value, string $note = ''): void
    {
        Terminal::text('  ' . str_pad($label, 34) . '[color=yellow]' . str_pad($value, 14, ' ', STR_PAD_LEFT) . '[/color]' . ($note ? '  [color=dark-gray]' . $note . '[/color]' : ''));
    }

    private static function ms(float $ns): string
    {
        return number_format($ns / 1e6, 3) . ' ms';
    }

    /**
     * Run something a few times and keep the fastest.
     *
     * A shared machine does not answer the same way twice. The floor is the
     * closest thing to what the work itself costs; the spread comes back beside
     * it so the caller can say how much the machine was interfering.
     *
     * @return array{0:float,1:float} best, worst - in nanoseconds
     */
    private static function best(callable $work, int $times = 3): array
    {
        $best = PHP_FLOAT_MAX;
        $worst = 0;

        for ($i = 0; $i < $times; $i++) {
            $t = hrtime(true);
            $work();
            $elapsed = hrtime(true) - $t;

            $best  = min($best, $elapsed);
            $worst = max($worst, $elapsed);
        }

        return [$best, $worst];
    }

    /**
     * How far apart the fastest and slowest runs were, when it is worth saying.
     */
    private static function spread(float $best, float $worst): string
    {
        if ($best <= 0 || $worst / $best < 1.5) return '';

        return 'varied up to ' . self::ms($worst) . ' - shared machine';
    }

    /**
     * What this machine is, since that decides most of the numbers below.
     */
    private static function environment(): void
    {
        self::title('Environment');
        self::line('php', PHP_VERSION . ' / ' . PHP_SAPI);
        self::line('opcache', function_exists('opcache_get_status') && (@\opcache_get_status()['opcache_enabled'] ?? false) ? 'on' : 'OFF',
            function_exists('opcache_get_status') ? '' : 'extension missing - boot below is compile-bound');

        # Where a swinging boot time usually hides. validate_timestamps makes
        # every request stat every cached file; a low hit rate or a restart count
        # that climbs means scripts are being recompiled rather than reused.
        if (function_exists('opcache_get_status')) {
            $status = @\opcache_get_status(false) ?: [];
            $stats  = $status['opcache_statistics'] ?? [];
            $memory = $status['memory_usage'] ?? [];

            $hits   = (int) ($stats['hits'] ?? 0);
            $misses = (int) ($stats['misses'] ?? 0);
            if ($hits + $misses > 0) self::line('opcache hit rate', number_format($hits / ($hits + $misses) * 100, 1) . '%',
                ($stats['num_cached_scripts'] ?? '?') . ' scripts cached');

            $restarts = (int) ($stats['oom_restarts'] ?? 0) + (int) ($stats['hash_restarts'] ?? 0) + (int) ($stats['manual_restarts'] ?? 0);
            if ($restarts > 0) self::line('opcache restarts', (string) $restarts, 'cache has been thrown away this many times');

            if (isset($memory['free_memory'], $memory['used_memory'])) {
                $free = $memory['free_memory'] / 1048576;
                self::line('opcache free memory', number_format($free, 1) . ' MB', $free < 8 ? 'low - a full cache evicts and recompiles' : '');
            }

            $directives = @\opcache_get_configuration()['directives'] ?? [];
            if (array_key_exists('opcache.validate_timestamps', $directives))
                self::line('validate_timestamps', $directives['opcache.validate_timestamps'] ? 'on' : 'off',
                    $directives['opcache.validate_timestamps'] ? 'stats every included file, every request' : 'deploy must reload php');
        }
        # GlobalCache's own answer rather than the extension's: it also honours
        # cache.apcu, so this reports what the framework will actually do.
        self::line('apcu', \zFramework\Core\GlobalCache::apcu() ? 'on' : 'OFF',
            'holds the table scheme when on');
        self::line('os', PHP_OS_FAMILY);
    }

    /**
     * Opening a connection, and opening it again once the pool has one.
     *
     * The second number is what a request pays under FPM: the first request a
     * worker serves fills the pool, every one after it reuses it. Without
     * ATTR_PERSISTENT both numbers are the same, and that is the finding.
     */
    private static function connections(): void
    {
        self::title('Database connections');

        # connections[] holds live PDO objects once anything has connected, which
        # Auth::init() does from the autoloader. The dsn kept aside for
        # reconnect() is what can still be opened again here.
        $connections = ($GLOBALS['databases']['dsn'] ?? []) + ($GLOBALS['databases']['connections'] ?? []);

        if (!count($connections)) {
            self::line('connections', 'none defined', 'database/connections.php is empty?');
            return;
        }

        foreach ($connections as $name => $parameters) {
            if (!is_array($parameters)) {
                self::line("[$name]", 'already open', 'no dsn kept - cannot reopen to measure');
                continue;
            }

            $options = [];
            foreach ($parameters['options'] ?? [] as $option) $options[$option[0]] = $option[1];
            $persistent = (bool) ($options[\PDO::ATTR_PERSISTENT] ?? false);

            $dsn = $parameters[0] ?? '';
            $transport = str_contains($dsn, 'unix_socket') ? 'unix socket'
                : (preg_match('/host=([^;]+)/', $dsn, $m) ? ($m[1] === 'localhost' && PHP_OS_FAMILY !== 'Windows' ? 'unix socket (localhost)' : 'tcp ' . $m[1]) : '?');

            $user = $parameters[1] ?? null;
            $pass = $parameters[2] ?? null;

            try {
                # Pool bypassed, so this is the handshake itself. Timing the
                # persistent one instead would measure an already-filled pool and
                # report near zero.
                $noPool = $options;
                unset($noPool[\PDO::ATTR_PERSISTENT]);

                $fresh   = PHP_INT_MAX;
                $freshId = null;
                for ($i = 0; $i < 3; $i++) {
                    $t      = hrtime(true);
                    $handle = new \PDO($dsn, $user, $pass, $noPool);
                    $fresh  = min($fresh, hrtime(true) - $t);
                    $freshId ??= @$handle->query('SELECT CONNECTION_ID()')->fetchColumn();
                    unset($handle);
                }

                self::line("[$name] transport", $transport);
                self::line("[$name] ATTR_PERSISTENT", $persistent ? 'requested' : 'off');
                self::line("[$name] fresh connect", self::ms($fresh), 'no pooling - the handshake itself');

                if (!$persistent) {
                    self::line("[$name] per request", self::ms($fresh), 'every request pays this');
                    self::budget("connect [$name]", $fresh, 'no pooling - full handshake');
                    continue;
                }

                # Now the pooled one. Best of five, since the point is the floor.
                $pooled   = PHP_INT_MAX;
                $pooledId = null;
                $firstId  = null;
                for ($i = 0; $i < 5; $i++) {
                    $t      = hrtime(true);
                    $handle = new \PDO($dsn, $user, $pass, $options);
                    $pooled = min($pooled, hrtime(true) - $t);
                    $firstId ??= @$handle->query('SELECT CONNECTION_ID()')->fetchColumn();
                    $pooledId  = @$handle->query('SELECT CONNECTION_ID()')->fetchColumn();
                    unset($handle);
                }

                $pooling = $firstId !== null && $firstId === $pooledId && $firstId !== $freshId;

                self::line("[$name] pooled reopen", self::ms($pooled),
                    $pooling ? 'same server thread - pooling works' : 'NEW thread each time - pooling NOT working');
                self::line("[$name] saved per request", self::ms(max($fresh - $pooled, 0)),
                    $pooling ? 'what persistent is worth here' : 'nothing - see above');

                self::budget("connect [$name]", $pooling ? $pooled : $fresh, $pooling ? 'from the pool' : 'pooling not working');
            } catch (\Throwable $e) {
                self::line("[$name]", 'unreachable', substr($e->getMessage(), 0, 60));
            }
        }
    }

    /**
     * Building the table scheme from information_schema, against reading it back
     * from the cache. The first is paid whenever the cache is cold.
     */
    private static function scheme(): void
    {
        self::title('Table scheme');

        try {
            $db = new \zFramework\Core\Facades\DB();
            if (!$db->connection()) return;

            $reflection = new \ReflectionClass($db);
            $builder    = $reflection->getProperty('builder');
            $driver = $builder->getValue($db);
            if (!$driver) return;

            $t      = hrtime(true);
            $scheme = $driver->setParent($db)->tables();
            $elapsed   = hrtime(true) - $t;

            $tables  = count($scheme['TABLES'] ?? []);
            $columns  = array_sum(array_map(fn($x) => count($x['columns'] ?? []), $scheme['TABLE_COLUMNS'] ?? []));
            $json   = json_encode($scheme, JSON_UNESCAPED_UNICODE);

            self::line('tables / columns', $tables . ' / ' . $columns);
            self::line('build from server', self::ms($elapsed), 'paid when the cache is cold');
            self::line('cached size', number_format(strlen($json) / 1024, 1) . ' KB');

            $t = hrtime(true);
            json_decode($json, true);
            $decode = hrtime(true) - $t;

            $apcu = \zFramework\Core\GlobalCache::apcu();
            self::line('json_decode', self::ms($decode), $apcu ? 'not paid - apcu holds it' : 'paid per request - apcu is off');
            if (!$apcu) self::budget('scheme decode', $decode, 'turn apcu on and this goes');
        } catch (\Throwable $e) {
            self::line('scheme', 'failed', substr($e->getMessage(), 0, 60));
        }
    }

    /**
     * What the framework does before it can serve anything. A long-running
     * server pays this once; FPM pays it every request.
     */
    private static function boot(): void
    {
        self::title('Boot (per request under FPM)');

        # Cold: the include() itself, which opcache serves from memory in
        # production and recompiles here when it is off. Warm: what a second
        # lookup in the same request costs.
        $t = hrtime(true);
        Config::get('app');
        Config::framework('view');
        Config::framework('route');
        $configRead = hrtime(true) - $t;
        self::line('config files, first read (3)', self::ms($configRead), 'include - opcache territory');
        self::budget('config includes', $configRead);

        $t = hrtime(true);
        Config::get('app');
        Config::framework('view');
        Config::framework('route');
        self::line('config files, second read (3)', self::ms(hrtime(true) - $t), 'memoised');

        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) Config::get('app.debug');
        self::line('config lookup x1000', self::ms(hrtime(true) - $t), 'DB::prepare() does one per query');

        $t = hrtime(true);
        glob(BASE_PATH . '/App/Providers/*.php');
        @scandir(BASE_PATH . '/modules');
        $discover = hrtime(true) - $t;
        self::line('provider glob + module scan', self::ms($discover));
        self::budget('provider + module discovery', $discover);

        self::line('files loaded so far', (string) count(get_included_files()));
        self::line('peak memory', number_format(memory_get_peak_usage() / 1048576, 1) . ' MB');
    }

    /**
     * Route table size, and what matching one costs - best and worst case.
     */
    private static function routes(): void
    {
        self::title('Routes');

        # The terminal does not build the route table, so build it here - the same
        # way `route cache` does, and only if nothing has built it already.
        if (!count(RouteFacade::$routes)) {
            try {
                Run::initProviders()::findModules(base_path('/modules'))::loadModules();
                Run::includer(BASE_PATH . '/route', true, false, '.php', BASE_PATH . '/route/dynamic');
            } catch (\Throwable $e) {
                self::line('route table', 'could not build', substr($e->getMessage(), 0, 60));
                return;
            }
        }

        $routes = RouteFacade::$routes;
        $total  = count($routes);
        if (!$total) return;

        $dynamic = count(array_filter($routes, fn($r) => $r['dynamic'] ?? false));
        self::line('routes (static / dynamic)', ($total - $dynamic) . ' / ' . $dynamic);

        # Whether the table is actually being served from cache, which is a
        # different question from whether caching is switched on. One closure
        # anywhere keeps the whole table out, and then every request re-runs the
        # route files - the cost of which is this line, not the matching below.
        global $storage_path;
        $cache    = ($storage_path ?? FRAMEWORK_PATH . '/storage') . '/routes.cache.php';
        $blockers = RouteFacade::cacheBlockers();

        # Usually the largest line in the summary, so more than one sample. The
        # table is restored between runs: includer() appends, and three passes
        # would otherwise measure a table three times its real size.
        $table = RouteFacade::$routes;
        [$defining, $definingWorst] = self::best(function () use ($table) {
            RouteFacade::$routes = $table;
            Run::includer(BASE_PATH . '/route', true, false, '.php', BASE_PATH . '/route/dynamic');
        });
        RouteFacade::$routes = $table;

        if (count($blockers)) {
            self::line('route cache', 'BLOCKED', count($blockers) . ' route(s) use a closure - `route cache` names them');
            self::line('cost of that', self::ms($defining), self::spread($defining, $definingWorst) ?: 're-running the route files, every request');
            self::budget('route definitions', $defining, count($blockers) . ' closure route(s) block the cache');
        } elseif (is_file($cache)) {
            self::line('route cache', 'in use', number_format(filesize($cache) / 1024, 1) . ' KB');
            self::line('saved by it', self::ms($defining), 'what defining the routes would have cost');

            # What the cached table costs instead: one include, which opcache
            # serves from memory.
            $t = hrtime(true);
            include $cache;
            self::budget('route cache include', hrtime(true) - $t);
        } else {
            self::line('route cache', 'not built', 'run `php terminal route cache`');
            self::line('cost of that', self::ms($defining), 're-running the route files, every request');
            self::budget('route definitions', $defining, 'no cache built yet');
        }

        $reflection = new \ReflectionClass(RouteFacade::class);
        $index      = $reflection->getProperty('index');
        $build = $reflection->getMethod('index');

        $t = hrtime(true);
        for ($i = 0; $i < 20; $i++) { $index->setValue(null, null); $build->invoke(null); }
        $indexBuild = (hrtime(true) - $t) / 20;
        self::line('index build', self::ms($indexBuild), 'once per request');
        self::budget('route index build', $indexBuild, 'not cached - rebuilt every request');

        $called = $reflection->getProperty('calledRoute');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ([['first match', array_values($routes)[0]['url'] ?? '/'], ['no match (404)', '/' . bin2hex(random_bytes(6))]] as [$label, $path]) {
            $_SERVER['REQUEST_URI'] = $path;
            $t = hrtime(true);
            for ($i = 0; $i < 100; $i++) { $called->setValue(null, null); RouteFacade::match(); }
            self::line($label, self::ms((hrtime(true) - $t) / 100));
        }
        $_SERVER['REQUEST_URI'] = $uri;
        $called->setValue(null, null);
    }
}
