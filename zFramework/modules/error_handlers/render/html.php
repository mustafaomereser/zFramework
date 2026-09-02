<?php

/**
 * The error page. Receives $report from Report::build() and returns HTML.
 *
 * Everything is inlined - stylesheet, script, fonts are the system's - so the
 * copy written under error_logs/ opens on its own months later, on a machine
 * with no network, exactly as it looked.
 */

use zFramework\modules\error_handlers\Highlighter;

return (function (array $report): string {
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    # Lines of a file, coloured once however many frames point into it.
    $highlighted = [];
    $lines = function (string $file) use (&$highlighted): ?array {
        if (!isset($highlighted[$file])) $highlighted[$file] = is_file($file) ? Highlighter::lines((string) file_get_contents($file)) : null;
        return $highlighted[$file];
    };

    $value = function ($v, int $depth = 0) use (&$value, $h): string {
        if ($v === null) return '<span class="val-null">null</span>';
        if (is_bool($v)) return '<span class="val-bool">' . ($v ? 'true' : 'false') . '</span>';
        if (is_int($v) || is_float($v)) return '<span class="val-num">' . $v . '</span>';
        if (is_string($v)) return $v === '••••••' ? '<span class="val-mask">••••••</span>' : '<span class="val-str">' . $h($v) . '</span>';
        if (is_array($v)) {
            if (!$v) return '<span class="empty">[]</span>';
            $rows = '';
            foreach ($v as $k => $x) $rows .= '<tr><th>' . $h($k) . '</th><td>' . $value($x, $depth + 1) . '</td></tr>';
            $summary = 'array(' . count($v) . ')';
            return '<details class="tree"' . ($depth < 1 ? ' open' : '') . '><summary>' . $summary . '</summary><table class="kv">' . $rows . '</table></details>';
        }
        return '<span class="val-str">' . $h(print_r($v, true)) . '</span>';
    };

    $table = function (array $data) use ($value, $h): string {
        if (!$data) return '<div class="empty" style="padding:8px 10px">empty</div>';
        $rows = '';
        foreach ($data as $k => $v) $rows .= '<tr><th>' . $h($k) . '</th><td>' . $value($v) . '</td></tr>';
        return '<table class="kv">' . $rows . '</table>';
    };

    $snippetOf = function (array $frame, int $chain) use ($lines, $h): string {
        $out  = '<div class="snippet" data-index="' . $frame['index'] . '">';
        $out .= '<div class="code-head"><div>';
        $out .= '<div class="file">' . $h($frame['file']) . '<span class="ln">:' . $frame['line'] . '</span></div>';
        if ($frame['function']) $out .= '<div class="fn">' . $h($frame['function']) . '()</div>';
        if ($frame['compiled']) $out .= '<div class="compiled">compiled line ' . $frame['compiled']['line'] . '</div>';
        $out .= '</div>';
        $out .= '<button class="btn ide" onclick="goIDE(' . $h(json_encode($frame['file'])) . ', ' . (int) $frame['line'] . ')">↗ Open in editor</button>';
        $out .= '</div>';

        if ($frame['args']) {
            $out .= '<div class="args"><div class="label">Arguments</div><table>';
            foreach ($frame['args'] as $name => $arg) {
                $shown = is_array($arg) ? json_encode($arg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : (is_string($arg) ? '"' . $arg . '"' : var_export($arg, true));
                if (mb_strlen((string) $shown) > 400) $shown = mb_substr((string) $shown, 0, 400) . '…';
                $out .= '<tr><td>' . $h($name) . '</td><td>' . $h($shown) . '</td></tr>';
            }
            $out .= '</table></div>';
        }

        $file  = $frame['file'];
        $line  = (int) $frame['line'];
        $rows  = isset($frame['snippet']) ? Highlighter::lines($frame['snippet']) : $lines($file);

        $out .= '<div class="code">';
        if ($rows === null) {
            $out .= '<div class="nofile">' . $h(basename($file)) . ' is not on this disk</div>';
        } else {
            $from = max(1, $line - 15);
            $to   = min(count($rows), $line + 15);
            for ($i = $from; $i <= $to; $i++) {
                $out .= '<div class="line' . ($i === $line ? ' err' : '') . '"><span class="n">' . $i . '</span><span class="c">' . ($rows[$i - 1] === '' ? ' ' : $rows[$i - 1]) . '</span></div>';
            }
        }
        $out .= '</div></div>';

        return $out;
    };

    # The first frame that is the application's own is opened by default - it is
    # almost always the one to read first. Failing that, the throw site.
    $defaultFrame = function (array $frames): int {
        foreach ($frames as $f) if (in_array($f['kind'], ['app', 'view'], true)) return $f['index'];
        return 0;
    };

    $req = $report['request'];
    $env = $report['env'];

    $suggestion = null;
    if ($report['code'] && is_file($file = dirname(__DIR__) . '/suggestions/' . $report['code'] . '.php')) {
        ob_start();
        include $file;
        $suggestion = ob_get_clean();
    }

    # A text rendering for the clipboard, produced once here rather than in JS.
    $asText = (include __DIR__ . '/text.php')($report, false);

    ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($report['class']) ?> — <?= $h(mb_strimwidth($report['message'], 0, 60, '…')) ?></title>
<style><?= file_get_contents(__DIR__ . '/error.css') ?></style>
</head>
<body data-theme="dark">
<div class="page">

    <div class="top">
        <div class="brand">
            <span class="logo"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>
            zFramework <span class="ver">v<?= $h($env['framework']) ?></span>
        </div>
        <div class="badges">
            <span class="badge">PHP <?= $h($env['php']) ?></span>
            <span class="badge"><?= $h($env['sapi']) ?><?= $env['worker'] ? ' · worker' : '' ?></span>
            <span class="badge"><?= $env['opcache'] ? 'opcache' : 'no opcache' ?></span>
            <span class="badge"><?= $h($env['elapsed']) ?> ms</span>
            <span class="badge"><?= round($env['memory'] / 1048576, 1) ?> MB peak</span>
            <span class="badge"><?= $h($env['time']) ?> <?= $h($env['timezone']) ?></span>
        </div>
    </div>

    <div class="banner">
        <div class="class">
            <span><?= $h($report['class']) ?></span>
            <?php if ($report['code']): ?><span class="code">code <?= $h($report['code']) ?></span><?php endif ?>
        </div>
        <div class="message"><?= $h($report['message']) ?></div>

        <?php if (count($report['chain']) > 1): ?>
            <div class="chain">
                <?php foreach ($report['chain'] as $i => $ex): ?>
                    <?php if ($i): ?><span class="arrow">← caused by</span><?php endif ?>
                    <button data-pick="<?= $i ?>" title="<?= $h($ex['message']) ?>"><?= $h($ex['class']) ?></button>
                <?php endforeach ?>
            </div>
        <?php endif ?>

        <?php if ($suggestion): ?>
            <div class="suggestion"><div class="label">Suggestion</div><?= $suggestion ?></div>
        <?php endif ?>

        <div class="meta">
            <span class="chip click" onclick="goIDE(<?= $h(json_encode(str_replace('\\', '/', $report['chain'][0]['frames'][0]['file']))) ?>, <?= (int) $report['chain'][0]['frames'][0]['line'] ?>)">
                <b><?= $h(basename($report['chain'][0]['frames'][0]['file'])) ?>:<?= (int) $report['chain'][0]['frames'][0]['line'] ?></b>
            </span>
            <span class="chip"><b><?= $h($req['method']) ?></b> <?= $h($req['url']) ?></span>
            <?php if ($report['route']): ?>
                <span class="chip"><span class="k">route</span> <b><?= $h($report['route']['name'] ?? '—') ?></b><?php if ($report['route']['handler']): ?> → <?= $h($report['route']['handler']) ?><?php endif ?></span>
            <?php endif ?>
            <?php if ($req['ip']): ?><span class="chip"><span class="k">ip</span> <?= $h($req['ip']) ?></span><?php endif ?>
            <?php if ($report['user']): ?><span class="chip"><span class="k">user</span> #<?= $h($report['user']['id'] ?? '?') ?><?= isset($report['user']['email']) ? ' ' . $h($report['user']['email']) : (isset($report['user']['username']) ? ' ' . $h($report['user']['username']) : '') ?></span><?php endif ?>
            <?php if (!$env['debug']): ?><span class="chip"><span class="k">saved copy</span> the visitor saw a plain 500</span><?php endif ?>
        </div>
    </div>

    <?php foreach ($report['chain'] as $ci => $ex): ?>
    <div class="split" data-chain="<?= $ci ?>"<?= $ci ? ' hidden' : '' ?>>
        <div class="panel">
            <div class="panel-head">
                <span>Stack <span class="count"><?= count($ex['frames']) ?> frames</span></span>
                <?php if ($ci === 0): ?><label class="switch"><input type="checkbox" id="hide-framework" checked> hide framework</label><?php endif ?>
            </div>
            <div class="frames">
                <?php $default = $defaultFrame($ex['frames']); foreach ($ex['frames'] as $f): ?>
                    <div class="frame <?= $f['kind'] ?><?= $f['index'] === $default ? ' default' : '' ?>" data-index="<?= $f['index'] ?>">
                        <span class="tag <?= $f['kind'] ?>"><?= strtoupper($f['kind']) ?></span>
                        <span class="where"><?= $h(basename($f['file'])) ?><span class="ln">:<?= $f['line'] ?></span></span>
                        <?php if ($f['function']): ?><span class="fn"><?= $h($f['function']) ?>()</span><?php endif ?>
                        <span class="path"><?= $h(dirname($f['file'])) ?></span>
                    </div>
                <?php endforeach ?>
                <div class="hidden-note">framework and vendor frames hidden — press f</div>
            </div>
        </div>
        <div class="panel">
            <?php foreach ($ex['frames'] as $f) echo $snippetOf($f, $ci) ?>
        </div>
    </div>
    <?php endforeach ?>

    <div class="tabs-wrap">
        <div class="tabs">
            <button class="active" data-tab="t-request">Request</button>
            <button data-tab="t-headers">Headers <span class="count"><?= count($req['headers']) ?></span></button>
            <button data-tab="t-state">Cookies & Session</button>
            <button data-tab="t-route">Route</button>
            <button data-tab="t-queries">Queries <span class="count"><?= count($report['queries']) ?></span></button>
            <button data-tab="t-server">Server</button>
            <button data-tab="t-previous">Previous reports <span class="count"><?= count($report['previous']) ?></span></button>
        </div>

        <div class="tab active" id="t-request">
            <div class="group"><h4>Query string</h4><?= $table($req['get']) ?></div>
            <div class="group"><h4>Body</h4><?= $table($req['post']) ?></div>
            <?php if ($req['files']): ?><div class="group"><h4>Files</h4><?= $table($req['files']) ?></div><?php endif ?>
        </div>
        <div class="tab" id="t-headers"><?= $table($req['headers']) ?></div>
        <div class="tab" id="t-state">
            <div class="group"><h4>Cookies <span style="color:var(--fg-4);font-weight:400;text-transform:none;letter-spacing:0">(names as stored; values are never shown)</span></h4><?= $table($req['cookies']) ?></div>
            <div class="group"><h4>Session</h4><?= $table($req['session']) ?></div>
            <?php if ($report['user']): ?><div class="group"><h4>User</h4><?= $table($report['user']) ?></div><?php endif ?>
        </div>
        <div class="tab" id="t-route">
            <?php if ($report['route']): ?>
                <?= $table(['name' => $report['route']['name'] ?? null, 'handler' => $report['route']['handler'], 'prefix' => $report['route']['prefix'], 'parameters' => $report['route']['parameters'], 'middlewares' => $report['route']['middlewares']]) ?>
            <?php else: ?>
                <div class="empty" style="padding:8px 10px">no route was matched before the error — it happened during boot, in a global middleware, or the url is not routed</div>
            <?php endif ?>
        </div>
        <div class="tab queries" id="t-queries">
            <?php if (!$report['queries']): ?>
                <div class="empty" style="padding:8px 10px">no queries ran<?= $env['debug'] ? '' : ' (recorded only with app.debug on)' ?></div>
            <?php else: foreach ($report['queries'] as $i => $q): ?>
                <div class="q<?= !empty($q['error']) ? ' failed' : '' ?>">
                    <div class="sql"><?= $h($q['sql']) ?></div>
                    <div class="meta"><span>#<?= $i + 1 ?></span><span><?= $h($q['db']) ?></span><span class="<?= $q['ms'] > 100 ? 'slow' : '' ?>"><?= $h($q['ms']) ?> ms</span><?php if ($q['bindings']): ?><span><?= $h(json_encode($q['bindings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></span><?php endif ?></div>
                    <?php if (!empty($q['error'])): ?><div class="fail"><?= $h($q['error']) ?></div><?php endif ?>
                </div>
            <?php endforeach; endif ?>
        </div>
        <div class="tab" id="t-server">
            <div class="group"><h4>Environment</h4><?= $table(['php' => $env['php'], 'framework' => $env['framework'], 'sapi' => $env['sapi'], 'worker' => $env['worker'], 'opcache' => $env['opcache'], 'memory peak' => round($env['memory'] / 1048576, 1) . ' MB', 'elapsed' => $env['elapsed'] . ' ms', 'debug' => $env['debug'], 'host' => $req['host'], 'scheme' => $req['scheme']]) ?></div>
            <div class="group"><h4>$_SERVER</h4><?= $table($req['server']) ?></div>
        </div>
        <div class="tab prev" id="t-previous">
            <?php if (!$report['previous']): ?><div class="empty" style="padding:8px 10px">nothing under error_logs/</div>
            <?php else: foreach ($report['previous'] as $p): ?>
                <a href="file:///<?= $h(str_replace('\\', '/', $p['file'])) ?>" target="_blank"><span class="t"><?= $h($p['time']) ?></span><span class="c"><?= $h($p['class'] ?? $p['name']) ?></span></a>
            <?php endforeach; endif ?>
        </div>
    </div>
</div>

<div class="controls">
    <button class="btn" id="copy" title="copy as text">⎘ Copy</button>
    <button class="btn" id="theme" title="theme (t)">
        <span id="ico-dark">☾</span><span id="ico-light" hidden>☀</span>
    </button>
    <select id="ide" title="editor for ↗ links">
        <option value="vscode">VS Code</option>
        <option value="cursor">Cursor</option>
        <option value="phpstorm">PhpStorm</option>
        <option value="idea">IntelliJ</option>
        <option value="sublime">Sublime</option>
    </select>
</div>
<div class="toast" id="toast"></div>
<pre id="as-text" hidden><?= $h($asText) ?></pre>

<script><?= file_get_contents(__DIR__ . '/error.js') ?></script>
</body>
</html>
<?php
    return ob_get_clean();
});
