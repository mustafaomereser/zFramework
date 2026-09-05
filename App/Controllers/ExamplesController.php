<?php

namespace App\Controllers;

use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Pusher;
use zFramework\Core\Facades\Response;

/**
 * Working examples of the framework's features, reachable from the welcome
 * page. Each page shows the thing running and the code that runs it - delete
 * the controller, the views and the routes when the application no longer
 * wants them; nothing else depends on them.
 */
class ExamplesController extends Controller
{
    /**
     * The list of examples. GET /demo
     *
     * @return mixed
     */
    public function index()
    {
        return view('app.pages.examples.index');
    }

    /**
     * Pusher Channels: chat, ping, progress, private and presence channels.
     * GET /demo/pusher
     *
     * @return mixed
     */
    public function pusher()
    {
        return view('app.pages.examples.pusher', [
            'configured' => Pusher::available(),
            'client'     => Pusher::available() ? Pusher::client() : [],
        ]);
    }

    /**
     * The server half of the Pusher page: whatever the page asks for is one
     * trigger, sent inline so the answer can say what Pusher replied.
     * POST /demo/pusher/send
     *
     * @return mixed
     */
    public function pusherSend()
    {
        if (!Pusher::available()) return Response::json(['status' => 0, 'reason' => 'config/pusher.php has no app_id/key/secret - run `php terminal pusher status`']);

        $kind     = (string) request('kind');
        $socketId = request('socket_id') ?: null;
        $who      = Auth::check() ? (Auth::user()['name'] ?? Auth::user()['email'] ?? '#' . Auth::id()) : 'guest ' . substr(md5(ip()), 0, 4);

        try {
            $result = match ($kind) {
                # Everyone on the page gets the line - except the tab that typed it,
                # which showed it already: that is what the socket id is for.
                'chat' => Pusher::triggerNow('examples', 'chat', ['who' => $who, 'text' => mb_substr(trim((string) request('text')), 0, 200), 'at' => date('H:i:s')], $socketId),

                'ping' => Pusher::triggerNow('examples', 'ping', ['who' => $who, 'at' => date('H:i:s')]),

                # A job that reports as it goes: ten events from one request. A real
                # one would run in the queue and trigger from there the same way.
                'progress' => (function () use ($who) {
                    $last = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'nothing sent'];
                    for ($step = 1; $step <= 10; $step++) {
                        usleep(250_000);
                        $last = Pusher::triggerNow('examples', 'progress', ['who' => $who, 'percent' => $step * 10]);
                        if (!$last['ok']) break;
                    }
                    return $last;
                })(),

                # Only the signed-in user's own private channel - the auth endpoint
                # signed the subscription, the server only ever names the channel.
                'private' => Auth::check()
                    ? Pusher::triggerNow('private-examples-user-' . Auth::id(), 'note', ['text' => 'Only you can see this - sent ' . date('H:i:s')])
                    : ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'sign in first'],

                default => ['ok' => false, 'status' => 0, 'body' => '', 'error' => "unknown kind `$kind`"],
            };
        } catch (\InvalidArgumentException $e) {
            return Response::json(['status' => 0, 'reason' => $e->getMessage()]);
        }

        return Response::json([
            'status' => (int) $result['ok'],
            'http'   => $result['status'],
            'reason' => $result['ok'] ? null : ($result['error'] ?: $result['body']),
        ]);
    }
}
