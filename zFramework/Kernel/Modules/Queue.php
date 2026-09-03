<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use zFramework\Core\Facades\Queue as QueueFacade;
use zFramework\Core\Facades\Redis;

class Queue
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Process queued jobs until stopped
     * Usage: php terminal queue work {queue-name}
     * @param {queue-name} (second argument, optional)
     * @param --once     (optional) exit after one job
     * @param --tries=N  (optional) attempts before a job is dropped, default 3
     * @param --sleep=N  (optional) seconds to block waiting for a job, default 5
     */
    public static function work()
    {
        # The callback config/app.php ships dies on CLI unless this is defined, and this
        # loop is the case it means: a process that outlives one unit of work. Without
        # it the first job to exhaust its tries takes the worker down with it.
        if (!defined('ZF_WORKER')) define('ZF_WORKER', true);

        $queue = @Terminal::$commands[2] ?: QueueFacade::DEFAULT_QUEUE;
        $once  = in_array('--once', Terminal::$parameters);
        $tries = (int) (Terminal::$parameters['--tries'] ?? 3);
        $sleep = (int) (Terminal::$parameters['--sleep'] ?? 5);

        if (!Redis::available('queue')) return Terminal::text("[color=red]Redis is not available - jobs run inline on push, there is nothing to process.\nEnable it in config/framework.php (redis.enabled).[/color]");

        Terminal::text("[color=green]Working queue `$queue`[/color] [color=dark-gray](ctrl+c to stop)[/color]");

        while (true) {
            $entry = QueueFacade::pop($queue, $sleep);

            if (!$entry) {
                if ($once) return Terminal::text("[color=dark-gray]No job within {$sleep}s, exiting (--once).[/color]");

                # An idle turn is where a lost server is noticed and retried. Nothing
                # in this loop ends a request, so $failed - set by one refused
                # connection - stayed set for the life of the worker, and it sat
                # silent with a queue it could no longer see.
                if (!Redis::available('queue')) {
                    Terminal::text("[color=red]Redis unreachable, retrying in {$sleep}s[/color]", true);
                    sleep($sleep);
                }
                Redis::flushRequestState();
                continue;
            }

            $label   = is_array($entry['job'] ?? null) ? implode('@', $entry['job']) : (string) ($entry['job'] ?? '?');
            $started = microtime(true);

            Terminal::text("[color=yellow]-> {$label}[/color]", true);

            try {
                QueueFacade::run($entry);
                Terminal::text("[color=green]-> $label done[/color] [color=dark-gray](" . round((microtime(true) - $started) * 1000) . "ms)[/color]", true);
            } catch (\Throwable $e) {
                $requeued = QueueFacade::retry($entry, $tries);
                Terminal::text("[color=red]-> $label failed: " . $e->getMessage() . "[/color]" . ($requeued ? " [color=yellow](requeued)[/color]" : " [color=red](dropped after $tries attempts)[/color]"), true);
                # errorHandler() logs and then aborts, and the abort throws. There is no
                # request to end here, so the signal would only unwind out of the loop and
                # leave the queue unattended. The log is written before it arrives.
                if (function_exists('errorHandler') && !$requeued) {
                    try {
                        errorHandler($e);
                    } catch (\zFramework\Core\ResponseSignal) {
                    }
                }
            }

            if ($once) return;
        }
    }

    /**
     * Description: Show how many jobs are waiting
     * Usage: php terminal queue size {queue-name}
     * @param {queue-name} (second argument, optional)
     */
    public static function size()
    {
        $queue = @Terminal::$commands[2] ?: QueueFacade::DEFAULT_QUEUE;

        if (!Redis::available('queue')) return Terminal::text("[color=red]Redis is not available.[/color]");

        Terminal::text("[color=green]`$queue`:[/color] " . QueueFacade::size($queue) . " job(s) waiting");
    }
}
