<?php

/**
 * php terminal tests run autossl
 *
 * The ACME client against a stub CA on php -S: External Account Binding
 * (signature checked the way a CA checks it), badNonce retries, finalizing an
 * order only once it is ready, a fresh CSR per issuance, renewAll() keeping
 * every name, and an account the CA no longer knows being retired instead of
 * failing every run. No real CA, no account, no domain needed - the stub signs
 * certificates from the CSR with a throwaway CA. Live checks against Let's
 * Encrypt / GTS staging belong in denenecek.md.
 */

use zFramework\Core\Helpers\AutoSSL;

$cnf = FRAMEWORK_PATH . '/Core/Facades/PushNotification/openssl.cnf';
$ssl = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

$dir = sys_get_temp_dir() . '/zf_acme_stub_' . getmypid();
@mkdir($dir, 0777, true);

# A throwaway CA for the stub to sign with.
$caKey = openssl_pkey_new($ssl);
if ($caKey === false) {
    test('stub CA', fn() => skip('openssl cannot generate keys here: ' . openssl_error_string()));
    return;
}
$caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'zf stub CA'], $caKey, $ssl), null, $caKey, 365, $ssl);
openssl_pkey_export($caKey, $caKeyPem, null, $ssl);
openssl_x509_export($caCert, $caCertPem);
file_put_contents("$dir/ca.key", $caKeyPem);
file_put_contents("$dir/ca.crt", $caCertPem);

$port  = random_int(8800, 8899);
$base  = "http://127.0.0.1:$port";
$state = function (?array $set = null) use ($dir) {
    $now = json_decode((string) @file_get_contents("$dir/state.json"), true) ?: [];
    if ($set !== null) file_put_contents("$dir/state.json", json_encode($set + $now));
    return $set !== null ? $set + $now : $now;
};
$state(['base' => $base, 'cnf' => $cnf, 'eab_required' => false, 'eab_kid' => 'zf-eab-kid', 'eab_hmac' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'badnonce' => 0, 'valid_days' => 90, 'ready_lag' => 1, 'accounts' => [], 'orders' => [], 'log' => []]);

file_put_contents("$dir/index.php", <<<'STUB'
<?php
$dir   = __DIR__;
$S     = json_decode(file_get_contents("$dir/state.json"), true);
$path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b64d  = fn($s) => base64_decode(strtr($s, '-_', '+/'));
$b64e  = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$save  = function () use (&$S, $dir) { file_put_contents("$dir/state.json", json_encode($S)); };
$reply = function (int $code, $body = null, array $headers = []) use (&$S, $save) {
    $save();
    http_response_code($code);
    header('Replay-Nonce: ' . bin2hex(random_bytes(8)));
    foreach ($headers as $k => $v) header("$k: $v");
    if ($body !== null) { header('Content-Type: application/json'); echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES); }
    exit;
};
$problem = fn(int $code, string $type, string $detail) => $reply($code, ['type' => "urn:ietf:params:acme:error:$type", 'detail' => $detail]);

if ($path === '/directory') $reply(200, [
    'newNonce' => "{$S['base']}/new-nonce", 'newAccount' => "{$S['base']}/new-account", 'newOrder' => "{$S['base']}/new-order",
    'meta' => ['externalAccountRequired' => (bool) $S['eab_required']],
]);
if ($path === '/new-nonce') $reply(200);

# Every other endpoint takes a JWS.
$jws       = json_decode(file_get_contents('php://input'), true) ?: [];
$protected = json_decode($b64d($jws['protected'] ?? ''), true) ?: [];
$payload   = ($jws['payload'] ?? '') === '' ? '' : json_decode($b64d($jws['payload']), true);
$S['log'][] = $path;

# Reads by plain GET are still answered, as Let's Encrypt still does - the
# client uses POST-as-GET, but the other checks here should not hinge on that.
$plainGet = $_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/(authz|order|cert)/#', $path);

