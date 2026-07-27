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

    # Verify the cache against the route files it was built from, and ignore it
    # when any of them changed. Files and their directories are both watched, so
    # adding or deleting a route file is noticed too.
    #
    # This makes a stale cache harmless: edit a route, the change takes effect
    # immediately, no `route clear` needed. The cache stays unused until
    # `php terminal route cache` is run again - it is NOT rebuilt automatically,
    # and that is deliberate: building it from a web request would freeze
    # whatever was true for that one request (its user, its tenant) into a table
    # served to everybody.
    #
    # Costs one stat() per route file per request. Leave it on in development.
    # In production the deploy script rebuilds the cache, so it can be false.
    'auto-check' => true,
];
