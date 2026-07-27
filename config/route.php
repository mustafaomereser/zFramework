<?php

return [
    # Use the compiled route table when one exists (php terminal route cache).
    #
    # Turn this OFF in development: with it on, an existing cache keeps being
    # served after you edit a route file, until `php terminal route clear` is run.
    # Turning it off here is enough - the cache file can stay where it is.
    #
    # With no cache file present this setting changes nothing; route files are
    # loaded normally either way.
    #
    # Note that route/dynamic/ is never cached regardless of this setting.
    'caching' => true,

    # OFF by default, and deliberately so: it costs work on every request, while
    # the cache it maintains only helps applications that can be cached at all.
    # A single closure route makes the whole table uncacheable, so on many
    # projects this would rebuild the table, discover the closure and throw the
    # result away - forever, on every request.
    #
    # Turn it on once `php terminal route cache` succeeds, i.e. once every route
    # is [Controller::class, 'method'].
    #
    # Verify the cache against the route files it was built from and rebuild it
    # when any of them changed. Files and their directories are both watched, so
    # adding or deleting a route file is noticed too.
    #
    # Edit a route and the next request rebuilds the table by itself - no
    # `route clear`, no stale routes. The write is atomic (temp file + rename),
    # so concurrent requests cannot read a half-written table.
    #
    # ONE ASSUMPTION: a route must be declared unconditionally, or under a
    # condition that is the same for every request. A route wrapped in something
    # request-dependent - if (Auth::check()), a tenant flag - would be captured
    # as it was for whichever request happened to trigger the rebuild, and then
    # served to everyone. Put those in route/dynamic/ (never cached) or, better,
    # express them as middleware so the route always exists and access is decided
    # per request.
    #
    # Costs a stat per route file per request when a cache exists, and a full
    # table rebuild per request when one cannot be written.
    'auto-check' => false,
];
