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
];
