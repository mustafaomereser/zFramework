<?php

use modules\Profiling\Recorder;
use zFramework\Core\Facades\Config;

/**
 * Module informations.
 */
return [
    'status'            => true,
    'name'              => 'Profiling',
    'description'       => 'Records what each request costs and compares runs. Needs framework.profiling.enabled.',
    'author'            => 'Mustafa',
    'created_at'        => '2026-08-01 00:00:00',
    'framework_version' => '3.0.0',
    'module_version'    => '0.1.0',
    'sort'              => 1,

    /**
     * Run::handle() reaches this after the global middlewares and before the
     * route is matched, which is exactly the boundary worth measuring: what came
     * before is the framework booting, what comes after is the application
     * answering.
     *
     * Recording still has to be switched on in config/framework.php - turning
     * this module off stops it entirely, turning it on does not start it.
     */
    'callback' => function () {
        if (!Config::framework('profiling.enabled')) return;
        Recorder::begin();
        $GLOBALS['menu']['profiling'] = [
            'icon'  => 'fad fa-flask',
            'title' => 'Profiler',
            'route' => route('profiling.index')
        ];
    },
];
