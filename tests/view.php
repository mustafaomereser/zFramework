<?php

/**
 * php terminal tests run view
 *
 * The template engine in isolation: its own view/cache directories under
 * storage, its own settings - the application's views are never touched.
 * Settings are restored in cleanup so nothing leaks into other tests.
 */

use zFramework\Core\View;

$tmp = FRAMEWORK_PATH . '/storage/zf_test_views';
foreach (["$tmp/views/t", "$tmp/cache"] as $dir) if (!is_dir($dir)) mkdir($dir, 0777, true);

$oldConfig = View::$config ?? null;
Test::cleanup(function () use ($tmp, $oldConfig) {
    rrmdir($tmp);
    if ($oldConfig !== null) View::setSettings($oldConfig);
});

View::setSettings(['dir' => "$tmp/views", 'caches' => "$tmp/cache", 'suffix' => '', 'caching' => true, 'minify' => true]);
View::directive('pages', fn($x) => '<?php if (true): ?>');

$write = fn(string $name, string $body) => file_put_contents("$tmp/views/t/$name.php", $body);

test('layout, sections and nested view() keep their own context', function () use ($write) {
    $write('main', "<html><title>@yield('title')</title><body>@yield('body')</body></html>");
    $write('card', "<h2>@yield('title', 'Card default')</h2>");
    $write('page', "@extends('t.main')\n@section('title', 'Outer')\n@section('body')\nPAGE <?= view('t.card') ?>\n@endsection");

    $out = View::view('t.page');
    contains('<title>Outer</title>', $out);
    contains('<h2>Card default</h2>', $out, "the inner view must not inherit the outer 'title' section");
    same(trim($out), trim(View::view('t.page')), 'cache hit renders the same');
});

test('abort() inside a template discards the partial output', function () use ($write) {
    $write('boom', "<b>PARTIAL</b>@php abort(404, 'nope') @endphp AFTER");

    $level = ob_get_level();
    ob_start();
    throws(\zFramework\Core\ResponseSignal::class, fn() => View::view('t.boom'));
    $left = ob_get_clean();

    same($level, ob_get_level(), 'no buffer left open');
    falsy(str_contains((string) $left, 'PARTIAL'), 'half a page leaked in front of the response');
});

test('minify keeps script line breaks and quoted attributes', function () use ($write) {
    $write('js', "<script>\nlet a = 1\nlet b = 2\n</script><p title=\"two  spaces\">  x  </p>");

    # A debug compile skips minify on purpose - turn it off for this one render.
    $debug = $GLOBALS['framework_config']['debug'] ?? null;
    $GLOBALS['framework_config']['debug'] = false;
    \zFramework\Core\Facades\Config::clearCache();
    try {
        $out = View::view('t.js');
    } finally {
        $GLOBALS['framework_config']['debug'] = $debug;
        \zFramework\Core\Facades\Config::clearCache();
    }
    contains("let a=1\nlet b=2", $out, 'ASI-reliant javascript must survive');
    contains('title="two  spaces"', $out, 'attribute values are untouchable');
    contains('<p title="two  spaces"> x </p>', $out, 'plain html still shrinks');
});

test('an email address is not a directive, @@ escapes one', function () use ($write) {
    $write('at', "mail support@elsewhere.com | @@if(x) | @pagesdev | @pages ok @endif");

    $out = trim(View::view('t.at'));
    contains('support@elsewhere.com', $out);
    contains('@if(x)', $out);
    contains('@pagesdev', $out, '@pages must not eat the front of another word');
    contains('ok', $out);
});

test('compiled files are written atomically', function () use ($tmp) {
    same([], (array) glob("$tmp/cache/*.tmp"), 'no temp files left behind');
    truthy(is_file("$tmp/cache/t.js.manifest.json"), 'manifest exists');
});
