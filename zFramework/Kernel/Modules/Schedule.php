<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use zFramework\Run;
use zFramework\Core\Facades\Schedule as ScheduleFacade;

class Schedule
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Run every task due this minute
     * Usage: php terminal schedule run
     *
     * Driven by one crontab line:
     *   * * * * * cd /path/to/app && php terminal schedule run >> /dev/null 2>&1
     */
    public static function run()
    {
        if (!self::load()) return;

        $reported = false;

        ScheduleFacade::run(function ($name, $status, $message) use (&$reported) {
            $reported = true;
            $colour   = ['ok' => 'green', 'failed' => 'red'][$status] ?? 'dark-gray';

            Terminal::text("[color={$colour}]{$status}[/color] [color=yellow]{$name}[/color] [color=dark-gray]{$message}[/color]");
        });

        # Skipped tasks were due, they just did not run - saying nothing was due
        # would send you looking for a scheduling bug that is not there.
        if (!$reported) Terminal::text('[color=dark-gray]Nothing due this minute.[/color]');
    }

    /**
     * Description: Show registered tasks and when each one next runs
     * Usage: php terminal schedule list
     */
    public static function list()
    {
        if (!self::load()) return;

        $tasks = ScheduleFacade::tasks();

        if (!count($tasks)) return Terminal::text('[color=dark-gray]No tasks registered under schedule/.[/color]');

        foreach ($tasks as [$expression, , $name]) {
            $next = self::next($expression);

            Terminal::text(
                '[color=yellow]' . str_pad($name, 24) . '[/color]'
                    . '[color=dark-gray]' . str_pad($expression, 16) . '[/color]'
                    . '[color=green]' . ($next ? 'next ' . date('Y-m-d H:i', $next) : 'never') . '[/color]'
            );
        }
    }

    /**
     * Include everything under schedule/, which is where tasks are registered.
     *
     * A directory rather than one file, for the same reason route/ is one: a
     * task list grows, and splitting it per subject or per module beats one file
     * everyone edits.
     *
     * Deliberately not loaded anywhere else - a served request must not pay for
     * files only the scheduler reads.
     *
     * @return bool
     */
    private static function load(): bool
    {
        $dir = BASE_PATH . '/schedule';

        if (!is_dir($dir)) {
            Terminal::text("[color=red]No schedule/ directory in the project root.[/color]");
            Terminal::text("[color=dark-gray]Create one and register tasks with Schedule::daily(), ::everyMinutes(), ::cron().[/color]");
            return false;
        }

        Run::includer($dir);

        return true;
    }

    /**
     * Walk forward a minute at a time to find the next match. A year of minutes
     * is the cutoff - an expression that matches nothing within that (February
     * 30th, say) is reported as never rather than searched for forever.
     *
     * @param string $expression
     * @return int|null
     */
    private static function next(string $expression): ?int
    {
        $at = (int) (floor(time() / 60) * 60);

        for ($i = 1; $i <= 527040; $i++)
            if (ScheduleFacade::due($expression, $at + $i * 60)) return $at + $i * 60;

        return null;
    }
}
