<?php

namespace zFramework\Core\Helpers;

use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Lang;
use zFramework\Core\Facades\Str;

class File
{
    /**
     * Get path, if not exists create and get path.
     * @param string $path
     * @return string
     */
    private static function path(string $path): string
    {
        $path = public_dir($path);
        @mkdir($path, 0777, true);
        return $path;
    }

    /**
     * Check File is exists.
     * @param string $file
     * @param int $level
     * @return string
     */
    private static function checkIsExist(string $file, int $level = 0): string
    {
        if (!is_file($file)) return $file;
        $info = pathinfo($file);
        $file = str_replace($info['filename'], $info['filename'] . Str::rand(2 + $level), $file);
        return self::checkIsExist($file, ($level + 1));
    }

    /**
     * Remove public_dir string
     * @return string
     */
    private static function removePublic(string $name): string
    {
        return str_replace(public_dir(), '', $name);
    }

    /**
     * Save a file
     * @param string $path
     * @param string $file
     * @return string
     */
    public static function save(string $path, string $file): string
    {
        $uploadName = self::path($path) . "/" . @end(explode('/', $file));
        file_put_contents($uploadName, file_get_contents($file));
        return self::removePublic($uploadName);
    }

    /**
     * Upload files
     * @param string $path
     * @param array $file
     * @param array $options
     * @return bool|array
     */
    public static function upload(string $path, array $file, array $options = [])
    {
        $files = [];

        if (gettype($file['name']) === 'string') foreach ($file as $key => $val) $file[$key] = [$val];

        $path = self::path($path);
        foreach ($file['name'] as $key => $name) {
            $name = $file['name'][$key];
            $error = 0;

            if (isset($options['accept'])) {
                $ext = @end(explode('.', $name));
                if (!in_array($ext, $options['accept'])) {
                    $error++;
                    Alerts::danger(Lang::get('errors.file.type', ['file_types' => implode(', ', $options['accept'])]));
                }
            }

            if (isset($options['size']) && is_numeric($options['size']))
                if ($file['size'][$key] > $options['size']) {
                    $error++;
                    Alerts::danger(Lang::get('errors.file.size', ['current-size' => self::humanFileSize($file['size'][$key]), 'accept-size' => self::humanFileSize($options['size'])]));
                }

            if ($error) continue;

            $uploadName = self::checkIsExist("$path/$name");
            if (move_uploaded_file($file['tmp_name'][$key], $uploadName)) $files[$key] = self::removePublic($uploadName);
        }

        if (!count($files)) return false;
        return count($files) > 1 ? $files : @end($files);
    }

    /**
     * Download a file from public_dir
     * @param string $file
     */
    public static function download(string $file)
    {
        $attachment_location = public_dir($file);
        $filename = self::removePublic(@end(explode('/', str_replace('\\', '/', $file))));

        if (!file_exists($attachment_location)) abort(404, 'File not exists.');

        header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
        header("Cache-Control: public");
        header("Content-Type: application/zip");
        header("Content-Transfer-Encoding: Binary");
        header("Content-Length:" . filesize($attachment_location));
        header("Content-Disposition: attachment; filename=$filename");
        die(readfile($attachment_location));
    }

    /**
     * Resize a image
     * @param string $file
     * @param int $width
     * @param int $height
     * @param bool $force_to_desired_sizes
     * @param string $_target
     * @param bool $info_size
     * @return string
     */
    public static function resizeImage(string $file, int $width = 50, int $height = 50, bool $force_to_desired_sizes = true, string $_target = null, bool $info_size = false): string
    {
        $file = public_dir($file);
        if (!is_file($file)) return false;

        $info     = pathinfo($file);
        $filename = $info['filename'];
        $ext      = strtolower($info['extension']);

        list($image_width, $image_height) = getimagesize($file);

        if (!$force_to_desired_sizes) {
            $src_aspect = $image_width / $image_height;
            $dst_aspect = $width / $height;
            if ($src_aspect > $dst_aspect) $height = $width / $src_aspect;
            else $width  = $height * $src_aspect;
        }

        $to_save = self::removePublic($file);
        if ($info_size) $to_save = str_replace(".$ext", '', $to_save) . "-$width" . "x" . "$height.$ext";
        if ($_target) $to_save = "$_target/$filename";

        $callbacks = [
            'jpg'  => ['source' => fn() => imagecreatefromjpeg($file), 'target' => fn($target) => imagejpeg($target, public_dir($to_save), 100)],
            'jpeg' => ['source' => fn() => imagecreatefromjpeg($file), 'target' => fn($target) => imagejpeg($target, public_dir($to_save), 100)],
            'png'  => ['source' => fn() => imagecreatefrompng($file), 'target' => fn($target) => imagepng($target, public_dir($to_save), 100)],
            'gif'  => ['source' => fn() => imagecreatefromgif($file), 'target' => fn($target) => imagegif($target, public_dir($to_save), 100)],
            'webp' => ['source' => fn() => imagecreatefromwebp($file), 'target' => fn($target) => imagewebp($target, public_dir($to_save), 100)],
            'bmp'  => ['source' => fn() => imagecreatefrombmp($file), 'target' => fn($target) => imagebmp($target, public_dir($to_save), 100)],
            'avif' => ['source' => fn() => imagecreatefromavif($file), 'target' => fn($target) => imageavif($target, public_dir($to_save), 100)],
        ][$ext];

        $source = $callbacks['source']();
        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $image_width, $image_height);

        $callbacks['target']($target);

        // clear cache
        imagedestroy($source);
        imagedestroy($target);
        //

        return self::removePublic($file);
    }


    /**
     * Convert Image to different extension
     * @param string $file
     * @param string $to
     * @return string
     */
    public function convertImage(string $file, string $to)
    {
        $file = public_dir($file);
        if (!is_file($file)) return false;

        $info     = pathinfo($file);
        $filename = $info['filename'];
        $to_save  = $info['dirname'] . "/$filename.$to";
        $ext      = strtolower($info['extension']);

        $from = [
            'jpeg' => fn() => imagecreatefromjpeg($file),
            'jpg'  => fn() => imagecreatefromjpeg($file),
            'png'  => fn() => imagecreatefrompng($file),
            'gif'  => fn() => imagecreatefromgif($file),
            'webp' => fn() => imagecreatefromwebp($file),
            'bmp'  => fn() => imagecreatefrombmp($file),
            'avif' => fn() => imagecreatefromavif($file),
        ][$ext]();

        list($width, $height) = getimagesize($file);
        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $from, 0, 0, 0, 0, $width, $height, $width, $height);

        $to = [
            'jpeg' => fn() => imagejpeg($target, public_dir($to_save), 100),
            'jpg'  => fn() => imagejpeg($target, public_dir($to_save), 100),
            'png'  => fn() => imagepng($target, public_dir($to_save), 100),
            'gif'  => fn() => imagegif($target, public_dir($to_save), 100),
            'webp' => fn() => imagewebp($target, public_dir($to_save), 100),
            'bmp'  => fn() => imagebmp($target, public_dir($to_save), 100),
            'avif' => fn() => imageavif($target, public_dir($to_save), 100),
        ][$to]();

        // clear cache
        imagedestroy($from);
        imagedestroy($to);
        //

        return $to_save;
    }


    /**
     * Show human readable file size
     * 
     * @param float $bytes
     * @param int $decimals
     * @return string
     */
    public static function humanFileSize(float $bytes, int $decimals = 2): string
    {
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'][$factor];
    }
}
