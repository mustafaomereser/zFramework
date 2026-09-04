<?php

namespace zFramework\Kernel\Modules;

use zFramework\Core\Facades\Mongo as MongoFacade;
use zFramework\Kernel\Terminal;

/**
 * MongoDB from the terminal. There is no migrate here on purpose - a
 * collection exists the moment a document lands in it; what a deployment
 * actually has to put in place is the indexes, and `mongo indexes` reads them
 * off the models the way `db migrate` reads columns() off a migration.
 */
class Mongo
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Ping the configured server and show what it is
     * Usage: php terminal mongo status
     */
    public static function status()
    {
        if (!extension_loaded('mongodb')) return Terminal::text('[color=red]The mongodb extension is not loaded (php.ini: extension=mongodb).[/color]');
        if (!MongoFacade::available()) return Terminal::text('[color=red]No connection configured - database/mongoconnections.php.[/color]');

        try {
            $build = MongoFacade::command(['buildInfo' => 1], 'admin')[0] ?? [];
            Terminal::text('[color=green]Connected.[/color] [color=dark-gray]MongoDB ' . ($build['version'] ?? '?') . ' - database `' . MongoFacade::database() . '`[/color]');
        } catch (\Throwable $e) {
            Terminal::text('[color=red]No answer: ' . $e->getMessage() . '[/color]');
        }
    }

    /**
     * Description: Create the indexes every MongoModel under App/Models declares
     * Usage: php terminal mongo indexes
     */
    public static function indexes()
    {
        if (!MongoFacade::available()) return Terminal::text('[color=red]No connection configured - database/mongoconnections.php (and extension=mongodb in php.ini).[/color]');

        $any = false;

        foreach ((array) glob(base_path('App/Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, \zFramework\Core\Abstracts\MongoModel::class)) continue;

            $model   = new $class;
            $indexes = $model->indexes();
            if (!$indexes) continue;

            $any = true;

            # Names, so a re-run is an upsert rather than a duplicate: mongo
            # treats an index with the same name and keys as already there.
            foreach ($indexes as $i => $index)
                $indexes[$i]['name'] ??= implode('_', array_map(fn($k, $d) => $k . '_' . $d, array_keys($index['key']), $index['key']));

            try {
                MongoFacade::command(['createIndexes' => $model->collection, 'indexes' => $indexes]);
                Terminal::text('[color=green]-> `' . $model->collection . '`[/color] [color=dark-gray]' . count($indexes) . ' index(es): ' . implode(', ', array_column($indexes, 'name')) . '[/color]');
            } catch (\Throwable $e) {
                Terminal::text('[color=red]-> `' . $model->collection . '` failed: ' . $e->getMessage() . '[/color]');
            }
        }

        if (!$any) Terminal::text('[color=dark-gray]No MongoModel under App/Models declares indexes().[/color]');
    }
}
