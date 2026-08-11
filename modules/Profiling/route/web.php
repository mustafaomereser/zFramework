<?php

use modules\Profiling\Controllers\ReportController;
use zFramework\Core\Route;

/**
 * Controller callbacks rather than closures: one closure anywhere keeps the whole
 * route table out of the compiled cache, and a module has no business costing an
 * application that.
 */
Route::pre('/profiling')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/clear', [ReportController::class, 'clear'])->name('clear');
});
