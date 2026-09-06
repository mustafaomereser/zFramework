<?php

namespace App\Services;

use zFramework\Kernel\Terminal;

/**
 * What the application does once an error report has been written.
 *
 * Named in config/framework.php as `error.callback`; the framework calls
 * handle() with the report's path and its HTML after the file is on disk and
 * the visitor has been answered. Loaded only then - nothing here costs a
 * request that does not fail.
 *
 * This is the place for "tell someone": a Slack webhook, Sentry, a mail to
 * the on-call address. Keep it short and never let it throw - an exception
 * from here would be reported as a second error on top of the first.
 */
class ErrorLog
{
    /**
     * @param string $path Absolute path of the HTML report under error_logs/.
     * @param string $html The report itself, for a channel that wants the body.
     * @return void
     */
    public static function handle(string $path, string $html): void
    {
        # A terminal command or a cron script has nothing to answer; the path
        # is the useful part, and the process is done. ZF_WORKER runs under the
        # CLI SAPI too, and die() there would kill the worker, not the request.
        if (PHP_SAPI === 'cli' && !defined('ZF_WORKER')) die(Terminal::text("[color=red]-> report written to[/color][color=green] $path [/color]"));

        # e.g. Mail::send([...]) / a webhook: Http helpers are loaded, the DB may
        # be the thing that failed - do not rely on it.
    }
}
