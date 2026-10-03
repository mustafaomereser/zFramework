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

        # Remembered, because the return shape follows what was asked for and not
        # what survived. A `<input multiple>` where two of three files were rejected
        # used to answer with the one path as a bare string, and the foreach on the
        # other side walked its characters.
        $single = gettype($file['name']) === 'string';

        if ($single) foreach ($file as $key => $val) $file[$key] = [$val];

        $path = self::path($path);
        foreach ($file['name'] as $key => $name) {
            # An optional field nobody touched arrives as UPLOAD_ERR_NO_FILE with an
            # empty name, and warning about its file type is nonsense - there is no
            # file. It used to fail the accept check and tell the visitor so.
            if (($file['error'][$key] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_NO_FILE) continue;

            $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $error = 0;

            # Whatever the accept list says - and with no accept list at all, which
            # used to mean no check at all - a name the web server would execute
            # does not land in the webroot under that name. shell.php, and
            # shell.php.jpg for a server that reads every extension, were kept as
            # sent, executable, at a url the uploader was then handed.
            if (self::executable($name)) {
                $error++;
                Alerts::danger(Lang::get('errors.file.type', ['file_types' => implode(', ', $options['accept'] ?? [])]));
            }

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
        return $single ? end($files) : array_values($files);
    }

    /**
     * Send a file as a download response.
     * @param string $file  Path relative to public_dir
     */
    public static function download(string $file): never
    {
        $fullPath = public_dir($file);
        if (!self::inside($fullPath) || !file_exists($fullPath)) abort(404, 'File not exists.');

        $headers = [
            'Cache-Control'             => 'public',
            'Content-Type'              => 'application/octet-stream',
            'Content-Transfer-Encoding' => 'Binary',
            'Content-Length'            => (string) filesize($fullPath),
            'Content-Disposition'       => 'attachment; filename="' . basename($fullPath) . '"',
        ];

        # Under FPM keep streaming: readfile() sends the file in chunks, so a 2 GB
        # download costs no more memory than a 2 KB one. The signal carries the
        # body in a string, which is only acceptable where there is no choice.
        if (PHP_SAPI !== 'cli') {
            foreach ($headers as $name => $value) \zFramework\Core\Facades\Response::header($name, $value);
            readfile($fullPath);
            throw new \zFramework\Core\ResponseSignal();
        }

        # A long-running worker has to hand the response back rather than write it
        # out, so here the file does go through memory.
        throw new \zFramework\Core\ResponseSignal(200, $headers, (string) file_get_contents($fullPath));
    }

    /**
     * Open an image by what the file is, not what it is called.
     *
     * getimagesize() reads the header, so a PNG uploaded as photo.webp still
     * opens - the extension-picked imagecreatefromwebp() returned false for it
     * and the caller died passing false to imagecopyresampled(). A type GD has
     * no dedicated loader for falls back to imagecreatefromstring().
     *
     * @param string $file
     * @param int    $type IMAGETYPE_* as getimagesize() reported it.
     * @return \GdImage|false
     */
    private static function openImage(string $file, int $type): \GdImage|false
    {
        $loader = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG  => 'imagecreatefrompng',
            IMAGETYPE_GIF  => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_BMP  => 'imagecreatefrombmp',
            IMAGETYPE_AVIF => 'imagecreatefromavif',
        ][$type] ?? null;

        try {
            $image = $loader && function_exists($loader) ? @$loader($file) : @imagecreatefromstring((string) file_get_contents($file));
        } catch (\Throwable) {
            return false;
        }

        return $image ?: false;
    }

    /**
     * A blank canvas for a target format.
     *
     * imagecreatetruecolor() starts opaque black: transparent areas came out
     * black in every format that has an alpha channel. Formats without one get
     * white instead, so a transparent PNG converted to JPEG is not black either.
     *
     * @param int    $width
     * @param int    $height
     * @param string $ext
     * @return \GdImage
     */
    private static function canvas(int $width, int $height, string $ext): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if (in_array($ext, ['png', 'gif', 'webp', 'avif'], true)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));

        return $canvas;
    }

    /**
     * Write an image in the format its extension names.
     *
     * Quality only where the format takes one on a 0-100 scale: imagepng()'s
     * third argument is a 0-9 compression level and threw a ValueError at 100,
     * imagegif() takes no third argument at all, and imagebmp()'s is a bool.
     *
     * @param \GdImage $image
     * @param string   $path
     * @param string   $ext
     * @return bool    False for a format GD cannot write here.
     */
    private static function saveImage(\GdImage $image, string $path, string $ext): bool
    {
        $writer = [
            'jpg'  => fn() => imagejpeg($image, $path, 100),
            'jpeg' => fn() => imagejpeg($image, $path, 100),
            'png'  => fn() => imagepng($image, $path),
            'gif'  => fn() => imagegif($image, $path),
            'webp' => fn() => imagewebp($image, $path, 100),
            'bmp'  => fn() => imagebmp($image, $path),
            'avif' => fn() => function_exists('imageavif') && imageavif($image, $path, 100),
        ][$ext] ?? null;

        return $writer ? (bool) $writer() : false;
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
        $ext  = strtolower($info['extension'] ?? '');

        # Not an image, or a damaged one.
        $probe = @getimagesize($file);
        if (!$probe || !$probe[0] || !$probe[1]) return false;
        [$image_width, $image_height] = $probe;

        if (!$sizes['desired_sizes']) {
            $src_aspect = $image_width / $image_height;
            $dst_aspect = $sizes['width'] / $sizes['height'];
            if ($src_aspect > $dst_aspect) $sizes['height'] = $sizes['width'] / $src_aspect;
            else $sizes['width'] = $sizes['height'] * $src_aspect;
        }

        # The aspect maths yields fractions; GD takes whole pixels.
        $sizes['width']  = max(1, (int) round($sizes['width']));
        $sizes['height'] = max(1, (int) round($sizes['height']));

        $to_save = $new_name
            ? str_replace($info['filename'], $new_name, $file)
            : str_replace(".$ext", '', $file) . '-' . implode('x', [$sizes['width'], $sizes['height']]) . ".$ext";

        $source = self::openImage($file, $probe[2]);
        if (!$source) return false;

        $target = self::canvas($sizes['width'], $sizes['height'], $ext);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $sizes['width'], $sizes['height'], $image_width, $image_height);

        return self::saveImage($target, $to_save, $ext) ? self::removePublic($to_save) : false;
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

        $to      = strtolower($to);
        $info    = pathinfo($file);
        $to_save = $info['dirname'] . '/' . $info['filename'] . '.' . $to;

        $probe = @getimagesize($file);
        if (!$probe || !$probe[0] || !$probe[1]) return false;
        [$width, $height] = $probe;

        $from = self::openImage($file, $probe[2]);
        if (!$from) return false;

        $target = self::canvas($width, $height, $to);
        imagecopyresampled($target, $from, 0, 0, 0, 0, $width, $height, $width, $height);

        return self::saveImage($target, $to_save, $to) ? self::removePublic($to_save) : false;
    }

    /**
     * Format bytes as a human-readable string.
     * @param float $bytes
     * @param int   $decimals
     * @return string
     */
    public static function humanFileSize(float $bytes, int $decimals = 2): string
    {
        # By powers of 1024, not decimal digit count: 1023 showed as "1.00KB",
        # 1000 as "0.98KB", and a float in e-notation broke the digit trick.
        $factor = $bytes >= 1 ? floor(log($bytes, 1024)) : 0;
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
        if (!self::inside($full) || !is_file($full)) return false;
        return unlink($full);
    }

    /**
     * Is this path under the public directory once `..` is resolved?
     *
     * public_dir() only concatenates, so `../config/app.php` left the webroot
     * and download() served it, delete() removed it.
     *
     * @param string $path
     * @return bool
     */
    private static function inside(string $path): bool
    {
        $root = realpath(PUBLIC_DIR);
        $real = realpath($path);
        if ($root === false || $real === false) return false;

        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $real = str_replace('\\', '/', $real);
        return str_starts_with($real . '/', $root);
    }

    /**
     * Would the web server run this file rather than serve it?
     *
     * Every dotted segment after the first is checked, not only the last: Apache
     * with a multi-extension handler runs shell.php.jpg as php. Server control
     * files (.htaccess, .user.ini) count too - they change how the directory is
     * served. html and svg are refused as well: served from the application's
     * own origin they are a stored script, not a document.
     *
     * @param string $name
     * @return bool
     */
    public static function executable(string $name): bool
    {
        $base = strtolower(basename($name));
        if (in_array($base, ['.htaccess', '.htpasswd', '.user.ini', 'web.config'], true)) return true;

        $parts = explode('.', $base);
        array_shift($parts);
        foreach ($parts as $ext)
            if (preg_match('/^(php\d*|phtml|phar|phps|pht|cgi|pl|py|sh|bat|cmd|exe|com|asp|aspx|jsp|jspx|shtml|html?|svg)$/', $ext)) return true;

        return false;
    }
}
