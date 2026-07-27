<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use zFramework\Run;
use zFramework\Core\Route as RouteFacade;

class Route
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Compile the route table into a cache file
     * Usage: php terminal route cache
     */
    public static function cache()
    {
        global $storage_path;

        RouteFacade::$routes = [];

        $before = Run::$included;

        Run::initProviders()::findModules(base_path('/modules'))::loadModules();

        # route/dynamic is excluded on purpose: it is where definitions that depend
        # on request state live, and a cache would freeze them at build time.
        Run::includer(BASE_PATH . '/route', true, false, '.php', BASE_PATH . '/route/dynamic');

        $sources = RouteFacade::sources(array_values(array_diff(Run::$included, $before)));
        $total   = count(RouteFacade::$routes);
        $blocked = RouteFacade::cacheBlockers();

        if (count($blocked)) {
            Terminal::text("[color=red]Cannot cache: " . count($blocked) . " of $total route(s) use a closure.[/color]");
            foreach ($blocked as $name => $reason) Terminal::text("[color=yellow]-> {$name}[/color] [color=dark-gray]($reason)[/color]");
            Terminal::text("[color=dark-gray]Replace them with [Controller::class, 'method'] and run this again.[/color]");
            return;
        }

        $path = "$storage_path/routes.cache.php";
        if (!RouteFacade::writeCache($path, $sources)) return Terminal::text("[color=red]Could not write $path - check permissions.[/color]");

        Terminal::text("[color=green]Routes cached:[/color] $total route(s) from " . count($sources) . " source(s) -> $path");
        Terminal::text("[color=dark-gray]Run `php terminal route clear` after changing a route file.[/color]");

        Terminal::text("\n[color=yellow]A cached table is a snapshot of this moment.[/color]");
        Terminal::text("[color=dark-gray]A route declared inside a condition that varies per request -[/color]");
        Terminal::text("[color=dark-gray]if (Auth::check()), a tenant flag, a privilege check - is frozen as it[/color]");
        Terminal::text("[color=dark-gray]was here, and no CLI process is logged in. Move those to middleware,[/color]");
        Terminal::text("[color=dark-gray]or into route/dynamic/ which is never cached.[/color]");

        if (is_dir(BASE_PATH . '/route/dynamic')) Terminal::text("[color=green]route/dynamic/ found - those files stay dynamic.[/color]");
    }

    /**
     * Description: Remove the compiled route cache
     * Usage: php terminal route clear
     */
    public static function clear()
    {
        global $storage_path;

        $path = "$storage_path/routes.cache.php";
        if (!is_file($path)) return Terminal::text("[color=yellow]No route cache to clear.[/color]");

        @unlink($path);
        Terminal::text("[color=green]Route cache cleared.[/color]");
    }

    /**
     * Description: List every registered route
     * Usage: php terminal route list
     */
    public static function list()
    {
        RouteFacade::$routes = [];

        Run::initProviders()::findModules(base_path('/modules'))::loadModules();
        Run::includer(BASE_PATH . '/route');

        foreach (RouteFacade::$routes as $key => $route) {
            $method = str_pad($route['method'] ?: 'ANY', 7);
            $name   = is_string($key) ? $key : '-';
            Terminal::text("[color=blue]{$method}[/color] [color=green]" . str_pad($route['url'], 45) . "[/color] [color=dark-gray]{$name}[/color]");
        }

        Terminal::text("\n[color=green]" . count(RouteFacade::$routes) . " route(s).[/color]");
    }
}
