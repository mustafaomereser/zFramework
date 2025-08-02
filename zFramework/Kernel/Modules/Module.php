<?php

namespace zFramework\Kernel\Modules;

use zFramework\Core\Helpers\Date;
use zFramework\Kernel\Terminal;

class Module
{
    static $assets_path = FRAMEWORK_PATH . "\Kernel\Includes\module\\";
    static $assets;

    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');

        if (empty(Terminal::$commands[2])) return Terminal::text('[color=red]Module name is required.[/color]');
        self::{Terminal::$commands[1]}(Terminal::$commands[2]);
    }

    private static function assets()
    {
        $assets = [];
        foreach (glob(self::$assets_path . "*") as $key => $val) $assets[strtolower(str_replace(self::$assets_path, '', $val))] = $val;
        self::$assets = $assets;
    }

    /**
     * Description: Create an module
     * @important $name
     * @param $name (second arguman)
     */
    public static function create($name)
    {
        self::assets();

        if (is_dir(base_path("/modules/$name"))) return Terminal::text("[color=red]`$name` module already exists.[/color]");

        foreach (['route', 'views', 'Controllers', 'Middlewares', 'Models', 'Requests', 'Observers', 'migrations'] as $folder) @mkdir(base_path("/modules/$name/$folder"), 0777, true);
        file_put_contents(base_path("/modules/$name/route/web.php"), str_replace(['{name}'], [$name], file_get_contents(self::$assets['route'])));
        file_put_contents(base_path("/modules/$name/info.php"), str_replace(['{name}', '{date}', '{author}', '{framework_version}', '{sort}'], [$name, Date::timestamp(), gethostname(), FRAMEWORK_VERSION, count(scan_dir(base_path("/modules")))], file_get_contents(self::$assets['info'])));
        return Terminal::text("[color=green]`$name` module is created.[/color]");
    }
}
