<?php

namespace zFramework\Core\Facades\PushNotification;

use App\Models\PushNotificationSubscriptions;
use zFramework\Core\Facades\Queue;
use zFramework\Core\Facades\Redis;
use zFramework\Core\Helpers\Date;

/**
 * Push notifications: reach a user who is not on the site.
 *
 *   PushNotification::toUser($user['id'])->send([
 *       'title' => 'Order shipped',
 *       'url'   => '/orders/812',
 *   ]);
 *
 * A subscription belongs to an application in config/push-notification.php, so
 * several products can share one installation without reaching each other's
 * subscribers. The to* methods are filters and combine:
 *
 *   PushNotification::app('admin')->toTopic('orders')->send(...)
 *   PushNotification::toUser([3, 9])->toTopic('billing')->send(...)  // both must match
 *   PushNotification::toAll()->send(...)
 *
 * The transport is a [Channel](../PushNotification/Channel.php). Sends go
 * through the queue when configured - a broadcast is one HTTPS request per
 * subscriber - and inline without redis, same outcome.
 */
class PushNotification
{
    /**
     * Application these filters and this send belong to.
     */
    private static ?string $app = null;

    /**
     * Accumulated filters: user ids, topics, explicit subscription rows.
     */
    private static array $users         = [];
    private static array $topics        = [];
    private static array $subscriptions = [];
    private static bool  $all           = false;

    /**
     * Per-send delivery options: ttl, urgency, topic.
     */
    private static array $options = [];

    /**
     * Drop everything a send accumulated. Same reason Mail clears its
     * recipients: a filter surviving the request delivers one user's
     * notification to another's devices.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$app           = null;
        self::$users         = [];
        self::$topics        = [];
        self::$subscriptions = [];
        self::$all           = false;
        self::$options       = [];
    }

    /**
     * Choose the application to send as.
     *
     * @param string $app Key in config/push-notification.php `apps`.
     * @return self
     */
    public static function app(string $app): self
    {
        self::$app = $app;
        return new self();
    }

    /**
     * Notify these users, wherever they are subscribed.
     *
     * @param int|array $users
     * @return self
     */
    public static function toUser(int|array $users): self
    {
        foreach ((array) $users as $user) self::$users[] = is_array($user) ? ($user['id'] ?? null) : $user;
        self::$users = array_values(array_filter(array_unique(self::$users), fn($id) => $id !== null));

        return new self();
    }

    /**
     * Notify devices that asked for this topic. Topics are what the device
     * subscribed with, so a device that asked for nothing is not reached -
     * "only tell me about billing" without a preferences table.
     *
     * @param string|array $topics
     * @return self
     */
    public static function toTopic(string|array $topics): self
    {
        foreach ((array) $topics as $topic) self::$topics[] = (string) $topic;
        self::$topics = array_values(array_unique(self::$topics));

        return new self();
    }

    /**
     * Notify one known subscription - a row, or the object a browser just sent.
     *
     * @param array $subscription
     * @return self
     */
    public static function toSubscription(array $subscription): self
    {
        self::$subscriptions[] = $subscription;
        return new self();
    }

    /**
     * Every subscriber of the application. A filter that removes the filters.
     *
     * @return self
     */
    public static function toAll(): self
    {
        self::$all = true;
        return new self();
    }

    /**
     * How long the push service should hold the message for an offline device.
     *
     * @param int $seconds 0 delivers now or not at all.
     * @return self
     */
    public static function ttl(int $seconds): self
    {
        self::$options['ttl'] = $seconds;
        return new self();
    }

    /**
     * very-low | low | normal | high - what a phone does with it while asleep.
     *
     * @param string $urgency
     * @return self
     */
    public static function urgency(string $urgency): self
    {
        self::$options['urgency'] = $urgency;
        return new self();
    }

    /**
     * Collapse key: a newer message replaces the undelivered one carrying the
     * same topic.
     *
     * @param string $topic
     * @return self
     */
    public static function collapse(string $topic): self
    {
        self::$options['topic'] = $topic;
        return new self();
    }

