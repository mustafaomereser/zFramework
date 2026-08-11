<?php

namespace modules\Profiling;

use zFramework\Core\Facades\Config;

/**
 * Records what each request cost - one JSON file per request under
 * analysis/profiling/ - so runs can be compared over time.
 *
 * Where `bench run` measures one moment on demand, this collects many samples of
 * real traffic: what a page costs on an ordinary afternoon, and whether a deploy
 * changed it.
 *
 * Timing comes from two points a module can see: this class is started from the
 * module callback, once boot and the global middlewares are done, and finishes
 * at shutdown. That gives boot and handle. Splitting handle further into
 * controller and view would need timing code inside the framework itself.
 *
 * Switched on in config/framework.php under profiling.
 */
class Recorder
{
    /**
     * When the module's callback ran: boot and the global middlewares are done,
     * the route has not been matched yet.
     */
    private static ?float $booted = null;

    /**
     * Whether this request is being recorded, decided once.
     */
    private static ?bool $recording = null;

    /**
     * Stage durations reported by the framework, in nanoseconds. A stage may be
     * reported more than once - several controllers cannot happen, but a view
     * rendered after a redirect can - so they accumulate.
     */
    private static array $stages = [];

    /**
     * Start recording, if this request is one of the sampled ones.
     *
     * Called from the module's callback, which Run::handle() reaches after the
     * global middlewares and before the route is matched.
     *
     * @return void
     */
    public static function begin(): void
    {
        if (!self::sampling()) return;

        self::$booted = microtime(true);

        # From here the framework reports each stage it finishes. Without this
        # call it reports nothing and the timing code in it costs a comparison.
        \zFramework\Core\Profiler::listen(function (string $stage, float $ns) {
            self::$stages[$stage] = (self::$stages[$stage] ?? 0) + $ns;
        });

        register_shutdown_function([self::class, 'write']);
    }

    /**
     * Is this request being recorded?
     *
     * Config first, since that is the switch people reach for, then the rate -
     * on a busy site every request is not worth a file.
     *
     * @return bool
     */
    private static function sampling(): bool
    {
        if (self::$recording !== null) return self::$recording;

        if (!Config::framework('profiling.enabled')) return self::$recording = false;
        if (PHP_SAPI === 'cli') return self::$recording = false;

        $rate = (float) (Config::framework('profiling.rate') ?? 1);
        if ($rate >= 1) return self::$recording = true;
        if ($rate <= 0) return self::$recording = false;

        return self::$recording = (mt_rand() / mt_getrandmax()) < $rate;
    }

    /**
     * Where records live.
     *
     * @return string
     */
    public static function directory(): string
    {
        return base_path('/analysis/profiling');
    }

    /**
     * Write this request's record. Runs at shutdown, after the response.
     *
     * @return void
     */
    public static function write(): void
    {
        if (self::$booted === null) return;

        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? self::$booted);
        $ended   = microtime(true);

        $directory = self::directory();
        if (!is_dir($directory)) @mkdir($directory, 0755, true);

        # Stop writing rather than delete: old records are what a comparison is
        # made against. Clear them from /profiling when they no longer describe
        # the code that is running.
        $keep = (int) (Config::framework('profiling.keep') ?? 200);
        if ($keep > 0 && count(glob("$directory/*.json") ?: []) >= $keep) return;

        $record = [
            'at'      => date('Y-m-d H:i:s'),
            'url'     => strtok($_SERVER['REQUEST_URI'] ?? '/', '?'),
            'method'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'status'  => http_response_code() ?: 200,

            # boot   everything before the route was matched: bootstrap, the route
            #        table, providers, modules, the global middlewares.
            # handle matching, the controller, rendering, and the response.
            'boot_ms'   => round((self::$booted - $started) * 1000, 3),
            'handle_ms' => round(($ended - self::$booted) * 1000, 3),
            'total_ms'  => round(($ended - $started) * 1000, 3),

            # Inside handle, reported by the framework. view is part of
            # controller, not additional to it - a controller returning a view
            # spends most of its time there.
            'controller_ms' => round((self::$stages['controller'] ?? 0) / 1e6, 3),
            'view_ms'       => round((self::$stages['view'] ?? 0) / 1e6, 3),

            'memory_mb' => round(memory_get_peak_usage() / 1048576, 2),
            'files'     => count(get_included_files()),

            # Recorded alongside, since the same code costs very different things
            # depending on them.
            'opcache'   => function_exists('opcache_get_status') && (@\opcache_get_status(false)['opcache_enabled'] ?? false),
            'apcu'      => \zFramework\Core\GlobalCache::apcu(),
        ];

        @file_put_contents(
            $directory . '/' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.json',
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Every record on disk, newest first.
     *
     * @return array
     */
    public static function all(): array
    {
        $records = [];

        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            $record = json_decode(@file_get_contents($file), true);
            if (is_array($record) && isset($record['total_ms'])) $records[] = $record + ['file' => basename($file)];
        }

        usort($records, fn($a, $b) => strcmp($b['file'], $a['file']));

        return $records;
    }

    /**
     * Records grouped by url, with the numbers that survive a noisy machine.
     *
     * Median rather than mean, since one request that waited on a busy disk
     * drags an average somewhere no request actually went. best is closest to
     * what the work costs; the distance to worst is the machine interfering.
     *
     * @return array
     */
    public static function summary(): array
    {
        $groups = [];
        foreach (self::all() as $record) $groups[$record['url']][] = $record;

        $summary = [];
        foreach ($groups as $url => $records) {
            $totals = array_column($records, 'total_ms');
            $boots  = array_column($records, 'boot_ms');
            sort($totals);

            $summary[] = [
                'url'       => $url,
                'runs'      => count($records),
                'best_ms'   => $totals[0],
                'median_ms' => $totals[intdiv(count($totals), 2)],
                'worst_ms'  => $totals[count($totals) - 1],
                'boot_ms'   => count($boots) ? round(array_sum($boots) / count($boots), 3) : 0,
                'memory_mb' => max(array_column($records, 'memory_mb')),
                'files'     => max(array_column($records, 'files')),
            ];
        }

        usort($summary, fn($a, $b) => $b['median_ms'] <=> $a['median_ms']);

        return $summary;
    }

    /**
     * Delete every record.
     *
     * @return int How many went.
     */
    public static function clear(): int
    {
        $gone = 0;
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) if (@unlink($file)) $gone++;

        return $gone;
    }
}
