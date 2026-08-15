<?php

namespace zFramework\Core\Facades;

/**
 * Application log.
 *
 *   Log::info('Order paid', ['order' => $id]);
 *   Log::error('Gateway refused', ['code' => $e->getCode()]);
 *
 * One file per day under storage/logs, appended with LOCK_EX so concurrent
 * requests do not interleave a line. Nothing here is loaded until the first
 * call - a request that never logs does not compile this file.
 *
 * This is not the error handler. Uncaught throwables still go through
 * errorHandler(); this is for the things you want to read at 03:00 that were
 * never exceptions - a refused payment, a webhook that arrived twice.
 */
class Log
{
    /**
     * Severity order. A message below the configured level is dropped before
     * anything is formatted or opened.
     */
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /**
     * Resolved config, read once per process.
     */
    private static ?array $config = null;

    /**
     * Directory the day files live in, resolved once.
     */
    private static ?string $dir = null;

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @return array{enabled: bool, level: string, days: int}
     */
    private static function config(): array
    {
        if (self::$config !== null) return self::$config;

        $config = (array) (Config::framework('log') ?: []);

        return self::$config = [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'level'   => strtolower((string) ($config['level'] ?? 'debug')),
            'days'    => (int) ($config['days'] ?? 14),
        ];
    }

    /**
     * Append one line.
     *
     * @param string $level
     * @param string $message
     * @param array  $context Appended as JSON when not empty.
     * @return void
     */
    private static function write(string $level, string $message, array $context = []): void
    {
        $config = self::config();
        if (!$config['enabled']) return;

        $minimum = self::LEVELS[$config['level']] ?? 0;
        if ((self::LEVELS[$level] ?? 0) < $minimum) return;

        if (self::$dir === null) {
            global $storage_path;
            self::$dir = ($storage_path ?: FRAMEWORK_PATH . '/storage') . '/logs';
            if (!is_dir(self::$dir)) @mkdir(self::$dir, 0755, true);

            self::prune($config['days']);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message
            . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')
            . PHP_EOL;

        @file_put_contents(self::$dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Delete day files older than the retention window.
     *
     * Runs at most once per process, and only on the request that logs - a
     * site that never logs never scans the directory. Costs one readdir on
     * that request, which is cheaper than a cron nobody remembers to set up.
     *
     * @param int $days 0 or less keeps everything.
     * @return void
     */
    private static function prune(int $days): void
    {
        if ($days <= 0) return;

        $cutoff = strtotime("-$days days");

        foreach ((array) glob(self::$dir . '/*.log') as $file) {
            $date = strtotime(basename($file, '.log'));
            if ($date !== false && $date < $cutoff) @unlink($file);
        }
    }
}
