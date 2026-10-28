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
    foreach (['/' => 200, '/auth' => 200, '/demo' => 200, '/demo/pusher' => 200, '/demo/push-notification' => 200, '/no/such/page' => 404, '/language/tr' => 302] as $path => $expected) {
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

    # Not a string at all: a 500 with an error report on disk, for anyone, before.
    $logs = glob(BASE_PATH . '/error_logs/*') ?: [];
    [$array] = $request('/sign-in', '_token[]=x&email=x@y.z&password=wrong');
    [$none]  = $request('/sign-in', 'email=x@y.z&password=wrong');
    same(406, $array, '_token[] is refused, not a TypeError');
    same(406, $none, 'a missing _token is refused');
    same(count($logs), count(glob(BASE_PATH . '/error_logs/*') ?: []), 'no error report was written');
});

test('a failed form redirects back and the next page shows its alerts', function () use ($request, $port) {
    [, , $body] = $request('/auth');
    preg_match("/_token' value='([^']+)'/", $body, $m);

    # Not ajax: the validator raises an alert per failed rule and calls back().
    [$code, $headers] = $request('/sign-in', "_token={$m[1]}&email=&password=", ["Referer: http://127.0.0.1:$port/"]);
    same(302, $code, 'a failed validation redirects');

    [, , $page] = $request('/');
    truthy(preg_match('/showAlerts\((\[.*?\]|\{.*?\})\)/s', $page, $a), 'no alert block on the page');
    contains('danger', $a[1], 'the redirect cleared the alerts before the page it led to could show them');

    [, , $again] = $request('/');
    preg_match('/showAlerts\((\[.*?\]|\{.*?\})\)/s', $again, $b);
    falsy(str_contains($b[1] ?? '', 'danger'), 'shown once, then consumed');

    # /sign-in allows five posts in five minutes; the sign-in tests below need theirs.
    @rrmdir(FRAMEWORK_PATH . '/storage/ratelimit');
});

test('seeded admin signs in and stays signed in', function () use ($request) {
    [, , $body] = $request('/auth');
    preg_match("/_token' value='([^']+)'/", $body, $m);

    [$code, , $json] = $request('/sign-in', "_token={$m[1]}&email=admin@localhost.com&password=admin&keep-logged-in=1");
    if ($code === 302 || !str_contains((string) $json, '"status":1')) skip('no seeded admin user on this database (`db migrate --seed`)');

    [, , $page] = $request('/auth-content');
    contains('admin', $page, 'the next request must already be signed in');
});

