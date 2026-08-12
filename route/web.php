<?php

use App\Controllers\AuthController;
use zFramework\Core\Route;
use App\Controllers\HomeController;
use App\Controllers\LanguageController;
use App\Controllers\PushNotificationController;

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

Route::middleware([App\Middlewares\Guest::class])->group(function () {
    Route::get('/auth', [AuthController::class, 'auth'])->name('auth-form');
    Route::post('/sign-in', [AuthController::class, 'signin'])->name('sign-in');
    Route::post('/sign-up', [AuthController::class, 'signup'])->name('sign-up');
});

Route::middleware([App\Middlewares\Auth::class])->group(fn() => Route::any('/sign-out', [AuthController::class, 'signout'])->name('sign-out'));

Route::get('/auth-content', [AuthController::class, 'content'])->name('auth-content');
Route::resource('/', HomeController::class);
