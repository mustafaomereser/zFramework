<?php

use modules\Blog\Controllers\Admin\BlogCategoriesController as AdminBlogCategoriesController;
use modules\Blog\Controllers\Admin\BlogController as AdminBlogController;
use modules\Blog\Controllers\Client\BlogController as ClientBlogController;
use modules\Blog\Controllers\Client\CategoryController as ClientCategoryController;
use zFramework\Core\Route;

Route::pre('/blog')->group(function () {
    Route::resource('/', ClientBlogController::class);
    Route::resource('/categories', ClientCategoryController::class);
});

Route::pre('/admin')->middleware([App\Middlewares\Auth::class])->group(function () {
    Route::pre('/blog')->group(function () {
        Route::resource('/categories', AdminBlogCategoriesController::class);
        Route::resource('/', AdminBlogController::class);
    });
});