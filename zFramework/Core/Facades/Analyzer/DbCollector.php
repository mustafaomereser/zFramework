<?php

namespace zFramework\Core\Facades\Analyzer;

use zFramework\Core\Facades\DB;

class DbCollector
{
    public static function analyze(DB $db, string $sql, array $data, float $queryTime): void
    {
        try {
            $executed = $db->debugSQL($sql, $data);
            $pdo = $db->connection();

            $analysis = [
                'fingerprint' => self::fingerprint($sql),
                'sql' => $sql,
                'executed_sql' => $executed,
                'query_time_ms' => round($queryTime * 1000, 2),
                'tables' => [],
                'used_indexes' => [],
                'used_columns' => [],
                'warnings' => [],
                'index_suggestions' => [],
                'metrics' => ['estimated_rows' => null, 'actual_rows' => null, 'actual_time_ms' => null],
                'row_stats' => ['scanned' => 0, 'returned' => 0, 'mode' => null]
            ];

            $row = $pdo->query("EXPLAIN FORMAT=JSON $executed")->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $plan = json_decode(current($row), true);
                $root = $plan['query_block'] ?? $plan;
                self::walkExplain($root, $analysis);
            }

            $row = $pdo->query("EXPLAIN ANALYZE FORMAT=JSON $executed")->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $plan = json_decode(current($row), true);
                $root = $plan['query_plan'] ?? $plan;
                self::walkAnalyze($root, $analysis);
            }

            $analysis['tables'] = array_values(array_unique($analysis['tables']));
            $analysis['used_indexes'] = array_values(array_unique($analysis['used_indexes']));
            $analysis['used_columns'] = array_values(array_unique($analysis['used_columns']));
            $analysis['warnings'] = array_values(array_unique($analysis['warnings']));

            $analysis['row_stats']['scanned'] = $analysis['metrics']['actual_rows'] ?? 0;
            $analysis['row_stats']['returned'] = self::extractLimit($sql) ?? ($analysis['metrics']['actual_rows'] ?? 0);
            $analysis['row_stats']['mode'] = in_array('FULL_SCAN', $analysis['warnings']) ? 'FULL_SCAN' : 'INDEX';

            foreach ($analysis['tables'] as $table) {
                $size = self::approxTableRows($pdo, $table);
                $analysis['metrics']['estimated_rows'] = $size;
                if ($size !== null) {
                    if (($analysis['metrics']['actual_rows'] ?? 0) > $size * 1.5) $analysis['warnings'][] = "BAD_ESTIMATION_STATS:$table";
                    if ($size < 5000) $analysis['warnings'][] = "SMALL_TABLE:$table(~$size rows)";
                    if ($size >= 5000 && in_array('FULL_SCAN', $analysis['warnings'])) $analysis['warnings'][] = "LARGE_TABLE_FULL_SCAN:$table(~$size rows)";
                }
            }

            if (in_array('FULL_SCAN', $analysis['warnings']) && empty($analysis['used_indexes'])) {
                $cols = self::extractIndexableColumns($sql);
                if (!empty($cols) && !empty($analysis['tables']))
                    $analysis['index_suggestions'][] = "CREATE INDEX idx_" . time() . rand() . " ON " . $analysis['tables'][0] . " (" . implode(', ', $cols) . ")";
            }

            $table = $analysis['tables'][0] ?? null;
            $used = $analysis['used_columns'];
            $size = $analysis['metrics']['estimated_rows'] ?? 0;

            if ($table && !in_array('COVERING_INDEX', $analysis['warnings'])) {
                if (count($used) > 0 && count($used) <= 6 && $size > 10000) {
                    $analysis['warnings'][] = 'COVERING_POSSIBLE';
                    $analysis['index_suggestions'][] = "OPTIONAL COVERING INDEX: CREATE INDEX idx_cover_" . time() . rand() . " ON $table (" . implode(', ', $used) . ")";
                }
                if (count($used) > 6) $analysis['warnings'][] = 'COVERING_SKIPPED_TOO_MANY_COLUMNS';
            }

