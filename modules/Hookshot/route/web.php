<?php

use zFramework\Core\Route;

Route::pre('/hookshot')->group(function () {
    Route::get('/', fn() => view('modules.Hookshot.views.index'))->name('index');
});
