<?php

namespace App\Controllers;

use App\Models\PushNotificationSubscriptions;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Csrf;
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
        # The service worker has no page to read a csrf token from, and a subscription
        # it cannot re-register goes quiet without anything reporting it. Handed over
        # here instead: a same-origin GET with no CORS headers, so another site can
        # make the request but cannot read what comes back.
        return Response::json(PushNotification::client(request('app') ?: null) + ['_token' => Csrf::get()]);
    }

    /**
     * Store the subscription the browser just produced.
     *
     * @return string
     */
    public function subscribe()
    {
        $app    = request('app') ?: null;
        $topics = array_filter(array_map('trim', (array) (request('topics') ?: [])));

        # pushsubscriptionchange hands the worker a new endpoint and nothing else -
        # the application and the topics live on the row the push service just
        # replaced, and the worker never saw them. Carried over instead of reset,
        # which moved the device to the default application with no topics at all.
        if ($old = (string) (request('old_endpoint') ?: '')) {
            $previous = (new PushNotificationSubscriptions)->where('endpoint_hash', hash('sha256', $old))->first();

            if ($previous) {
                $app    = $app ?: ($previous['app'] ?: null);
                $topics = $topics ?: array_values((array) json_decode((string) $previous['topics'], true));
            }
        }

        try {
            $subscription = PushNotification::subscribe([
                'endpoint' => (string) request('endpoint'),
                'keys'     => [
                    'p256dh' => (string) request('p256dh'),
                    'auth'   => (string) request('auth'),
                ],
            ], Auth::id(), $topics, $app);
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
