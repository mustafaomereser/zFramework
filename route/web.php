<?php

use App\Controllers\AuthController;
use zFramework\Core\Route;
use App\Controllers\HomeController;
use App\Controllers\LanguageController;

Route::get('/language/{lang}', [LanguageController::class, 'set'])->name('language');

Route::middleware([App\Middlewares\Guest::class])->group(function () {
    Route::get('/auth', [AuthController::class, 'auth'])->name('auth-form');
    Route::post('/sign-in', [AuthController::class, 'signin'])->name('sign-in');
    Route::post('/sign-up', [AuthController::class, 'signup'])->name('sign-up');
});

Route::middleware([App\Middlewares\Auth::class])->group(fn() => Route::any('/sign-out', [AuthController::class, 'signout'])->name('sign-out'));

Route::get('/auth-content', [AuthController::class, 'content'])->name('auth-content');
Route::resource('/', HomeController::class);
