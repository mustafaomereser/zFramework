<?php

namespace App\Middlewares;

use zFramework\Core\Middleware;

$list = [
    AutoMinifyAssets::class,
    Language::class,
    ViewDirectives::class
];

Middleware::middleware($list);
