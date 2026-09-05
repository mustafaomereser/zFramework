<?php

use App\Controllers\AuthController;
use zFramework\Core\Route;
use App\Controllers\WelcomeController;
use App\Controllers\LanguageController;
use App\Controllers\PushNotificationController;
use App\Controllers\PusherController;
use App\Controllers\ExamplesController;

Route::get('/language/{lang}', [LanguageController::class, 'set'])->name('language');

# Subscribing is a POST, so it carries a csrf token like every other one -
# assets/js/push-notification.js reads it from the page.
Route::pre('/push-notification')->group(function () {
    # Route::pre() already prefixes the name with the group, so these become
    # push-notification.config and so on.
    Route::get('/config', [PushNotificationController::class, 'config'])->name('config');
    Route::post('/subscribe', [PushNotificationController::class, 'subscribe'])->name('subscribe');
    Route::post('/unsubscribe', [PushNotificationController::class, 'unsubscribe'])->name('unsubscribe');
});

# Pusher Channels (config/pusher.php): the page reads the public key here, and
# private-/presence- subscriptions are signed at /pusher/auth - signed-in users
# only, the policy is in the controller.
Route::get('/pusher/config', [PusherController::class, 'config'])->name('pusher.config');
Route::middleware([App\Middlewares\Auth::class])->group(fn() => Route::post('/pusher/auth', [PusherController::class, 'auth'])->name('pusher.auth'));

# Working examples, linked from the welcome page. Delete these three lines with
# ExamplesController and resource/views/app/pages/examples/ when not wanted.
Route::get('/demo', [ExamplesController::class, 'index'])->name('examples');
Route::get('/demo/pusher', [ExamplesController::class, 'pusher'])->name('pusher.examples');
Route::post('/demo/pusher/send', [ExamplesController::class, 'pusherSend'])->name('pusher.examples.send');

# Five attempts per five minutes, per ip. A login form is the one place on a web
# app worth limiting by default - everything else here is cheap to serve.
Route::throttle(5, 300)->middleware([App\Middlewares\Guest::class])->group(function () {
    Route::get('/auth', [AuthController::class, 'auth'])->name('auth-form');
    Route::post('/sign-in', [AuthController::class, 'signin'])->name('sign-in');
    Route::post('/sign-up', [AuthController::class, 'signup'])->name('sign-up');
});

Route::middleware([App\Middlewares\Auth::class])->group(fn() => Route::any('/sign-out', [AuthController::class, 'signout'])->name('sign-out'));

Route::get('/auth-content', [AuthController::class, 'content'])->name('auth-content');
Route::resource('/', WelcomeController::class);
