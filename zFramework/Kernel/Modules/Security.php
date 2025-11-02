<?php

namespace zFramework\Kernel\Modules;

use zFramework\Core\Facades\Config;
use zFramework\Core\Facades\Str;
use zFramework\Kernel\Terminal;

class Security
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Create a crypt key.
     * @param --regen (optional)
     */
    public static function key()
    {
        if (in_array('--regen', Terminal::$parameters)) {
            Config::set('crypt', [
                'key'  => Str::rand(30),
                'salt' => Str::rand(30)
            ]);

            Terminal::text('[color=green]Security crypt key is regenerated.[/color]');
        } else {
            Terminal::text('[color=red]Security crypt key is already exists! (for force --regen)[/color]');
        }
    }
}
