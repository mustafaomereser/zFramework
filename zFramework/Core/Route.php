<?php

namespace zFramework\Core;

use ReflectionFunction;
use ReflectionMethod;
use zFramework\Core\Facades\Lang;

class Route
{
    /**
     * Route parameters
     */
    static $routes      = [];
    static $calledRoute = null;
    static $caching     = true;

    /**
     * Group parameters.
     */
    static $groups      = [];
    static $add_groups  = [];

    /**
     * Find was setted route.
     * @param string $name
     * @param array $array
     * @param bool $return_bool
     * @return string|bool
     */
    public static function find(string $name, array $data = [], bool $return_bool = false)
    {
        $route_is_exists = true;
        $return = $name;
        if (!isset(self::$routes[$name])) $route_is_exists = false;

        if ($route_is_exists) {
            $url = self::$routes[$name]['url'];
            foreach ($data as $key => $val) @$url = str_replace(["{" . $key . "}", "{?" . $key . "}"], $val, $url);
            while (strstr($url, '//')) $url = str_replace(['//'], ['/'], $url);
            $return = (host() . script_name()) . rtrim($url, '/');
        }

        if ($return_bool) return $route_is_exists;
        return $return;
    }


    /**
     * Route Has keyword in URI.
     * @param string keyword
     * @return bool
     */
    public static function has(string $keyword)
    {
        return strstr(uri(), $keyword) ? true : false;
    }

    /**
     * Organize and clear route name.
     * @param string $name
     * @return string|\Closure
     */
    private static function nameOrganize(string $name)
    {
        $name = str_replace("..", ".", rtrim(ltrim(str_replace('/', '.', $name), '.'), '.'));
        if (strstr($name, '..')) return self::nameOrganize($name);
        return $name;
    }

    /**
     * set name for route.
     * @param string $name
     * @return self
     */
    public static function name(string $name)
    {
        $name    = self::nameOrganize(@self::$groups['pre'] . "/$name");
        $old_key = @end(array_keys(self::$routes));
        self::$routes[$name] = array_pop(self::$routes);
        if (!is_null(self::$calledRoute) && @self::$calledRoute['name'] == $old_key) self::$calledRoute['name'] = $name;
        return new self();
    }

    /**
     * Quick redirect.
     * @param $url
     * @param $to
     * @return self
     */
    public static function redirect(string $url, string $to)
    {
        self::any($url, function () use ($to) {
            http_response_code(302);
            die(header("Location: $to"));
        });
        return new self();
    }

    /**
     * Method Any
     * @return self
     */
    public static function any()
    {
        self::call(null, func_get_args());
        return new self();
    }

    /**
     * Method Get
     * @return self
     */
    public static function get()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Post
     * @return self
     */
    public static function post()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Patch
     * @return self
     */
    public static function patch()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Put
     * @return self
     */
    public static function put()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Method Delete
     * @return self
     */
    public static function delete()
    {
        self::call(__FUNCTION__, func_get_args());
        return new self();
    }

    /**
     * Set a resource scheme.
     * @param string $url
     * @param string $callback
     * @return self
     */
    public static function resource(string $url, string $callback)
    {
        self::get($url, [$callback, 'index'])->name("$url.index");
        self::post($url, [$callback, 'store'])->name("$url.store");
        self::get("$url/create", [$callback, 'create'])->name("$url.create");
        self::get("$url/{id}", [$callback, 'show'])->name("$url.show");
        self::get("$url/{id}/edit", [$callback, 'edit'])->name("$url.edit");
        self::patch("$url/{id}", [$callback, 'update'])->name("$url.update");
        self::put("$url/{id}", [$callback, 'update'])->name("$url.update");
        self::delete("$url/{id}", [$callback, 'delete'])->name("$url.delete");

        return new self();
    }

