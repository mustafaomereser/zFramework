<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use zFramework\Run;
use zFramework\Core\Route as RouteFacade;
use zFramework\Core\Facades\Config;

/**
 * Measures where a request's time actually goes, on the machine that serves it.
 *
 * Written because a round of optimisation was done against local numbers and
 * the difference did not show up in production - and the reason is the sort of
 * thing no amount of reading finds. Connecting to MySQL over TCP on one platform
 * and over a unix socket on another differ by an order of magnitude; opcache
 * changes what boot costs but nothing about what a connection costs. Guessing
 * which of those applies to a given server is how a day gets spent on the wrong
 * 0.2 ms.
 *
 * Everything here only reads. No table is written, no cache is cleared, no
 * request is served - it is safe to run on a live machine, which is the only
 * place the answer is worth anything.
 */
class Bench
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Measure connection, scheme, boot and route costs on this machine
     * Usage: php terminal bench run
     */
    public static function run()
    {
        self::environment();
        self::connections();
        self::scheme();
        self::boot();
        self::routes();

        Terminal::text('');
        Terminal::text('[color=dark-gray]Read the connection figures first. Everything else is arithmetic on top[/color]');
        Terminal::text('[color=dark-gray]of them, and they are the part that differs most between machines.[/color]');
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
     * What this machine is, since that decides most of the numbers below.
     */
    private static function environment(): void
    {
        self::title('Environment');
        self::line('php', PHP_VERSION . ' / ' . PHP_SAPI);
        self::line('opcache', function_exists('opcache_get_status') && (@\opcache_get_status()['opcache_enabled'] ?? false) ? 'on' : 'OFF',
            function_exists('opcache_get_status') ? '' : 'extension missing - boot below is compile-bound');
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

        # connections[] holds the live PDO once something has connected - and
        # something has, because Auth::init() runs from the autoloader before any
        # of this. The dsn and credentials are kept aside there for reconnect();
        # they are what this needs too.
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
                # A genuinely new connection: the pool is bypassed, so this is what
                # the handshake actually costs here. Measuring the persistent one
                # "first" would not do - by the time anything runs, Auth::init() has
                # already filled the pool and the answer would come back near zero.
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
            self::line('json_decode', self::ms(hrtime(true) - $t), 'paid per request without apcu');
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
        Config::get('view');
        Config::get('route');
        self::line('config files, first read (3)', self::ms(hrtime(true) - $t), 'include - opcache territory');

        $t = hrtime(true);
        Config::get('app');
        Config::get('view');
        Config::get('route');
        self::line('config files, second read (3)', self::ms(hrtime(true) - $t), 'memoised');

        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) Config::get('app.debug');
        self::line('config lookup x1000', self::ms(hrtime(true) - $t), 'DB::prepare() does one per query');

        $t = hrtime(true);
        glob(BASE_PATH . '/App/Providers/*.php');
        @scandir(BASE_PATH . '/modules');
        self::line('provider glob + module scan', self::ms(hrtime(true) - $t));

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

        $t = hrtime(true);
        Run::includer(BASE_PATH . '/route', true, false, '.php', BASE_PATH . '/route/dynamic');
        $defining = hrtime(true) - $t;

        if (count($blockers)) {
            self::line('route cache', 'BLOCKED', count($blockers) . ' route(s) use a closure - `route cache` names them');
            self::line('cost of that', self::ms($defining), 're-running the route files, every request');
        } elseif (is_file($cache)) {
            self::line('route cache', 'in use', number_format(filesize($cache) / 1024, 1) . ' KB');
            self::line('saved by it', self::ms($defining), 'what defining the routes would have cost');
        } else {
            self::line('route cache', 'not built', 'run `php terminal route cache`');
            self::line('cost of that', self::ms($defining), 're-running the route files, every request');
        }

        $reflection = new \ReflectionClass(RouteFacade::class);
        $index      = $reflection->getProperty('index');
        $build = $reflection->getMethod('index');

        $t = hrtime(true);
        for ($i = 0; $i < 20; $i++) { $index->setValue(null, null); $build->invoke(null); }
        self::line('index build', self::ms((hrtime(true) - $t) / 20), 'once per request');

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
