<?php

/**
 * php terminal tests run proxy
 *
 * ip() and host() behind proxies: which forwarded values are believed, and from
 * whom. trusted_proxy() reads framework.trusted-proxies once per process, so the
 * list is set here before anything asks - this file runs in a process of its own.
 */

$cache = new ReflectionProperty(\zFramework\Core\Facades\Config::class, 'frameworkCache');
$cache->setAccessible(true);
$cache->setValue(null, ['trusted-proxies' => ['127.0.0.1', '10.0.0.0/8', 'cloudflare']] + (array) $cache->getValue());

$server = $_SERVER;
Test::cleanup(function () use ($server) {
    $_SERVER = $server;
});

$as = function (string $remote, array $headers = []) use ($server) {
    $_SERVER = ['REMOTE_ADDR' => $remote, 'HTTP_HOST' => 'site.example'] + $headers + $server;
    unset($_SERVER['HTTPS']);
};

test('ranges and the cloudflare alias', function () {
    truthy(ip_in_range('10.1.2.3', '10.0.0.0/8'));
    falsy(ip_in_range('11.1.2.3', '10.0.0.0/8'));
    truthy(ip_in_range('2606:4700::1', '2606:4700::/32'));
    falsy(ip_in_range('10.1.2.3', '2606:4700::/32'), 'v4 against a v6 range');
    falsy(ip_in_range('10.0.0.1', '10.0.0.1'), 'a bare address is not a range');
    truthy(trusted_proxy('104.16.0.1'), 'cloudflare expands to its published ranges');
    falsy(trusted_proxy('8.8.8.8'));
});

test('a direct visitor cannot name an address', function () use ($as) {
    $as('203.0.113.9', ['HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_CLIENT_IP' => '2.2.2.2', 'HTTP_CF_CONNECTING_IP' => '3.3.3.3']);
    same('203.0.113.9', ip());
});

test('behind a trusted proxy the client is the right-most untrusted hop', function () use ($as) {
    # nginx appends the real client after whatever the client sent.
    $as('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7']);
    same('198.51.100.7', ip(), 'the first entry is the client\'s own claim - a new bucket per request');

    # Two of our own proxies in a row.
    $as('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7, 10.0.0.5']);
    same('198.51.100.7', ip());

    # Client-IP is never read: no proxy overwrites it.
    $as('127.0.0.1', ['HTTP_CLIENT_IP' => '9.9.9.9']);
    same('127.0.0.1', ip());
});

test('CF-Connecting-IP only when Cloudflare itself connected', function () use ($as) {
    $as('104.16.0.1', ['HTTP_CF_CONNECTING_IP' => '198.51.100.7']);
    same('198.51.100.7', ip());

    # A local proxy forwarding a header the client wrote: not Cloudflare.
    $as('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '9.9.9.9']);
    same('127.0.0.1', ip());

    # Cloudflare -> nginx -> PHP: the chain says it, walked past both.
    $as('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7, 104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9']);
    same('198.51.100.7', ip());
});

test('the forwarded host is the nearest proxy\'s, and page cache keys follow it', function () use ($as) {
    $as('127.0.0.1', ['HTTP_X_FORWARDED_HOST' => 'evil.example, site.example', 'HTTP_X_FORWARDED_PROTO' => 'http, https']);
    same('https://site.example', host(), 'the first value is the client\'s own');

    $as('203.0.113.9', ['HTTP_X_FORWARDED_HOST' => 'evil.example']);
    same('http://site.example', host(), 'not from a direct visitor');

    $key = new ReflectionMethod(\zFramework\Core\Facades\Page::class, 'key');
    $key->setAccessible(true);
    $as('127.0.0.1', ['HTTP_X_FORWARDED_HOST' => 'site.example']);
    $real = $key->invoke(null, 'GET', '/');
    $as('127.0.0.1', ['HTTP_X_FORWARDED_HOST' => 'evil.example']);
    truthy($real !== $key->invoke(null, 'GET', '/'), 'a page built for another host was stored under the real host\'s key');
});
