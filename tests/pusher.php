<?php

/**
 * php terminal tests run pusher
 *
 * The Pusher facade without a Pusher account: signing against the vectors
 * Pusher publishes, channel authentication, webhook verification, the limits -
 * and the HTTP call itself against a stub server that records what arrived,
 * through each dispatch mode.
 */

use zFramework\Core\Facades\Config;
use zFramework\Core\Facades\Defer;
use zFramework\Core\Facades\Pusher;

# Pusher's documented example app: what its docs sign, this must sign identically.
$vector = ['app_id' => '3', 'key' => '278d425bdf160c739803', 'secret' => '7ad3773142a6692b25b8', 'cluster' => 'mt1'];

$configure = function (array $apps, array $shared = []) {
    Config::$caches['pusher'] = $shared + ['default' => array_key_first($apps), 'dispatch' => 'inline', 'queue_name' => 'pusher', 'timeout' => 5, 'apps' => $apps];
};
Test::cleanup(fn() => Config::clearCache());

$configure(['docs' => $vector]);

test('the config override reaches config()', function () {
    same('278d425bdf160c739803', config('pusher.apps.docs.key'));
    same('docs', (new Pusher)->app, 'the default app is the first one');
    truthy(Pusher::available());
});

test('REST signing matches the vector in Pusher\'s docs', function () {
    $body  = '{"name":"foo","channels":["project-3"],"data":"{\"some\":\"data\"}"}';
    $query = Pusher::sign('POST', '/apps/3/events', $body, [], 1353088179);

    same('ec365a775a4cd0599faeb73354201b6f', $query['body_md5']);
    same('da454824c97ba181a32ccc17a72625ba02771f50b50e1e7430e47a1f3f457e6c', $query['auth_signature']);
    same(['auth_key', 'auth_timestamp', 'auth_version', 'body_md5', 'auth_signature'], array_keys($query), 'sorted, signature last');
});

test('private channel authentication matches the docs', function () {
    same(['auth' => '278d425bdf160c739803:58df8b0c36d6982b82c3ecf6b4662e34fe8c25bba48f5369f135bf843651c3a4'], Pusher::authenticate('private-foobar', '1234.1234'));
});

test('presence channel authentication signs the member data too', function () {
    $answer = Pusher::authenticate('presence-foobar', '1234.1234', ['user_id' => 10, 'user_info' => ['name' => 'Mr. Pusher']]);
    same('{"user_id":"10","user_info":{"name":"Mr. Pusher"}}', $answer['channel_data'], 'user_id is a string, as the protocol wants');
    same('278d425bdf160c739803:' . hash_hmac('sha256', '1234.1234:presence-foobar:' . $answer['channel_data'], '7ad3773142a6692b25b8'), $answer['auth']);

    throws(InvalidArgumentException::class, fn() => Pusher::authenticate('presence-foobar', '1234.1234'), 'presence without a user');
    throws(InvalidArgumentException::class, fn() => Pusher::authenticate('orders', '1234.1234'), 'a public channel needs no auth');
    throws(InvalidArgumentException::class, fn() => Pusher::authenticate('private-x', 'not-a-socket'));
    throws(InvalidArgumentException::class, fn() => Pusher::authenticate('private-x:y', '1.1'), 'a colon would let the socket id forge the channel');
});

test('a webhook is accepted only with this app\'s key and a matching signature', function () {
    $body = '{"time_ms":1327078148132,"events":[{"name":"channel_occupied","channel":"test_channel"}]}';
    $sig  = hash_hmac('sha256', $body, '7ad3773142a6692b25b8');
    truthy(Pusher::webhook($body, '278d425bdf160c739803', $sig));
    truthy(Pusher::webhook($body, '278d425bdf160c739803', strtoupper($sig)), 'case does not matter');
    falsy(Pusher::webhook($body, 'other-key', $sig));
    falsy(Pusher::webhook($body . ' ', '278d425bdf160c739803', $sig));
});

test('Pusher\'s limits are refused before the request goes out', function () {
    throws(InvalidArgumentException::class, fn() => Pusher::trigger([], 'x'), 'no channel');
    throws(InvalidArgumentException::class, fn() => Pusher::trigger(range(1, 101), 'x'), '101 channels');
    throws(InvalidArgumentException::class, fn() => Pusher::trigger('bad channel!', 'x'), 'a space and a bang');
    throws(InvalidArgumentException::class, fn() => Pusher::trigger('ok', str_repeat('e', 201)), 'event name over 200');
    throws(InvalidArgumentException::class, fn() => Pusher::trigger('ok', 'x', str_repeat('d', 10241)), 'data over 10 KB');
    throws(InvalidArgumentException::class, fn() => Pusher::trigger('ok', 'x', [], 'socket'), 'socket id shape');
    throws(InvalidArgumentException::class, fn() => Pusher::app('nope'), 'an app not in config');
});

test('an app without credentials neither sends nor breaks', function () use ($configure, $vector) {
    $configure(['docs' => $vector, 'empty' => ['app_id' => '', 'key' => '', 'secret' => '']]);
    falsy(Pusher::app('empty')->available());
    falsy(Pusher::app('empty')->trigger('orders', 'created', ['id' => 1]), 'trigger() is a quiet false');
    contains('apps.empty', Pusher::app('empty')->triggerNow('orders', 'created')['error']);
    same('https://api-mt1.pusher.com', Pusher::app('docs')->endpoint());
    same(['key' => '278d425bdf160c739803', 'cluster' => 'mt1'], Pusher::app('docs')->client(), 'and the client never sees the secret');
});

