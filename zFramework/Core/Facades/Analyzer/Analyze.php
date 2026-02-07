<?php

namespace zFramework\Core\Facades\Analyzer;

class Analyze
{
    static $process_id;

    public static function init()
    {
        self::$process_id = uniqid('analyze-');
    }
}
