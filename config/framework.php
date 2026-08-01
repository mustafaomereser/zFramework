<?php

/**
 * How the framework itself behaves.
 *
 * These used to be five separate files - view.php, route.php, session.php,
 * cache.php, redis.php - and they are read together, changed together and
 * reasoned about together. One file so the whole picture is on one screen.
 *
 * The old files still work: anything missing here falls back to them, so an
 * application can move across when it suits. Delete them once you have.
 */
return [

    /**
     * Compiled views. Caching off means every request parses the template.
     */
    'view' => [
        'caching' => true,
        'minify'  => true,
    ],

    /**
     * The compiled route table (`php terminal route cache`).
     *
     * auto-check costs a stat per route file per request to notice an edit.
     * Leave it off in production, where the deploy rebuilds the cache anyway.
     */
    'route' => [
        'caching'    => true,
        'auto-check' => false,
    ],

    /**
     * driver: file | redis. Redis needs 'redis.enabled' below and is what lets
     * more than one machine recognise the same visitor.
     */
    'session' => [
        'driver'         => 'file',
        'gc_probability' => 1,
    ],

    /**
     * APCu holds the table scheme in shared memory instead of reading and
     * decoding scheme.json per connection. Turn it off to measure whether it is
     * earning its keep, or when the working set no longer fits in apc.shm_size.
     */
    'cache' => [
        'apcu' => true,
    ],

    /**
     * Shared cache, sessions and queue across servers. Nothing here is touched
     * while 'enabled' is false.
     */
    'redis' => [
        'enabled'  => false,
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => null,
        'timeout'  => 1.5,   # connect timeout in seconds
        'database' => [
            'cache'   => 0,
            'session' => 1,
            'queue'   => 2,
        ],
        'prefix'   => 'zf:',
        'l1_ttl'   => 5,
    ],

    /**
     * Measuring the application: whole requests, and the queries inside them.
     *
     * All of it off by default and meant to stay that way in production. The
     * Profiling module under modules/ reads what lands in analysis/profiling/
     * and compares runs - disable the module and nothing is recorded at all.
     */
    'profiling' => [
        'enabled' => false,

        # Record only this fraction of requests. 1 is all of them, 0.05 is one in
        # twenty - enough to see a pattern on a busy site without filling a disk.
        'rate'    => 1,

        # Stop writing once this many files are in the directory. Old runs are
        # what make a comparison possible, so they are kept rather than rotated.
        'keep'    => 200,

        /**
         * Query analyzer: EXPLAIN on every SELECT, collected per request.
         *
         * Only ever runs while app.debug is true, so production is safe even if
         * this is left on. true analyses every SELECT, false is off, and a
         * fraction samples - 0.01 is one query in a hundred, which is what keeps
         * a busy staging box usable.
         *
         * Bear in mind an analysed query is re-executed through EXPLAIN ANALYZE,
         * so it costs roughly twice what it measures.
         */
        'queryAnalyze' => false,
    ],
];
