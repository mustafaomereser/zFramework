<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

class Run
{
    /**
     * Run the project.
     * @param --host (optional)
     * @param --port (optional)
     */
    public static function begin()
    {
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

        shell_exec("php -S $server:$port");
    }
}
