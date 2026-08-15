<?php

namespace App\Middlewares;

use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Config;
use zFramework\Core\Facades\RateLimit;
use zFramework\Core\Facades\Response;
use zFramework\Core\Route;
use zFramework\Core\ResponseSignal;

/**
 * Rate limit, opt-in per route group:
 *
 *   Route::pre('/api')->throttle(120)->middleware([API::class])->noCSRF()->group(...);
 *   Route::throttle(5, 300)->group(fn() => Route::post('/sign-in', ...));
 *
 * The limit comes from the route group - `Route::pre('/api')->throttle(120)` -
 * so it sits with the routes it governs. config/framework.php carries only the
 * defaults, for a group that attaches the middleware without saying a number.
 *
 * Deliberately not a url-prefix table in config: that is a second copy of the
 * routing, and it stops matching silently the moment a url changes - which with
 * a translated prefix it does on every request.
 *
 * It answers 429 itself rather than declining. A declined middleware with no
 * fallback closure ends as a 404 - see references/routing.md - and a 404 is the
 * wrong answer to "you are going too fast", so this does not leave it to chance.
 *
 * Ordering, and the one place it is a real decision:
 *
 *   by: 'ip'    - put this first. The response is a signal, which unwinds out of
 *                 the middleware loop, so a caller over the limit never reaches
 *                 whatever follows. On the API group that skips the token lookup
 *                 entirely.
 *
 *   by: 'token' - it has to come AFTER whatever authenticates, or Auth has not
 *                 resolved anybody yet and every caller is counted by ip instead.
 *                 That degrades silently: the limit still works, just not per
 *                 account. Measured - Throttle first gives `ip:::1|/x`, Throttle
 *                 after the API middleware gives `user:8|/x`.
 *
 * So `by: 'token'` costs the lookup even for a caller you are about to refuse.
 * Use it when one account must not spread its quota across addresses; otherwise
 * ip is both cheaper and harder to get wrong.
 */
#[\AllowDynamicProperties]
class Throttle
{
    public function attempt()
    {
        $config = (array) (Config::framework('throttle') ?: []);
        if (!($config['enabled'] ?? true)) return true;

        $rule = self::rule($config);

        $hit = RateLimit::hit(self::caller($rule['by']), (int) $rule['limit'], (int) $rule['window'], (int) $rule['block']);

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
            'message'      => ($hit['blocked'] ? 'Blocked for sending too many requests. Try again in ' : 'Too many requests. Try again in ')
                . $hit['retry_after'] . ' seconds.',
            'try_again_in' => $hit['retry_after'],
        ], JSON_UNESCAPED_UNICODE));
    }

    public function error()
    {
        abort(429);
    }

    /**
     * What this group asked for, falling back to the config defaults.
     *
     * @param array $config
     * @return array{limit: int, window: int, by: string, block: int}
     */
    private static function rule(array $config): array
    {
        $group = (array) (Route::$matchedGroups['throttle'] ?? []);

        return [
            'limit'  => (int) ($group['limit']  ?? $config['limit']  ?? 60),
            'window' => (int) ($group['window'] ?? $config['window'] ?? 60),
            'by'     => (string) ($group['by']  ?? $config['by']     ?? 'ip'),
            'block'  => (int) ($group['block']  ?? $config['block']  ?? 0),
        ];
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
