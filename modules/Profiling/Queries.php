<?php

namespace modules\Profiling;

use zFramework\Core\Facades\Config;
use zFramework\Core\Facades\DB;

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
                'connection'  => $runs[0]['connection'] ?? null,
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
     * Each index worth creating once, with what it would help. One that is the start
     * of another goes: the longer index serves its queries too. One the table already
     * has goes, so what has been run stops being offered.
     *
     * @param array $summary
     * @param bool $small Advice for small tables too. A development database is full
     *                    of them, and an index on 50 rows helps nobody.
     * @return array
     */
    public static function suggestions(array $summary, bool $small = false): array
    {
        $suggestions = [];
        foreach ($summary as $query) foreach ($query['suggestions'] as $statement) {
            $parsed = self::parse($statement);
            if (!$parsed) continue;

            $suggestions[$statement] ??= $parsed + ['statement' => $statement, 'connection' => $query['connection'] ?? null, 'queries' => 0, 'runs' => 0, 'total_ms' => 0, 'small' => true];
            $suggestions[$statement]['queries']++;
            $suggestions[$statement]['runs'] += $query['runs'];
            $suggestions[$statement]['total_ms'] = round($suggestions[$statement]['total_ms'] + $query['total_ms'], 2);
            if (!$query['small']) $suggestions[$statement]['small'] = false;
        }

        foreach ($suggestions as $statement => $suggestion) {
            foreach ($suggestions as $other) if ($other['statement'] !== $statement && $other['table'] === $suggestion['table'] && self::startsWith($other['columns'], $suggestion['columns'])) {
                unset($suggestions[$statement]);
                continue 2;
            }

            foreach (self::existingIndexes($suggestion['connection'], $suggestion['table']) as $columns) if (self::startsWith($columns, $suggestion['columns'])) {
                unset($suggestions[$statement]);
                continue 2;
            }
        }

        if (!$small) $suggestions = array_filter($suggestions, fn($suggestion) => !$suggestion['small']);
        usort($suggestions, fn($a, $b) => $b['total_ms'] <=> $a['total_ms']);

        return $suggestions;
    }

    /**
     * @param string $statement CREATE INDEX name ON table (a, b)
     * @return ?array name, table, columns
     */
    private static function parse(string $statement): ?array
    {
        if (!preg_match('/^CREATE INDEX (\w+) ON (\w+) \(([\w, ]+)\)$/', $statement, $m)) return null;

        return ['name' => $m[1], 'table' => $m[2], 'columns' => array_map('trim', explode(',', $m[3]))];
    }

    private static function startsWith(array $columns, array $start): bool
    {
        return array_map('strtolower', array_slice($columns, 0, count($start))) === array_map('strtolower', $start);
    }

    /**
     * The table's indexes, each as its columns in order.
     */
    private static function existingIndexes(?string $connection, string $table): array
    {
        if (!$connection || !isset($GLOBALS['databases']['connections'][$connection])) return [];

        try {
            $statement = (new DB($connection))->connection()->prepare('SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
            $statement->execute([$table]);
        } catch (\Throwable) {
            return [];
        }

        $indexes = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) $indexes[$row['INDEX_NAME']][] = $row['COLUMN_NAME'];

        return array_values($indexes);
    }

    /**
     * Act on chosen suggestions, each on its own: one that fails leaves the others.
     *
     * Only what the records suggest right now is accepted, whatever arrives. This is
     * a page without a login and the statements came through a form.
     *
     * @param array $statements
     * @param string $how migration: declare it, the next db migrate creates it.
     *                    now: create it. A table with a migration loses it at its next
     *                    db migrate unless it is declared too.
     * @return array statement, ok, message
     */
    public static function apply(array $statements, string $how): array
    {
        $offered = array_column(self::suggestions(self::summary(self::all()), true), null, 'statement');

        $results = [];
        foreach ($statements as $statement) {
            $statement  = (string) $statement;
            $suggestion = $offered[$statement] ?? null;

            if (!$suggestion) {
                $results[] = ['statement' => $statement, 'ok' => false, 'message' => 'not a current suggestion, not touched'];
                continue;
            }

            if (!$suggestion['connection'] || !isset($GLOBALS['databases']['connections'][$suggestion['connection']])) {
                $results[] = ['statement' => $statement, 'ok' => false, 'message' => 'recorded without its connection: clear and record again'];
                continue;
            }

            try {
                $results[] = ['statement' => $statement, 'ok' => true, 'message' => $how === 'now' ? self::runNow($suggestion) : self::declare($suggestion)];
            } catch (\Throwable $e) {
                $results[] = ['statement' => $statement, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private static function runNow(array $suggestion): string
    {
        $quote = fn(string $identifier) => '`' . str_replace('`', '``', $identifier) . '`';

        (new DB($suggestion['connection']))->connection()->exec('CREATE INDEX ' . $quote($suggestion['name']) . ' ON ' . $quote($suggestion['table']) . ' (' . implode(', ', array_map($quote, $suggestion['columns'])) . ')');

        return self::migrationOf($suggestion['connection'], $suggestion['table'])
            ? 'created. Its table has a migration: the next db migrate of it drops this unless it is written there too'
            : 'created';
    }

    private static function declare(array $suggestion): string
    {
        $file = self::migrationOf($suggestion['connection'], $suggestion['table']);
        if (!$file) throw new \RuntimeException('no migration declares this table, nothing to write to');

        $source = file_get_contents($file);
        $tokens = \PhpToken::tokenize($source);

        # What migrate makes of it: index:name becomes idx_name.
        $name = preg_replace('/^idx_/', '', $suggestion['name']);

        $declared = array_map('strtolower', self::declaredColumns($tokens));
        foreach ($suggestion['columns'] as $column) if (!in_array(strtolower($column), $declared)) throw new \RuntimeException("this migration has no column `$column`");

        $eol   = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $entry = var_export($name, true) . ' => [' . implode(', ', array_map(fn($column) => var_export($column, true), $suggestion['columns'])) . '],';
        $open  = self::valueOf($tokens, '$suggested_indexes');

        if ($open !== null) {
            foreach (array_slice($tokens, $open) as $token) if ($token->is(T_CONSTANT_ENCAPSED_STRING) && trim($token->text, "'\"") === $name) return basename($file) . ' already declares it. php terminal db migrate creates it';
            if (!in_array($tokens[$open]->text, ['[', 'array'])) throw new \RuntimeException('could not read $suggested_indexes in ' . basename($file));

            # Past the array( of the long form, which is two tokens.
            $at = $tokens[$open]->text === '[' ? $tokens[$open]->pos + 1 : strpos($source, '(', $tokens[$open]->pos) + 1;
            $added = substr($source, 0, $at) . $eol . '        ' . $entry . substr($source, $at);
        } else {
            $value = self::valueOf($tokens, '$table');
            $end   = strpos($source, ';', $tokens[$value]->pos) + 1;
            $start = strrpos(substr($source, 0, $end), "\n");
            $line  = substr($source, $start === false ? 0 : $start + 1);
            $tab   = preg_match('/^[ \t]*/', $line, $m) ? $m[0] : '    ';

            $added = substr($source, 0, $end) . $eol . $eol
                . $tab . '# added from profiling suggestions' . $eol
                . $tab . 'static $suggested_indexes = [' . $eol
                . $tab . '    ' . $entry . $eol
                . $tab . '];' . substr($source, $end);
        }

        # Parsed before it is written: a migration this broke would take db migrate with it.
        try {
            token_get_all($added, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \RuntimeException(basename($file) . ' would not parse, not written: ' . $e->getMessage());
        }

        if (file_put_contents($file, $added, LOCK_EX) === false) throw new \RuntimeException('could not write ' . basename($file));

        return 'written to ' . basename($file) . '. php terminal db migrate creates it';
    }

    /**
     * The columns of columns(), as the table will have them.
     */
    private static function declaredColumns(array $tokens): array
    {
        $prefixAt = self::valueOf($tokens, '$prefix');
        $prefix   = $prefixAt !== null && $tokens[$prefixAt]->is(T_CONSTANT_ENCAPSED_STRING) ? trim($tokens[$prefixAt]->text, "'\"") : '';
        $prefix   = $prefix === '' ? '' : $prefix . '_';

        $inside = false;
        $columns = $shorthands = [];
        foreach ($tokens as $key => $token) {
            if ($token->is(T_FUNCTION)) {
                $next = self::nextSolid($tokens, $key);
                $inside = $next !== null && strtolower($tokens[$next]->text) === 'columns';
                continue;
            }
            if (!$inside || !$token->is(T_CONSTANT_ENCAPSED_STRING)) continue;

            $text = trim($token->text, "'\"");
            $next = self::nextSolid($tokens, $key);
            if ($next !== null && $tokens[$next]->is(T_DOUBLE_ARROW)) $columns[] = $prefix . $text;
            elseif (in_array($text, ['timestamps', 'softDelete'])) $shorthands[] = $text;
        }

        $consts = config('model.consts');
        if (in_array('timestamps', $shorthands)) array_push($columns, $consts['updated_at'], $consts['created_at']);
        if (in_array('softDelete', $shorthands)) $columns[] = $consts['deleted_at'];

        return $columns;
    }

    /**
     * The file declaring a table on a connection. Read, not loaded: this runs in a
     * request, and a migration class has no business being autoloaded by one. Tokens,
     * so a commented-out line is the comment it is.
     */
    private static function migrationOf(string $connection, string $table): ?string
    {
        $folders = [base_path('/database/migrations')];
        foreach (glob(base_path('/modules/*/migrations')) ?: [] as $folder) $folders[] = $folder;

        foreach ($folders as $folder) {
            if (!is_dir($folder)) continue;

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;

                $tokens = \PhpToken::tokenize((string) file_get_contents($file->getPathname()));
                $at     = self::valueOf($tokens, '$table');
                if ($at === null || !$tokens[$at]->is(T_CONSTANT_ENCAPSED_STRING) || strcasecmp(trim($tokens[$at]->text, "'\""), $table) !== 0) continue;

                $db = self::valueOf($tokens, '$db');
                if ($db !== null && $tokens[$db]->is(T_CONSTANT_ENCAPSED_STRING) && trim($tokens[$db]->text, "'\"") !== $connection) continue;

                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * Where the value of `static $name = ...` starts, as a token key.
     */
    private static function valueOf(array $tokens, string $name): ?int
    {
        foreach ($tokens as $key => $token) {
            if (!$token->is(T_VARIABLE) || $token->text !== $name) continue;

            $equals = self::nextSolid($tokens, $key);
            if ($equals === null || $tokens[$equals]->text !== '=') continue;

            return self::nextSolid($tokens, $equals);
        }

        return null;
    }

    private static function nextSolid(array $tokens, int $key): ?int
    {
        for ($i = $key + 1; $i < count($tokens); $i++) if (!$tokens[$i]->isIgnorable()) return $i;

        return null;
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
