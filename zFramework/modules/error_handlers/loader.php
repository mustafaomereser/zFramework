<?php

/**
 * errorHandler(), and nothing else, until something goes wrong.
 *
 * handle.php is 68 KB - the largest file the framework loads, larger than Route
 * and View together - and on a request that does not fail its whole
 * contribution is one function definition. The name is defined here; the
 * renderer arrives only when it is called.
 */
function errorHandler($data)
{
    static $loaded = false;

    errorHandlerReported(true);

    if (!$loaded) {
        $loaded = true;
        require_once FRAMEWORK_PATH . '/modules/error_handlers/handle.php';
    }

    return errorHandlerRender($data);
}

/**
 * Whether this request has already reported an error.
 *
 * The shutdown handler below asks, because the two overlap: in production
 * errorHandler() ends with abort(), abort() throws, and a throw from inside an
 * exception handler is itself a fatal error. That fatal is an artefact of the
 * reporting, not a second failure, and logging it would bury the real one.
 *
 * Request state, so Run::resetState() clears it: in a worker the flag would
 * otherwise stand for the life of the process, and one handled exception would be
 * enough to silence the fatal reporter for every request after it.
 *
 * @param bool|null $set true to mark, false to clear, null to only ask.
 * @return bool
 */
function errorHandlerReported(?bool $set = null): bool
{
    static $reported = false;

    if ($set !== null) $reported = $set;
    return $reported;
}

# Memory to give back when there is none left. An OOM fatal leaves the process at
# its ceiling, and the shutdown handler below cannot allocate so much as the string
# it wants to write - which is exactly why the one error worth reporting reported
# nothing. Dropping this makes room for it.
$GLOBALS['error_handler_reserve'] = str_repeat(' ', 65536);

/**
 * What is left when nothing else runs.
 *
 * set_exception_handler covers a throw. It does not cover running out of memory,
 * exceeding max_execution_time, or a parse error in a file included at runtime -
 * and there is no set_error_handler in this framework either. So the heaviest
 * failures a production site has were the ones nobody heard about: no file under
 * error_logs, nothing through app.error.stream, nothing in the log, and half a
 * page delivered to whoever was reading.
 *
 * Deliberately plain text and no renderer: handle.php is 68 KB of markup building,
 * which is the one thing that cannot be asked of a process that just died for want
 * of memory.
 */
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) return;

    # Warnings and notices already printed themselves; only what ended the request.
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;

    if (errorHandlerReported()) return;
    errorHandlerReported(true);

    unset($GLOBALS['error_handler_reserve']);

    $summary = sprintf(
        '[zFramework] FATAL %s in %s:%s (%s)',
        $error['message'],
        $error['file'],
        $error['line'],
        $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '?')
    );

    # Each channel on its own, because any of them can be the broken one - a full
    # disk, a config that never loaded because the fatal was in boot.
    try {
        if (defined('ERROR_LOG_DIR')) {
            @mkdir(ERROR_LOG_DIR, 0755, true);
            @file_put_contents(ERROR_LOG_DIR . '/' . date('Y-m-d-H-i-s') . '-' . substr(md5($summary), 0, 6) . '.fatal.txt', $summary . PHP_EOL, FILE_APPEND);
        }
    } catch (\Throwable) {
    }

    try {
        # framework.error first, app.error for an application that has not moved it.
        $stream = 'error_log';
        if (class_exists(\zFramework\Core\Facades\Config::class, false)) {
            $stream = \zFramework\Core\Facades\Config::framework('error.stream');
            if ($stream === null) $stream = \zFramework\Core\Facades\Config::get('app.error.stream');
            if (is_array($stream)) $stream = 'error_log';
        }

        match ($stream ?: 'error_log') {
            'syslog' => syslog(LOG_ERR, $summary),
            'stderr' => @file_put_contents('php://stderr', $summary . PHP_EOL),
            default  => error_log($summary),
        };
    } catch (\Throwable) {
        error_log($summary);
    }

    # A truncated 200 reads as a successful empty page to a browser, a monitor and a
    # cache alike. Only possible when nothing has been sent yet.
    if (PHP_SAPI !== 'cli' && !headers_sent()) http_response_code(500);
});

set_exception_handler(function ($exception) {
    # A response signal thrown outside Run::begin() - from a cron script, a
    # terminal command, anything bootstrapping the framework on its own - still
    # has to produce its response rather than an error page.
    if ($exception instanceof \zFramework\Core\ResponseSignal) return $exception->send();

    return errorHandler($exception);
});
