<?php

return [
    'debug'       => true, # turn false on production.
    'analyze'     => false,
    'error'       => [
        'logging'  => true,
        'callback' => function ($log_path, $log) {
            if (PHP_SAPI === 'cli') die(zFramework\Kernel\Terminal::text("[color=red]-> unexcepted terminal error[/color][color=green] $log_path [/color]"));
        }
    ],

    'force-https' => false, # force redirect https.

    'lang'        => 'tr', # if browser haven't language in Languages list auto choose that default lang.
    'title'       => 'zFramework',
    'public'      => 'public_html',
    'version'     => '1.0.0',

    'pagination' => [
        'default-view' => 'layouts.pagination.default'
    ]
];
