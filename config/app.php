<?php

return [
    'debug'       => true, # turn false on production.

    # Query analyzer. Only ever runs while 'debug' is true, so production is safe
    # even if this stays on. true = every SELECT, false = off, or a sampling rate
    # (0.01 = 1% of SELECTs) to keep the overhead down on a busy dev/staging box.
    # Note it re-executes each analysed query via EXPLAIN ANALYZE.
    'analyze'     => true,
    'error'       => [
        'logging'  => true,
        'callback' => function ($log_path, $log) {
            if (PHP_SAPI === 'cli') die(zFramework\Kernel\Terminal::text("[color=red]-> unexcepted terminal error[/color][color=green] $log_path [/color]"));
        }
    ],

    'force-https'      => false, # force redirect https.
    'x-powered-by'     => true,  # set false to hide X-Powered-By response header.

    'lang'        => 'tr', # if browser haven't language in Languages list auto choose that default lang.
    'title'       => 'zFramework',
    'public'      => 'public_html',
    'version'     => '1.0.0',

    'pagination' => [
        'default-view' => 'layouts.pagination.default'
    ]
];
