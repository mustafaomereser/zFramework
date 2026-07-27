<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use zFramework\Core\Facades\Config as ConfigFacade;

class Config
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Compile every config file into one cached array
     * Usage: php terminal config cache
     */
    public static function cache()
    {
        $files   = glob(base_path('config') . '/*.php');
        $merged  = [];
        $skipped = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $data = include $file;

            if (!is_array($data)) continue;

            # var_export() cannot write closures. Such a file stays out of the
            # compile and keeps being read the normal way - correct, just not cached.
            if (self::hasClosure($data)) {
                $skipped[] = $name;
                continue;
            }

            $merged[$name] = $data;
        }

        ConfigFacade::clearCache();
        file_put_contents2(ConfigFacade::cachePath(), "<?php \nreturn " . var_export($merged, true) . ";");

        Terminal::text("[color=green]Config cached:[/color] " . count($merged) . " file(s) -> " . ConfigFacade::cachePath());
        foreach ($skipped as $name) Terminal::text("[color=yellow]-> `$name` skipped: contains a closure, will be read from disk.[/color]");
        Terminal::text("[color=dark-gray]Run `php terminal config clear` after changing a config file.[/color]");
    }

    /**
     * Description: Remove the compiled config cache
     * Usage: php terminal config clear
     */
    public static function clear()
    {
        ConfigFacade::clearCache();
        Terminal::text("[color=green]Config cache cleared.[/color]");
    }

    /**
     * Does this structure hold a closure anywhere?
     *
     * @param mixed $data
     * @return bool
     */
    private static function hasClosure(mixed $data): bool
    {
        if ($data instanceof \Closure) return true;
        if (!is_array($data)) return false;

        foreach ($data as $value) if (self::hasClosure($value)) return true;
        return false;
    }
}
