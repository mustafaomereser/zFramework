<?php

/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  route/dynamic  —  NEVER CACHED
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Everything in this directory is executed on EVERY request, even when a route
 *  cache exists (`php terminal route cache`). Files under route/ are compiled
 *  into that cache; files here are deliberately left out of it and re-evaluated
 *  each time.
 *
 *  WHY THIS DIRECTORY EXISTS
 *
 *  A cached route table is a snapshot of the moment `route cache` ran - from the
 *  CLI, where nobody is logged in and no tenant is selected. So a definition like
 *
 *      if (Auth::check()) Route::get('/panel', [PanelController::class, 'index']);
 *
 *  gets frozen as "not registered at all" and returns 404 for everyone, forever.
 *  Put that kind of definition here and the condition is evaluated per request,
 *  the way it was written to be.
 *
 *  BEFORE YOU PUT SOMETHING HERE
 *
 *  Most conditions around a route are really access control, and access control
 *  belongs in middleware - the route always exists, permission is decided per
 *  request:
 *
 *      Route::middleware([Auth::class])->group(function () {
 *          Route::get('/panel', [PanelController::class, 'index']);
 *      });
 *
 *  That works with the route cache, and with any long-running server (RoadRunner
 *  and friends) where route files are executed once at boot and this directory
 *  would be frozen too.
 *
 *  So reach for this directory only when a route genuinely must not exist for
 *  some requests: per-tenant feature flags, licence-dependent modules, routes
 *  that only exist while debugging.
 *
 *  COST
 *
 *  These files run on every request, so keep them small and keep the conditions
 *  cheap. A database query here is paid by every single request, including the
 *  ones that never touch these routes.
 * ─────────────────────────────────────────────────────────────────────────────
 */

use zFramework\Core\Route;

/**
 * Example: a route that only exists while debug is on.
 *
 * Turn 'debug' off in config/app.php and this route disappears without
 * rebuilding the route cache - which is the whole point of this directory.
 * Delete it once you have something real here.
 */
if (config('app.debug')) {
    Route::get('/_dynamic-check', fn() => 'route/dynamic is live: this route is not in the route cache.')->name('dynamic-check');
}
