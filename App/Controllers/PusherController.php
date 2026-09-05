<?php

namespace App\Controllers;

use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Csrf;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Pusher;
use zFramework\Core\Facades\Response;

/**
 * The two endpoints a page needs for Pusher Channels.
 *
 * In the application rather than the framework because the policy is here:
 * which private or presence channel a signed-in user may join, and what the
 * others in a presence channel get to see about them.
 *
 * Both take `app` (query or form field) to pick an entry in config/pusher.php
 * `apps`; without it, the default one.
 */
class PusherController extends Controller
{
    /**
     * The public key and cluster, so a page never has them pasted in by hand.
     *
     * @return string
     */
    public function config()
    {
        # `app` is the client's to send; one that is not configured is a 404,
        # not a report in error_logs.
        try {
            $pusher = Pusher::app(request('app') ?: null);
        } catch (\InvalidArgumentException) {
            abort(404, 'No such application.');
        }

        if (!$pusher->available()) abort(404, 'Pusher is not configured.');

        # The csrf token rides along for a page that has no form on it: the auth
        # endpoint is a POST like every other one. Same-origin GET, no CORS
        # headers, so another site cannot read it.
        return Response::json($pusher->client() + ['_token' => Csrf::get()]);
    }

    /**
     * Sign a private-/presence- subscription. pusher-js posts channel_name and
     * socket_id here (plus the csrf token as a param); the route sits behind
     * the Auth middleware, so a guest never reaches this method.
     *
     * @return string
     */
    public function auth()
    {
        try {
            $pusher = Pusher::app(request('app') ?: null);
        } catch (\InvalidArgumentException) {
            abort(404, 'No such application.');
        }
        if (!$pusher->available()) abort(404, 'Pusher is not configured.');

        $channel  = (string) request('channel_name');
        $socketId = (string) request('socket_id');
        $user     = Auth::user();

        # Policy goes here. The default: any signed-in user may join any private
        # or presence channel. Narrow it per prefix, e.g.
        #   if (str_starts_with($channel, 'private-orders-') && !str_ends_with($channel, '-' . $user['id'])) abort(403);

        try {
            $answer = $pusher->authenticate($channel, $socketId, [
                'user_id'   => $user['id'],
                'user_info' => ['name' => $user['name'] ?? $user['username'] ?? $user['email'] ?? ('#' . $user['id'])],
            ]);
        } catch (\InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }

        return Response::json($answer);
    }
}
