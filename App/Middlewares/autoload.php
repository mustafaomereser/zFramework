<?php

namespace App\Middlewares;

use zFramework\Core\Middleware;

$list = [
    Language::class,
    ViewDirectives::class
];

Middleware::middleware($list);
