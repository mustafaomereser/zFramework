<?php

namespace App\Controllers;

use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\PushNotification\PushNotification;
use zFramework\Core\Facades\Response;

/**
 * The three endpoints a browser needs to be notifiable.
 *
 * In the application rather than the framework because this is where the policy
 * lives: who may subscribe, which topics they may ask for, which application a
 * request belongs to.
 */
class PushNotificationController extends Controller
{
    /**
     * The application's public key. Public by definition.
     *
     * @return string
     */
    public function config()
    {
        return Response::json(PushNotification::client(request('app') ?: null));
    }

    /**
     * Store the subscription the browser just produced.
     *
     * @return string
     */
    public function subscribe()
    {
        $topics = array_filter(array_map('trim', (array) (request('topics') ?: [])));

        try {
            $subscription = PushNotification::subscribe([
                'endpoint' => (string) request('endpoint'),
                'keys'     => [
                    'p256dh' => (string) request('p256dh'),
                    'auth'   => (string) request('auth'),
                ],
            ], Auth::id(), $topics, request('app') ?: null);
        } catch (\Throwable $e) {
            # The client's fault - malformed key, endpoint pointing somewhere it
            # should not - and the status says so, or the browser retries
            # something that can never work.
            Response::status(422);
            return Response::json(['status' => 0, 'message' => $e->getMessage()]);
        }

        return Response::json(['status' => 1, 'id' => $subscription['id'] ?? null]);
    }

    /**
     * Forget it again. Answered the same way whether or not anything was
     * stored: the browser has already revoked the endpoint.
     *
     * @return string
     */
    public function unsubscribe()
    {
        PushNotification::unsubscribe((string) request('endpoint'));
        return Response::json(['status' => 1]);
    }
}
