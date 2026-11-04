<?php

namespace modules\Profiling;

use zFramework\Core\Facades\Config;

/**
 * Reads back what the query analyzer recorded (framework.profiling.queryStore) and
 * groups it by query shape. The analyzer is the framework's and writes either way;
 * this only reads, so removing the module leaves it writing to nobody.
 */
class Queries
{
    /**
     * Newest records read. A page that answers in seconds beats a complete one
     * that does not answer.
     */
    const LIMIT = 5000;

    /**
     * @return string
     */
    public static function store(): string
    {
        return Config::framework('profiling.queryStore') === 'table' ? 'table' : 'file';
    }

    /**
     * @return string
     */
    public static function directory(): string
    {
        return base_path('/analysis/queries');
    }

    /**
     * Every record held, newest first, the same shape from either store.
     *
     * @return array
     */
    public static function all(): array
    {
        return self::store() === 'table' ? self::fromTable() : self::fromFiles();
    }

    private static function fromFiles(): array
    {
        $files = glob(self::directory() . '/*.jsonl') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $records = [];
        foreach ($files as $file) foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && isset($record['fingerprint'])) $records[] = $record;
            if (count($records) >= self::LIMIT) return $records;
        }

        return $records;
    }

    private static function fromTable(): array
    {
        $model = new \App\Models\SystemDbCollector;
        $model->ignoreAnalyze = true; # or the page would fill the report with itself

        $records = $model->closureMode(false)->orderBy(['id' => 'DESC'])->limit(self::LIMIT)->get();
        foreach ($records as $key => $record) foreach (['tables', 'used_indexes', 'used_columns', 'warnings', 'index_suggestions', 'metrics', 'row_stats', 'estimated_total_cost'] as $column)
            $records[$key][$column] = json_decode((string) ($record[$column] ?? ''), true) ?: [];

        return $records;
    }

    /**
     * Records grouped by fingerprint, what cost the most in total first: a 2 ms query
     * run 500 times is more often the problem than the slow one everybody sees.
     *
     * @param array $records
     * @return array
     */
    public static function summary(array $records): array
    {
        $groups = [];
        foreach ($records as $record) $groups[$record['fingerprint']][] = $record;

        $summary = [];
        foreach ($groups as $fingerprint => $runs) {
            $times    = array_map(fn($run) => (float) ($run['query_time_ms'] ?? 0), $runs);
            $scanned  = array_map(fn($run) => array_sum(array_column($run['row_stats'] ?? [], 'scanned')), $runs);
            $returned = array_filter(array_map(fn($run) => array_values($run['row_stats'] ?? [])[0]['returned'] ?? null, $runs), fn($value) => $value !== null);

            $warnings = $suggestions = $urls = [];
            foreach ($runs as $run) {
                foreach ($run['warnings'] ?? [] as $list) $warnings = array_merge($warnings, $list);
                foreach ($run['index_suggestions'] ?? [] as $kinds) foreach ($kinds as $statements) $suggestions = array_merge($suggestions, $statements);

                $url = $run['url'] ?? 'unknown';
                $urls[$url] = ($urls[$url] ?? 0) + 1;
            }
            arsort($urls);
            $warnings = array_values(array_unique($warnings));

            $summary[] = [
                'fingerprint' => $fingerprint,
                'query'       => $runs[0]['query'] ?? '',
                'runs'        => count($runs),
                'total_ms'    => round(array_sum($times), 2),
                'avg_ms'      => round(array_sum($times) / count($times), 2),
                'worst_ms'    => max($times),
                'scanned'     => (int) round(array_sum($scanned) / count($scanned)),
                'returned'    => $returned ? (int) round(array_sum($returned) / count($returned)) : null,
                'tables'      => array_values(array_unique(array_merge(...array_map(fn($run) => $run['tables'] ?? [], $runs)))),
                'indexes'     => array_values(array_unique(array_merge(...array_map(fn($run) => $run['used_indexes'] ?? [], $runs)))),
                'warnings'    => $warnings,
                'full_scan'   => in_array('FULL_SCAN', $warnings),
                'small'       => (bool) preg_grep('/^SMALL_TABLE:/', $warnings) && !preg_grep('/^LARGE_TABLE_FULL_SCAN/', $warnings),
                'suggestions' => array_values(array_unique($suggestions)),
                'urls'        => $urls,
            ];
        }

        usort($summary, fn($a, $b) => $b['total_ms'] <=> $a['total_ms']);

        return $summary;
    }

    /**
     * Each index worth creating once, with what it would help.
     *
     * @param array $summary
     * @param bool $small Advice for small tables too. A development database is full
     *                    of them, and an index on 50 rows helps nobody.
     * @return array
     */
    public static function suggestions(array $summary, bool $small = false): array
    {
        $suggestions = [];
        foreach ($summary as $query) {
            if ($query['small'] && !$small) continue;

            foreach ($query['suggestions'] as $statement) {
                $suggestions[$statement] ??= ['statement' => $statement, 'queries' => 0, 'runs' => 0, 'total_ms' => 0];
                $suggestions[$statement]['queries']++;
                $suggestions[$statement]['runs'] += $query['runs'];
                $suggestions[$statement]['total_ms'] = round($suggestions[$statement]['total_ms'] + $query['total_ms'], 2);
            }
        }

        usort($suggestions, fn($a, $b) => $b['total_ms'] <=> $a['total_ms']);

        return $suggestions;
    }

    /**
     * Has the file store reached profiling.keep? New requests then go unrecorded,
     * and without saying so the page would just look quiet.
     *
     * @return bool
     */
    public static function full(): bool
    {
        if (self::store() !== 'file') return false;

        $keep = (int) (Config::framework('profiling.keep') ?? 200);
        return $keep > 0 && count(glob(self::directory() . '/*.jsonl') ?: []) >= $keep;
    }

    /**
     * Is the analyzer writing at all?
     *
     * @return bool
     */
    public static function analyzing(): bool
    {
        return Config::debug() && (float) Config::framework('profiling.queryAnalyze') > 0;
    }

    /**
     * Delete every record in the current store.
     *
     * @return int How many went: files, or rows.
     */
    public static function clear(): int
    {
        if (self::store() === 'table') return (new \App\Models\SystemDbCollector)->delete();

        $gone = 0;
        foreach (glob(self::directory() . '/*.jsonl') ?: [] as $file) if (@unlink($file)) $gone++;

        return $gone;
    }
}
