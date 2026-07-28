<?php

namespace zFramework\Core\Facades\Analyzer;

use zFramework\Core\Facades\Session;
use zFramework\Core\Helpers\Http;

class Analyze
{
    static $process_id;

    /**
     * Only ever holds the request currently being analysed.
     *
     * Run::resetState() used to null this itself, as a line of its own. Doing it
     * here instead is what lets `php terminal state check` see that it happens -
     * a static cleared from somewhere else looks exactly like one nobody cleared.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$process_id = null;
    }

    public static function init()
    {
        $id = (PHP_SAPI === 'cli' || !Http::isAjax()) ? uniqid('analyze-') : (Session::get('analyze-id') ?? uniqid('analyze-'));
        if (PHP_SAPI != 'cli') Session::set('analyze-id', $id);
        self::$process_id = $id;
    }
}
