<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

class Cache
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Cache Clear
     * Usage: php terminal cache clear {views|sessions|pages|ratelimit}
     * @param {views|sessions|pages|ratelimit} (second argument)
     */
    public static function clear()
    {
        global $storage_path;

        $option = @Terminal::$commands[2];

        # A fixed list, not whatever directories happen to exist: on a clean
        # install storage/pages is not there yet, and "clear pages" answered
        # "Wrong Option!" instead of "nothing to clear". And scan_dir offered
        # things that are not caches - routes.cache.php, AutoSSL - for deletion.
        $list = ['views', 'sessions', 'pages', 'ratelimit', 'logs'];
        if (!in_array($option, $list, true)) return Terminal::text("[color=red]Wrong Option!\nOptions: " . implode(', ', $list) . ".[/color]");

        Terminal::text("[color=yellow]Processing...[/color]");

        $count = is_dir($storage_path . "/$option") ? rrmdir($storage_path . "/$option") : 0;

        Terminal::clear();
        Terminal::text("[color=green]$option ($count qty) caches cleared![/color]");
    }
}
