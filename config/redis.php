<?php

return [
    # Master switch. Everything below is ignored while this is false, and the
    # framework keeps using its single-server defaults: APCu for GlobalCache,
    # files for sessions, synchronous execution for queued jobs.
    'enabled'  => false,

    'host'     => '127.0.0.1',
    'port'     => 6379,
    'password' => null,
    'timeout'  => 1.5,   # connect timeout in seconds

    # Keep sessions and cache on SEPARATE instances (or at least separate
    # databases). A cache instance evicts keys when it fills up; if sessions live
    # there too, a full cache logs everyone out.
    #
    # Recommended server config:
    #   session instance : maxmemory-policy noeviction   + appendonly yes
    #   cache instance   : maxmemory-policy allkeys-lru  + appendonly no
    'database' => [
        'cache'   => 0,
        'session' => 1,
        'queue'   => 2,
    ],

    # Namespaces every key. Change it when several applications share one server.
    'prefix'   => 'zf:',

    # How long GlobalCache may serve a value from local APCu (L1) before checking
    # Redis (L2) again. Short on purpose: L1 cannot be invalidated across servers,
    # so this is the window in which they may disagree.
    'l1_ttl'   => 5,
];