if (!$plainGet && ($protected['url'] ?? '') !== $S['base'] . $path) $problem(400, 'malformed', 'url in JWS does not match');
if (!$plainGet && $S['badnonce'] > 0) { $S['badnonce']--; $problem(400, 'badNonce', 'JWS has an invalid anti-replay nonce'); }

if ($path === '/new-account') {
    $jwk = $protected['jwk'] ?? null;
    if (!$jwk) $problem(400, 'malformed', 'newAccount needs a jwk');
    if ($S['eab_required']) {
        $eab = $payload['externalAccountBinding'] ?? null;
        if (!$eab) $problem(400, 'externalAccountRequired', 'no externalAccountBinding');
        $eabProtected = json_decode($b64d($eab['protected']), true);
        $expected     = $b64e(hash_hmac('sha256', $eab['protected'] . '.' . $eab['payload'], $b64d($S['eab_hmac']), true));
        if (($eabProtected['alg'] ?? '') !== 'HS256' || ($eabProtected['kid'] ?? '') !== $S['eab_kid'] || ($eabProtected['url'] ?? '') !== "{$S['base']}/new-account") $problem(400, 'malformed', 'EAB protected header');
        if (!hash_equals($expected, (string) $eab['signature'])) $problem(400, 'unauthorized', 'EAB signature does not verify');
        if (json_decode($b64d($eab['payload']), true) != $jwk) $problem(400, 'malformed', 'EAB payload is not the account key');
    }
    $id = substr(hash('sha256', json_encode($jwk)), 0, 16);
    $S['accounts'][$id] = 'valid';
    $reply(201, ['status' => 'valid'], ['Location' => "{$S['base']}/acct/$id"]);
}

# Signed with a kid from here on: it has to be an account we issued.
$kid = (string) ($protected['kid'] ?? '');
if (!$plainGet && (!preg_match('#/acct/(\w+)$#', $kid, $m) || !isset($S['accounts'][$m[1]]))) $problem(400, 'malformed', 'Unable to validate JWS :: JWS verification error');

if (preg_match('#^/acct/\w+$#', $path)) $reply(200, ['status' => $S['accounts'][$m[1]]]);

if ($path === '/new-order') {
    $oid = (string) count($S['orders']);
    $ids = array_column($payload['identifiers'], 'value');
    $S['orders'][$oid] = ['ids' => $ids, 'valid' => array_fill(0, count($ids), false), 'reads' => 0, 'status' => 'pending'];
    $reply(201, ['status' => 'pending', 'finalize' => "{$S['base']}/finalize/$oid", 'authorizations' => array_map(fn($i) => "{$S['base']}/authz/$oid/$i", array_keys($ids))], ['Location' => "{$S['base']}/order/$oid"]);
}

if (preg_match('#^/authz/(\d+)/(\d+)$#', $path, $a)) {
    $o = $S['orders'][$a[1]];
    $reply(200, ['identifier' => ['type' => 'dns', 'value' => $o['ids'][$a[2]]], 'status' => $o['valid'][$a[2]] ? 'valid' : 'pending',
        'challenges' => [['type' => 'http-01', 'url' => "{$S['base']}/chall/{$a[1]}/{$a[2]}", 'token' => "tok{$a[1]}x{$a[2]}"]]]);
}

if (preg_match('#^/chall/(\d+)/(\d+)$#', $path, $a)) {
    $S['orders'][$a[1]]['valid'][$a[2]] = true;
    $reply(200, ['status' => 'valid']);
}

if (preg_match('#^/order/(\d+)$#', $path, $a)) {
    $o = &$S['orders'][$a[1]];
    # Ready one read after the last authorization went valid - not at once.
    if ($o['status'] === 'pending' && !in_array(false, $o['valid'], true)) $o['status'] = $o['reads']++ >= 1 ? 'ready' : 'pending';
    $body = ['status' => $o['status']];
    if ($o['status'] === 'valid') $body['certificate'] = "{$S['base']}/cert/{$a[1]}";
    $reply(200, $body);
}