test('remember-me: auth-stay-in alone signs back in, a half-expired pair does not', function () use ($request, $port) {
    # Cookie names are the encrypted key; the values come from the sign-in above.
    $name = fn(string $key) => \zFramework\Core\Facades\Cookie::keyparse($key);
    $raw  = function (string $path, array $cookies) use ($port): array {
        $ch = curl_init("http://127.0.0.1:$port$path");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10, CURLOPT_COOKIE => implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))]);
        $out  = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return [$info['http_code'], substr((string) $out, 0, $info['header_size']), substr((string) $out, $info['header_size'])];
    };

    $request('/sign-out'); # the jar is signed in from the test above; attempt() refuses a second sign-in
    [, , $body] = $request('/auth');
    preg_match("/_token' value='([^']+)'/", $body, $m);
    [$code, $headers, $json] = $request('/sign-in', "_token={$m[1]}&email=admin@localhost.com&password=admin&keep-logged-in=1");
    if ($code === 302 || !str_contains((string) $json, '"status":1')) skip('no seeded admin user on this database (`db migrate --seed`)');

    $issued = [];
    preg_match_all('/^Set-Cookie: ([^=]+)=([^;]*)(.*)$/mi', $headers, $all, PREG_SET_ORDER);
    foreach ($all as $c) $issued[$c[1]] = ['value' => $c[2], 'attrs' => $c[3]];
    foreach (['auth-token', 'auth-password', 'auth-stay-in'] as $key) truthy(isset($issued[$name($key)]), "$key was not issued at sign-in");

    preg_match('/Max-Age=(\d+)/', $issued[$name('auth-token')]['attrs'], $day);
    preg_match('/Max-Age=(\d+)/', $issued[$name('auth-stay-in')]['attrs'], $long);
    same(86400, (int) ($day[1] ?? 0), 'auth-token lives a day');
    truthy((int) ($long[1] ?? 0) > 86400 * 365, 'auth-stay-in outlives it by far');

    # A day later: auth-token and auth-password are gone, only auth-stay-in is presented.
    [$code, $headers, $page] = $raw('/auth-content', [$name('auth-stay-in') => $issued[$name('auth-stay-in')]['value']]);
    same(200, $code);
    contains('admin', $page, 'the remember-me cookie alone must sign back in');
    contains('Set-Cookie: ' . $name('auth-token') . '=', $headers, 'and a fresh auth-token is issued with it');

    # auth-token without auth-password is a broken pair: the session ends and the remember-me cookie goes with it.
    [$code, $headers, $page] = $raw('/auth-content', [$name('auth-token') => $issued[$name('auth-token')]['value'], $name('auth-stay-in') => $issued[$name('auth-stay-in')]['value']]);
    same(200, $code);
    falsy(str_contains($page, 'admin'), 'a token without its password cookie is not a session');
    truthy(preg_match('/^Set-Cookie: ' . preg_quote($name('auth-stay-in'), '/') . '=[^\r\n]*Max-Age=0/mi', $headers), 'auth-stay-in is dropped with it');

    # Garbage in the cookie is a guest, not an error.
    [$code, , $page] = $raw('/auth-content', [$name('auth-stay-in') => 'garbage']);
    same(200, $code);
    falsy(str_contains($page, 'admin'));
    falsy(str_contains($page, 'Warning:'));
});

test('Auth::login() can issue the remember-me cookie itself', function () {
    # In-process: an application that checks the password itself and then logs in.
    $user = \zFramework\Core\Facades\Auth::model()->select('id, email')->where('email', 'admin@localhost.com')->first();
    if (!$user) skip('no seeded admin user on this database (`db migrate --seed`)');

    $cookie = \zFramework\Core\Facades\Cookie::keyparse('auth-stay-in');
    unset($_COOKIE[$cookie]);
    Test::cleanup(fn() => \zFramework\Core\Facades\Auth::logout());

    truthy(\zFramework\Core\Facades\Auth::login($user + ['password' => 'x']));
    falsy(isset($_COOKIE[$cookie]), 'remember-me is opt-in');

    truthy(\zFramework\Core\Facades\Auth::login($user, true), 'api_token and the password column are fetched when the row lacks them');
    truthy(isset($_COOKIE[$cookie]), 'staymein wrote no auth-stay-in');
    contains('|', \zFramework\Core\Crypter::decode($_COOKIE[$cookie]), 'the same token|trace format attempt() writes');
});

test('auth-token carries a signed expiry, so a copied cookie dies with it', function () {
    # In-process, like the login() test above: the cookie values are at hand.
    $Auth   = \zFramework\Core\Facades\Auth::class;
    $user   = $Auth::model()->select('id, password')->where('email', 'admin@localhost.com')->first();
    if (!$user) skip('no seeded admin user on this database (`db migrate --seed`)');

    $name = fn(string $key) => \zFramework\Core\Facades\Cookie::keyparse($key);
    Test::cleanup(fn() => $Auth::logout());

    truthy($Auth::login($user));
    $stamp = \zFramework\Core\Crypter::decode($_COOKIE[$name('auth-token')]);
    [$id, $expires, $signature] = array_pad(explode('|', $stamp, 3), 3, '');
    same((string) $user['id'], $id);
    truthy((int) $expires > time() + 86000 && (int) $expires <= time() + 86400, 'a day, like the cookie');

    $as = function (string $token) use ($Auth, $name) {
        $Auth::flushRequestState();
        $_COOKIE[$name('auth-token')] = \zFramework\Core\Crypter::encode($token);
        return $Auth::user();
    };
    $password = $_COOKIE[$name('auth-password')];

    truthy($as($stamp), 'the stamp as issued');

    $_COOKIE[$name('auth-password')] = $password;
    falsy($as("$id|" . (time() + 999999) . "|$signature"), 'a pushed-back expiry with the old signature');

    $_COOKIE[$name('auth-password')] = $password;
    falsy($as("$id|" . (time() - 1) . '|' . hash_hmac('sha256', 'x', 'y')), 'an expired one');

    $_COOKIE[$name('auth-password')] = $password;
    falsy($as($id), 'the bare id the cookie used to hold - valid for as long as the password was');
});

