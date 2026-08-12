<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

class Run
{
    /**
     * Run the project.
     * Usage: php kernel run [--host=0.0.0.0] [--port=8080]
     * Usage: php kernel run roadrunner [serve|reset|workers|stop]
     * @param roadrunner (optional) run under RoadRunner instead of the dev server
     * @param --host (optional)
     * @param --port (optional)
     * @param --opcache (optional) run with opcache.
     */
    public static function begin()
    {
        if (strtolower((string) @Terminal::$commands[1]) === 'roadrunner') return self::roadrunner();

        chdir(config('app.public'));

        $server = Terminal::$parameters['--host'] ?? null;
        $port   = Terminal::$parameters['--port'] ?? 80;

        if (!$server) {
            $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (@socket_connect($sock, "8.8.8.8", 53)) {
                socket_getsockname($sock, $name); // $name passed by reference
                socket_close($sock);
                // This is the local machine's external IP address
                $localAddr = $name;
            } else $localAddr = getHostByName(getHostName());

            $server = ($localAddr ?? '127.0.0.1');
        }

        while (true) if (!@fsockopen($server, $port, $errno, $errstr, 2)) break;
        else Terminal::text("[color=red]$port is already using,[/color][color=yellow] new port is " . (++$port) . ".[/color]");

        shell_exec("start http://$server:$port");

        Terminal::clear();

        echo "\e[33mServer running on \e[32m`" . getHostName() . "`\e[33m host: \e[31m\n";

        $opcache = "";

        if (in_array('--opcache', Terminal::$parameters)) $opcache = '-d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0 -d opcache.memory_consumption=128 -d opcache.max_accelerated_files=20000';

        shell_exec("php $opcache -S $server:$port");
    }

    /**
     * RoadRunner: serve, and manage the workers while it serves.
     *
     * Sub-commands (php terminal run roadrunner <sub>):
     *   serve   (default) start the server in the foreground
     *   reset   reload workers gracefully - what a deploy runs
     *   workers show worker state: pid, memory, requests served
     *   stop    stop the server
     *
     * @return mixed
     */
    private static function roadrunner()
    {
        $action = strtolower((string) (@Terminal::$commands[2] ?: 'serve'));
        $binary = self::rrBinary();
        $config = BASE_PATH . '/.rr.yaml';

        if (!$binary) {
            Terminal::text("[color=red]RoadRunner binary not found.[/color]");
            Terminal::text("[color=dark-gray]Download it into the project root:[/color]");
            Terminal::text("[color=yellow]  ./vendor/bin/rr get-binary[/color]   [color=dark-gray]or grab a release from github.com/roadrunner-server/roadrunner[/color]");
            return;
        }

        if (!is_file($config)) return Terminal::text("[color=red].rr.yaml not found in " . BASE_PATH . "[/color]");

        if ($action === 'serve') {
            if (!self::rrDependencies()) return;

            if ($workers = Terminal::$parameters['--workers'] ?? null) Terminal::text("[color=dark-gray]Override: $workers workers (edit .rr.yaml to make it permanent)[/color]");

            Terminal::text("[color=green]Starting RoadRunner[/color] [color=dark-gray](ctrl+c to stop)[/color]");
            Terminal::text("[color=dark-gray]Workers boot the application once and serve requests in a loop.[/color]");
            Terminal::text("[color=yellow]After deploying new code run: php terminal run roadrunner reset[/color]\n");

            passthru(escapeshellarg($binary) . " serve -c " . escapeshellarg($config) . ($workers ? " -o http.pool.num_workers=$workers" : ''));
            return;
        }

        $commands = [
            'reset'   => ['reset', 'Workers reloaded - each finished its current request first.'],
            'workers' => ['workers -i', null],
            'stop'    => ['stop', 'RoadRunner stopped.'],
        ];

        if (!isset($commands[$action])) return Terminal::text("[color=red]Unknown sub-command `$action`. Use: serve, reset, workers, stop.[/color]");

        [$argument, $done] = $commands[$action];
        passthru(escapeshellarg($binary) . " $argument -c " . escapeshellarg($config), $status);

        if ($status !== 0) return Terminal::text("[color=red]Command failed - is RoadRunner running?[/color]");
        if ($done) Terminal::text("[color=green]{$done}[/color]");
    }

    /**
     * Locate the RoadRunner binary.
     * @return string|null
     */
    private static function rrBinary(): ?string
    {
        $windows = str_starts_with(strtolower(PHP_OS_FAMILY), 'win');

        foreach (
            [
                BASE_PATH . '/rr' . ($windows ? '.exe' : ''),
                BASE_PATH . '/zFramework/vendor/bin/rr' . ($windows ? '.exe' : ''),
            ] as $candidate
        ) if (is_file($candidate)) return $candidate;

        # Fall back to whatever is on PATH.
        $which = @shell_exec(($windows ? 'where rr' : 'command -v rr') . ' 2>' . ($windows ? 'nul' : '/dev/null'));
        $which = trim((string) strtok((string) $which, "\n"));

        return strlen($which) ? $which : null;
    }

    /**
     * Are the PHP-side RoadRunner packages installed?
     * @return bool
     */
    private static function rrDependencies(): bool
    {
        if (class_exists('Spiral\RoadRunner\Worker') && class_exists('Nyholm\Psr7\Factory\Psr17Factory')) return true;

        Terminal::text("[color=red]RoadRunner PHP packages are missing.[/color]");
        Terminal::text("[color=yellow]  composer require spiral/roadrunner-http spiral/roadrunner-worker nyholm/psr7[/color]");
        return false;
    }
}
