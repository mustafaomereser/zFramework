<?php

namespace App\Middlewares;

use zFramework\Core\Helpers\Assets;

#[\AllowDynamicProperties]
class AutoMinifyAssets
{
    # Note: thats just an example you can remove that middleware.
    public function attempt()
    {
        foreach (Assets::list(public_dir('/assets'), ['css', 'js']) as $asset_path) {
            if (strstr($asset_path, '.auto.min.')) continue;
            $asset_path     = str_replace('\\', '/', $asset_path);
            $filename       = @end(explode('/', $asset_path));
            $ext            = @end(explode('.', $filename));
            $purename       = str_replace(".$ext", '', $filename);
            $save_minify    = str_replace($filename, "$purename.auto.min.$ext", $asset_path);

            if (filemtime($asset_path) > @(filemtime($save_minify) ?? 0)) file_put_contents2($save_minify, Assets::{$ext . "Minify"}(file_get_contents($asset_path)));
        }

        return true;
    }
}
