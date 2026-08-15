<?php

/**
 * route/dynamic - executed on every request, never written into the route cache.
 *
 * The cache is a snapshot of the moment `route cache` ran, from the CLI, where
 * nobody is logged in. A route wrapped in `if (Auth::check())` would be frozen
 * as "not registered" and 404 for everyone; put that kind of definition here
 * and the condition is evaluated per request instead.
 *
 * Most conditions around a route are access control, and that belongs in
 * middleware - the route exists, permission is decided per request, and it
 * still works with the cache. Use this directory only when a route genuinely
 * must not exist for some requests: per-tenant flags, licence-gated modules.
 *
 * The other case is a url that is not a constant. A group like
 *
 *     Route::pre('/' . _l('routes.admin.route'), '/admin')
 *
 * translates the url per locale while the route name stays admin.*, so no
 * route() call site changes. The cache stores urls as literal strings, so
 * built from the CLI it would freeze one language for everyone - a group like
 * that belongs here.
 *
 * These files run on every request, so keep the conditions cheap. A query here
 * is paid by requests that never touch these routes.
 */

use zFramework\Core\Route;

# Disappears when debug goes off, without rebuilding the cache. Delete it once
# there is something real here.
if (config('app.debug')) {
    Route::get('/_dynamic-check', fn() => 'route/dynamic is live: this route is not in the route cache.')->name('dynamic-check');
}
