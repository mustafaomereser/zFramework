<?php

/**
 * The report as plain text - for a terminal, and for the clipboard on the page.
 *
 * @param array $report
 * @param bool  $colour ANSI colours, for a terminal that shows them.
 */
return function (array $report, bool $colour = false): string {
    $c = fn(string $code, string $text) => $colour ? "\033[{$code}m{$text}\033[0m" : $text;

    $out   = [];
    $req   = $report['request'];
    $env   = $report['env'];

    foreach ($report['chain'] as $i => $ex) {
        $out[] = ($i ? $c('90', 'caused by ') : '') . $c('1;31', $ex['class']) . ($ex['code'] ? $c('90', " [{$ex['code']}]") : '');
        $out[] = '  ' . $ex['message'];
        $out[] = '';

        foreach ($ex['frames'] as $f) {
            $tag   = str_pad(strtoupper($f['kind']), 9);
            $where = $f['file'] . ':' . $f['line'];
            $out[] = sprintf('  %s %s %s', $c($f['kind'] === 'app' || $f['kind'] === 'view' ? '32' : '90', $tag), $c($f['kind'] === 'app' || $f['kind'] === 'view' ? '0' : '90', $where), $f['function'] ? $c('36', $f['function'] . '()') : '');
        }
        $out[] = '';
    }

    $out[] = $c('1', 'Request');
    $out[] = '  ' . $req['method'] . ' ' . $req['url'];
    if ($report['route']) $out[] = '  route: ' . ($report['route']['name'] ?? '—') . ($report['route']['handler'] ? ' → ' . $report['route']['handler'] : '');
    if ($req['ip']) $out[] = '  ip: ' . $req['ip'];
    if ($report['user']) $out[] = '  user: ' . json_encode($report['user'], JSON_UNESCAPED_UNICODE);
    if ($req['get']) $out[] = '  query: ' . json_encode($req['get'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($req['post']) $out[] = '  body: ' . json_encode($req['post'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $out[] = '';

    if ($report['queries']) {
        $out[] = $c('1', 'Queries') . $c('90', ' (' . count($report['queries']) . ')');
        foreach ($report['queries'] as $n => $q) {
            $out[] = sprintf('  %2d. %s  %s', $n + 1, $c(!empty($q['error']) ? '31' : '0', preg_replace('/\s+/', ' ', $q['sql'])), $c('90', $q['ms'] . ' ms' . ($q['bindings'] ? ' ' . json_encode($q['bindings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')));
            if (!empty($q['error'])) $out[] = '      ' . $c('31', $q['error']);
        }
        $out[] = '';
    }

    $out[] = $c('1', 'Environment');
    $out[] = sprintf('  PHP %s · zFramework %s · %s%s · %s · %s ms · %s MB', $env['php'], $env['framework'], $env['sapi'], $env['worker'] ? ' (worker)' : '', $env['opcache'] ? 'opcache' : 'no opcache', $env['elapsed'], round($env['memory'] / 1048576, 1));
    $out[] = '  ' . $env['time'] . ' ' . $env['timezone'];

    return implode("\n", $out) . "\n";
};
