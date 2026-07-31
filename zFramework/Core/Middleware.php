<?php

namespace zFramework\Core;

class Middleware
{
    /**
     * Set to an array to have each middleware's duration recorded into it.
     *
     * Off by default and null while it is off, so a request that is not being
     * measured pays one null comparison per middleware. `bench request` turns it
     * on: the global middlewares are where an application's own time tends to go,
     * and a profile of the framework that stops at the framework's edge answers
     * the wrong question.
     *
     * @var array<array{0:string,1:float}>|null
     */
    public static ?array $timings = null;

    /**
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$timings = null;
    }

    /**
     * Check Middlewares
     * @param array $middlewares
     * @param object $callback
     * @return array|int
     */
    public static function middleware(array $middlewares, $callback = null)
    {
        $declined = [];
        foreach ($middlewares as $middleware) {
            $measuring = self::$timings !== null;
            $started   = $measuring ? hrtime(true) : 0;

            $call   = new $middleware();
            $passed = call_user_func_array([$call, 'attempt'], []);

            if ($measuring) self::$timings[] = [$middleware, hrtime(true) - $started];
            if ($passed) continue;

            $declined[] = $middleware;
            if (!$callback) call_user_func_array([$call, 'error'], []);
        }

        return $callback ? $callback($declined) : (count($declined) ? false : true);
    }
}
