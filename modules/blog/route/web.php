<?php

use Modules\Blog\Controllers\Admin\BlogCategoriesController as AdminBlogCategoriesController;
use Modules\Blog\Controllers\Admin\BlogController as AdminBlogController;
use Modules\Blog\Controllers\Client\BlogController as ClientBlogController;
use Modules\Blog\Controllers\Client\CategoryController as ClientCategoryController;
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