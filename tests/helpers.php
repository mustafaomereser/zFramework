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

test('images open by content, keep transparency and fail soft', function () {
    if (!function_exists('imagecreatetruecolor')) return skip('no GD');

    $dir = public_dir('/zf_test_images');
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    Test::cleanup(fn() => rrmdir($dir));

    $im = imagecreatetruecolor(200, 100);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 50, 25, 150, 75, imagecolorallocate($im, 255, 0, 0));
    imagepng($im, "$dir/t.png");
    imagepng($im, "$dir/png-named.webp");
    imagejpeg($im, "$dir/p.jpg");
    imagegif($im, "$dir/g.gif");
    file_put_contents("$dir/broken.png", 'not an image');

    $png = File::resizeImage('/zf_test_images/t.png', ['width' => 50, 'height' => 50, 'desired_sizes' => false]);
    truthy($png, 'imagepng(..., 100) threw a ValueError');
    $out = imagecreatefrompng(public_dir($png));
    same([50, 25], [imagesx($out), imagesy($out)], 'aspect kept, whole pixels');
    same(127, (imagecolorat($out, 0, 0) >> 24) & 0x7F, 'the transparent corner stays transparent');

    truthy(File::resizeImage('/zf_test_images/png-named.webp', ['width' => 40, 'height' => 40]), 'a PNG called .webp');
    truthy(File::resizeImage('/zf_test_images/g.gif', ['width' => 20, 'height' => 20]), 'imagegif() takes no quality');
    $gif = File::convertImage('/zf_test_images/t.png', 'gif');
    $out = imagecreatefromgif(public_dir($gif));
    same(imagecolortransparent($out), imagecolorat($out, 0, 0), 'a transparent corner is the GIF transparent colour, not black');

    mkdir("$dir/t.png.d");
    copy("$dir/t.png", "$dir/t.png.d/t.png");
    same('/zf_test_images/t.png.d/x.png', File::resizeImage('/zf_test_images/t.png.d/t.png', ['width' => 10, 'height' => 10], 'x'), 'the name is replaced in the file, not in the directories');
    same('/zf_test_images/t.png.d/t-10x10.png', File::resizeImage('/zf_test_images/t.png.d/t.png', ['width' => 10, 'height' => 10]));
    same(false, File::resizeImage('/zf_test_images/broken.png'));

    $jpg = File::convertImage('/zf_test_images/t.png', 'jpg');
    truthy($jpg);
    same(0xFFFFFF, imagecolorat(imagecreatefromjpeg(public_dir($jpg)), 0, 0), 'transparency onto JPEG is white, not black');
    truthy(File::convertImage('/zf_test_images/p.jpg', 'webp'));
    truthy(File::convertImage('/zf_test_images/t.png', 'bmp'));
    same(false, File::convertImage('/zf_test_images/t.png', 'xyz'));
});

test('abort() as JSON carries the pending alerts', function () {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    Test::cleanup(function () {
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        \zFramework\Core\Facades\Alerts::unset();
    });

    \zFramework\Core\Facades\Alerts::danger('zf-test-alert');
    try {
        abort(400, 'nope');
    } catch (ResponseSignal $e) {
        $body = json_decode($e->body, true);
    }

    same('nope', $body['message'] ?? null);
    same(['danger', 'zf-test-alert'], array_values($body['alerts'] ?? [])[0] ?? null);
    falsy($e->navigates(), 'an abort consumes the alerts');
});
