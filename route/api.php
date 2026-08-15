<?php

use App\Middlewares\API;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Response;
use zFramework\Core\Route;

# throttle() attaches the middleware and carries the limit with it, so the number
# lives next to the routes it governs rather than in a config table keyed by url.
#
# It runs before API::class: the 429 unwinds out of the middleware loop, so a
# caller over the limit never costs the Auth-Token lookup. The group needs no
# fallback closure either - Throttle answers 429 itself.
Route::pre('/api')->throttle(1, 2)->middleware([API::class])->noCSRF()->group(function () {
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