if (preg_match('#^/finalize/(\d+)$#', $path, $a)) {
    $o = &$S['orders'][$a[1]];
    # ready_lag 0: ready the moment its authorizations are, for the tests that
    # are about something else than waiting.
    if ($o['status'] === 'pending' && empty($S['ready_lag']) && !in_array(false, $o['valid'], true)) $o['status'] = 'ready';
    if ($o['status'] !== 'ready') $problem(403, 'orderNotReady', "order is {$o['status']}");

    # The names in the CSR must be the order's, exactly - as a CA checks.
    $der = $b64d($payload['csr']);
    preg_match_all('/\x82([\x01-\x7f])/', $der, $hits, PREG_OFFSET_CAPTURE);
    $names = [];
    foreach ($hits[1] as [$len, $at]) { $name = substr($der, $at + 1, ord($len)); if (preg_match('/^[\w*.-]+$/', $name)) $names[] = $name; }
    sort($names); $want = $o['ids']; sort($want);
    if ($names !== $want) $problem(400, 'badCSR', 'CSR names ' . implode(',', $names) . ' do not match order ' . implode(',', $want));

    $pem  = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der), 64) . "-----END CERTIFICATE REQUEST-----\n";
    $conf = tempnam(sys_get_temp_dir(), 'zfs');
    file_put_contents($conf, file_get_contents($S['cnf']) . "\n[zf_leaf]\nsubjectAltName = " . implode(',', array_map(fn($n) => "DNS:$n", $o['ids'])) . "\n");
    $leaf = openssl_csr_sign($pem, file_get_contents("$dir/ca.crt"), file_get_contents("$dir/ca.key"), $S['valid_days'], ['config' => $conf, 'x509_extensions' => 'zf_leaf', 'digest_alg' => 'sha256']);
    @unlink($conf);
    openssl_x509_export($leaf, $leafPem);
    $o['cert']   = $leafPem . file_get_contents("$dir/ca.crt");
    $o['status'] = 'valid';
    $reply(200, ['status' => 'processing']);
}

if (preg_match('#^/cert/(\d+)$#', $path, $a)) {
    $save();
    header('Replay-Nonce: ' . bin2hex(random_bytes(8)));
    header('Content-Type: application/pem-certificate-chain');
    echo $S['orders'][$a[1]]['cert'];
    exit;
}

$problem(404, 'malformed', "no $path");
STUB);

