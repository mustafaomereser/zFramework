<?php

/**
 * php terminal tests run cpanel
 *
 * The cPanel client against a TLS stub with a self-signed certificate: the
 * certificate is checked by default (the token is never sent to it), the port
 * is configurable, and cron goes through API 2 - UAPI has no Cron module - with
 * the schedule in the five fields add_line/edit_line ask for. What the real
 * cPanel accepts belongs in denenecek.md.
 */

use zFramework\Core\Helpers\cPanel\{API, Cron};

$cnf = FRAMEWORK_PATH . '/Core/Facades/PushNotification/openssl.cnf';
$ssl = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

$dir = sys_get_temp_dir() . '/zf_cpanel_stub_' . getmypid();
@mkdir($dir, 0777, true);

$key = openssl_pkey_new($ssl);
if ($key === false) {
    test('stub', fn() => skip('openssl cannot generate keys here: ' . openssl_error_string()));
    return;
}
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => '127.0.0.1'], $key, $ssl), null, $key, 1, $ssl);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem, null, $ssl);
file_put_contents("$dir/server.pem", $certPem . $keyPem);

file_put_contents("$dir/stub.php", <<<'STUB'
<?php
[, $port, $dir] = $argv;
$ctx    = stream_context_create(['ssl' => ['local_cert' => "$dir/server.pem", 'verify_peer' => false]]);
$server = stream_socket_server("tls://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) exit(1);
file_put_contents("$dir/ready", '1');

$row = fn($key, $n) => ['linekey' => $key, 'command' => "cmd-$n", 'minute' => '0', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*', 'count' => $n];

while (true) {
    # A client that refuses the certificate fails the handshake here.
    $c = @stream_socket_accept($server, -1);
    if (!$c) continue;

    $head = '';
    while (!str_contains($head, "\r\n\r\n") && ($line = fgets($c)) !== false) $head .= $line;
    $body = preg_match('/^content-length:\s*(\d+)/mi', $head, $m) && $m[1] > 0 ? stream_get_contents($c, (int) $m[1]) : '';
    $target = explode(' ', strtok($head, "\r\n"))[1] ?? '';
    parse_str($body, $post);
    file_put_contents("$dir/log", json_encode(['target' => $target, 'auth' => preg_match('/^authorization:\s*(.+?)\s*$/mi', $head, $a) ? $a[1] : null, 'post' => $post]) . "\n", FILE_APPEND);

    if (str_starts_with($target, '/execute/')) $reply = ['status' => 1, 'data' => ['target' => $target], 'errors' => null, 'messages' => null];
    else {
        $func = $post['cpanel_jsonapi_func'] ?? '';
        $data = match (true) {
            $func === 'listcron'                                   => [$row('k1', 1), $row('k2', 2), ['count' => 2]],
            $func === 'add_line' && ($post['command'] ?? '') === 'fail' => [['status' => 0, 'reason' => 'bad command', 'statusmsg' => '']],
            $func === 'add_line'                                   => [['status' => 1, 'linekey' => 'k9', 'statusmsg' => 'crontab installed']],
            default                                                => [['status' => 1, 'statusmsg' => 'ok']],
        };
        $reply = ['cpanelresult' => ['apiversion' => 2, 'module' => $post['cpanel_jsonapi_module'] ?? '', 'func' => $func, 'data' => $data, 'event' => ['result' => 1]]];
    }

    $json = json_encode($reply);
    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n$json");
    fclose($c);
}
STUB);

$port = random_int(9000, 9099);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$stub = @proc_open([PHP_BINARY, "$dir/stub.php", (string) $port, $dir], [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $dir, null, ['bypass_shell' => true]);

Test::cleanup(function () use ($stub, $dir) {
    if (is_resource($stub)) {
        $pid = proc_get_status($stub)['pid'] ?? null;
        if (PHP_OS_FAMILY === 'Windows' && $pid) exec("taskkill /F /T /PID $pid >NUL 2>&1");
        else proc_terminate($stub);
    }
    rrmdir($dir);
});

for ($i = 0; $i < 40 && !is_file("$dir/ready"); $i++) usleep(100_000);
if (!is_file("$dir/ready")) {
    test('stub', fn() => skip("the TLS stub did not start on 127.0.0.1:$port"));
    return;
}

API::$domain   = '127.0.0.1';
API::$username = 'zfuser';
API::$apiToken = 'zftoken';
API::$port     = $port;

$log = function () use ($dir) {
    $lines = array_map(fn($l) => json_decode($l, true), file("$dir/log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    @unlink("$dir/log");
    return $lines;
};

test('the certificate is checked by default: a self-signed one is refused before the token goes out', function () use ($log) {
    API::$verifySSL = true;
    $r = API::request('DomainInfo/list_domains');
    truthy(isset($r['error']), 'curl reports the certificate');
    same([], $log(), 'no request reached the stub');
});

test('UAPI: on the configured port, with the token header', function () use ($log, $port) {
    API::$verifySSL = false;
    $r = API::request('DomainInfo/list_domains', ['a' => 1]);
    same(1, $r['status'] ?? null);
    same([['target' => '/execute/DomainInfo/list_domains?a=1', 'auth' => 'cpanel zfuser:zftoken', 'post' => []]], $log());
});

test('Cron::list() goes through API 2 and drops the trailing count row', function () use ($log) {
    API::$verifySSL = false;
    $r = Cron::list();
    same(1, $r['status']);
    same(['k1', 'k2'], array_column($r['data'], 'linekey'));

    $sent = $log()[0];
    same('/json-api/cpanel', $sent['target']);
    same('cpanel zfuser:zftoken', $sent['auth']);
    same(['cpanel_jsonapi_user' => 'zfuser', 'cpanel_jsonapi_apiversion' => '2', 'cpanel_jsonapi_module' => 'Cron', 'cpanel_jsonapi_func' => 'listcron'], $sent['post']);
});

test('create() / edit() send the schedule as five fields, delete() the line number', function () use ($log) {
    API::$verifySSL = false;
    $r = Cron::create('0  */6 * * 1-5', '/usr/bin/php terminal schedule');
    same([1, 'k9'], [$r['status'], $r['data'][0]['linekey'] ?? null]);
    Cron::edit('k1', '30 2 1 * *', 'php x');
    Cron::delete(2);

    $strip = fn($post) => array_diff_key($post, array_flip(['cpanel_jsonapi_user', 'cpanel_jsonapi_apiversion', 'cpanel_jsonapi_module']));
    same([
        ['cpanel_jsonapi_func' => 'add_line', 'minute' => '0', 'hour' => '*/6', 'day' => '*', 'month' => '*', 'weekday' => '1-5', 'command' => '/usr/bin/php terminal schedule'],
        ['cpanel_jsonapi_func' => 'edit_line', 'linekey' => 'k1', 'minute' => '30', 'hour' => '2', 'day' => '1', 'month' => '*', 'weekday' => '*', 'command' => 'php x'],
        ['cpanel_jsonapi_func' => 'remove_line', 'line' => '2'],
    ], array_map(fn($s) => $strip($s['post']), $log()));
});

test('a schedule that is not five fields is refused before any request', function () use ($log) {
    API::$verifySSL = false;
    throws(\InvalidArgumentException::class, fn() => Cron::create('@hourly', 'php x'));
    throws(\InvalidArgumentException::class, fn() => Cron::edit('k1', '0 * * *', 'php x'));
    same([], $log());
});

test('an operation API 2 refuses inside a successful call is a failure, with its reason', function () use ($log) {
    API::$verifySSL = false;
    $r = Cron::create('0 * * * *', 'fail');
    same([0, ['bad command']], [$r['status'], $r['errors']]);
    $log();
});
