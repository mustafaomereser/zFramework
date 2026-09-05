<?php

namespace App\Controllers;

use App\Models\PushNotificationSubscriptions;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Pusher;
use zFramework\Core\Facades\PushNotification\PushNotification;
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
     * Push notifications: subscribe this browser, send to it, to a user, a
     * topic or everyone. GET /demo/push-notification
     *
     * @return mixed
     */
    public function pushNotification()
    {
        $app  = (string) (config('push-notification.default') ?: 'app');
        $keys = (array) (config("push-notification.apps.$app") ?: []);

        $configured = ($keys['public_key'] ?? '') !== '' && ($keys['private_key'] ?? '') !== '';
        $model      = new PushNotificationSubscriptions;

        return view('app.pages.examples.push-notification', [
            'configured' => $configured,
            'app'        => $app,
            'stored'     => $configured && $model->connection() ? $model->count() : 0,
            'mine'       => $configured && Auth::check() ? $model->where('user_id', Auth::id())->count() : 0,
        ]);
    }

    /**
     * Send one notification the way the page asked: to this browser's own
     * subscription, to the signed-in user's devices, to a topic, to everyone.
     * POST /demo/push-notification/send
     *
     * @return mixed
     */
    public function pushNotificationSend()
    {
        $app  = (string) (config('push-notification.default') ?: 'app');
        $keys = (array) (config("push-notification.apps.$app") ?: []);
        if (($keys['public_key'] ?? '') === '' || ($keys['private_key'] ?? '') === '') return Response::json(['status' => 0, 'reason' => "config/push-notification.php apps.$app has no key pair - `php terminal push-notification keys $app`"]);

        $payload = [
            'title' => mb_substr(trim((string) request('title')) ?: 'Hello from zFramework', 0, 80),
            'body'  => mb_substr(trim((string) request('body')), 0, 160),
            'url'   => route('push-notification.examples'),
            'tag'   => 'zf-demo',
        ];

        try {
            $result = match ((string) request('to')) {
                # The row this browser produced in step 1, found by its endpoint.
                'browser' => (function () use ($payload) {
                    $row = (new PushNotificationSubscriptions)->where('endpoint_hash', hash('sha256', (string) request('endpoint')))->first();
                    if (!$row) return null;
                    return PushNotification::toSubscription($row)->send($payload);
                })(),
                'user'  => Auth::check() ? PushNotification::toUser(Auth::id())->send($payload) : null,
                'topic' => PushNotification::toTopic('demo')->send($payload),
                'all'   => PushNotification::toAll()->send($payload),
                default => null,
            };
        } catch (\Throwable $e) {
            return Response::json(['status' => 0, 'reason' => get_class($e) . ': ' . $e->getMessage()]);
        }

        if ($result === null) return Response::json(['status' => 0, 'reason' => request('to') === 'user' ? 'sign in first' : 'no subscription matched - subscribe in step 1']);
        return Response::json(['status' => 1, 'result' => $result]);
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
