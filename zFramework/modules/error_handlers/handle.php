<?php

/**
 * What happens once something has gone wrong.
 *
 * Reached through errorHandler() in loader.php and loaded only then. Gathers a
 * report (Report.php), writes it under error_logs/, and answers the caller in
 * the shape the caller can use: a page for a browser, JSON for a client that
 * asked for it, text for a terminal - and in production, nothing but a 500.
 */

use zFramework\Core\Facades\Config;
use zFramework\Core\Helpers\Http;
use zFramework\Core\ResponseSignal;
use zFramework\modules\error_handlers\Report;

require_once __DIR__ . '/Highlighter.php';
require_once __DIR__ . '/Report.php';

/**
 * @param \Throwable|array $data
 * @return string The HTML report when one was rendered, else ''. Never echo it -
 *                it has been printed already where printing was right.
 */
function errorHandlerRender($data): string
{
    # Every level down to the request's own, not one: a view rendering inside a
    # view is two buffers deep, and one ob_end_clean() left half a page in front
    # of the report. A worker holds the outermost buffer and reads the response
    # out of it - that one stays, or the report went to the worker's stdout and
    # the visitor got an empty 200.
    $floor = defined('ZF_WORKER') ? 1 : 0;
    while (ob_get_level() > $floor) @ob_end_clean();

    # The cli that is a terminal, not the cli that is a worker.
    $terminal = PHP_SAPI === 'cli' && !defined('ZF_WORKER');

    $report = Report::build($data);
    $debug  = Config::debug();
    $render = fn(string $format) => include __DIR__ . "/render/$format.php";

    # A failed request is a 500 whatever it was going to be - a browser, a
    # monitor and a cache all read a 200 as success. Something may have chosen
    # already (DB::connection() answers 503 with Retry-After) and that stands.
    # http_response_code() reads false under the cli until something set it, and
    # the worker reads the status back from the same place, so setting it works
    # there. headers_sent() is not consulted for a worker: under the cli it turns
    # true on the first byte the process ever wrote, and stays so.
    if (!$terminal && (defined('ZF_WORKER') || !headers_sent()) && in_array((int) http_response_code(), [0, 200], true)) http_response_code(500);

    $html = null;

    # Answered first, logged second: the shipped callback ends in die() on the
    # cli, and the text report has to be on the screen before it does.
    if ($debug) {
        if ($terminal) {
            echo $render('text')($report, true);
        } elseif (Http::wantsJson()) {
            \zFramework\Core\Facades\Response::header('Content-Type', 'application/json; charset=utf-8');
            echo $render('json')($report, true);
        } else {
            \zFramework\Core\Facades\Response::header('Content-Type', 'text/html; charset=utf-8');
            echo $html = $render('html')($report);
        }
    }

    if (Report::setting('logging') ?? true) {
        $html ??= $render('html')($report);

        # Second, random suffix, exception class: sortable, collision-free, and
        # readable in a directory listing without opening anything.
        $path = ERROR_LOG_DIR . '/' . date('Y-m-d-H-i-s') . '-' . bin2hex(random_bytes(3)) . '-' . str_replace('\\', '.', $report['class']) . '.html';
        file_put_contents2($path, $html);

        # Before the callback: the shipped one dies under the cli.
        pruneErrorLogs();

        if (is_callable($callback = Report::setting('callback'))) $callback($path, $html);
    }

    # One line to a stream a log collector can read. The HTML files above are
    # unbeatable while debugging one machine and useless across several.
    if ($stream = Report::setting('stream')) {
        $summary = sprintf('[zFramework] %s: %s in %s:%s', $report['class'], $report['message'], $report['file'], $report['line']);
        match ($stream) {
            'syslog' => syslog(LOG_ERR, $summary),
            'stderr' => @file_put_contents('php://stderr', $summary . PHP_EOL),
            default  => error_log($summary),
        };
    }

    # Production: the visitor learns that something failed and nothing else.
    if (!$debug) {
        if (Http::wantsJson()) throw new ResponseSignal(500, ['Content-Type' => 'application/json; charset=utf-8'], $render('json')($report, false));
        abort(500, 'An unexpected error occurred. It has been reported; if it persists, contact the administrator.');
    }

    return $html ?? '';
}

/**
 * Drop error reports older than error.keep_days.
 *
 * Each report is a rendered page, tens to hundreds of KB, and nothing ever removed
 * one - a site that has been failing quietly for a year keeps a year of them. Set
 * the config key to 0 or false to keep everything.
 *
 * Only ever runs on a request that already failed, and at most once an hour: the
 * marker's own mtime is the clock, so there is no state to keep anywhere else.
 *
 * @return void
 */
function pruneErrorLogs(): void
{
    $days = Report::setting('keep_days');
    $days = $days === null ? 14 : (int) $days;
    if ($days < 1) return;

    $marker = ERROR_LOG_DIR . '/.pruned';
    if (is_file($marker) && (time() - (int) @filemtime($marker)) < 3600) return;
    @touch($marker);

    $cutoff = time() - ($days * 86400);

    foreach (glob(ERROR_LOG_DIR . '/*.{html,fatal.txt}', GLOB_BRACE) ?: [] as $old)
        if ((int) @filemtime($old) < $cutoff) @unlink($old);
}
