<?php

namespace zFramework\Core\Facades\Analyzer;

class DbCollector
{
    public static function collect(string $table, string $sql, string $analyze_identity, $query_time)
    {
        $usage = self::extractColumnUsage($sql);
        ob_start();
        echo str_repeat('-', 50) . "\n";
        print_r($usage);
        print_r(func_get_args());
        print_r(($query_time * 1000) . 'ms');
        echo "\n" . str_repeat('-', 50) . "\n";
        $analyze = ob_get_clean();
        file_put_contents2(base_path("/db-analyzes/" . Analyze::$process_id), $analyze, FILE_APPEND);
    }

    public static function extractColumnUsage(string $sql): array
    {
        $usage = [
            'where' => [],
            'join'  => [],
            'group' => [],
            'order' => []
        ];
        $sql = preg_replace("/'([^']*)'/", '', $sql);
        $sql = preg_replace('/"([^"]*)"/', '', $sql);
        $sql = preg_replace('/`([^`]*)`/', '', $sql);
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        if (preg_match('/WHERE (.+?)(GROUP BY|ORDER BY|$)/i', $sql, $m))  $usage['where'] = self::extractColumnsFromClause($m[1]);
        if (preg_match_all('/JOIN .*? ON (.+?)(WHERE|JOIN|GROUP BY|ORDER BY|$)/i', $sql, $matches)) foreach ($matches[1] as $onClause) $usage['join'] = array_merge($usage['join'], self::extractColumnsFromClause($onClause));
        if (preg_match('/GROUP BY (.+?)(ORDER BY|$)/i', $sql, $m)) $usage['group'] = self::extractColumnsFromClause($m[1]);
        if (preg_match('/ORDER BY (.+?)($)/i', $sql, $m)) $usage['order'] = self::extractColumnsFromClause($m[1]);
        foreach ($usage as &$cols) $cols = array_unique($cols);
        return $usage;
    }

    private static function extractColumnsFromClause(string $clause): array
    {
        $columns = [];
        $clause  = preg_replace("/'([^']*)'/", '', $clause);
        $clause  = preg_replace('/"([^"]*)"/', '', $clause);
        $clause  = preg_replace('/`([^`]*)`/', '', $clause);
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*\.)?[a-zA-Z_][a-zA-Z0-9_]*\b/', $clause, $matches);
        $keywords = ['SELECT', 'FROM', 'WHERE', 'JOIN', 'ON', 'AND', 'OR', 'ORDER', 'BY', 'GROUP', 'LIMIT', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'IS', 'NULL', 'LIKE', 'IN', 'BETWEEN', 'NOT', 'EXISTS'];

        foreach ($matches[0] as $col) {
            if (str_starts_with($col, ':') || str_starts_with($col, 'id_')) continue;
            if (in_array(strtoupper($col), $keywords)) continue;
            $columns[] = strtolower($col);
        }

        return array_unique($columns);
    }
}
