<?php

/**
 * Framework behaviour: caching, sessions, redis, profiling.
 *
 * Application settings live in app.php. This file is about how the framework
 * itself works, not about what your application is.
 */
return [

    /**
     * Views.
     *
     * caching  Compile templates once and reuse them. Turn off while writing
     *          templates so edits show up without clearing anything; on in
     *          production. Clear with `php terminal view clear`.
     * minify   Strip whitespace from the compiled output.
     */
    'view' => [
        'caching' => true,
        'minify'  => true,
    ],

    /**
     * Routes.
     *
     * caching     Serve the route table from the compiled cache built by
     *             `php terminal route cache`, instead of running every route
     *             file on every request.
     * auto-check  Notice an edited route file and fall back to the source. Costs
     *             one stat per route file per request, so leave it off in
     *             production where the deploy rebuilds the cache.
     *
     * A route defined with a closure cannot be cached, and one of them keeps the
     * whole table out. `route cache` names them.
     */
    'route' => [
        'caching'    => true,
        'auto-check' => false,
    ],

    /**
     * Sessions.
     *
     * driver          file | redis. Use redis when more than one machine serves
     *                 the site, so a visitor is recognised whichever one answers.
     *                 Needs redis.enabled below.
     * gc_probability  Chance of PHP sweeping expired session files, per request.
     *                 Ignored by the redis driver, which expires its own keys.
     */
    'session' => [
        'driver'         => 'file',
        'gc_probability' => 1,
    ],

    /**
     * Responses.
     *
     * ajax.include-alerts  Attach pending alerts to every JSON response, so the
     *                      caller can display them without a second request.
     *                      Turn off if your front end fetches alerts itself, or
     *                      if JSON responses should contain only what the
     *                      controller returned.
     */
    'response' => [
        'ajax' => [
            'include-alerts' => true,
        ],
    ],

    /**
     * Local cache.
     *
     * apcu  Keep the table scheme in shared memory instead of reading and
     *       decoding storage/db/<db>/scheme.json on every connection. Needs
     *       ext-apcu; without it everything still works from disk.
     *
     *       Turn off if the working set outgrows apc.shm_size - constant
     *       eviction costs more than the cache saves.
     */
    'cache' => [
        'apcu' => true,
    ],

    /**
     * Redis: shared cache, sessions and queue across servers.
     *
     * Nothing here is read while enabled is false.
     *
     * database  Which redis database each store uses. Separate numbers keep a
     *           cache flush from taking sessions with it.
     * prefix    Prepended to every key, so several applications can share one
     *           redis instance.
     * l1_ttl    Seconds a cached value may be served from this process's own
     *           memory before redis is asked again. Higher is faster and means
     *           two servers can briefly disagree.
     */
    'redis' => [
        'enabled'  => false,
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => null,
        'timeout'  => 1.5,   # connect timeout, seconds
        'database' => [
            'cache'   => 0,
            'session' => 1,
            'queue'   => 2,
        ],
        'prefix'   => 'zf:',
        'l1_ttl'   => 5,
    ],

    /**
     * Profiling: what requests cost, and what the queries inside them do.
     *
     * Off by default. Records go to analysis/profiling/ and the Profiling module
     * under modules/ reads them back at /profiling, grouped by url. Disabling
     * that module stops recording whatever this says.
     *
     * enabled       Record requests.
     * rate          Fraction of requests to record. 1 is every one; 0.05 is one
     *               in twenty, enough to see a pattern without filling a disk.
     * keep          Stop recording once this many records exist. Old records are
     *               what you compare against, so nothing is deleted to make room.
     *               Clear them at /profiling after a deploy.
     *
     * queryAnalyze  Run EXPLAIN on each SELECT and collect what it says: tables
     *               scanned, indexes used, missing-index suggestions. true is
     *               every query, false is off, or a fraction to sample.
     *
     *               Needs app.debug, so production is unaffected either way. An
     *               analysed query is executed a second time to measure it, so
     *               it costs about twice what it reports.
     *
     * queryStore    file  analysis/queries/<id>.jsonl, one line per query.
     *                     Needs nothing to exist and opens in any editor.
     *               table rows in system_db_collector, so findings can be
     *                     queried and joined. Run `php terminal db migrate`
     *                     first, and note it writes to the database it measures.
     */
    'profiling' => [
        'enabled'      => false,
        'rate'         => 1,
        'keep'         => 200,
        'queryAnalyze' => false,
        'queryStore'   => 'file',
    ],
];