# A stub that records every request it gets and answers {} - what the API
# does on success. Run from the test's own temp dir so nothing else answers.
$dir = sys_get_temp_dir() . '/zf_pusher_stub_' . getmypid();
@mkdir($dir);
file_put_contents("$dir/index.php", '<?php file_put_contents(__DIR__ . "/last.json", json_encode(["method" => $_SERVER["REQUEST_METHOD"], "uri" => $_SERVER["REQUEST_URI"], "body" => file_get_contents("php://input"), "type" => $_SERVER["CONTENT_TYPE"] ?? ""])); header("Content-Type: application/json"); echo "{}";');
$port = random_int(8700, 8799);
$stub = @proc_open(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/index.php"), [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes, $dir, null, ['bypass_shell' => true]);
Test::cleanup(function () use ($stub, $dir) {
    if (is_resource($stub)) {
        $pid = proc_get_status($stub)['pid'] ?? null;
        if (PHP_OS_FAMILY === 'Windows' && $pid) exec("taskkill /F /T /PID $pid >NUL 2>&1");
        else proc_terminate($stub);
    }
    @unlink("$dir/last.json");
    @unlink("$dir/index.php");
    @rmdir($dir);
});

$up = false;
for ($i = 0; $i < 20 && !$up; $i++) {
    usleep(150_000);
    $up = @file_get_contents("http://127.0.0.1:$port/ping") !== false;
}
$last = fn() => json_decode((string) @file_get_contents("$dir/last.json"), true);
$configure(['local' => $vector + ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http']]);

if (!$up) {
    test('stub server', fn() => skip("php -S did not answer on 127.0.0.1:$port"));
    return;
}

test('inline: the request Pusher would receive is signed and shaped right', function () use ($last, $dir) {
    @unlink("$dir/last.json");
    truthy(Pusher::trigger(['orders', 'admin'], 'created', ['id' => 812, 'note' => 'çay'], '1234.5678'));

    $got = $last();
    same('POST', $got['method']);
    contains('application/json', $got['type']);
    [$path, $qs] = explode('?', $got['uri'], 2);
    same('/apps/3/events', $path);

    parse_str($qs, $query);
    same(['auth_key', 'auth_timestamp', 'auth_version', 'body_md5', 'auth_signature'], array_keys($query));
    same(md5($got['body']), $query['body_md5']);
    $again = Pusher::sign('POST', $path, $got['body'], [], (int) $query['auth_timestamp']);
    same($again['auth_signature'], $query['auth_signature'], 'the signature covers exactly what was sent');

    $body = json_decode($got['body'], true);
    same(['name' => 'created', 'channels' => ['orders', 'admin'], 'data' => '{"id":812,"note":"çay"}', 'socket_id' => '1234.5678'], $body, 'data travels as one json string');
});

test('get() signs a GET with no body_md5 and decodes the answer', function () use ($last) {
    $result = Pusher::get('/channels', ['filter_by_prefix' => 'presence-']);
    truthy($result['ok']);
    same([], $result['data']);

    $got = $last();
    same('GET', $got['method']);
    [$path, $qs] = explode('?', $got['uri'], 2);
    same('/apps/3/channels', $path);
    parse_str($qs, $query);
    falsy(isset($query['body_md5']));
    same('presence-', $query['filter_by_prefix']);
    same(Pusher::sign('GET', $path, '', ['filter_by_prefix' => 'presence-'], (int) $query['auth_timestamp'])['auth_signature'], $query['auth_signature']);
});

test('defer: nothing leaves until the response has, then it does', function () use ($configure, $vector, $last, $dir, $port) {
    $configure(['local' => $vector + ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http']], ['dispatch' => 'defer']);
    @unlink("$dir/last.json");

    truthy(Pusher::trigger('orders', 'deferred', ['n' => 1]));
    falsy(file_exists("$dir/last.json"), 'not sent yet');
    truthy(Defer::pending());

    Defer::flush();
    same('deferred', json_decode($last()['body'] ?? '', true)['name'] ?? null, 'sent after the response');
});

test('queue: without redis the job runs at once through SendPusherEvent', function () use ($configure, $vector, $last, $dir, $port) {
    $configure(['local' => $vector + ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http']], ['dispatch' => 'queue']);
    if (\zFramework\Core\Facades\Redis::available('queue')) skip('a redis queue is configured here; the job would wait for a worker');
    @unlink("$dir/last.json");

    truthy(Pusher::trigger('orders', 'queued', ['n' => 2]));
    $body = json_decode($last()['body'] ?? '', true);
    same('queued', $body['name'] ?? null);
    same('{"n":2}', $body['data'] ?? null, 'the job resends the already-encoded data untouched');
});

test('a second app is its own credentials and endpoint', function () use ($configure, $vector, $last, $port) {
    $configure([
        'main'  => $vector + ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http'],
        'admin' => ['app_id' => '77', 'key' => 'adminkey', 'secret' => 'adminsecret', 'host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http'],
    ]);

    truthy(Pusher::app('admin')->trigger('audit', 'login', ['who' => 1]));
    [$path, $qs] = explode('?', $last()['uri'], 2);
    same('/apps/77/events', $path);
    parse_str($qs, $query);
    same('adminkey', $query['auth_key']);
    same('adminkey:' . hash_hmac('sha256', '1.1:private-x', 'adminsecret'), Pusher::app('admin')->authenticate('private-x', '1.1')['auth']);
    same('278d425bdf160c739803', Pusher::sign('GET', '/x')['auth_key'], 'the static call is still the default app');
});
