<?php

use App\Middlewares\API;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Response;
use zFramework\Core\Route;

Route::pre('/api')->middleware([API::class])->noCSRF()->group(function () {
    Route::pre('/v1')->group(function () {
        Route::get('/', fn() => Response::json([
            'status'    => rand(0, 999),
            'message'   => ["Welcome to API Route👋!", "If you wanna user login, send with 'Auth-Token' header in token."],
            'user'      => Auth::check() ? Auth::user() : 'not logged in.',
            'ip'        => ip(),
            'time'      => time(),
            'timezone'  => date_default_timezone_get()
        ], JSON_PRETTY_PRINT));
    });
});
