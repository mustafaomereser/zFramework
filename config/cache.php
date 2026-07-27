<?php

return [
    # Use APCu when the extension is available.
    #
    # What it caches: the table scheme (one entry per database) and anything put
    # through GlobalCache. Turning it off makes both fall back to disk - correct,
    # just not cached between requests.
    #
    # Worth turning off when: APCu is shared with other applications and running
    # out of room, the scheme set no longer fits (multi-tenant with more active
    # tenants than apc.shm_size can hold, where constant eviction costs more than
    # it saves), or simply to measure whether it is helping at all.
    'apcu' => true,
];
