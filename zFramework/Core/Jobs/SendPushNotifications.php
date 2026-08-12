<?php

namespace zFramework\Core\Jobs;

use App\Models\PushNotificationSubscriptions;
use zFramework\Core\Facades\PushNotification\PushNotification;

/**
 * Queue job for a chunk of subscriptions handed over by PushNotification::send().
 * Run by `php terminal queue work push-notification`.
 *
 * Ids travel in the payload, not the rows: a device may unsubscribe before the
 * worker gets to it, and a job carrying a copy would push anyway.
 */
class SendPushNotifications
{
    /**
     * @param array $payload app, ids, payload, options
     * @return void
     */
    public function handle(array $payload): void
    {
        $ids = array_filter((array) ($payload['ids'] ?? []));
        if (!$ids) return;

        $subscriptions = (new PushNotificationSubscriptions)->whereIn('id', $ids)->get();
        if (!$subscriptions) return;

        PushNotification::dispatch(
            $payload['app'] ?? (config('push-notification.default') ?: 'app'),
            $subscriptions,
            $payload['payload'] ?? [],
            $payload['options'] ?? []
        );
    }
}
