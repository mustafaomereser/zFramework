<?php

use zFramework\Core\Route;

Route::pre('/hookshot')->group(function () {
    Route::get('/', fn() => view('modules.Hookshot.views.index'))->name('index');

    Route::pre('/assets')->group(function () {
        foreach (glob(base_path('/modules/Hookshot/assets') . '/*') as $asset) Route::any("/" . str_replace('.', '-', @end(explode('/', $asset))), function () use ($asset) {
            $ext = pathinfo($asset, PATHINFO_EXTENSION);

            $mimeTypes = [
                'js'   => 'application/javascript',
                'css'  => 'text/css',
                'json' => 'application/json',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'svg'  => 'image/svg+xml',
            ];

            if (!isset($mimeTypes[$ext])) abort(400, 'There is no mimetype');
            header("Content-Type: " . $mimeTypes[$ext]);
            readfile($asset);
        });
    });
});