    /**
     * Dispatch Route. 
     * @param string|null $method
     * @param array $args
     * @return array
     */
    private static function dispatch($method, array $args): array
    {
        while (strstr($args[0], '//')) $args[0] = str_replace(['//'], ['/'], $args[0]);

        $method = strtoupper($method ?? '');
        $URI    = explode('/', substr(strtok($_SERVER['REQUEST_URI'], '?'), 1));
        $URL    = explode('/', substr($args[0], 1));

        $match          = 0;
        $parameters     = [];
        $parameter_keys = [];
        foreach ($URL as $key => $row) {
            @$column = $URI[$key];

            if (strstr($row, '{') && strstr($row, '}')) {
                $parameter_keys[] = $row;

                if (!strlen($column ?? '')) {
                    if (strstr($row, '{?')) $match++;
                    continue;
                }

                $URL[$key] = $column;
                $match++;
                $parameters[str_replace(['{?', '{', '}'], '', $row)] = $column;
            } else {
                if ($column == $row) $match++;
            }

            if (!strlen($URL[$key]) && count($URL) != 1) unset($URL[$key]);
        }

        $URI = array_values($URI);
        $URL = array_values($URL);

        self::$routes[] = [
            'url'        => $args[0],
            'method'     => $method,
            'parameters' => $parameter_keys,
            'groups'     => self::$groups
        ];

        $match = ((empty($method) || $method == method()) && $URI == $URL ? 1 : 0); #($match > 0 && (count($URL) - count($URI) == 0))) ? 1 : 0;
        return compact('match', 'parameters', 'URI', 'URL');
    }

    /**
     * Call a route.
     * @param string|null $method
     * @param array $args
     */
    public static function call($method, array $args): void
    {
        $args[0] = @self::$groups['pre'] . $args[0];

        $dispatch = self::dispatch($method, $args);
        if (self::$calledRoute != null || !$dispatch['match']) return;
        if (!Csrf::check(isset(self::$groups['no-csrf']))) abort(406, Lang::get('errors.csrf.no-verify'));

        if (@self::$groups['middlewares']) {
            $middleware = Middleware::middleware(self::$groups['middlewares'][0], function ($declines) {
                if (!count($declines)) return true;
                if (self::$groups['middlewares'][1]) self::$groups['middlewares'][1]($declines);
                return false;
            });

            if (!$middleware) return;
        }

        self::$calledRoute = [
            'name'       => @end(array_keys(self::$routes)),
            'callback'   => $args[1],
            'parameters' => $dispatch['parameters']
        ];
    }

    /**
     * Run route with options.
     */
    public static function run(): void
    {
        if (self::$calledRoute === null) abort(404);

        $callback = self::$calledRoute['callback'];
        if (!in_array(gettype($callback), ['object', 'array', 'string'])) throw new \Exception('This type not valid.');

        switch (gettype($callback)) {
            case 'string':
                $callback    = explode('@', $callback);
                $callback[0] = strtok(findFile($callback[0], 'php', 'App\Controllers'), '.');
                $callback    = [new $callback[0]($callback[1]), $callback[1]];
                break;
            case 'array':
                $callback = [new $callback[0]($callback[1]), $callback[1]];
                break;
        }

        try {
            $reflection = new ReflectionMethod($callback[0], $callback[1]);
        } catch (\Throwable $e) {
            $reflection = new ReflectionFunction($callback);
        }

        $parameters = $reflection->getParameters();
        foreach ($parameters as $parameter) {
            $name       = $parameter->getName();
            $dependence = (string) $parameter->getType();
            if (!empty(self::$calledRoute['parameters'][$name]) || !class_exists($dependence)) continue;
            self::$calledRoute['parameters'][$name] = new $dependence;
        }

        echo call_user_func_array($callback, self::$calledRoute['parameters']);
    }

    #region Groups
    /**
     * Don't check csrf token
     */
    public static function noCSRF()
    {
        self::$add_groups['no-csrf'] = true;
        return new self();
    }

    /**
     * Set prefix.
     * @param string $prefix
     * @return self
     */
    public static function pre(string $prefix)
    {
        self::$add_groups['pre'] = @self::$groups['pre'] . $prefix;
        return new self();
    }

    /**
     * Set route's middlewares.
     * @param array $list
     * @param \Closure|null $callback
     */
    public static function middleware(array $list, $callback = null)
    {
        self::$add_groups['middlewares'] = [array_merge((self::$groups['middlewares'][0] ?? []), $list), $callback];
        return new self();
    }

    /**
     * Group routes with group options
     * @param \Closure $callback
     * @return mixed
     */
    public static function group(\Closure $callback)
    {
        $groupsReverse = [];
        foreach (self::$add_groups as $key => $setting) {
            $groupsReverse[$key] = self::$groups[$key] ?? null;
            self::$groups[$key]  = $setting;
        }
        $callback = $callback();
        foreach ($groupsReverse as $key => $reverse) self::$groups[$key] = $reverse;
        self::$add_groups = [];
        return $callback;
    }
    #endregion
}
