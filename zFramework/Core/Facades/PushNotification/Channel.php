<?php

namespace zFramework\Core\Facades\PushNotification;

/**
 * What every delivery transport has to do.
 *
 * Web push is the one that ships. An application that later wants FCM or APNs
 * adds a class here rather than a second notification system beside this one.
 *
 * Only three things differ per transport: what a subscription must contain,
 * how one message is delivered, and what the client needs to subscribe.
 * Targeting, payloads, retries and pruning are the same either way and live in
 * [PushNotification](PushNotification.php).
 */
abstract class Channel
{
    /**
     * @param string $app    Key in config/push-notification.php `apps`.
     * @param array  $config That application's configuration.
     */
    public function __construct(protected string $app, protected array $config) {}

    /**
     * Deliver one message to one subscription. Never throws for a rejection: a
     * dead subscription is an outcome, and a broadcast must not abort on the
     * first uninstalled browser.
     *
     * @param array $subscription Row from push_notification_subscriptions.
     * @param array $payload      Already merged with the application defaults.
     * @param array $options      ttl, urgency, topic.
     * @return array{ok: bool, status: int, gone: bool, error: string|null}
     */
    abstract public function deliver(array $subscription, array $payload, array $options = []): array;

    /**
     * Turn what the client sent into columns, or fail with why. Only the channel
     * knows what its platform's subscribe API produces.
     *
     * @param array $input
     * @return array Columns for push_notification_subscriptions.
     * @throws \InvalidArgumentException
     */
    abstract public function subscription(array $input): array;

    /**
     * What the browser needs to subscribe: public key, and nothing secret.
     *
     * @return array
     */
    abstract public function client(): array;

    /**
     * Channel name to class. Spelled out because the autoloader maps a class to
     * a path character for character - `ucfirst('webpush')` finds nothing on a
     * case-sensitive filesystem.
     */
    private const CHANNELS = [
        'webpush' => Channels\WebPush::class,
    ];

    /**
     * Build the channel an application is configured to use. A name outside the
     * list above is taken as a class of its own, which is all a custom
     * transport needs: extend this and name it in config.
     *
     * @param string     $app
     * @param array|null $config Read from config when omitted.
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function make(string $app, ?array $config = null): self
    {
        $config ??= config("push-notification.apps.$app");
        if (!$config) throw new \InvalidArgumentException("Push: no application `$app` in config/push-notification.php.");

        $channel = $config['channel'] ?? 'webpush';
        $class   = self::CHANNELS[$channel] ?? $channel;

        if (!class_exists($class) || !is_subclass_of($class, self::class)) throw new \InvalidArgumentException("Push: `$channel` is not a delivery channel.");

        return new $class($app, $config);
    }
}
