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
     * Description: Create an Request
     * @important $make
     * @param $make (third arguman)    
     * @param --module={module_name} (optional)
     */
    public static function request($make)
    {
        return self::save(self::$save . '\Requests', $make);
    }

    /**
     * Description: Create an Controller
     * @important $make
     * @param $make (third arguman)
     * @param --resource (optional)
     * @param --module={module_name} (optional)
     */
    public static function controller($make)
    {
        return self::save(self::$save . '\Controllers', $make);
    }

    /**
     * Description: Create an Middleware
     * @important $make
     * @param $make (third arguman)
     * @param --module={module_name} (optional)
     */
    public static function middleware($make)
    {
        return self::save(self::$save . '\Middlewares', $make);
    }

    /**
     * Description: Create an Migration
     * @important $make
     * @param $make (third arguman)
     * @param --module={module_name} (optional)
     * @param --table (optional) (ifnull = Get self Migration Name)
     * @param --dbname (optional) (ifnull = Get first DB KEY)
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
     * Description: Create an Model
     * @important $make
     * @param $make (third arguman)
     * @param --module={module_name} (optional)
     * @param --table (optional) (ifnull = Get self Model Name)
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
     * Description: Create an Seeder
     * @important $make
     * @param $make (third arguman)
     * @param --module={module_name} (optional)
     */
    public static function seeder($make)
    {
        return self::save('Database\Seeders', $make);
    }

    /**
     * Description: Create an Observer
     * @important $make
     * @param $make (third arguman)
     * @param --module={module_name} (optional)
     */
    public static function observer($make)
    {
        return self::save(self::$save . '\Observers', $make);
    }
}