    /**
     * Send to whatever the filters selected.
     *
     * @param array|string $payload Title alone, or title/body/icon/badge/url/tag/data.
     * @return array{queued: int, sent: int, failed: int, removed: int, errors: array}
     */
    public static function send(array|string $payload): array
    {
        [$app, $config] = self::application();

        $payload = self::payload($payload, $config);
        $options = self::$options;
        $ids     = [];

        # An explicit subscription is delivered to as given; it may not be in the
        # table at all, which is how a test send right after subscribing works.
        $direct = self::$subscriptions;
        $rows   = self::rows($app);

        foreach ($rows as $row) $ids[] = $row['id'];

        self::flushRequestState();

        $report = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];

        if ($direct) $report = self::merge($report, self::dispatch($app, $direct, $payload, $options));

        if (!$ids) return $report;

        # Chunked rather than one job per subscriber (floods redis) or one job
        # for all of them (an hour of work no other worker can help with).
        if (!(config('push-notification.queue') && Redis::available('queue'))) return self::merge($report, self::dispatch($app, $rows, $payload, $options));

        foreach (array_chunk($ids, 50) as $chunk) {
            Queue::push([\zFramework\Core\Jobs\SendPushNotifications::class, 'handle'], [
                'app'     => $app,
                'ids'     => $chunk,
                'payload' => $payload,
                'options' => $options,
            ], config('push-notification.queue_name') ?: 'push-notification');

            $report['queued'] += count($chunk);
        }

