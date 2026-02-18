<?php

/**
 * Module informations.
 */
return [
    'status'            => true,
    'name'              => 'blog',
    'description'       => '',
    'author'            => 'Mustafa',
    'created_at'        => '2024-03-05 14:20:15',
    'framework_version' => '2.6.0',
    'sort'              => 1,
    'callback'          => function () {
        $GLOBALS['menu']['blog'] = [
            'icon'  => 'fad fa-newspaper',
            'title' => 'Blog',
            'route' => route('blog.index')
        ];
    }
];
