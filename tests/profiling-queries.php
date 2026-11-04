<?php

/**
 * php terminal tests run profiling-queries
 *
 * The Queries tab of /profiling: what the analyzer recorded, grouped and suggested
 * back, escaped, and closed once debug is off. No database: the records are written
 * here in the analyzer's shape. tests/analyzer.php covers the shape itself.
 */

use modules\Profiling\Controllers\QueriesController;
use modules\Profiling\Queries;
use zFramework\Core\Facades\Config;

$directory = Queries::directory();
$had       = is_dir($directory);
$own       = $directory . '/zftest-' . uniqid() . '.jsonl';

Test::cleanup(function () use ($own, $directory, $had) {
    @unlink($own);
    if (!$had) {
        @rmdir($directory);
        @rmdir(dirname($directory));
    }
});

$record = fn(string $fingerprint, float $ms, array $extra = []) => $extra + [
    'fingerprint'       => $fingerprint,
    'query'             => "SELECT * FROM t_$fingerprint WHERE a = :a",
    'executed'          => "SELECT * FROM t_$fingerprint WHERE a = 'secret-value'",
    'url'               => 'GET /page',
    'query_time_ms'     => $ms,
    'tables'            => ["t_$fingerprint"],
    'used_indexes'      => [],
    'warnings'          => ["t_$fingerprint" => ['FULL_SCAN', "LARGE_TABLE_FULL_SCAN (~9000 rows)"]],
    'index_suggestions' => ["t_$fingerprint" => ['stable' => ["CREATE INDEX idx_{$fingerprint}_a ON t_$fingerprint (a)"]]],
    'row_stats'         => ["t_$fingerprint" => ['scanned' => 9000, 'returned' => 1, 'mode' => 'FULL_SCAN']],
];

test('grouped by shape, most total time first: many small runs above one slow one', function () use ($record) {
    $records = [$record('slow', 50)];
    for ($i = 0; $i < 30; $i++) $records[] = $record('often', 2, ['url' => $i < 20 ? 'GET /a' : 'GET /b']);

    $summary = Queries::summary($records);
    same(['often', 'slow'], array_column($summary, 'fingerprint'));
    same(30, $summary[0]['runs']);
    same(60.0, $summary[0]['total_ms']);
    same(2.0, $summary[0]['avg_ms']);
    same(['GET /a' => 20, 'GET /b' => 10], $summary[0]['urls']);
    same(9000, $summary[0]['scanned']);
    same(1, $summary[0]['returned']);
});

test('an index is suggested once, with all it would help; a small table waits to be asked', function () use ($record) {
    $shared = ['index_suggestions' => ['t' => ['stable' => ['CREATE INDEX i ON t (a)']]]];
    $small  = ['warnings' => ['t_tiny' => ['FULL_SCAN', 'SMALL_TABLE:t_tiny(~50 rows)']]];

    $summary = Queries::summary([$record('one', 10, $shared), $record('two', 5, $shared), $record('tiny', 90, $small)]);
    $list    = Queries::suggestions($summary);

    same(['CREATE INDEX i ON t (a)'], array_column($list, 'statement'));
    same(2, $list[0]['queries']);
    same(15.0, $list[0]['total_ms']);
    same(2, count(Queries::suggestions($summary, true)));
});

test('a record from before url existed, or with no measurement, still groups', function () {
    $summary = Queries::summary([['fingerprint' => 'old', 'query' => 'SELECT 1', 'query_time_ms' => 1, 'tables' => [], 'used_indexes' => []]]);

    same(['unknown' => 1], $summary[0]['urls']);
    same(null, $summary[0]['returned']);
    same(0, $summary[0]['scanned']);
});

test('the page shows the statement, escaped, and never its values', function () use ($record, $own, $directory) {
    @mkdir($directory, 0755, true);
    file_put_contents($own, json_encode($record('page', 3, ['url' => 'GET /<script>alert(1)</script>', 'query' => "SELECT '<img src=x>' FROM t_page"])) . PHP_EOL);

    $html = (string) (new QueriesController)->index();

    contains('t_page', $html);
    contains('CREATE INDEX idx_page_a ON t_page (a)', $html);
    falsy(str_contains($html, '<script>alert'), 'a url is whatever the client sent');
    falsy(str_contains($html, '<img src=x>'));
    falsy(str_contains($html, 'secret-value'), 'bound values stay in the store');
});

test('with debug off nothing is read out and nothing is cleared', function () use ($record, $own, $directory) {
    @mkdir($directory, 0755, true);
    file_put_contents($own, json_encode($record('page', 3)) . PHP_EOL);

    $cache = new \ReflectionProperty(Config::class, 'debugCache');
    $cache->setAccessible(true);
    Config::debug();
    $saved = $cache->getValue();
    $cache->setValue(null, false);

    try {
        falsy(Config::debug(), 'the switch this test relies on');
        $html = (string) (new QueriesController)->index();
        try {
            (new QueriesController)->clear();
        } catch (\Throwable) {
            # redirect() signals through an exception
        }
    } finally {
        $cache->setValue(null, $saved);
    }

    falsy(str_contains($html, 't_page'), $html);
    contains('needs', $html);
    truthy(is_file($own), 'the records are still there');
});

test('clear empties the store, only when it holds nothing of anybody else', function () use ($record, $own, $directory) {
    @mkdir($directory, 0755, true);
    file_put_contents($own, json_encode($record('page', 3)) . PHP_EOL);

    if (count(glob("$directory/*.jsonl")) > 1) skip('analysis/queries holds real records, not clearing them');
    if (Queries::store() !== 'file') skip('queryStore is table here');

    same(1, Queries::clear());
    same([], glob("$directory/*.jsonl"));
});
