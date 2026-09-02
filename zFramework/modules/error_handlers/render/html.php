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

    # Paths are shown relative to the project: the absolute prefix is the same on
    # every line and says nothing.
    $base = defined('BASE_PATH') ? str_replace('\\', '/', BASE_PATH) . '/' : null;
    $rel  = fn(string $path) => $base && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

    # Lines of a file, coloured once however many frames point into it.
    $highlighted = [];
    $lines = function (string $file) use (&$highlighted): ?array {
        if (!isset($highlighted[$file])) $highlighted[$file] = is_file($file) ? Highlighter::lines((string) file_get_contents($file)) : null;
        return $highlighted[$file];
    };

    # A value as a tree: scalars typed by colour, arrays as a table that folds.
    $value = function ($v, int $depth = 0) use (&$value, $h): string {
        if ($v === null) return '<span class="val-null">null</span>';
        if (is_bool($v)) return '<span class="val-bool">' . ($v ? 'true' : 'false') . '</span>';
        if (is_int($v) || is_float($v)) return '<span class="val-num">' . $v . '</span>';
        if (is_string($v)) return $v === '••••••' ? '<span class="val-mask">••••••</span>' : ($v === '' ? '<span class="empty">""</span>' : '<span class="val-str">' . $h($v) . '</span>');
        if (is_array($v)) {
            if (!$v) return '<span class="empty">[]</span>';
            $rows = '';
            foreach ($v as $k => $x) $rows .= '<tr><th>' . $h($k) . '</th><td>' . $value($x, $depth + 1) . '</td></tr>';
            return '<details class="tree"' . ($depth < 1 ? ' open' : '') . '><summary>array(' . count($v) . ')</summary><table class="kv">' . $rows . '</table></details>';
        }
        return '<span class="val-str">' . $h(print_r($v, true)) . '</span>';
    };

    $table = function (array|string $data) use ($value, $h): string {
        if (is_string($data)) return '<div class="empty">' . $h($data) . '</div>';
        if (!$data) return '<div class="empty">empty</div>';
        $rows = '';
        foreach ($data as $k => $v) $rows .= '<tr><th>' . $h($k) . '</th><td>' . $value($v) . '</td></tr>';
        return '<table class="kv">' . $rows . '</table>';
    };

    $section = fn(string $title, string $body) => '<section class="group"><h4>' . $h($title) . '</h4>' . $body . '</section>';

    $snippetOf = function (array $frame) use ($lines, $h, $rel, $value): string {
        $file = $frame['file'];
        $line = (int) $frame['line'];

        $out  = '<div class="snippet" data-index="' . $frame['index'] . '">';
        $out .= '<div class="code-head">';
        $out .= '<div class="code-title"><span class="path">' . $h($rel($file)) . '</span><span class="ln">:' . $line . '</span>';
        if ($frame['compiled']) $out .= '<span class="note">compiled line ' . $frame['compiled']['line'] . '</span>';
        $out .= '</div>';
        if ($frame['function']) $out .= '<div class="code-fn">' . $h($frame['function']) . '()' . ($frame['via'] ? ' <span class="dim">threw from ' . $h($frame['via']) . '()</span>' : '') . '</div>';
        $out .= '<button class="btn ide" onclick="goIDE(' . $h(json_encode($file)) . ', ' . $line . ')">Open in editor</button>';
        $out .= '</div>';

        if ($frame['args']) {
            $out .= '<div class="args"><div class="args-title">Arguments of ' . $h($frame['function'] ?? '') . '()</div><table class="kv">';
            foreach ($frame['args'] as $name => $arg) $out .= '<tr><th>' . $h($name) . '</th><td class="type">' . $h($arg['type']) . '</td><td>' . $value($arg['value'], 0) . '</td></tr>';
            $out .= '</table></div>';
        }

        $rows = isset($frame['snippet']) ? Highlighter::lines($frame['snippet']) : $lines($file);

        $out .= '<div class="code">';
        if ($rows === null) {
            $out .= '<div class="nofile">' . $h(basename($file)) . ' is not on this disk</div>';
        } else {
            $from = max(1, $line - 12);
            $to   = min(count($rows), $line + 12);
            for ($i = $from; $i <= $to; $i++) {
                $out .= '<div class="line' . ($i === $line ? ' err' : '') . '"><span class="n">' . $i . '</span><span class="c">' . ($rows[$i - 1] === '' ? ' ' : $rows[$i - 1]) . '</span></div>';
            }
        }
        $out .= '</div>';


        return $out . '</div>';
    };

    # The first frame that is the application's own opens by default - it is
    # almost always the one to read first. Failing that, the throw site.
    $defaultFrame = function (array $frames): int {
        foreach ($frames as $f) if (in_array($f['kind'], ['app', 'view'], true)) return $f['index'];
        return 0;
    };

    $req   = $report['request'];
    $env   = $report['env'];
    $first = $report['chain'][0]['frames'][0];

    $suggestion = null;
    if ($report['code'] && is_file($file = dirname(__DIR__) . '/suggestions/' . $report['code'] . '.php')) {
        ob_start();
        include $file;
        $suggestion = ob_get_clean();
    }

    # A text rendering for the clipboard, produced once here rather than in JS.
    $asText = (include __DIR__ . '/text.php')($report, false);

    # A one-line summary ahead of the markup, for the listing of earlier reports:
    # class, message and url without parsing a page. `--` cannot appear inside an
    # HTML comment, so it is stripped from the message here.
    $summary = json_encode([
        'class'   => $report['class'],
        'message' => str_replace('--', '- -', mb_strimwidth($report['message'], 0, 300, '…')),
        'url'     => str_replace('--', '- -', $req['method'] . ' ' . mb_strimwidth($req['url'], 0, 200, '…')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    ob_start();
    echo '<!--zf:' . $summary . "-->\n";
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

    <header class="top">
        <div class="brand"><span class="logo"></span>zFramework <span class="ver"><?= $h($env['framework']) ?></span></div>
        <div class="when">PHP <?= $h($env['php']) ?> · <?= $h($env['sapi']) ?><?= $env['worker'] ? ' worker' : '' ?> · <?= $env['opcache'] ? 'opcache' : 'no opcache' ?> · <?= $h($env['elapsed']) ?> ms · <?= round($env['memory'] / 1048576, 1) ?> MB · <?= $h($env['time']) ?><?= $env['debug'] ? '' : ' · saved copy — the visitor saw a plain 500' ?></div>
    </header>

    <section class="banner">
        <div class="kind">
            <button class="class active" data-pick="0"><?= $h($report['class']) ?></button>
            <?php if ($report['code']): ?><span class="errcode"><?= $h($report['code']) ?></span><?php endif ?>
            <?php if (count($report['chain']) > 1): ?>
                <span class="chain">
                    <?php foreach ($report['chain'] as $i => $ex): if (!$i) continue; ?>
                        <span class="arrow">caused by</span><button data-pick="<?= $i ?>" title="<?= $h($ex['message']) ?>"><?= $h($ex['class']) ?></button>
                    <?php endforeach ?>
                </span>
            <?php endif ?>
        </div>
        <h1 class="message"><?= $h($report['message']) ?></h1>

        <div class="context">
            <span class="ctx click" onclick="goIDE(<?= $h(json_encode($first['file'])) ?>, <?= (int) $first['line'] ?>)"><?= $h($rel($first['file'])) ?><b>:<?= (int) $first['line'] ?></b></span>
            <span class="ctx"><b><?= $h($req['method']) ?></b> <?= $h($req['url']) ?></span>
            <?php if ($report['route'] && ($report['route']['name'] || $report['route']['handler'])): ?>
                <span class="ctx"><?= $h($report['route']['name'] ?? 'route') ?><?php if ($report['route']['handler']): ?> <span class="dim">→</span> <?= $h($report['route']['handler']) ?><?php endif ?></span>
            <?php endif ?>
            <?php if ($req['ip']): ?><span class="ctx"><span class="dim">ip</span> <?= $h($req['ip']) ?></span><?php endif ?>
            <?php if (is_array($report['user'])): ?>
                <span class="ctx"><span class="dim">user</span> #<?= $h($report['user']['id'] ?? '?') ?><?= isset($report['user']['email']) ? ' ' . $h($report['user']['email']) : (isset($report['user']['username']) ? ' ' . $h($report['user']['username']) : '') ?></span>
            <?php endif ?>
        </div>

        <?php if ($suggestion): ?>
            <div class="suggestion"><?= $suggestion ?></div>
        <?php endif ?>
    </section>

    <?php foreach ($report['chain'] as $ci => $ex): ?>
    <section class="split" data-chain="<?= $ci ?>"<?= $ci ? ' hidden' : '' ?>>
        <div class="panel frames-panel">
            <div class="panel-head">
                <span><?= $ci ? $h($ex['class']) : 'Stack' ?> <span class="count"><?= count($ex['frames']) ?></span></span>
                <?php if ($ci === 0): ?><label class="switch"><input type="checkbox" id="hide-framework" checked> app only</label><?php endif ?>
            </div>
            <div class="frames">
                <?php $default = $defaultFrame($ex['frames']); foreach ($ex['frames'] as $f): ?>
                    <div class="frame <?= $f['kind'] ?><?= $f['index'] === $default ? ' default' : '' ?>" data-index="<?= $f['index'] ?>" title="<?= $h($rel($f['file'])) ?>">
                        <span class="where"><?= $h(basename($f['file'])) ?><span class="ln">:<?= $f['line'] ?></span></span>
                        <span class="tag <?= $f['kind'] ?>"><?= $f['kind'] ?></span>
                        <span class="fn"><?= $f['function'] ? $h($f['function']) . '()' : '<span class="dim">' . $h(dirname($rel($f['file']))) . '</span>' ?></span>
                    </div>
                <?php endforeach ?>
                <div class="hidden-note">framework frames hidden</div>
            </div>
        </div>
        <div class="panel code-panel">
            <?php foreach ($ex['frames'] as $f) echo $snippetOf($f) ?>
        </div>
    </section>
    <?php endforeach ?>

    <section class="tabs-wrap">
        <nav class="tabs">
            <button class="active" data-tab="t-request">Request</button>
            <button data-tab="t-args">Arguments</button>
            <button data-tab="t-user">User</button>
            <button data-tab="t-route">Route</button>
            <button data-tab="t-queries">Queries<?php if ($report['queries']): ?> <span class="count"><?= count($report['queries']) ?></span><?php endif ?></button>
            <button data-tab="t-env">Environment</button>
            <button data-tab="t-previous">Earlier reports<?php if ($report['previous']): ?> <span class="count"><?= count($report['previous']) ?></span><?php endif ?></button>
        </nav>

        <div class="tab active" id="t-request">
            <div class="cols">
                <div>
                    <?= $section('Query string', $table($req['get'])) ?>
                    <?= $section('Body', $table($req['post'])) ?>
                    <?php if ($req['files']) echo $section('Files', $table($req['files'])) ?>
                    <?= $section('Cookies', $table($req['cookies'])) ?>
                    <?= $section('Session', $table($req['session'])) ?>
                </div>
                <div>
                    <?= $section('Headers', $table(['Method' => $req['method'], 'URL' => $req['url'], 'IP' => $req['ip']] + $req['headers'])) ?>
                </div>
            </div>
        </div>

        <div class="tab argsall" id="t-args">
            <?php
            # Every call on the way to the error, with what it was handed - grouped by
            # the part of the system it belongs to, so "what did the database see" or
            # "what did the controller get" is one glance rather than a walk of the stack.
            $areas = [];
            foreach ($report['chain'] as $ci => $ex) foreach ($ex['frames'] as $f) if ($f['function'] && $f['args']) $areas[$f['area'] ?? 'Other'][] = [$ci, $f];
            $order = ['Application', 'View', 'Database', 'Validation', 'Auth & Session', 'Mail & Queue', 'Routing', 'Framework', 'Vendor', 'PHP'];
            uksort($areas, function ($a, $b) use ($order) {
                $ia = array_search($a, $order, true); $ib = array_search($b, $order, true);
                if (str_starts_with($a, 'Module:')) $ia = 0.5; if (str_starts_with($b, 'Module:')) $ib = 0.5;
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            });
            ?>
            <?php if (!$areas): ?><div class="empty">no calls with arguments</div><?php endif ?>
            <?php foreach ($areas as $area => $calls): ?>
                <section class="group">
                    <h4><?= $h($area) ?> <span class="dim"><?= count($calls) ?></span></h4>
                    <?php foreach ($calls as [$ci, $f]): ?>
                        <div class="call">
                            <div class="call-head">
                                <span class="call-fn"><?= $h($f['function']) ?>()</span>
                                <span class="call-at"><?= $h($rel($f['file'])) ?>:<?= $f['line'] ?><?= $ci ? ' · caused by #' . $ci : '' ?></span>
                            </div>
                            <table class="kv">
                                <?php foreach ($f['args'] as $name => $arg): ?>
                                    <tr><th><?= $h($name) ?></th><td class="type"><?= $h($arg['type']) ?></td><td><?= $value($arg['value'], 0) ?></td></tr>
                                <?php endforeach ?>
                            </table>
                        </div>
                    <?php endforeach ?>
                </section>
            <?php endforeach ?>
        </div>

        <div class="tab" id="t-user"><?= $table($report['user']) ?></div>

        <div class="tab" id="t-route">
            <?php if ($report['route']): ?>
                <?= $table(['name' => $report['route']['name'] ?? null, 'handler' => $report['route']['handler'], 'prefix' => $report['route']['prefix'], 'parameters' => $report['route']['parameters'], 'middlewares' => $report['route']['middlewares']]) ?>
            <?php else: ?>
                <div class="empty">no route was matched before the error — it happened during boot, in a global middleware, or the url is not routed</div>
            <?php endif ?>
        </div>

        <div class="tab queries" id="t-queries">
            <?php if (!$report['queries']): ?>
                <div class="empty">no queries ran<?= $env['debug'] ? '' : ' (recorded only with app.debug on)' ?></div>
            <?php else: foreach ($report['queries'] as $i => $q): ?>
                <div class="q<?= !empty($q['error']) ? ' failed' : '' ?>">
                    <div class="q-head"><span class="q-n">#<?= $i + 1 ?></span><span class="q-db"><?= $h($q['db']) ?></span><span class="q-ms<?= $q['ms'] > 100 ? ' slow' : '' ?>"><?= $h($q['ms']) ?> ms</span></div>
                    <div class="sql"><?= $h($q['sql']) ?></div>
                    <?php if ($q['bindings']): ?><div class="bind"><?= $h(json_encode($q['bindings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div><?php endif ?>
                    <?php if (!empty($q['error'])): ?><div class="fail"><?= $h($q['error']) ?></div><?php endif ?>
                </div>
            <?php endforeach; endif ?>
        </div>

        <div class="tab" id="t-env">
            <div class="cols">
                <div><?= $section('Runtime', $table(['php' => $env['php'], 'framework' => $env['framework'], 'sapi' => $env['sapi'] . ($env['worker'] ? ' (worker)' : ''), 'opcache' => $env['opcache'], 'debug' => $env['debug'], 'memory peak' => round($env['memory'] / 1048576, 1) . ' MB', 'elapsed' => $env['elapsed'] . ' ms', 'time' => $env['time'] . ' ' . $env['timezone'], 'host' => $req['host'], 'scheme' => $req['scheme']])) ?></div>
                <div><?= $section('$_SERVER', $table($req['server'])) ?></div>
            </div>
        </div>

        <div class="tab prev" id="t-previous">
            <?php if (!$report['previous']): ?><div class="empty">nothing under error_logs/</div>
            <?php else: foreach ($report['previous'] as $p): ?>
                <a href="file:///<?= $h(str_replace('\\', '/', $p['file'])) ?>" target="_blank">
                    <span class="t"><?= $h($p['time']) ?></span>
                    <span class="c"><?= $h($p['class'] ?? $p['name']) ?></span>
                    <span class="m"><?= $h($p['message'] ?? '') ?></span>
                    <span class="u"><?= $h($p['url'] ?? '') ?></span>
                </a>
            <?php endforeach; endif ?>
        </div>
    </section>
</div>

<div class="controls">
    <button class="btn" id="copy" title="copy as text">Copy</button>
    <button class="btn" id="theme" title="theme (t)"><span id="ico-dark">Dark</span><span id="ico-light" hidden>Light</span></button>
    <select id="ide" title="editor for the open links">
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
