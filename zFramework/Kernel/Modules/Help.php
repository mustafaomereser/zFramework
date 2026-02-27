<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Helpers\Module;
use zFramework\Kernel\Terminal;

class Help
{
    /**
     * Shows help page
     */
    public static function begin()
    {
        Terminal::text("[color=green]Usable Modules:[/color]");
        echo PHP_EOL;

        foreach (Module::$list as $name => $module) if (!strstr($name, '---')) {
            Terminal::text("• [color=yellow]" . $name . "[/color]");
            foreach ($module['methods'] as $method) Terminal::text("  -> [color=blue]" . $method['name'] . "[/color]" . $method['doc']);
            echo PHP_EOL;
        }
    }
}
