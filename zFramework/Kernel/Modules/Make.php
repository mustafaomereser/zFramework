<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Helpers\Ask;
use zFramework\Kernel\Terminal;

class Make
{
    static $assets_path;
    static $assets;
    static $save        = "App";
    static $save_status = false;
    static $ask         = true;

    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');

        if (isset(Terminal::$parameters['--module'])) {
            self::$save        = "Modules/" . ucfirst(Terminal::$parameters['--module']);
            self::$save_status = true;
        }

        self::assets();
        self::do();
    }

    private static function assets()
    {
        self::$assets_path = FRAMEWORK_PATH . "/Kernel/Includes/make/";
        $assets = glob(self::$assets_path . "*");
        foreach ($assets as $key => $val) {
            unset($assets[$key]);
            $assets[strtolower(str_replace(self::$assets_path, '', $val))] = $val;
        }
        self::$assets = $assets;
    }

    private static function parseName()
    {
        $namespace  = explode('/', str_replace('\\', '/', Terminal::$commands[2]));
        $name       = ucfirst(end($namespace));
        unset($namespace[array_search(end($namespace), $namespace)]);
        $namespace  = implode('\\', $namespace);
        $table_name = "";

        foreach (str_split($name) as $key => $char) {
            if (ctype_upper($char)) $table_name .= ($key != 0 ? '_' : null) . strtolower($char);
            else $table_name .= $char;
        }

        return compact('namespace', 'name', 'table_name');
    }

    private static function do()
    {
        $method = Terminal::$commands[1];
        @$make  = self::$assets[$method];
        if (!$make) return Terminal::text('This is not makeable. List:' . implode(', ', array_keys(self::$assets)));

        if (in_array('--resource', Terminal::$parameters)) $make .= "_resource";

        extract(self::parseName());
        $make = str_replace(['{name}'], [$name], file_get_contents($make));

        if (!$make) return Terminal::text('This is not acceptable.');

        return self::{$method}($make);
    }

    private static function clearPath($str)
    {
        if (!strstr($str, '\\\\') && !strstr($str, '//')) return $str;
        return self::clearPath(str_replace(['\\\\', '//'], '/', $str));
    }

    private static function save($to, $content)
    {
        extract(self::parseName());

        $_to = base_path(str_replace('\\', '/', "$to/$namespace"));
        @mkdir($_to, 0777, true);
        $save_to = self::clearPath($_to . "/" . $name . ".php");

        if (file_exists($save_to)) return Terminal::text("[color=red]This is already exists. $save_to" . "[/color]");

        file_put_contents($save_to, str_replace(["{namespace}"], [str_replace('/', '\\', $to) . (strlen($namespace) ? "\\$namespace" : null)], $content));
        Terminal::text("[color=green]Asset is created to $save_to" . "[/color]");
    }


    /**
     * Description: Create a Request class
     * Usage: php kernel make request StoreUserRequest [--module=blog]
     * @param {name}           (third argument)
     * @param --module={name}  (optional)
     */
    public static function request($make)
    {
        return self::save(self::$save . '\Requests', $make);
    }

    /**
     * Description: Create a Controller class
     * Usage: php kernel make controller UserController [--resource] [--module=blog]
     * @param {name}           (third argument)
     * @param --resource       (optional) generate resource methods
     * @param --module={name}  (optional)
     */
    public static function controller($make)
    {
        return self::save(self::$save . '\Controllers', $make);
    }

    /**
     * Description: Create a Middleware class
     * Usage: php kernel make middleware AuthMiddleware [--module=blog]
     * @param {name}           (third argument)
     * @param --module={name}  (optional)
     */
    public static function middleware($make)
    {
        return self::save(self::$save . '\Middlewares', $make);
    }

    /**
     * Description: Create a Migration file
     * Usage: php kernel make migration CreateUsersTable [--table=users] [--dbname=default] [--module=blog]
     * @param {name}             (third argument)
     * @param --table={table}    (optional) defaults to snake_case of name
     * @param --dbname={key}     (optional) defaults to first db connection
     * @param --module={name}    (optional)
     */
    public static function migration($make)
    {
        self::save(
            (!self::$save_status ? 'Database' : self::$save) . '\Migrations',
            str_replace(
                ['{table}', '{dbname}'],
                [(Terminal::$parameters['table'] ?? self::parseName()['table_name']), (Terminal::$parameters['dbname'] ?? array_keys($GLOBALS['databases']['connections'])[0])],
                $make
            )
        );

        if (self::$ask) Ask::do("Do you wan't create a model also?", function () {
            self::$ask = false;
            Terminal::$commands[1] = 'model';
            return self::do();
        });

        return true;
    }

    /**
     * Description: Create a Model class
     * Usage: php kernel make model User [--table=users] [--module=blog]
     * @param {name}           (third argument)
     * @param --table={table}  (optional) defaults to snake_case of name
     * @param --module={name}  (optional)
     */
    public static function model($make)
    {
        self::save(
            self::$save . '\Models',
            str_replace(
                ['{table}'],
                [(Terminal::$parameters['table'] ?? self::parseName()['table_name'])],
                $make
            )
        );

        if (self::$ask) Ask::do("Do you wan't create a migration also?", function () {
            self::$ask = false;
            Terminal::$commands[1] = 'migration';
            return self::do();
        });
    }

    /**
     * Description: Create a Seeder class
     * Usage: php kernel make seeder UserSeeder
     * @param {name}  (third argument)
     */
    public static function seeder($make)
    {
        return self::save('Database\Seeders', $make);
    }

    /**
     * Description: Create an Observer class
     * Usage: php kernel make observer UserObserver [--module=blog]
     * @param {name}           (third argument)
     * @param --module={name}  (optional)
     */
    public static function observer($make)
    {
        return self::save(self::$save . '\Observers', $make);
    }
}
