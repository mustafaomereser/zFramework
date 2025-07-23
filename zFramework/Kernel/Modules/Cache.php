<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

class Cache
{
    public static function begin()
    {
        self::{Terminal::$commands[1]}();
    }

    public static function clear()
    {
        global $storage_path;

        $option = Terminal::$commands[2];

        $list = scan_dir($storage_path);
        if (!in_array($option, $list)) return Terminal::text("[color=red]Wrong Option!\nOptions: " . implode(', ', $list) . ".[/color]");

        Terminal::text("[color=yellow]Processing...[/color]");

        $dir = glob($storage_path . "/$option/*");
        foreach ($dir as $unlink) unlink($unlink);

        Terminal::clear();
        Terminal::text("[color=green]$option (" . count($list) . " qty) caches cleared![/color]");
    }
}