test('a guarded password stays out of Auth::user() and the API, and signing in still works', function () use ($request) {
    $Auth = \zFramework\Core\Facades\Auth::class;
    $user = $Auth::model()->select('id, password, api_token')->where('email', 'admin@localhost.com')->first();
    if (!$user) skip('no seeded admin user on this database (`db migrate --seed`)');
    Test::cleanup(fn() => $Auth::logout());

    $Auth::flushRequestState();
    truthy($Auth::login($user));
    $row = $Auth::user();
    truthy($row && $row['id'] == $user['id'], 'signed in although the model guards the column the check compares');
    falsy(array_key_exists('password', $row), 'User::$guard did not reach Auth::user()');

    [$code, , $json] = $request('/api/v1', null, ['Auth-Token: ' . $user['api_token']]);
    same(200, $code);
    contains('"username"', $json, 'the API saw the user');
    falsy(str_contains($json, $user['password']), 'the password hash went out in the API response');
});

test('a session id is the server\'s, and signing in replaces it', function () use ($port) {
    # Its own jar: the shared one is signed in by now.
    $jar = tempnam(sys_get_temp_dir(), 'zf_test_jar');
    Test::cleanup(fn() => @unlink($jar));
    $send = function (string $path, ?string $body = null, array $headers = []) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:$port$path");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out  = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return [$info['http_code'], substr((string) $out, 0, $info['header_size']), substr((string) $out, $info['header_size'])];
    };
    $sid = fn(string $headers) => preg_match('/^Set-Cookie: PHPSESSID=([^;\r\n]+)/mi', $headers, $m) ? $m[1] : null;

    # An id nobody issued is not adopted.
    [, $headers] = $send('/auth', null, ['Cookie: PHPSESSID=zfplantedsession1234567890']);
    $issued = $sid($headers);
    truthy($issued && $issued !== 'zfplantedsession1234567890', 'a planted session id was taken as-is');
    falsy(is_file(FRAMEWORK_PATH . '/storage/sessions/sess_zfplantedsession1234567890'), 'and a session file was created under it');

    @rrmdir(FRAMEWORK_PATH . '/storage/ratelimit');
    [, $headers, $body] = $send('/auth');
    preg_match("/_token' value='([^']+)'/", $body, $m);
    $before = $m[1] ?? '';
    $id     = $sid($headers) ?? $issued;

    [$code, $headers, $json] = $send('/sign-in', "_token=$before&email=admin@localhost.com&password=admin");
    if (!str_contains((string) $json, '"status":1')) skip('no seeded admin user on this database (`db migrate --seed`)');

    $after = $sid($headers);
    truthy($after && $after !== $id, 'the session kept the id it had before signing in');
    falsy(is_file(FRAMEWORK_PATH . "/storage/sessions/sess_$id"), 'the old session record is still there');

    [$reused] = $send('/sign-out', "_token=$before");
    same(406, $reused, 'a CSRF token read before signing in still works after it');

    @rrmdir(FRAMEWORK_PATH . '/storage/ratelimit');
});

test('json is answered as json', function () use ($request) {
    [$code, $headers] = $request('/no/such/page', null, ['Accept: application/json']);
    same(404, $code);
    contains('application/json', strtolower($headers));
});