        return $report;
    }

    /**
     * Deliver now, whatever the configuration says - what the queue worker runs.
     *
     * Outcomes are written back as they happen: a subscription reported gone is
     * deleted, a failing one counts up until max_failures drops it, a success
     * resets the count. Left alone a subscriber table becomes mostly browsers
     * that no longer exist.
     *
     * @param string $app
     * @param array  $subscriptions Rows from push_notification_subscriptions.
     * @param array  $payload
     * @param array  $options
     * @return array{queued: int, sent: int, failed: int, removed: int, errors: array}
     */
    public static function dispatch(string $app, array $subscriptions, array $payload, array $options = []): array
    {
        $channel = Channel::make($app);
        $report  = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];
        $max     = (int) (config('push-notification.max_failures') ?? 10);

        foreach ($subscriptions as $subscription) {
            $result = $channel->deliver($subscription, $payload, $options);
            $id     = $subscription['id'] ?? null;

            if ($result['ok']) {
                $report['sent']++;
                if ($id) (new PushNotificationSubscriptions)->where('id', $id)->update(['failures' => 0, 'last_sent_at' => Date::timestamp()]);
                continue;
            }

            $report['failed']++;
            $report['errors'][] = ['endpoint' => $subscription['endpoint'] ?? null, 'status' => $result['status'], 'error' => $result['error']];

            if (!$id) continue;

            $failures = ((int) ($subscription['failures'] ?? 0)) + 1;

            if ($result['gone'] || ($max > 0 && $failures >= $max)) {
                (new PushNotificationSubscriptions)->where('id', $id)->delete();
                $report['removed']++;
                continue;
            }

            (new PushNotificationSubscriptions)->where('id', $id)->update(['failures' => $failures]);
        }

        return $report;
    }

    /**
     * Store what a browser subscribed with, or refresh what is already there.
     * Browsers re-subscribe on their own, so matching on the endpoint hash
     * replaces the row instead of duplicating it.
     *
     * @param array    $input   endpoint + keys, as PushManager.subscribe() produced.
     * @param int|null $user_id Null for a visitor who is not signed in.
     * @param array    $topics
     * @param string   $app
     * @return array The stored row.
     * @throws \InvalidArgumentException
     */
    public static function subscribe(array $input, ?int $user_id = null, array $topics = [], ?string $app = null): array
    {
        $app     = $app ?: (self::$app ?: (config('push-notification.default') ?: 'app'));
        $channel = Channel::make($app);
        $columns = $channel->subscription($input);

        $topics = array_values(array_unique(array_map('strval', $topics ?: (array) ($input['topics'] ?? []))));

        $row = [
            'app'           => $app,
            'channel'       => config("push-notification.apps.$app.channel") ?: 'webpush',
            'user_id'       => $user_id,
            'endpoint'      => $columns['endpoint'],
            'endpoint_hash' => hash('sha256', $columns['endpoint']),
            'p256dh'        => $columns['p256dh'],
            'auth'          => $columns['auth'],
            'topics'        => $topics ? json_encode($topics, JSON_UNESCAPED_UNICODE) : null,
            'user_agent'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            'failures'      => 0,
        ];

        $existing = (new PushNotificationSubscriptions)->where('app', $app)->where('endpoint_hash', $row['endpoint_hash'])->first();

        if ($existing) {
            (new PushNotificationSubscriptions)->where('id', $existing['id'])->update($row);
            return array_merge($existing, $row);
        }

        $inserted = (new PushNotificationSubscriptions)->insert($row);

        return is_array($inserted) ? $inserted : $row;
    }

    /**
     * Forget a subscription. Safe to call for one that was never stored.
     *
     * @param string      $endpoint
     * @param string|null $app Every application when omitted: a revoked endpoint
     *                         is revoked for whoever holds it.
     * @return int Rows removed.
     */
    public static function unsubscribe(string $endpoint, ?string $app = null): int
    {
        $query = (new PushNotificationSubscriptions)->where('endpoint_hash', hash('sha256', trim($endpoint)));
        if ($app) $query->where('app', $app);

        return $query->delete();
    }

    /**
     * What the client needs to subscribe. Served to javascript rather than
     * pasted into it: a hand-copied key is one deploy from not matching.
     *
     * @param string|null $app
     * @return array
     */
    public static function client(?string $app = null): array
    {
        [$app] = self::application($app);
        return Channel::make($app)->client();
    }

    /**
     * Resolve which application is being used, and its configuration.
     *
     * @param string|null $app
     * @return array{0: string, 1: array}
     * @throws \InvalidArgumentException
     */
    private static function application(?string $app = null): array
    {
        $app    = $app ?: (self::$app ?: (config('push-notification.default') ?: 'app'));
        $config = config("push-notification.apps.$app");

        if (!is_array($config)) throw new \InvalidArgumentException("Push: no application `$app` in config/push-notification.php.");

        return [$app, $config];
    }

    /**
     * The subscriptions the filters select.
     *
     * @param string $app
     * @return array
     */
    private static function rows(string $app): array
    {
        if (!self::$all && !self::$users && !self::$topics) return [];

        $query = (new PushNotificationSubscriptions)->where('app', $app);

        if (self::$users) $query->whereIn('user_id', self::$users);

        # Substring test against the quoted form, so `"billing"` cannot match
        # `"billing-eu"`. Enough for a handful of short strings; a topics table
        # is the answer once they are queried on their own.
        if (self::$topics) {
            $conditions = [];
            $data       = [];

            foreach (array_values(self::$topics) as $index => $topic) {
                $conditions[]        = "topics LIKE :push_topic_$index";
                $data["push_topic_$index"] = '%"' . str_replace(['%', '_'], ['\%', '\_'], $topic) . '"%';
            }

            $query->whereRaw('(' . implode(' OR ', $conditions) . ')', $data);
        }

        return $query->get();
    }

    /**
     * Merge the application defaults under a payload, and accept a bare title.
     *
     * @param array|string $payload
     * @param array        $config
     * @return array
     */
    private static function payload(array|string $payload, array $config): array
    {
        if (is_string($payload)) $payload = ['title' => $payload];

        $payload += (array) ($config['defaults'] ?? []);

        if (empty($payload['title'])) throw new \InvalidArgumentException('Push: a notification needs a title - it is what the device displays.');

        return $payload;
    }

    /**
     * @param array $report
     * @param array $with
     * @return array
     */
    private static function merge(array $report, array $with): array
    {
        foreach (['queued', 'sent', 'failed', 'removed'] as $key) $report[$key] += $with[$key];
        $report['errors'] = array_merge($report['errors'], $with['errors']);

        return $report;
    }
}
