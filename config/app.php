<?php

return [
    'debug'       => true, # turn false on production.

    # 'analyze' moved to config/framework.php - it belongs with the other
    # framework behaviour, and is still read from here if you have not moved it.

    'error'       => [
        'logging'  => true,

        # Also send a one-line summary to a stream a log collector can read.
        # false | 'error_log' | 'stderr' | 'syslog'. Worth turning on as soon as
        # there is more than one app server - the HTML files under error_logs/
        # only help when you know which machine to look at.
        'stream'   => false,

        'callback' => function ($log_path, $log) {
            # ZF_WORKER: a long-running HTTP worker also runs under the CLI SAPI, and
            # die() there would kill the worker rather than end the request.
            if (PHP_SAPI === 'cli' && !defined('ZF_WORKER')) die(zFramework\Kernel\Terminal::text("[color=red]-> unexcepted terminal error[/color][color=green] $log_path [/color]"));
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
