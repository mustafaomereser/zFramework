<?php

namespace zFramework\Core\Jobs;

use zFramework\Core\Facades\Pusher;

/**
 * Queue job for one Pusher event, pushed by Pusher::trigger() when
 * config/pusher.php says `dispatch => 'queue'`. Run by
 * `php terminal queue work pusher`.
 */
class SendPusherEvent
{
    /**
     * @param array $payload app, name, channels, data (json string), socket_id
     * @return void
     */
    public function handle(array $payload): void
    {
        if (empty($payload['name']) || empty($payload['channels'])) return;

        $result = Pusher::app($payload['app'] ?? null)->triggerNow($payload['channels'], $payload['name'], (string) ($payload['data'] ?? ''), $payload['socket_id'] ?? null);

        # A queue job that fails silently is a job that did not run as far as
        # anyone can tell. Throw, and the queue records the attempt.
        if (!$result['ok']) throw new \RuntimeException('Pusher: ' . ($result['error'] ?: "HTTP {$result['status']} {$result['body']}"));
    }
}
