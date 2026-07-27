<?php

namespace zFramework\Core\Facades;

/**
 * Redis-backed job queue.
 *
 * Work that must not be lost and must not be waited for - mail, payment
 * notifications, report generation - is pushed here and run by a separate
 * worker process:
 *
 *   Queue::push([SendWelcomeMail::class, 'handle'], ['user_id' => $user['id']]);
 *   php terminal queue work
 *
 * Unlike [Defer](Defer.php) this does free the web worker, and the job survives
 * the request that created it.
 *
 * Jobs are a class and method plus a payload, never a closure - closures cannot
 * be serialised, so a queue that accepted them would only appear to work.
 *
 * With no Redis configured push() runs the job immediately, in-process. Same
 * outcome, no infrastructure, none of the benefits - which keeps development and
 * shared hosting working without a second code path in the application.
 */
class Queue
{
    public const DEFAULT_QUEUE = 'default';

    /**
     * Queue a job.
     *
     * @param array|string $job     [Class::class, 'method'] or 'Class@method'
     * @param array        $payload Passed to the method. Must be serialisable.
     * @param string       $queue   Queue name; workers pick one.
     * @return bool
     */
    public static function push(array|string $job, array $payload = [], string $queue = self::DEFAULT_QUEUE): bool
    {
        $entry = [
            'job'       => $job,
            'payload'   => $payload,
            'queue'     => $queue,
            'pushed_at' => date('Y-m-d H:i:s'),
            'attempts'  => 0,
        ];

        # No broker: run it now rather than silently dropping it.
        if (!Redis::available('queue')) {
            self::run($entry);
            return true;
        }

        return Redis::push("queue:$queue", $entry, 'queue');
    }

    /**
     * Block until a job is available or $timeout seconds pass.
     *
     * @param string $queue
     * @param int    $timeout Seconds; 0 blocks forever.
     * @return array|null
     */
    public static function pop(string $queue = self::DEFAULT_QUEUE, int $timeout = 5): ?array
    {
        return Redis::pop("queue:$queue", $timeout, 'queue') ?: null;
    }

    /**
     * How many jobs are waiting.
     *
     * @param string $queue
     * @return int
     */
    public static function size(string $queue = self::DEFAULT_QUEUE): int
    {
        return Redis::size("queue:$queue", 'queue');
    }

    /**
     * Execute one job entry.
     *
     * @param array $entry
     * @return void
     * @throws \Throwable Whatever the job throws; the worker decides what to do.
     */
    public static function run(array $entry): void
    {
        $job = $entry['job'] ?? null;

        if (is_string($job) && str_contains($job, '@')) $job = explode('@', $job, 2);
        if (!is_array($job) || count($job) !== 2) throw new \InvalidArgumentException('Queue: a job must be [Class::class, \'method\'] or \'Class@method\'.');

        [$class, $method] = $job;
        if (!class_exists($class)) throw new \RuntimeException("Queue: job class `$class` not found.");

        $instance = new $class;
        if (!method_exists($instance, $method)) throw new \RuntimeException("Queue: `$class` has no method `$method`.");

        $instance->{$method}($entry['payload'] ?? []);
    }

    /**
     * Push a job back after a failure, counting the attempt.
     *
     * @param array $entry
     * @param int   $maxAttempts
     * @return bool True if it was requeued, false if it is exhausted.
     */
    public static function retry(array $entry, int $maxAttempts = 3): bool
    {
        $entry['attempts'] = ($entry['attempts'] ?? 0) + 1;
        if ($entry['attempts'] >= $maxAttempts) return false;

        return Redis::push("queue:" . ($entry['queue'] ?? self::DEFAULT_QUEUE), $entry, 'queue');
    }
}
