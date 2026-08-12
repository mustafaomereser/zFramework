<?php

/**
 * Push notifications: which applications can send, and how they are signed.
 *
 * A subscription belongs to an application key, not to the installation, so one
 * codebase can serve several products without any of them reaching another's
 * subscribers - a browser only accepts a message signed by the key it
 * subscribed with.
 *
 * Generate a key pair per application with `php terminal push-notification keys
 * {app}` and paste it below. Changing it invalidates every subscription taken
 * with the old one, so generate once and keep it out of the repository.
 */
return [

    /**
     * Application used when a send does not name one.
     */
    'default' => 'app',

    /**
     * Queue sends instead of doing them in the request. A push is one HTTPS
     * request per subscriber (100-400ms), so a broadcast is not something a
     * visitor waits for. Ignored without redis - sends then run inline.
     */
    'queue' => true,

    /**
     * Its own queue, so a broadcast does not sit in front of the mail a signup
     * is waiting on: `php terminal queue work push-notification`.
     */
    'queue_name' => 'push-notification',

    /**
     * How long the push service holds a message for an offline device, in
     * seconds. 0 means deliver now or drop it.
     */
    'ttl' => 2419200, # 28 days

    /**
     * very-low | low | normal | high - what a phone does with it while saving
     * battery. Sending everything as high is how an application gets throttled.
     */
    'urgency' => 'normal',

    /**
     * Drop a subscription after this many consecutive failures. 404 and 410 are
     * deleted immediately without counting; this is for timeouts and 500s.
     * 0 disables it.
     */
    'max_failures' => 10,

    /**
     * Push service hostnames allowed, as suffixes. Empty means any public https
     * host. Endpoints resolving to a private address are refused either way -
     * the server POSTs to whatever the client subscribed with.
     *
     *   ['fcm.googleapis.com', 'updates.push.services.mozilla.com']
     */
    'hosts' => [],

    /**
     * Applications that can send.
     *
     * channel      Transport. `webpush` needs no account with anyone and works
     *              in Chrome, Firefox, Edge and Safari 16.4+.
     * subject      mailto: or https: url a push service can reach you at.
     *              Required by RFC 8292.
     * public_key   base64url P-256 point, shared with the browser.
     * private_key  base64url scalar. Treat it as a credential.
     * defaults     Merged under every payload, so icon/badge/url are set once.
     */
    'apps' => [

        'app' => [
            'channel'     => 'webpush',
            'subject'     => 'mailto:admin@example.com',
            'public_key'  => '',
            'private_key' => '',

            'defaults'    => [
                'icon'  => '/assets/img/notification-icon.png',
                'badge' => '/assets/img/notification-badge.png',
                'url'   => '/',
            ],
        ],

    ],
];