$stub = @proc_open(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/index.php"), [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes, $dir, null, ['bypass_shell' => true]);

# The client writes accounts and certificates under $storage_path, and challenge
# files under public_html/.well-known - both put back afterwards.
global $storage_path;
$realStorage   = $storage_path;
$storage_path  = "$dir/storage";
$wellKnown     = public_dir('/.well-known');
$hadWellKnown  = is_dir($wellKnown);

Test::cleanup(function () use ($stub, $dir, $realStorage, $wellKnown, $hadWellKnown) {
    global $storage_path;
    $storage_path = $realStorage;
    if (is_resource($stub)) {
        $pid = proc_get_status($stub)['pid'] ?? null;
        if (PHP_OS_FAMILY === 'Windows' && $pid) exec("taskkill /F /T /PID $pid >NUL 2>&1");
        else proc_terminate($stub);
    }
    rrmdir($dir);
    if (!$hadWellKnown) rrmdir($wellKnown);
});

$up = false;
for ($i = 0; $i < 20 && !$up; $i++) {
    usleep(150_000);
    $up = @file_get_contents("$base/directory") !== false;
}
if (!$up) {
    test('stub CA', fn() => skip("php -S did not answer on 127.0.0.1:$port"));
    return;
}

$directory = "$base/directory";
$accounts  = fn() => glob("$dir/storage/AutoSSL/accounts/*", GLOB_ONLYDIR) ?: [];

test('a CA that requires EAB: refused without one, nothing left on disk', function () use ($state, $directory, $accounts, $cnf) {
    $state(['eab_required' => true]);
    throws(\InvalidArgumentException::class, fn() => new AutoSSL($directory, $cnf));
    same([], $accounts(), 'a key directory was left for a registration that never happened');
});

test('EAB: a wrong key is refused by the CA, the right one registers', function () use ($state, $directory, $accounts, $cnf) {
    $s = $state();
    throws(\Exception::class, fn() => new AutoSSL($directory, $cnf, null, ['kid' => $s['eab_kid'], 'hmac' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]));
    foreach ($accounts() as $d) rrmdir($d); # the refused attempt's key

    $ssl = new AutoSSL($directory, $cnf, null, ['kid' => $s['eab_kid'], 'hmac' => $s['eab_hmac']]);
    truthy($ssl->accounts()[$ssl->account()]['registered'], 'the binding verified on the CA side');

    # Registered: the next instance needs no EAB.
    same($ssl->account(), (new AutoSSL($directory, $cnf))->account());
    $state(['eab_required' => false]);
});

test('issue(): badNonce retried, finalize waits for ready, every name in the certificate', function () use ($state, $directory, $dir, $cnf) {
    $state(['badnonce' => 1, 'eab_required' => false, 'ready_lag' => 1]);
    $ssl  = new AutoSSL($directory, $cnf);
    $cert = $ssl->issue(['a.zf.test', 'www.a.zf.test']);

    $san = openssl_x509_parse($cert['cert'])['extensions']['subjectAltName'] ?? '';
    contains('DNS:a.zf.test', $san);
    contains('DNS:www.a.zf.test', $san);
    truthy(openssl_x509_check_private_key($cert['cert'], $cert['private']), 'the key handed back is not the certificate\'s');

    $meta = json_decode((string) file_get_contents("$dir/storage/AutoSSL/a.zf.test/meta.json"), true);
    same(['a.zf.test', 'www.a.zf.test'], $meta['domains']);
    same($directory, $meta['ca']);
    falsy(is_file("$dir/storage/AutoSSL/a.zf.test/private.key.new"), 'the new key was swapped in');
});

test('a second issuance with other names gets a fresh CSR, not the stored one', function () use ($state, $directory, $cnf) {
    $state(['eab_required' => false, 'ready_lag' => 0]);
    $ssl = new AutoSSL($directory, $cnf);
    $ssl->issue(['b.zf.test']);
    $cert = $ssl->issue(['b.zf.test', 'c.zf.test']); # badCSR with the reused CSR

    contains('DNS:c.zf.test', openssl_x509_parse($cert['cert'])['extensions']['subjectAltName'] ?? '');
});

test('renewAll() renews a certificate with all of its names, and its other folders not on their own', function () use ($state, $directory, $dir, $cnf) {
    $state(['valid_days' => 10, 'eab_required' => false, 'ready_lag' => 0]);
    $ssl = new AutoSSL($directory, $cnf);
    $ssl->issue(['d.zf.test', 'www.d.zf.test']);
    $state(['valid_days' => 90]);

    $before = count($state()['orders']);
    ob_start();
    $ssl->renewAll();
    $out = ob_get_clean();

    $orders = array_slice($state()['orders'], $before);
    same([['d.zf.test', 'www.d.zf.test']], array_column($orders, 'ids'), "renewAll ordered: " . json_encode(array_column($orders, 'ids')) . "\n$out");
    contains('www.d.zf.test', (string) openssl_x509_parse(file_get_contents("$dir/storage/AutoSSL/d.zf.test/certificate.crt"))['extensions']['subjectAltName']);
});

test('an account the CA no longer knows is retired, not failed on forever', function () use ($state, $directory, $accounts, $cnf) {
    $state(['eab_required' => false]);
    $ssl = new AutoSSL($directory, $cnf);
    $old = $ssl->account();
    $kid = dirname(dirname($ssl->accounts()[$old]['kid'])) . '/acct/unknown0000';
    file_put_contents(dirname($accounts()[0]) . "/$old/account.kid", $kid);

    $fresh = new AutoSSL($directory, $cnf);
    truthy($fresh->account() !== $old, 'still signing as the account the CA rejects');
    contains('JWS verification error', (string) $fresh->accounts()[$old]['retired']);
    falsy($fresh->accounts()[$old]['usable']);
});
