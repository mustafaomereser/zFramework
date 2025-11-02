<?php

namespace zFramework\Kernel\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use zFramework\Core\Helpers\Assets;
use zFramework\Kernel\Terminal;
use ZipArchive;

class Release
{
    static $cache   = FRAMEWORK_PATH . "/Caches/Releases";
    static $ignored = ['.git/', 'README.md', '.gitignore', '.gitkeep'];

    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::ignoreList();
        self::{Terminal::$commands[1]}();
    }

    /**
     * Make release, easily make release for updates.
     * @param string --name (optional)   -> release profile name (default: default).
     * @param string --date (optional)   -> start check filemtime.
     * @param string --minify (optional) -> to minify css and js files.
     */
    public static function make()
    {
        $release_name = Terminal::$parameters['--name'] ?? 'default';

        if (isset(Terminal::$parameters['--date'])) $release_date = strtotime(Terminal::$parameters['--date']);
        else $release_date = @json_decode(file_get_contents(self::$cache . "/$release_name.json") ?? '[]', true)['last'] ?? 0;

        if (!$release_date) {
            $key = array_key_first(array_filter(array_map(fn($ignore) => strstr($ignore, 'vendor') ? $ignore : false, self::$ignored)));
            unset(self::$ignored[$key]);
        }

        $files = [];
        foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH, FilesystemIterator::SKIP_DOTS))) as $file) {
            if (!$file->isFile()) continue;
            $path = $file->getPathname();
            if (self::isIgnored($path) || (($release_date == 0 ? false : $release_date > filemtime($path)))) continue;
            $files[] = $path;
        }

        $count = count($files);
        if (!$count) return Terminal::text("[color=red]Release: $release_name has nothing changes.[/color]");

        Terminal::text("[color=yellow]Release: $release_name has `$count` changes.[/color]\n");

        $zip = new ZipArchive();
        @mkdir(BASE_PATH . "/Releases/$release_name", 0777, true);
        if ($zip->open(BASE_PATH . "/Releases/$release_name/" . (!$release_date ? 'initial' : date('Y-m-d-H-i-s')) . ".zip", ZipArchive::CREATE) !== TRUE) die("Zip cannot open!");
        foreach ($files as $key => $path) {
            Terminal::bar($count, $key + 1);
            $file = str_replace([BASE_PATH . DIRECTORY_SEPARATOR, '/'], ['', DIRECTORY_SEPARATOR], $path);

            $addFromString = null;

            if (in_array('--minify', Terminal::$parameters)) {
                if (strstr($file, '.css')) $addFromString = Assets::cssMinify(file_get_contents($path));
                if (strstr($file, '.js')) $addFromString  = Assets::jsMinify(file_get_contents($path));
            }

            $add = $addFromString ? $zip->addFromString($file, $addFromString) : $zip->addFile($path, $file);
            if (!$add) throw new \Exception("$file cannot add to zip.");
        }
        $zip->close();

        Terminal::text("\r[color=green]Released: $release_name.[/color]\033[K");

        file_put_contents2(self::$cache . "/$release_name.json", json_encode(['last' => time()], JSON_UNESCAPED_UNICODE));
    }

    private static function isIgnored($path)
    {
        $path = str_replace([BASE_PATH . DIRECTORY_SEPARATOR, '/'], ['', DIRECTORY_SEPARATOR], $path);
        foreach (self::$ignored as $pattern) if (strstr($path, str_replace(['**', '/'], ['*', DIRECTORY_SEPARATOR], $pattern))) return true;
        return false;
    }

    private static function ignoreList()
    {
        $gitignore = BASE_PATH . '/.gitignore';
        if (!file_exists($gitignore)) return;

        foreach (file($gitignore, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            self::$ignored[] = $line;
        }
    }
}
