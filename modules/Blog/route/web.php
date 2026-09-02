<?php

use modules\Blog\Controllers\Admin\BlogCategoriesController as AdminBlogCategoriesController;
use modules\Blog\Controllers\Admin\BlogController as AdminBlogController;
use modules\Blog\Controllers\Client\BlogController as ClientBlogController;
use modules\Blog\Controllers\Client\CategoryController as ClientCategoryController;
use zFramework\Core\Route;

Route::pre('/blog')->group(function () {
    # The root resource registers /blog/{id}, which owns every one-segment url after it -
    # /blog/categories included. Anything more specific has to be declared above it, as
    # the admin group below already does.
    Route::resource('/categories', ClientCategoryController::class);
    Route::resource('/', ClientBlogController::class);
});

Route::pre('/admin')->middleware([App\Middlewares\Auth::class])->group(function () {
    Route::pre('/blog')->group(function () {
        Route::resource('/categories', AdminBlogCategoriesController::class);
        Route::resource('/', AdminBlogController::class);
    });
});