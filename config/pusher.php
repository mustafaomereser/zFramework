<?php

/**
 * Pusher Channels: live events to a browser that is on the site.
 *
 * Not the same thing as push notifications (config/push-notification.php).
 * Those reach a user whose tab is closed, one message at a time, through the
 * browser vendor. This is a websocket the open page keeps to Pusher, and the
 * server publishes into it - a new message appears without a reload, a counter
 * ticks, a progress bar moves. The two sit side by side.
 *
 * One entry per Channels app at dashboard.pusher.com - several products on one
 * installation each get their own, and a page only ever hears the app it
 * connected to. `secret` signs every request the server makes and every channel
 * a browser is let into; it never reaches a page. `key` is public by design.
 */
return [

    /**
     * Application used when a call does not name one: Pusher::trigger(...)
     * is Pusher::app('app')->trigger(...).
     */
    'default' => 'app',

    /**
     * When the HTTP call to Pusher is made. It takes 50-300 ms and the visitor
     * has no reason to wait for it.
     *
     *   defer   after the response is sent, in the same process (Defer::after).
     *           No infrastructure; the event is lost if the process dies first.
     *   queue   through Queue::push - survives the request, needs a worker
     *           (`php terminal queue work pusher`). Inline without redis.
     *   inline  right now, and trigger() reports whether Pusher took it.
     */
    'dispatch'   => 'defer',
    'queue_name' => 'pusher',

    /**
     * Seconds to wait for Pusher to answer.
     */
    'timeout' => 5,

    /**
     * app_id, key, secret, cluster   from the app's Keys page.
     * host, port, scheme             a self-hosted server that speaks the same
     *                                protocol (Soketi, a pusher-compatible proxy);
     *                                the cluster is then ignored. Empty host means
     *                                Pusher's own, api-{cluster}.pusher.com.
     */
    'apps' => [

        'app' => [
            'app_id'  => '',
            'key'     => '',
            'secret'  => '',
            'cluster' => 'eu',
            'host'    => '',
            'port'    => null,
            'scheme'  => 'https',
        ],

    ],
];
