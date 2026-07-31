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

    private static function baslik(string $text): void
    {
        Terminal::text('');
        Terminal::text("[color=green]== {$text}[/color]");
    }

    private static function satir(string $label, string $value, string $note = ''): void
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
        self::baslik('Environment');
        self::satir('php', PHP_VERSION . ' / ' . PHP_SAPI);
        self::satir('opcache', function_exists('opcache_get_status') && (@opcache_get_status()['opcache_enabled'] ?? false) ? 'on' : 'OFF',
            function_exists('opcache_get_status') ? '' : 'extension missing - boot below is compile-bound');
        self::satir('apcu', function_exists('apcu_enabled') && @apcu_enabled() ? 'on' : 'OFF',
            'holds the table scheme when on');
        self::satir('os', PHP_OS_FAMILY);
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
        self::baslik('Database connections');

        # connections[] holds the live PDO once something has connected - and
        # something has, because Auth::init() runs from the autoloader before any
        # of this. The dsn and credentials are kept aside there for reconnect();
        # they are what this needs too.
        $connections = ($GLOBALS['databases']['dsn'] ?? []) + ($GLOBALS['databases']['connections'] ?? []);

        if (!count($connections)) {
            self::satir('connections', 'none defined', 'database/connections.php is empty?');
            return;
        }

        foreach ($connections as $name => $parameters) {
            if (!is_array($parameters)) {
                self::satir("[$name]", 'already open', 'no dsn kept - cannot reopen to measure');
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

                $taze = PHP_INT_MAX;
                $tazeId = null;
                for ($i = 0; $i < 3; $i++) {
                    $t = hrtime(true);
                    $fresh = new \PDO($dsn, $user, $pass, $noPool);
                    $taze = min($taze, hrtime(true) - $t);
                    $tazeId ??= @$fresh->query('SELECT CONNECTION_ID()')->fetchColumn();
                    unset($fresh);
                }

                self::satir("[$name] transport", $transport);
                self::satir("[$name] ATTR_PERSISTENT", $persistent ? 'requested' : 'off');
                self::satir("[$name] fresh connect", self::ms($taze), 'no pooling - the handshake itself');

                if (!$persistent) {
                    self::satir("[$name] per request", self::ms($taze), 'every request pays this');
                    continue;
                }

                # Now the pooled one. Best of five, since the point is the floor.
                $havuz = PHP_INT_MAX;
                $havuzId = null;
                $ilkId   = null;
                for ($i = 0; $i < 5; $i++) {
                    $t = hrtime(true);
                    $pooled = new \PDO($dsn, $user, $pass, $options);
                    $havuz = min($havuz, hrtime(true) - $t);
                    $ilkId ??= @$pooled->query('SELECT CONNECTION_ID()')->fetchColumn();
                    $havuzId = @$pooled->query('SELECT CONNECTION_ID()')->fetchColumn();
                    unset($pooled);
                }

                $calisiyor = $ilkId !== null && $ilkId === $havuzId && $ilkId !== $tazeId;

                self::satir("[$name] pooled reopen", self::ms($havuz),
                    $calisiyor ? 'same server thread - pooling works' : 'NEW thread each time - pooling NOT working');
                self::satir("[$name] saved per request", self::ms(max($taze - $havuz, 0)),
                    $calisiyor ? 'what persistent is worth here' : 'nothing - see above');
            } catch (\Throwable $e) {
                self::satir("[$name]", 'unreachable', substr($e->getMessage(), 0, 60));
            }
        }
    }

    /**
     * Building the table scheme from information_schema, against reading it back
     * from the cache. The first is paid whenever the cache is cold.
     */
    private static function scheme(): void
    {
        self::baslik('Table scheme');

        try {
            $db = new \zFramework\Core\Facades\DB();
            if (!$db->connection()) return;

            $reflection = new \ReflectionClass($db);
            $builder    = $reflection->getProperty('builder');
            $builder->setAccessible(true);
            $driver = $builder->getValue($db);
            if (!$driver) return;

            $t      = hrtime(true);
            $scheme = $driver->setParent($db)->tables();
            $sure   = hrtime(true) - $t;

            $tablo  = count($scheme['TABLES'] ?? []);
            $kolon  = array_sum(array_map(fn($x) => count($x['columns'] ?? []), $scheme['TABLE_COLUMNS'] ?? []));
            $json   = json_encode($scheme, JSON_UNESCAPED_UNICODE);

            self::satir('tables / columns', $tablo . ' / ' . $kolon);
            self::satir('build from server', self::ms($sure), 'paid when the cache is cold');
            self::satir('cached size', number_format(strlen($json) / 1024, 1) . ' KB');

            $t = hrtime(true);
            json_decode($json, true);
            self::satir('json_decode', self::ms(hrtime(true) - $t), 'paid per request without apcu');
        } catch (\Throwable $e) {
            self::satir('scheme', 'failed', substr($e->getMessage(), 0, 60));
        }
    }

    /**
     * What the framework does before it can serve anything. A long-running
     * server pays this once; FPM pays it every request.
     */
    private static function boot(): void
    {
        self::baslik('Boot (per request under FPM)');

        # Cold: the include() itself, which opcache serves from memory in
        # production and recompiles here when it is off. Warm: what a second
        # lookup in the same request costs.
        $t = hrtime(true);
        Config::get('app');
        Config::get('view');
        Config::get('route');
        self::satir('config files, first read (3)', self::ms(hrtime(true) - $t), 'include - opcache territory');

        $t = hrtime(true);
        Config::get('app');
        Config::get('view');
        Config::get('route');
        self::satir('config files, second read (3)', self::ms(hrtime(true) - $t), 'memoised');

        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) Config::get('app.debug');
        self::satir('config lookup x1000', self::ms(hrtime(true) - $t), 'DB::prepare() does one per query');

        $t = hrtime(true);
        glob(BASE_PATH . '/App/Providers/*.php');
        @scandir(BASE_PATH . '/modules');
        self::satir('provider glob + module scan', self::ms(hrtime(true) - $t));

        self::satir('files loaded so far', (string) count(get_included_files()));
        self::satir('peak memory', number_format(memory_get_peak_usage() / 1048576, 1) . ' MB');
    }

    /**
     * Route table size, and what matching one costs - best and worst case.
     */
    private static function routes(): void
    {
        self::baslik('Routes');

        # The terminal does not build the route table, so build it here - the same
        # way `route cache` does, and only if nothing has built it already.
        if (!count(RouteFacade::$routes)) {
            try {
                Run::initProviders()::findModules(base_path('/modules'))::loadModules();
                Run::includer(BASE_PATH . '/route', true, false, '.php', BASE_PATH . '/route/dynamic');
            } catch (\Throwable $e) {
                self::satir('route table', 'could not build', substr($e->getMessage(), 0, 60));
                return;
            }
        }

        $routes = RouteFacade::$routes;
        $total  = count($routes);
        if (!$total) return;

        $dynamic = count(array_filter($routes, fn($r) => $r['dynamic'] ?? false));
        self::satir('routes (static / dynamic)', ($total - $dynamic) . ' / ' . $dynamic);

        $reflection = new \ReflectionClass(RouteFacade::class);
        $index      = $reflection->getProperty('index');
        $index->setAccessible(true);
        $build = $reflection->getMethod('index');
        $build->setAccessible(true);

        $t = hrtime(true);
        for ($i = 0; $i < 20; $i++) { $index->setValue(null, null); $build->invoke(null); }
        self::satir('index build', self::ms((hrtime(true) - $t) / 20), 'once per request');

        $called = $reflection->getProperty('calledRoute');
        $called->setAccessible(true);

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ([['first match', array_values($routes)[0]['url'] ?? '/'], ['no match (404)', '/' . bin2hex(random_bytes(6))]] as [$label, $path]) {
            $_SERVER['REQUEST_URI'] = $path;
            $t = hrtime(true);
            for ($i = 0; $i < 100; $i++) { $called->setValue(null, null); RouteFacade::match(); }
            self::satir($label, self::ms((hrtime(true) - $t) / 100));
        }
        $_SERVER['REQUEST_URI'] = $uri;
        $called->setValue(null, null);
    }
}
