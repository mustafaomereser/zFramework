<?php

/**
 * php terminal tests run helpers
 *
 * The small surface everything leans on: back()'s referer guard, path
 * traversal in File, upload name policy, locale from Accept-Language,
 * string/date/size helpers, Config edge cases.
 */

use zFramework\Core\Facades\Lang;
use zFramework\Core\Facades\Str;
use zFramework\Core\Helpers\Date;
use zFramework\Core\Helpers\File;
use zFramework\Core\ResponseSignal;

test('back() only follows a same-host referer', function () {
    $target = function (string $referer) {
        $_SERVER['HTTP_HOST']    = 'localhost';
        $_SERVER['HTTP_REFERER'] = $referer;
        try {
            back();
        } catch (ResponseSignal $e) {
            return $e->headers['Location'] ?? null;
        }
    };

    same('http://localhost/orders?p=2', $target('http://localhost/orders?p=2'));
    same('/', $target('https://attacker.example/phish'));
    same('/', $target('//attacker.example'));
    same('/orders', $target('/orders'));
});

test('File refuses to leave the public directory', function () {
    $outside = BASE_PATH . '/zf_test_outside.txt';
    file_put_contents($outside, 'x');
    Test::cleanup(fn() => @unlink($outside));

    same(false, File::delete('../zf_test_outside.txt'));
    truthy(file_exists($outside), 'the file above the webroot must survive');
    throws(ResponseSignal::class, fn() => File::download('../zf_test_outside.txt'));
});

test('server-executable upload names are refused', function () {
    truthy(File::executable('shell.php'));
    truthy(File::executable('x.php.jpg'), 'multi-extension handlers read every segment');
    truthy(File::executable('.htaccess'));
    falsy(File::executable('photo.jpg'));
});

test('Accept-Language cannot name a directory', function () {
    $locale = function (string $header) {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $header;
        Lang::locale(null, false);
        return Lang::$locale;
    };

    same(config('app.lang'), $locale('..'));
    same(config('app.lang'), $locale('./'));
    same('tr', $locale('tr-TR,tr;q=0.9'));
});

test('multibyte, timestamps and sizes behave at the edges', function () {
    same('çç', Str::limit('çç', 3));
    same('ççç...', Str::limit('ççççç', 3));
    same('1 minute', Date::timeago(time() - 90), 'an int is already a moment');
    same('1023.00B', File::humanFileSize(1023));
    same('1.00KB', File::humanFileSize(1024));
});

test('a missing config key is null at any depth', function () {
    same(null, config('push-notification.apps.zzz'));
    same(null, config('push-notification.apps.zzz.channel'));
    truthy(is_array(config('mail.from')), 'lists still come back whole');
});
