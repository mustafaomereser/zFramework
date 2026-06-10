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
            foreach ($module['methods'] as $method) {
                Terminal::text("  [color=blue]" . $method['name'] . "[/color]");
                foreach (array_filter(array_map('trim', preg_split('/\r?\n/', $method['doc']))) as $line) {
                    if (str_starts_with($line, 'Usage:'))
                        Terminal::text("    [color=cyan]" . $line . "[/color]");
                    elseif (str_starts_with($line, '@param') || str_starts_with($line, '@important'))
                        Terminal::text("    [color=dark-gray]" . $line . "[/color]");
                    elseif (str_starts_with($line, 'Description:'))
                        Terminal::text("    [color=light-gray]" . ltrim(substr($line, strlen('Description:'))) . "[/color]");
                    elseif (strlen($line))
                        Terminal::text("    [color=light-gray]" . $line . "[/color]");
                }
                echo PHP_EOL;
            }
            echo PHP_EOL;
        }
    }
}
