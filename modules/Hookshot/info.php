<?php

/**
 * Module informations.
 */
return [
    'status'            => true,
    'name'              => 'Hookshot',
    'description'       => '',
    'author'            => 'Mustafa-MacBook-Pro.local',
    'created_at'        => '2026-02-18 05:38:45',
    'framework_version' => '2.8.0',
    'sort'              => 2,
    'callback'          => function () {
        $GLOBALS['menu']['hookshot'] = [
            'icon'  => 'fad fa-campfire',
            'title' => 'Hookshot',
            'route' => route('hookshot.index')
        ];
    }
];
