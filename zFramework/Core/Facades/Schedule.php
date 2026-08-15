<?php

namespace zFramework\Core\Facades;

/**
 * Scheduled tasks, driven by one crontab line.
 *
 *   * * * * * cd /path/to/app && php terminal schedule run >> /dev/null 2>&1
 *
 * Everything else lives in schedule.php at the project root, in code you can
 * read, instead of a crontab nobody remembers editing:
 *
 *   Schedule::daily('03:00', fn() => Backup::run(), 'nightly-backup');
 *   Schedule::everyMinutes(5, fn() => Queue::drain(), 'queue-drain');
 *   Schedule::cron('0 9 * * 1', fn() => Mail::send(...), 'monday-report');
 *
 * Nothing here is reachable from a web request - schedule.php is only included
 * by the terminal command, so the file costs a served request nothing.
 *
 * Two things it does that a raw crontab does not: it will not start a task that
 * is still running from last time, and it will not run one twice if cron fires
 * twice in the same minute.
 */
class Schedule
{
    /**
     * Registered tasks: [expression, job, name].
     */
    private static array $tasks = [];

    /**
     * Where locks and last-run marks live.
     */
    private static ?string $dir = null;

    /**
     * Register a task by cron expression.
     *
     * Five fields - minute hour day-of-month month day-of-week - supporting
     * `*`, `*​/n`, `a,b,c` and `a-b`. Day-of-week is 0-6 with 0 as Sunday, and
     * 7 also accepted for Sunday.
     *
     * @param string   $expression
     * @param \Closure $job
     * @param string   $name Used for the lock, the last-run mark and the log line.
     * @return void
     */
    public static function cron(string $expression, \Closure $job, string $name): void
    {
        self::$tasks[] = [$expression, $job, $name];
    }

    public static function everyMinute(\Closure $job, string $name): void
    {
        self::cron('* * * * *', $job, $name);
    }

    /**
     * @param int $minutes 5 → at :00, :05, :10 ...
     */
    public static function everyMinutes(int $minutes, \Closure $job, string $name): void
    {
        self::cron('*/' . max(1, $minutes) . ' * * * *', $job, $name);
    }

    public static function hourly(int $minute, \Closure $job, string $name): void
    {
        self::cron("$minute * * * *", $job, $name);
    }

    /**
     * @param string $at 'HH:MM'
     */
    public static function daily(string $at, \Closure $job, string $name): void
    {
        [$hour, $minute] = self::time($at);
        self::cron("$minute $hour * * *", $job, $name);
    }

    /**
     * @param int    $dayOfWeek 0 Sunday .. 6 Saturday
     * @param string $at        'HH:MM'
     */
    public static function weekly(int $dayOfWeek, string $at, \Closure $job, string $name): void
    {
        [$hour, $minute] = self::time($at);
        self::cron("$minute $hour * * $dayOfWeek", $job, $name);
    }

    /**
     * @param int    $dayOfMonth 1..31
     * @param string $at         'HH:MM'
     */
    public static function monthly(int $dayOfMonth, string $at, \Closure $job, string $name): void
    {
        [$hour, $minute] = self::time($at);
        self::cron("$minute $hour $dayOfMonth * *", $job, $name);
    }

    /**
     * Everything registered, for `schedule list`.
     *
     * @return array
     */
    public static function tasks(): array
    {
        return self::$tasks;
    }

    /**
     * Whether a task's expression matches a moment.
     *
     * @param string   $expression
     * @param int|null $at Unix time; now when null.
     * @return bool
     */
    public static function due(string $expression, ?int $at = null): bool
    {
        $at     = $at ?? time();
        $fields = preg_split('/\s+/', trim($expression));

        if (count($fields) !== 5) return false;

        $now = [
            (int) date('i', $at),   # minute
            (int) date('G', $at),   # hour
            (int) date('j', $at),   # day of month
            (int) date('n', $at),   # month
            (int) date('w', $at),   # day of week, 0 = Sunday
        ];

        foreach ($fields as $index => $field)
            if (!self::fieldMatches($field, $now[$index], $index === 4)) return false;

        return true;
    }

    /**
     * Run everything due now.
     *
     * @param callable|null $report Called with (name, status, message) per task.
     * @return int How many ran.
     */
    public static function run(?callable $report = null): int
    {
        $ran    = 0;
        $minute = date('YmdHi');

        foreach (self::$tasks as [$expression, $job, $name]) {
            if (!self::due($expression)) continue;

            # Cron firing twice in the same minute, or `schedule run` invoked by
            # hand while the crontab entry is also running.
            $mark = self::dir() . '/' . sha1($name) . '.last';
            if (@file_get_contents($mark) === $minute) {
                $report && $report($name, 'skipped', 'already ran this minute');
                continue;
            }

            $lock = @fopen(self::dir() . '/' . sha1($name) . '.lock', 'c');

            # Still running from a previous tick. Skipping is the right answer -
            # two copies of a backup are worse than a late one.
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                if ($lock) fclose($lock);
                $report && $report($name, 'locked', 'previous run still going');
                continue;
            }

            @file_put_contents($mark, $minute);

            $started = microtime(true);

            try {
                $job();
                $status  = 'ok';
                $message = round((microtime(true) - $started) * 1000) . ' ms';
            } catch (\Throwable $e) {
                $status  = 'failed';
                $message = $e->getMessage();

                # One failing task must not take the rest of the tick with it.
                Log::error("Scheduled task `$name` failed.", ['error' => $e->getMessage()]);
            }

            flock($lock, LOCK_UN);
            fclose($lock);

            $ran++;
            $report && $report($name, $status, $message);
        }

        return $ran;
    }

    /**
     * @param string $at 'HH:MM'
     * @return array{0: int, 1: int}
     */
    private static function time(string $at): array
    {
        [$hour, $minute] = array_pad(explode(':', $at), 2, '0');

        return [(int) $hour, (int) $minute];
    }

    /**
     * One cron field against one value.
     *
     * @param string $field
     * @param int    $value
     * @param bool   $isDayOfWeek 7 means Sunday there, as it does in crontab.
     * @return bool
     */
    private static function fieldMatches(string $field, int $value, bool $isDayOfWeek = false): bool
    {
        foreach (explode(',', $field) as $part) {
            $step = 1;

            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                $step = max(1, (int) $stepText);
            }

            if ($part === '*' || $part === '') {
                if ($value % $step === 0) return true;
                continue;
            }

            if (str_contains($part, '-')) {
                [$from, $to] = array_map('intval', explode('-', $part, 2));
            } else {
                $from = $to = (int) $part;
            }

            if ($isDayOfWeek) {
                if ($from === 7) $from = 0;
                if ($to === 7) $to = 0;
            }

            if ($value < $from || $value > $to) continue;
            if (($value - $from) % $step === 0) return true;
        }

        return false;
    }

    /**
     * @return string
     */
    private static function dir(): string
    {
        if (self::$dir !== null) return self::$dir;

        global $storage_path;
        self::$dir = ($storage_path ?: FRAMEWORK_PATH . '/storage') . '/schedule';

        if (!is_dir(self::$dir)) @mkdir(self::$dir, 0755, true);

        return self::$dir;
    }
}
