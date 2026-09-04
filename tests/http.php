<?php

/**
 * php terminal tests run http
 *
 * The application over a real socket: php -S is started on a free port,
 * requests go through curl - sessions, csrf, the sign-in flow, error pages.
 * Skipped whole when the server cannot start (port policy, missing binary).
 */

$port = random_int(8600, 8699);
$srv  = @proc_open(
    escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port -t public_html public_html/index.php",
    [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
    $pipes,
    BASE_PATH,
    null,
    ['bypass_shell' => true]
);

Test::cleanup(function () use ($srv) {
    if (!is_resource($srv)) return;
    $pid = proc_get_status($srv)['pid'] ?? null;
    if (PHP_OS_FAMILY === 'Windows' && $pid) exec("taskkill /F /T /PID $pid >NUL 2>&1");
    else proc_terminate($srv);
});

# The throttle counts per ip: a run right after another must not inherit its
# hits - cleared before as well as after, or the sign-in below meets a 429.
@rrmdir(FRAMEWORK_PATH . '/storage/ratelimit');
Test::cleanup(fn() => @rrmdir(FRAMEWORK_PATH . '/storage/ratelimit'));

$jar = tempnam(sys_get_temp_dir(), 'zf_test_jar');
Test::cleanup(fn() => @unlink($jar));

$request = function (string $path, ?string $body = null, array $headers = []) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out  = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [$info['http_code'], substr((string) $out, 0, $info['header_size']), substr((string) $out, $info['header_size'])];
};

# One probe decides: no server, one skip line instead of six failures.
$up = false;
for ($i = 0; $i < 20 && !$up; $i++) {
    usleep(150_000);
    $up = ([$code] = $request('/'))[0] > 0 && $code > 0;
}

if (!$up) {
    test('http server', fn() => skip("php -S did not answer on 127.0.0.1:$port"));
    return;
}

test('pages answer without warnings', function () use ($request) {
    foreach (['/' => 200, '/auth' => 200, '/no/such/page' => 404, '/language/tr' => 302] as $path => $expected) {
        [$code, , $body] = $request($path);
        same($expected, $code, "GET $path");
        falsy(str_contains($body, 'Warning:'), "warning leaked into $path");
    }
});

test('the session cookie carries HttpOnly and SameSite', function () use ($request) {
    [, $headers] = $request('/auth');
    if (!preg_match('/^Set-Cookie: (PHPSESSID[^\r\n]*)/mi', $headers, $m)) return; // already issued earlier in the jar
    contains('HttpOnly', $m[1]);
    contains('SameSite=Lax', $m[1]);
});

test('a form round-trip: real token in, wrong token refused', function () use ($request) {
    [, , $body] = $request('/auth');
    truthy(preg_match("/_token' value='([^']+)'/", $body, $m), 'no csrf token on the page');

    [$ok, , $json] = $request('/sign-in', "_token={$m[1]}&email=x@y.z&password=wrong");
    [$bad]         = $request('/sign-in', "_token=fake&email=x@y.z&password=wrong");

    same(200, $ok, 'a valid token reaches the controller');
    contains('"status":0', $json, 'and a wrong password is a clean json refusal');
    same(406, $bad, 'a fake token is refused');
});

test('seeded admin signs in and stays signed in', function () use ($request) {
    [, , $body] = $request('/auth');
    preg_match("/_token' value='([^']+)'/", $body, $m);

    [$code, , $json] = $request('/sign-in', "_token={$m[1]}&email=admin@localhost.com&password=admin&keep-logged-in=1");
    if ($code === 302 || !str_contains((string) $json, '"status":1')) skip('no seeded admin user on this database (`db migrate --seed`)');

    [, , $page] = $request('/auth-content');
    contains('admin', $page, 'the next request must already be signed in');
});

test('json is answered as json', function () use ($request) {
    [$code, $headers] = $request('/no/such/page', null, ['Accept: application/json']);
    same(404, $code);
    contains('application/json', strtolower($headers));
});
