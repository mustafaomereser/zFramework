<?php

namespace App\Middlewares;

use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Config;
use zFramework\Core\Facades\RateLimit;
use zFramework\Core\Facades\Response;
use zFramework\Core\ResponseSignal;

/**
 * Rate limit, opt-in per route group:
 *
 *   Route::pre('/api')->middleware([Throttle::class, API::class])->noCSRF()->group(...);
 *   Route::middleware([Throttle::class])->group(fn() => Route::post('/sign-in', ...));
 *
 * Limits come from config/framework.php under `throttle`, matched by url prefix
 * with the longest match winning, falling back to the default. Which pages are
 * limited is decided by where you attach the middleware; how hard, by config.
 *
 * It answers 429 itself rather than declining. A declined middleware with no
 * fallback closure ends as a 404 - see references/routing.md - and a 404 is the
 * wrong answer to "you are going too fast", so this does not leave it to chance.
 *
 * Order it first in the list. The response is a signal, which unwinds out of the
 * middleware loop, so a caller over the limit never reaches whatever follows -
 * on the API group that saves the token lookup.
 */
#[\AllowDynamicProperties]
class Throttle
{
    public function attempt()
    {
        $config = (array) (Config::framework('throttle') ?: []);
        if (!($config['enabled'] ?? true)) return true;

        $rule = self::rule($config, uri());

        $hit = RateLimit::hit(self::caller($rule['by'] ?? 'ip'), (int) $rule['limit'], (int) $rule['window']);

        Response::header('X-RateLimit-Limit', (string) $rule['limit']);
        Response::header('X-RateLimit-Remaining', (string) $hit['remaining']);

        if ($hit['allowed']) return true;

        # JSON rather than abort(429). There is no errors/*/429 view, so abort
        # would have emitted the bare message as text - and a 429 is read by
        # retry logic more often than by a person, which wants the wait as a
        # number rather than inside a sentence.
        throw new ResponseSignal(429, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Retry-After'  => (string) $hit['retry_after'],
        ], json_encode([
            # The http status, not a boolean - `false` says something went wrong
            # without saying what, and the caller has to branch on it anyway.
            'status'       => 429,
            'message'      => 'Too many requests. Try again in ' . $hit['retry_after'] . ' seconds.',
            'try_again_in' => $hit['retry_after'],
        ], JSON_UNESCAPED_UNICODE));
    }

    public function error()
    {
        abort(429);
    }

    /**
     * Longest matching url prefix wins, so `/api/upload` can be stricter than
     * `/api` without repeating the rest of it.
     *
     * @param array  $config
     * @param string $uri
     * @return array{limit: int, window: int, by: string}
     */
    private static function rule(array $config, string $uri): array
    {
        $rule = [
            'limit'  => (int) ($config['limit'] ?? 60),
            'window' => (int) ($config['window'] ?? 60),
            'by'     => (string) ($config['by'] ?? 'ip'),
        ];

        $best = -1;

        foreach ((array) ($config['rules'] ?? []) as $prefix => $override) {
            if (!str_starts_with($uri, $prefix) || strlen($prefix) <= $best) continue;

            $best = strlen($prefix);
            $rule = ((array) $override) + $rule;
        }

        return $rule;
    }

    /**
     * Who is being counted.
     *
     * `token` counts a logged-in caller by identity and everyone else by ip, so
     * one account cannot spread its quota across addresses, and one office
     * behind a single ip does not share one quota between its people.
     *
     * @param string $by
     * @return string
     */
    private static function caller(string $by): string
    {
        $scope = uri();

        if ($by === 'token' && ($id = Auth::id())) return "user:$id|$scope";

        return 'ip:' . ip() . '|' . $scope;
    }
}
