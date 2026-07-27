<?php

namespace zFramework\Core\Facades;

/**
 * Run work after the response has been sent.
 *
 * Anything the user does not need to wait for - sending mail, writing stats,
 * warming a cache, calling a third party - can be handed to Defer::after().
 * Once the route is done, the response is flushed to the browser and the
 * queued jobs run with the connection already closed.
 *
 *   Defer::after(fn() => Mail::send($user), 'welcome-mail');
 *
 * What this is NOT: a queue. The worker stays busy until the jobs finish, so
 * the server's capacity is unchanged - the wait is hidden from the user, not
 * removed. And nothing is persisted: if the process dies after responding
 * (deploy, pm.max_requests recycle, fatal, request_terminate_timeout - which
 * counts this time too) the job is gone with no retry and no trace.
 *
 * So: fine for work whose loss is survivable (logs, counters, cache warming).
 * For work that must not be lost - mail, payment notifications - the job here
 * should be a queue push, not the task itself.
 */
class Defer
{
    /**
     * Queued jobs as [closure, label] pairs.
     */
    private static array $jobs = [];

    /**
     * Set once flush() starts, so late registrations do not get lost.
     */
    private static bool $closed = false;

    /**
     * Jobs slower than this (ms) are logged - they are candidates for a queue.
     */
    private const SLOW_JOB_MS = 1000;

    /**
     * Time budget for everything queued, in seconds.
     */
    private const TIME_LIMIT = 20;

    /**
     * Queue a job to run after the response is sent.
     *
     * @param \Closure $job
     * @param string   $label Shown in slow/failed job logs.
     * @return void
     */
    public static function after(\Closure $job, string $label = ''): void
    {
        # Registered from within a deferred job, or after flush already ran:
        # run it now rather than dropping it on the floor.
        if (self::$closed) {
            $job();
            return;
        }

        self::$jobs[] = [$job, $label];
    }

    /**
     * Reopen for the next request.
     *
     * flush() leaves $closed true so late registrations run inline rather than
     * being queued for a flush that already happened. In a long-running worker
     * that flag has to be cleared, or every later request would run its deferred
     * work inline - defeating the point.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::flush();

        self::$jobs   = [];
        self::$closed = false;
    }

    /**
     * Are there jobs waiting?
     * @return bool
     */
    public static function pending(): bool
    {
        return (bool) count(self::$jobs);
    }

    /**
     * Send the response, then run everything queued.
     *
     * Called once from Run::begin() after the route has finished. Safe to call
     * with nothing queued.
     *
     * @return void
     */
    public static function flush(): void
    {
        if (self::$closed) return;
        self::$closed = true;

        if (!count(self::$jobs)) return;

        # Write the session before the response is released: a job that takes a
        # second must not delay what the user's next request reads.
        Session::flush();

        # PHP-FPM only. Under the CLI dev server the jobs still run, the user just
        # waits for them - same behaviour, different timing.
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        # index.php disables the collector for request speed; background work can
        # run long enough to need it.
        gc_enable();
        set_time_limit(self::TIME_LIMIT);

        foreach (self::$jobs as [$job, $label]) {
            $started = microtime(true);
            try {
                $job();
            } catch (\Throwable $e) {
                # One bad job must not take the rest of the queue with it.
                if (function_exists('errorHandler')) errorHandler($e);
            } finally {
                $ms = round((microtime(true) - $started) * 1000);
                if ($ms > self::SLOW_JOB_MS && function_exists('errorHandler'))
                    errorHandler(new \Exception("Defer job `" . ($label ?: 'unnamed') . "` took {$ms}ms - consider a queue."));
            }
        }

        self::$jobs = [];
    }
}
