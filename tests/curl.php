<?php

/**
 * php terminal tests run curl
 *
 * The cURL facade against an echo server on php -S: requests built side by
 * side keep their own state, methods (HEAD without waiting for a body),
 * response headers from the final response only, headers adding up, file()
 * taking content as well as a path, and a failure reported rather than thrown.
 */

use zFramework\Core\Facades\cURL;

$dir = sys_get_temp_dir() . '/zf_curl_stub_' . getmypid();
@mkdir($dir, 0777, true);

file_put_contents("$dir/index.php", <<<'STUB'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect') { header('X-Hop: first'); header('Location: /echo', true, 302); exit; }
header('X-Echo: Yes');
header('Content-Type: application/json');
$headers = [];
foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
$files = [];
foreach ($_FILES as $k => $f) $files[$k] = ['name' => $f['name'], 'type' => $f['type'], 'content' => file_get_contents($f['tmp_name'])];
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'headers' => $headers, 'post' => $_POST, 'body' => file_get_contents('php://input'), 'files' => $files]);
STUB);

$port = random_int(8900, 8999);
$base = "http://127.0.0.1:$port";
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$stub = @proc_open(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/index.php"), [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $dir, null, ['bypass_shell' => true]);

Test::cleanup(function () use ($stub, $dir) {
    if (is_resource($stub)) {
        $pid = proc_get_status($stub)['pid'] ?? null;
        if (PHP_OS_FAMILY === 'Windows' && $pid) exec("taskkill /F /T /PID $pid >NUL 2>&1");
        else proc_terminate($stub);
    }
    rrmdir($dir);
});

$up = false;
for ($i = 0; $i < 20 && !$up; $i++) {
    usleep(150_000);
    $up = @file_get_contents("$base/echo") !== false;
}
if (!$up) {
    test('echo server', fn() => skip("php -S did not answer on 127.0.0.1:$port"));
    return;
}

$echo = fn($request) => $request->send(fn($body, $info, $error) => ['echo' => json_decode((string) $body, true), 'info' => $info, 'error' => $error]);

test('two requests built side by side keep their own url, body and headers', function () use ($base, $echo) {
    $a = cURL::set("$base/a")->headers(['X-Who' => 'a'])->post(['n' => 'a']);
    $b = cURL::set("$base/b")->headers(['X-Who' => 'b']);

    $rb = $echo($b);
    $ra = $echo($a);

    same(['GET', '/b', 'b', []], [$rb['echo']['method'], $rb['echo']['path'], $rb['echo']['headers']['x-who'], $rb['echo']['post']]);
    same(['POST', '/a', 'a', ['n' => 'a']], [$ra['echo']['method'], $ra['echo']['path'], $ra['echo']['headers']['x-who'], $ra['echo']['post']]);
});

test('method(): PUT carries the body, HEAD comes back without waiting for one', function () use ($base, $echo) {
    $r = $echo(cURL::set("$base/echo")->method('put')->post('{"x":1}')->headers(['Content-Type' => 'application/json']));
    same(['PUT', '{"x":1}'], [$r['echo']['method'], $r['echo']['body']]);

    $started = microtime(true);
    $head    = cURL::set("$base/echo")->method('HEAD')->send(fn($body, $info) => [$body, $info['http_code'], $info['headers']['x-echo'] ?? null]);
    same(['', 200, 'Yes'], $head);
    truthy(microtime(true) - $started < 5, 'HEAD returned at once');
});

test('response headers: lower-cased, from the final response only', function () use ($base, $echo) {
    $r = $echo(cURL::set("$base/redirect")->options([CURLOPT_FOLLOWLOCATION => true]));
    same('/echo', $r['echo']['path']);
    same('Yes', $r['info']['headers']['x-echo'] ?? null);
    falsy(isset($r['info']['headers']['x-hop']), 'the redirect\'s own headers are not mixed in');
    falsy(isset($r['info']['headers']['location']));

    $r = $echo(cURL::set("$base/redirect"));
    same([302, 'first', '/echo'], [$r['info']['http_code'], $r['info']['headers']['x-hop'] ?? null, $r['info']['headers']['location'] ?? null]);
});

test('headers() calls add up; a name given again replaces the earlier value', function () use ($base, $echo) {
    $r = $echo(cURL::set("$base/echo")->headers(['X-One' => '1', 'X-Two: 2'])->headers(['x-one' => 'again', 'X-Three' => '3']));
    same(['again', '2', '3'], [$r['echo']['headers']['x-one'] ?? null, $r['echo']['headers']['x-two'] ?? null, $r['echo']['headers']['x-three'] ?? null]);
});

test('file() sends a file on disk or content held in memory', function () use ($base, $echo, $dir) {
    file_put_contents("$dir/upload.txt", 'from disk');
    $r = $echo(cURL::set("$base/echo")
        ->post(['note' => 'hi'])
        ->file('disk', 'a.txt', "$dir/upload.txt")
        ->file('memory', 'b.csv', "id,name\n1,Çağrı", 'text/csv'));

    same('hi', $r['echo']['post']['note'] ?? null);
    same(['name' => 'a.txt', 'type' => 'text/plain', 'content' => 'from disk'], $r['echo']['files']['disk'] ?? null);
    same(['name' => 'b.csv', 'type' => 'text/csv', 'content' => "id,name\n1,Çağrı"], $r['echo']['files']['memory'] ?? null);
});

test('a failed request: false body and the error, no exception; without a callback, false', function () use ($echo) {
    $r = $echo(cURL::set('http://127.0.0.1:1/')->options([CURLOPT_CONNECTTIMEOUT => 1]));
    same(0, $r['info']['http_code']);
    truthy($r['error']['error_no'] > 0, $r['error']['error_message']);
    same(false, cURL::set('http://127.0.0.1:1/')->options([CURLOPT_CONNECTTIMEOUT => 1])->send());
});
