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
    # Costs one stat() per route file per request. In production the deploy
    # script rebuilds the cache, so this can be false there.
    'auto-check' => true,
];
