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
     *          production. Clear with `php terminal cache clear views`.
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
     * A route defined with a closure cannot be written to the cache, so its file
     * stays "live": the table comes from the cache and only that file is included
     * again per request to put the closure back. `route cache` names those files.
     * Move the closures to a controller and nothing is parsed per request.
     */
    'route' => [
        'caching'    => true,
        'auto-check' => false,
    ],

    /**
     * Application log - Log::info() and friends, written to storage/logs as one
     * file per day. Nothing is loaded until the first call, so a request that
     * never logs pays nothing.
     *
     * level  debug | info | warning | error. Anything below it is dropped
     *        before the message is formatted.
     * days   How long a day file is kept. Pruned on the first write of a
     *        process; 0 keeps everything.
     */
    'log' => [
        'enabled' => true,
        'level'   => 'debug',
        'days'    => 14,
    ],

    /**
     * Rate limiting defaults.
     *
     * The limit itself belongs on the route group that needs it:
     *
     *     Route::pre('/api')->throttle(120)->group(...);
     *     Route::throttle(5, 300)->group(fn() => Route::post('/sign-in', ...));
     *
     * These values are only the fallback, for a group that attaches the
     * middleware without naming a number. There is no url-prefix table here on
     * purpose - that would be a second copy of the routing, and it would stop
     * matching the moment a url changed.
     *
     * enabled  false turns every limit off, wherever it was declared.
     * by       ip | token. `token` counts a logged-in caller by identity, so one
     *          account cannot spread its quota across addresses.
     * block    Seconds to refuse a caller outright once the limit is passed, on
     *          a single read - no counter, no route, no session. 0 keeps the
     *          plain window, where the next one lets them straight back in.
     */
    'throttle' => [
        'enabled' => true,
        'limit'   => 60,
        'window'  => 60,
        'by'      => 'ip',
        'block'   => 0,
    ],

    /**
     * Errors.
     *
     * logging    Write each report as a self-contained HTML page under error_logs/.
     * keep_days  How long one is kept. Each is a whole rendered page and nothing
     *            used to remove them, so a site failing quietly for a year kept a
     *            year of them. The sweep runs only on a request that already failed,
     *            at most once an hour. 0 keeps everything.
     * stream     Also send a one-line summary to a stream a log collector can read:
     *            false | 'error_log' | 'stderr' | 'syslog'. Worth turning on as soon
     *            as there is more than one app server - the HTML files only help
     *            when you know which machine to look at.
     * mask       Key names whose values the report shows as ••••••. Empty: nothing is
     *            hidden - a password field or a cookie is as likely as anything to
     *            be where the problem is, and whoever reads error_logs/ can read the
     *            database too. Matched case-insensitively as a substring of the key,
     *            everywhere: request data, session, cookies, headers, $_SERVER,
     *            frame arguments. ['password', 'card'] if a policy asks for it.
     * previous   How many earlier reports the page links to. 0 for none.
     * callback   Runs after a report is written, with its path and the HTML.
     *
     * The page itself is shown only while app.debug is on; a visitor gets a plain
     * 500 otherwise, and the report still goes to disk.
     */
    'error' => [
        'logging'   => true,
        'keep_days' => 14,
        'stream'    => false,
        'mask'      => [],
        'previous'  => 10,

        'callback' => function ($log_path, $log) {
            # ZF_WORKER: a long-running HTTP worker also runs under the CLI SAPI, and
            # die() there would kill the worker rather than end the request.
            if (PHP_SAPI === 'cli' && !defined('ZF_WORKER')) die(zFramework\Kernel\Terminal::text("[color=red]-> report written to[/color][color=green] $log_path [/color]"));
        },
    ],

    /**
     * Addresses allowed to speak for someone else.
     *
     * ip() answers with REMOTE_ADDR - the address the connection actually came
     * from, which cannot be forged. Only when that address is listed here does it
     * go on to read, in order, CF-Connecting-IP, Client-IP, and the first entry of
     * X-Forwarded-For (the chain is `client, proxy, proxy`). Each candidate is
     * validated as an address; anything else falls back to REMOTE_ADDR.
     *
     * The gate is the point. A forwarded header is written by whoever sent the
     * request, so on a directly served site reading it lets any caller pick a fresh
     * rate-limit bucket with one curl flag - or spend somebody else's address until
     * that address is blocked for everyone behind it.
     *
     * Empty is right when the site is reached directly. Behind Cloudflare, nginx or
     * a load balancer, put its address here: leave it empty there and every visitor
     * counts as the proxy, so the whole site shares one bucket.
     *
     *   'trusted-proxies' => ['10.0.0.5'],
     */
    'trusted-proxies' => [],

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

        # How long Response::cache() caches for when called without a number.
        'cache-ttl' => 600,

        # Serve pages that declared themselves cacheable from storage/pages,
        # without running the route. Headers alone only reach the browser and
        # any CDN; this is what stops PHP re-rendering. Never applies to a
        # request carrying an auth cookie, or to anything but GET.
        #
        # A kill switch, not an opt-in: nothing is stored unless a page calls
        # Page::cache(). Turn it off to take the whole mechanism out of the
        # request path.
        'page-cache' => true,
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