            print_r($analysis);
        } catch (\Throwable) {
        }
    }

    private static function walkExplain(array $node, array &$a): void
    {
        if (isset($node['table_name'])) $a['tables'][] = $node['table_name'];
        if (isset($node['key']) && $node['key']) $a['used_indexes'][] = $node['key'];
        if (!empty($node['used_key_parts'])) $a['used_columns'] = array_merge($a['used_columns'], $node['used_key_parts']);
        if (($node['access_type'] ?? null) === 'ALL') $a['warnings'][] = 'FULL_SCAN';
        foreach ($node as $child) if (is_array($child)) self::walkExplain($child, $a);
    }

    private static function walkAnalyze(array $node, array &$a): void
    {
        if (!empty($node['table_name'])) $a['tables'][] = $node['table_name'];
        if (!empty($node['index_name'])) $a['used_indexes'][] = $node['index_name'];
        if (!empty($node['used_columns']) && is_array($node['used_columns'])) $a['used_columns'] = array_merge($a['used_columns'], $node['used_columns']);
        if (($node['access_type'] ?? null) === 'table') $a['warnings'][] = 'FULL_SCAN';
        if (!empty($node['covering'])) $a['warnings'][] = 'COVERING_INDEX';
        if (isset($node['actual_rows'])) $a['metrics']['actual_rows'] = $node['actual_rows'];
        if (isset($node['actual_last_row_ms'])) $a['metrics']['actual_time_ms'] = $node['actual_last_row_ms'];
        if (!empty($node['inputs']) && is_array($node['inputs'])) foreach ($node['inputs'] as $child) self::walkAnalyze($child, $a);
        foreach ($node as $child) if (is_array($child)) self::walkAnalyze($child, $a);
    }

    private static function approxTableRows(\PDO $pdo, string $table): ?int
    {
        try {
            $stmt = $pdo->prepare("SELECT table_rows FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function extractIndexableColumns(string $sql): array
    {
        $cols = [];

        $sql = preg_replace('/SELECT\s.+?\sFROM\s/si', 'FROM ', $sql);

        if (preg_match('/WHERE (.+?) (ORDER BY|GROUP BY|LIMIT|$)/i', $sql, $m)) {
            preg_match_all('/\b([a-zA-Z0-9_\.]+)\s*(=|>|<|>=|<=|LIKE|IN)\b/i', $m[1], $w);
            if (!empty($w[1])) foreach ($w[1] as $col) $cols[] = @end(explode('.', $col));
        }

        if (preg_match('/JOIN\s+[a-zA-Z0-9_]+\s+ON\s+(.+?)\s+(WHERE|JOIN|ORDER BY|GROUP BY|LIMIT|$)/i', $sql, $m)) {
            preg_match_all('/\b([a-zA-Z0-9_\.]+)\s*=\s*[a-zA-Z0-9_\.]+\b/i', $m[1], $w);
            if (!empty($w[1])) foreach ($w[1] as $col) $cols[] = @end(explode('.', $col));
        }

        if (preg_match('/ORDER BY (.+?) (LIMIT|$)/i', $sql, $m)) foreach (explode(',', $m[1]) as $p) $cols[] = @end(explode('.', trim(preg_replace('/\s+(ASC|DESC)$/i', '', $p))));
        if (preg_match('/GROUP BY (.+?) (ORDER BY|LIMIT|$)/i', $sql, $m)) foreach (explode(',', $m[1]) as $p) $cols[] = @end(explode('.', trim($p)));

        return array_values(array_unique($cols));
    }


    private static function extractLimit(string $sql): ?int
    {
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m)) return (int)$m[1];
        return null;
    }

    public static function fingerprint(string $sql): string
    {
        $sql = preg_replace("/'[^']*'/", '?', $sql);
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return md5(strtolower($sql));
    }
}
