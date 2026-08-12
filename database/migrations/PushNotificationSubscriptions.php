<?php

namespace Database\Migrations;

/**
 * One row per browser that agreed to be notified: an endpoint on its push
 * service plus the two keys needed to encrypt for it. Nothing can recreate it -
 * only the browser can hand it out again.
 */
class PushNotificationSubscriptions
{
    static $charset = "utf8mb4_general_ci";
    static $table   = "push_notification_subscriptions";
    static $db      = "local";

    public static function columns()
    {
        return [
            'id'            => ['primary'],

            # Which application this belongs to and which transport delivers it.
            # Per row rather than read from config at send time, so changing an
            # application's channel does not push old subscriptions through a
            # service that never issued them.
            'app'           => ['varchar:64', 'required', 'unique:endpoint', 'index:app_user'],
            'channel'       => ['varchar:32', 'required', 'default:webpush'],

            # Null for a visitor who is not signed in - a price-drop banner
            # subscribes devices with no user behind them.
            'user_id'       => ['bigint', 'nullable', 'index:app_user'],

            # ~500 characters, so TEXT with the uniqueness on a hash of it -
            # MySQL will not index a column that long anyway.
            'endpoint'      => ['text', 'required'],
            'endpoint_hash' => ['char:64', 'required', 'unique:endpoint'],

            # What the payload is encrypted for. Both come from the browser.
            'p256dh'        => ['varchar:255', 'required'],
            'auth'          => ['varchar:64', 'required'],

            # Topics this device asked for. Without any it takes only what is
            # addressed to it or broadcast to everyone.
            'topics'        => ['json', 'nullable'],

            'user_agent'    => ['varchar:255', 'nullable'],

            # Consecutive failures, reset by the next success. A subscription
            # reported gone is deleted outright; this is for the rest.
            'failures'      => ['int', 'required', 'default:0'],
            'last_sent_at'  => ['datetime', 'nullable', 'default'],

            'timestamps'
        ];
    }
}
