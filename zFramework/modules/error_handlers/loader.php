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

    if (!$loaded) {
        $loaded = true;
        require_once FRAMEWORK_PATH . '/modules/error_handlers/handle.php';
    }

    return errorHandlerRender($data);
}

set_exception_handler(function ($exception) {
    # A response signal thrown outside Run::begin() - from a cron script, a
    # terminal command, anything bootstrapping the framework on its own - still
    # has to produce its response rather than an error page.
    if ($exception instanceof \zFramework\Core\ResponseSignal) return $exception->send();

    return errorHandler($exception);
});
