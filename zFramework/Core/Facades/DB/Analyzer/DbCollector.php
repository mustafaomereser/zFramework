<?php

namespace zFramework\Core\Facades\DB\Analyzer;

use zFramework\Core\Facades\DB;

class DbCollector
{
    /**
     * Is this statement a SELECT? Leading comments and the parentheses of a union are
     * allowed. So is WITH, unless it leads to a write: MySQL takes WITH ... UPDATE, and
     * measuring a statement means running it.
     *
     * The analyzer is scoped to SELECT: EXPLAIN on INSERT/UPDATE/DELETE costs a
     * round-trip per query without producing an index suggestion.
     */
    public static function isSelect(string $sql): bool
    {
        $sql = self::withoutStrings($sql);

        if (!preg_match('/^(?:\s|\/\*.*?\*\/|--[^\n]*\n|#[^\n]*\n)*\(*\s*(SELECT|WITH)\b/is', $sql, $m)) return false;
        return strtoupper($m[1]) === 'SELECT' || !preg_match('/\b(?:UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql);
    }

    /**
     * The statement that runs a query to measure it, which the two servers spell
     * differently: MariaDB has no EXPLAIN ANALYZE, and MySQL's only speaks TREE.
     *
     * @param \PDO $pdo
     * @param string $sql
     * @param bool $json MariaDB's answer as JSON; MySQL has no such form to give.
     * @return string
     */
    public static function measureStatement(\PDO $pdo, string $sql, bool $json = false): string
    {
        if (!self::isMariaDB($pdo)) return "EXPLAIN ANALYZE $sql";
        return 'ANALYZE ' . ($json ? 'FORMAT=JSON ' : '') . $sql;
    }

    private static function isMariaDB(\PDO $pdo): bool
    {
        return stripos((string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    }

    /**
     * @param DB $db
     * @param string $sql
     * @param array $data
     * @param float $queryTime
     * @param ?int $returned Rows the query really gave back, where the caller knows.
     * @return void
     */
    public static function analyze(DB $db, string $sql, array $data, float $queryTime, ?int $returned = null): void
    {
        if (!self::isSelect($sql)) return;

        # EXPLAIN FORMAT=JSON and the plan shape read below are MySQL/MariaDB's.
        # On another driver the query itself would run (EXPLAIN costs a real
        # plan) and the parser would shrug at the answer - skip honestly instead.
        if ($db->connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') return;

        # FOUND_ROWS() answers for the statement before it, and that would be ours.
        if (preg_match('/\bSQL_CALC_FOUND_ROWS\b/i', $sql)) return;

        try {
            $executed = $db->debugSQL($sql, $data);
            $pdo      = $db->connection();

            $analysis = [
                'analyze_id'           => Analyze::id(),
                'fingerprint'          => self::fingerprint($sql),
                'url'                  => self::origin(),
                'query'                => $sql,
                'executed'             => $executed,
                'query_time_ms'        => round($queryTime * 1000, 2),
                'tables'               => [],
                'used_indexes'         => [],
                'used_columns'         => [],
                'warnings'             => [],
                'index_suggestions'    => [],
                'metrics'              => [],
                'row_stats'            => [],
                'estimated_total_cost' => []
            ];

            $aliases  = self::aliases($sql);
            $tables   = [];
            $measure  = !self::hasSideEffects($sql);
            $mariadb  = self::isMariaDB($pdo);

            # MariaDB's ANALYZE is its EXPLAIN with the measurements added, so one
            # statement is enough there. MySQL needs both: the JSON plan for the
            # structure, the tree for what really happened.
            $statement = $mariadb && $measure ? self::measureStatement($pdo, $executed, true) : "EXPLAIN FORMAT=JSON $executed";
            $plan      = $pdo->query($statement)->fetchColumn();
            if ($plan) self::walkPlan(self::decodePlan($plan), $aliases, $tables);

            if ($measure && !$mariadb) try {
                $tree = $pdo->query(self::measureStatement($pdo, $executed))->fetchColumn();
                if ($tree) self::walkTree($tree, $aliases, $tables);
            } catch (\Throwable $e) {
                # What the plan said is still worth keeping.
                $analysis['warnings']['query'][] = 'MEASURE_FAILED: ' . $e->getMessage();
            }

            self::summarize($analysis, $tables, $sql, $aliases, $returned, $pdo);
            self::record($analysis);
        } catch (\Throwable $e) {
            # The application's query already succeeded and must not pay for this,
            # but with debug on somebody should be able to find out why a query has
            # no analysis.
            error_log('[zFramework] query analyzer: ' . $e->getMessage());
        }
    }

    /**
     * The request a query belongs to. An id can not say: AJAX calls share their page's.
     * No query string, it is where tokens travel.
     */
    private static function origin(): string
    {
        if (!isset($_SERVER['REQUEST_URI'])) return 'cli';

        return substr((($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . strtok($_SERVER['REQUEST_URI'], '?')), 0, 255);
    }

    /**
     * Would running this again do something? It is only explained then, not measured.
     */
    private static function hasSideEffects(string $sql): bool
    {
        return (bool) preg_match('/@\w+\s*:=|\bINTO\s+(?:OUTFILE|DUMPFILE|@)|\b(?:GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS|SLEEP|BENCHMARK|NEXTVAL|SETVAL|LASTVAL)\s*\(|\bNEXT\s+VALUE\s+FOR\b/i', self::withoutStrings($sql));
    }

    /**
     * MariaDB repeats keys in its plan: every table of a join is another "table" in
     * the same object, and json_decode() keeps only the last. Keys that open an object
     * are made unique first. Nothing reads those by name, the walk goes through them
     * all; whole strings are matched so that one inside a condition is left alone.
     */
    private static function decodePlan(string $json): array
    {
        $count = 0;
        $json  = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"(\s*:\s*\{)?/s', function ($m) use (&$count) {
            return isset($m[2]) ? '"' . $m[1] . '#' . (++$count) . '"' . $m[2] : $m[0];
        }, $json);

        return (array) json_decode($json, true);
    }

    /**
     * Table name per name it goes by here. A plan answers with the alias, which
     * information_schema has never heard of.
     */
    private static function aliases(string $sql): array
    {
        $aliases  = [];
        $keywords = ['WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'OUTER', 'NATURAL', 'STRAIGHT_JOIN', 'ON', 'USING', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'FOR', 'LOCK', 'PARTITION', 'USE', 'IGNORE', 'FORCE', 'WINDOW', 'INTO', 'PROCEDURE'];

        preg_match_all('/\b(?:FROM|JOIN)\s+`?(?:\w+`?\.`?)?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?/i', self::withoutStrings($sql), $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $aliases[strtolower($m[1])] = $m[1];
            if (isset($m[2]) && !in_array(strtoupper($m[2]), $keywords)) $aliases[strtolower($m[2])] = $m[1];
        }

        return $aliases;
    }

    private static function &tableEntry(string $name, array $aliases, array &$tables): array
    {
        $table = $aliases[strtolower($name)] ?? $name;
        $tables[$table] ??= ['full_scan' => false, 'covering' => false, 'indexes' => [], 'key_columns' => [], 'columns' => [], 'possible_keys' => [], 'actual_rows' => null, 'actual_time_ms' => null, 'loops' => 0, 'cost' => null];
        return $tables[$table];
    }

    private static function walkPlan(array $node, array $aliases, array &$tables): void
    {
        if (isset($node['table_name']) && is_string($node['table_name'])) {
            $entry = &self::tableEntry($node['table_name'], $aliases, $tables);

            if (!empty($node['key'])) $entry['indexes'][] = $node['key'];
            if (!empty($node['used_key_parts'])) $entry['key_columns'] = array_merge($entry['key_columns'], (array) $node['used_key_parts']);
            if (!empty($node['used_columns'])) $entry['columns'] = array_merge($entry['columns'], (array) $node['used_columns']);
            if (!empty($node['possible_keys'])) $entry['possible_keys'] = array_merge($entry['possible_keys'], (array) $node['possible_keys']);
            if (($node['access_type'] ?? null) === 'ALL') $entry['full_scan'] = true;
            if (!empty($node['using_index'])) $entry['covering'] = true;
            if (isset($node['cost_info']['prefix_cost'])) $entry['cost'] = $node['cost_info']['prefix_cost'];

            # MariaDB's ANALYZE reports rows per loop beside the loop count. Added up:
            # a table read under two aliases was read twice.
            if (isset($node['r_rows'])) {
                $loops = (int) ($node['r_loops'] ?? 1);
                $entry['actual_rows']    = (int) ($entry['actual_rows'] ?? 0) + (int) round((float) $node['r_rows'] * $loops);
                $entry['actual_time_ms'] = (float) ($entry['actual_time_ms'] ?? 0) + (float) ($node['r_total_time_ms'] ?? 0);
                $entry['loops']          = max($entry['loops'], $loops);
            }
            unset($entry);
        }

        foreach ($node as $child) if (is_array($child)) self::walkPlan($child, $aliases, $tables);
    }

    /**
     * MySQL's EXPLAIN ANALYZE, one iterator per line:
     *
     *   -> Index lookup on b using k_a (a_id=a.id)  (cost=0.25 rows=1) (actual time=0.01..0.02 rows=1 loops=100)
     *
     * Only the lines that read a table count; a Filter above one saw fewer rows than
     * were read. The rows and the time are per loop.
     */
    private static function walkTree(string $tree, array $aliases, array &$tables): void
    {
        foreach (explode("\n", $tree) as $line) {
            if (!preg_match('/^\s*-> (?:Table scan|(?:Covering |Reverse |Multi-range |Single-row |Single-row covering |Covering )*[Ii]ndex (?:scan|range scan|lookup|skip scan)|Full-text index search) on (`?)([^\s`]+)\1(?: using (\S+))?.*\(actual time=[\d.e+-]+\.\.([\d.e+-]+) rows=([\d.e+-]+) loops=(\d+)\)\s*$/i', $line, $m)) continue;

            # <temporary> and the like are the server's own, nothing the query named.
            if (str_starts_with($m[2], '<') || !isset($tables[$aliases[strtolower($m[2])] ?? $m[2]])) continue;

            $entry = &self::tableEntry($m[2], $aliases, $tables);
            $loops = (int) $m[6];
            $entry['actual_rows']    = (int) ($entry['actual_rows'] ?? 0) + (int) round((float) $m[5] * $loops);
            $entry['actual_time_ms'] = (float) ($entry['actual_time_ms'] ?? 0) + (float) $m[4] * $loops;
            $entry['loops']          = max($entry['loops'], $loops);
            if (stripos($line, 'covering') !== false) $entry['covering'] = true;
            unset($entry);
        }
    }

    /**
     * From what the walks found per table to the record's shape.
     */
    private static function summarize(array &$analysis, array $tables, string $sql, array $aliases, ?int $returned, \PDO $pdo): void
    {
        foreach ($tables as $table => $t) {
            $analysis['tables'][]     = $table;
            $analysis['used_indexes'] = array_merge($analysis['used_indexes'], $t['indexes']);
            $analysis['used_columns'] = array_merge($analysis['used_columns'], $t['key_columns'], $t['columns']);

            $warnings = [];
            if ($t['full_scan']) $warnings[] = 'FULL_SCAN';
            if ($t['covering']) $warnings[] = 'COVERING_INDEX';

            if ($t['actual_rows'] !== null) $analysis['metrics'][$table]['actual_rows'] = $t['actual_rows'];
            if ($t['actual_time_ms'] !== null) $analysis['metrics'][$table]['actual_time_ms'] = round($t['actual_time_ms'], 4);
            if ($t['cost'] !== null) $analysis['estimated_total_cost'][$table] = $t['cost'];

            $analysis['row_stats'][$table] = ['scanned' => $t['actual_rows'] ?? 0, 'returned' => $returned, 'mode' => $t['full_scan'] ? 'FULL_SCAN' : 'INDEX'];

            # null: a derived table, a CTE - nothing information_schema can size.
            $size = self::approxTableRows($pdo, $table);
            $analysis['metrics'][$table]['estimated_rows'] = $size;

            if ($size !== null) {
                # table_rows is itself an estimate, and a table read in a loop is read
                # more than once, so only a clear gap on a single pass says anything.
                if ($t['loops'] === 1 && $t['actual_rows'] > $size * 1.5 && $t['actual_rows'] - $size > 100) $warnings[] = 'BAD_ESTIMATION_STATS (' . ($t['actual_rows'] - $size) . ' rows diff)';
                if ($size < 5000) $warnings[] = "SMALL_TABLE:$table(~$size rows)";
                elseif ($t['full_scan']) $warnings[] = "LARGE_TABLE_FULL_SCAN (~$size rows)";

                # Indexes the server looked at and turned down: another on the same
                # columns would be turned down too.
                if ($t['full_scan'] && !$t['indexes'] && !$t['possible_keys']) {
                    $columns = self::indexableColumns($sql, $table, $aliases);
                    if ($columns) $analysis['index_suggestions'][$table]['stable'][] = self::createIndex('idx', $table, $columns);
                }

                # Only MySQL lists the columns a table needs, and a covering index
                # cannot be worked out from the ones an index happened to use.
                $needed = array_values(array_unique($t['columns']));
                if (!$t['covering'] && $needed && $size > 10000) {
                    if (count($needed) <= 6) {
                        $warnings[] = 'COVERING_POSSIBLE';
                        $analysis['index_suggestions'][$table]['covering'][] = self::createIndex('idx_cover', $table, $needed);
                    } else $warnings[] = 'COVERING_SKIPPED_TOO_MANY_COLUMNS';
                }
            }

            if ($warnings) $analysis['warnings'][$table] = array_merge($analysis['warnings'][$table] ?? [], $warnings);
        }

        $analysis['used_indexes'] = array_values(array_unique($analysis['used_indexes']));
        $analysis['used_columns'] = array_values(array_unique($analysis['used_columns']));
    }

    /**
     * Named after what it indexes, so the same advice twice is visibly the same.
     */
    private static function createIndex(string $prefix, string $table, array $columns): string
    {
        return 'CREATE INDEX ' . substr("{$prefix}_{$table}_" . implode('_', $columns), 0, 64) . " ON $table (" . implode(', ', $columns) . ')';
    }

    /**
     * Store one query's analysis, wherever framework.profiling.queryStore says.
     *
     * Two ways of keeping the same thing, and which suits depends on what you
     * mean to do with it. A table is queryable and joinable; a file needs nothing
     * to exist first, can be read in an editor, and does not write to the
     * database it is measuring.
     *
     * @param array $analysis
     * @return void
     */
    private static function record(array $analysis): void
    {
        if (\zFramework\Core\Facades\Config::framework('profiling.queryStore') === 'table') {
            (new \App\Models\SystemDbCollector)->insert($analysis, true);
            return;
        }

        # One file per request, named after the analyze id, one JSON object per
        # line. Appended as each query finishes rather than collected and written
        # at the end, so a request that dies halfway still leaves behind the
        # queries that ran before it did - usually the interesting part.
        $directory = base_path('/analysis/queries');
        $file      = $directory . '/' . Analyze::id() . '.jsonl';

        if (!is_dir($directory)) @mkdir($directory, 0755, true);

        # Same ceiling as the request records. Checked only when starting a new
        # file, so a request already being written keeps its remaining queries
        # rather than ending up half recorded.
        $keep = (int) (\zFramework\Core\Facades\Config::framework('profiling.keep') ?? 200);
        if ($keep > 0 && !is_file($file) && count(glob("$directory/*.jsonl") ?: []) >= $keep) return;

        @file_put_contents($file, json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    private static function approxTableRows(\PDO $pdo, string $table): ?int
    {
        try {
            $stmt = $pdo->prepare("SELECT table_rows FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
            $stmt->execute([$table]);
            $rows = $stmt->fetchColumn();
            return $rows === false ? null : (int) $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The columns of this table the query filters, joins, groups and sorts on, in the
     * order an index can use them: equality first, a range stops the rest.
     */
    private static function indexableColumns(string $sql, string $table, array $aliases): array
    {
        $sql = preg_replace('/\s+/', ' ', str_replace('`', '', self::withoutStrings($sql)));

        $only = count(array_unique($aliases)) === 1;
        $mine = function (string $reference) use ($table, $aliases, $only): ?string {
            if (ctype_digit($reference) || in_array(strtoupper($reference), ['AND', 'OR', 'NOT', 'NULL', 'ON', 'WHERE'])) return null;

            $parts = explode('.', $reference);
            $name  = end($parts);
            if (count($parts) === 1) return $only ? $name : null;

            return ($aliases[strtolower($parts[count($parts) - 2])] ?? null) === $table ? $name : null;
        };

        $collect = function (string $pattern, string $text) use ($mine): array {
            preg_match_all($pattern, $text, $m);
            return array_values(array_filter(array_map($mine, $m[1])));
        };

        $where = preg_match('/\bWHERE (.+?)(?= (?:GROUP BY|ORDER BY|HAVING|LIMIT|UNION)\b|$)/i', $sql, $m) ? $m[1] : '';
        $equal = $collect('/(?<![:\w.])([\w.]+) ?(?:<=>|=|\bIN\b|\bIS\b)/i', $where);
        $range = $collect('/(?<![:\w.])([\w.]+) ?(?:>=|<=|>|<(?!>)|\bBETWEEN\b|\bLIKE\b)/i', $where);

        $joins = [];
        preg_match_all('/\bON (.+?)(?= (?:(?:INNER |LEFT |RIGHT |CROSS |OUTER |STRAIGHT_)*JOIN|WHERE|GROUP BY|ORDER BY|HAVING|LIMIT|UNION)\b|$)/i', $sql, $on);
        foreach ($on[1] as $condition) $joins = array_merge($joins, $collect('/(?<![:\w.])([\w]+\.[\w.]+)/', $condition));

        $listed = function (string $pattern) use ($sql, $mine): array {
            if (!preg_match($pattern, $sql, $m)) return [];
            $items = array_map(fn($item) => trim(preg_replace('/ (?:ASC|DESC)\s*$/i', '', trim($item))), explode(',', $m[1]));
            return array_values(array_filter(array_map(fn($item) => preg_match('/^[\w.]+$/', $item) ? $mine($item) : null, $items)));
        };

        $group = $listed('/\bGROUP BY (.+?)(?= (?:ORDER BY|HAVING|LIMIT|UNION)\b|$)/i');
        $order = $listed('/\bORDER BY (.+?)(?= (?:LIMIT|UNION)\b|$)/i');

        return array_values(array_unique(array_merge($equal, $joins, $range, $group, $order)));
    }

    /**
     * The same query shape, whatever its values, its placeholders' names and the
     * length of its IN lists. whereBetween() names its placeholders with uniqid().
     */
    public static function fingerprint(string $sql): string
    {
        $sql = self::withoutStrings($sql);
        $sql = preg_replace('/:[a-zA-Z_]\w*/', '?', $sql);
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        $sql = preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $sql);
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return md5(strtolower($sql));
    }

    /**
     * Quoted values as `?`, so that what a value says is not read as SQL.
     */
    private static function withoutStrings(string $sql): string
    {
        return preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"/s', '?', $sql);
    }
}
