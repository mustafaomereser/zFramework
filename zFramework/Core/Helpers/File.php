<?php

namespace zFramework\Core\Helpers;

use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Lang;
use zFramework\Core\Facades\Str;

class File
{
    /** Extension → allowed MIME types map for upload validation. */
    private static array $mimeMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-bmp'],
        'avif' => ['image/avif'],
        'svg'  => ['image/svg+xml'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'mp3'  => ['audio/mpeg'],
        'wav'  => ['audio/wav', 'audio/x-wav'],
        'ogg'  => ['audio/ogg', 'video/ogg'],
        'pdf'  => ['application/pdf'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    /**
     * Get path, creating it if it does not exist.
     * @param string $path
     * @return string
     */
    private static function path(string $path): string
    {
        $path = public_dir($path);
        if (!is_dir($path)) mkdir($path, 0755, true);
        return $path;
    }

    /**
     * Append a suffix to the filename until no collision exists.
     * @param string $file
     * @return string
     */
    private static function checkIsExist(string $file): string
    {
        if (!is_file($file)) return $file;

        $info  = pathinfo($file);
        $level = 1;
        do {
            $candidate = $info['dirname'] . '/' . $info['filename'] . Str::rand(2 + $level) . '.' . ($info['extension'] ?? '');
            $level++;
        } while (is_file($candidate));

        return $candidate;
    }

    /**
     * Remove the public_dir prefix from a path.
     * @param string $name
     * @return string
     */
    public static function removePublic(string $name): string
    {
        return str_replace(public_dir(), '', $name);
    }

    /**
     * Copy a file into a public path.
     * @param string $path  Destination directory (relative to public_dir)
     * @param string $file  Source file path
     * @return string
     */
    public static function save(string $path, string $file): string
    {
        $dest = self::path($path) . '/' . basename($file);
        file_put_contents($dest, file_get_contents($file));
        return self::removePublic($dest);
    }

    /**
     * Upload one or more files.
     * @param string $path      Destination directory (relative to public_dir)
     * @param array  $file      $_FILES entry
     * @param array  $options   accept (string[]), size (int bytes)
     * @return string|array|false  Single path, array of paths, or false on total failure
     */
    public static function upload(string $path, array $file, array $options = []): string|array|false
    {
        $files = [];

        if (gettype($file['name']) === 'string') foreach ($file as $key => $val) $file[$key] = [$val];

        $path = self::path($path);
        foreach ($file['name'] as $key => $name) {
            $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $error = 0;

            if (isset($options['accept'])) {
                if (!in_array($ext, $options['accept'])) {
                    $error++;
                    Alerts::danger(Lang::get('errors.file.type', ['file_types' => implode(', ', $options['accept'])]));
                } else {
                    $allowedMimes = array_merge(...array_map(fn($e) => self::$mimeMap[$e] ?? [], $options['accept']));
                    if (!empty($allowedMimes)) {
                        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'][$key]);
                        if (!in_array($mime, $allowedMimes)) {
                            $error++;
                            Alerts::danger(Lang::get('errors.file.type', ['file_types' => implode(', ', $options['accept'])]));
                        }
                    }
                }
            }

            if (isset($options['size']) && is_numeric($options['size']) && $file['size'][$key] > $options['size']) {
                $error++;
                Alerts::danger(Lang::get('errors.file.size', ['current-size' => self::humanFileSize($file['size'][$key]), 'accept-size' => self::humanFileSize($options['size'])]));
            }

            if ($error) continue;

            $uploadName = self::checkIsExist("$path/" . basename($name));
            if (move_uploaded_file($file['tmp_name'][$key], $uploadName)) $files[$key] = self::removePublic($uploadName);
        }

        if (!count($files)) return false;
        return count($files) > 1 ? $files : end($files);
    }

    /**
     * Send a file as a download response.
     * @param string $file  Path relative to public_dir
     */
    public static function download(string $file): never
    {
        $fullPath = public_dir($file);
        if (!file_exists($fullPath)) abort(404, 'File not exists.');

        header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
        header("Cache-Control: public");
        header("Content-Type: application/octet-stream");
        header("Content-Transfer-Encoding: Binary");
        header("Content-Length: " . filesize($fullPath));
        header("Content-Disposition: attachment; filename=\"" . basename($fullPath) . "\"");
        readfile($fullPath);
        exit;
    }

    /**
     * Resize an image file.
     * @param string      $file   Path relative to public_dir
     * @param array       $sizes  width, height, desired_sizes
     * @param string|null $new_name
     * @return string|false  New file path or false on failure
     */
    public static function resizeImage(string $file, array $sizes = [], ?string $new_name = null): string|false
    {
        $file = public_dir($file);
        if (!is_file($file)) return false;

        $sizes = [
            'width'         => $sizes['width'] ?? 50,
            'height'        => $sizes['height'] ?? 50,
            'desired_sizes' => $sizes['desired_sizes'] ?? true,
        ];

        $info = pathinfo($file);
        $ext  = strtolower($info['extension']);

        [$image_width, $image_height] = getimagesize($file);

        if (!$sizes['desired_sizes']) {
            $src_aspect = $image_width / $image_height;
            $dst_aspect = $sizes['width'] / $sizes['height'];
            if ($src_aspect > $dst_aspect) $sizes['height'] = $sizes['width'] / $src_aspect;
            else $sizes['width'] = $sizes['height'] * $src_aspect;
        }

        $to_save = $new_name
            ? str_replace($info['filename'], $new_name, $file)
            : str_replace(".$ext", '', $file) . '-' . implode('x', [$sizes['width'], $sizes['height']]) . ".$ext";

        $callbacks = [
            'jpg'  => ['source' => fn() => imagecreatefromjpeg($file), 'target' => fn($t) => imagejpeg($t, $to_save, 100)],
            'jpeg' => ['source' => fn() => imagecreatefromjpeg($file), 'target' => fn($t) => imagejpeg($t, $to_save, 100)],
            'png'  => ['source' => fn() => imagecreatefrompng($file),  'target' => fn($t) => imagepng($t, $to_save, 100)],
            'gif'  => ['source' => fn() => imagecreatefromgif($file),  'target' => fn($t) => imagegif($t, $to_save, 100)],
            'webp' => ['source' => fn() => imagecreatefromwebp($file), 'target' => fn($t) => imagewebp($t, $to_save, 100)],
            'bmp'  => ['source' => fn() => imagecreatefrombmp($file),  'target' => fn($t) => imagebmp($t, $to_save, 100)],
            'avif' => ['source' => fn() => imagecreatefromavif($file), 'target' => fn($t) => imageavif($t, $to_save, 100)],
        ][$ext] ?? null;

        if (!$callbacks) return false;

        $source = $callbacks['source']();
        $target = imagecreatetruecolor($sizes['width'], $sizes['height']);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $sizes['width'], $sizes['height'], $image_width, $image_height);
        $callbacks['target']($target);

        return self::removePublic($to_save);
    }

    /**
     * Convert an image to a different format.
     * @param string $file  Path relative to public_dir
     * @param string $to    Target extension
     * @return string|false  New file path or false on failure
     */
    public static function convertImage(string $file, string $to): string|false
    {
        $file = public_dir($file);
        if (!is_file($file)) return false;

        $info    = pathinfo($file);
        $ext     = strtolower($info['extension']);
        $to_save = $info['dirname'] . '/' . $info['filename'] . '.' . $to;

        $sources = [
            'jpeg' => fn() => imagecreatefromjpeg($file),
            'jpg'  => fn() => imagecreatefromjpeg($file),
            'png'  => fn() => imagecreatefrompng($file),
            'gif'  => fn() => imagecreatefromgif($file),
            'webp' => fn() => imagecreatefromwebp($file),
            'bmp'  => fn() => imagecreatefrombmp($file),
            'avif' => fn() => imagecreatefromavif($file),
        ];

        $targets = [
            'jpeg' => fn($t) => imagejpeg($t, $to_save, 100),
            'jpg'  => fn($t) => imagejpeg($t, $to_save, 100),
            'png'  => fn($t) => imagepng($t, $to_save, 100),
            'gif'  => fn($t) => imagegif($t, $to_save, 100),
            'webp' => fn($t) => imagewebp($t, $to_save, 100),
            'bmp'  => fn($t) => imagebmp($t, $to_save, 100),
            'avif' => fn($t) => imageavif($t, $to_save, 100),
        ];

        if (!isset($sources[$ext], $targets[$to])) return false;

        [$width, $height] = getimagesize($file);
        $from   = $sources[$ext]();
        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $from, 0, 0, 0, 0, $width, $height, $width, $height);
        $targets[$to]($target);

        return self::removePublic($to_save);
    }

    /**
     * Format bytes as a human-readable string.
     * @param float $bytes
     * @param int   $decimals
     * @return string
     */
    public static function humanFileSize(float $bytes, int $decimals = 2): string
    {
        $factor = floor((strlen((string) $bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . (['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'][$factor] ?? '');
    }


    /**
     * Delete a file from public_dir.
     * @param string $file  Path relative to public_dir
     * @return bool
     */
    public static function delete(string $file): bool
    {
        $full = public_dir($file);
        if (!is_file($full)) return false;
        return unlink($full);
    }
}
