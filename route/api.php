<?php

use App\Middlewares\API;
use App\Middlewares\Throttle;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Response;
use zFramework\Core\Route;

# API::class authenticates from the Auth-Token header; Throttle::class limits the
# caller. Limits come from config/framework.php throttle - `/api` has its own rule
# there. Throttle aborts 429 itself, so the group needs no fallback closure.
Route::pre('/api')->middleware([API::class, Throttle::class])->noCSRF()->group(function () {
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
