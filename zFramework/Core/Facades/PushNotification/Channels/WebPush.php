<?php

namespace zFramework\Core\Facades\PushNotification\Channels;

use zFramework\Core\Facades\cURL;
use zFramework\Core\Facades\Str;
use zFramework\Core\Facades\PushNotification\Channel;
use zFramework\Core\Facades\PushNotification\Encryption;
use zFramework\Core\Facades\PushNotification\VAPID;

/**
 * The browser's own push standard: RFC 8030 delivery, RFC 8291 encryption,
 * RFC 8292 identification.
 *
 * One HTTPS POST to the endpoint the browser handed out; the push service holds
 * it until the device connects. Nothing has to be registered with anyone - a
 * key pair is enough to notify Chrome, Firefox, Edge and Safari 16.4+.
 */
class WebPush extends Channel
{
    /**
     * Seconds to wait. A service that never answers otherwise holds a queue
     * worker for as long as it is allowed to.
     */
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT         = 15;

    /**
     * @param array $subscription
     * @param array $payload
     * @param array $options
     * @return array{ok: bool, status: int, gone: bool, error: string|null}
     */
    public function deliver(array $subscription, array $payload, array $options = []): array
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');

        try {
            $body = Encryption::encrypt(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (string) $subscription['p256dh'],
                (string) $subscription['auth']
            );

            $headers = [
                'Authorization'    => VAPID::authorization($endpoint, $this->subject(), $this->config['public_key'] ?? '', $this->config['private_key'] ?? ''),
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'Content-Length'   => strlen($body),
                'TTL'              => (int) ($options['ttl'] ?? config('push-notification.ttl') ?? 2419200),
                'Urgency'          => $options['urgency'] ?? config('push-notification.urgency') ?? 'normal',
            ];

            # A topic replaces the undelivered message carrying the same one: a
            # device offline for an hour gets one basket total, not twelve.
            if (!empty($options['topic'])) $headers['Topic'] = substr(preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $options['topic']), 0, 32);
        } catch (\Throwable $e) {
            # Malformed keys cannot be repaired by retrying, and one unusable row
            # must not stop a broadcast.
            return $this->result(false, 0, false, $e->getMessage());
        }

        $response = cURL::set($endpoint)
            ->post($body)
            ->headers($headers)
            ->options([
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
            ])
            ->send(fn($response, $info, $error) => ['body' => $response, 'code' => (int) ($info['http_code'] ?? 0), 'error' => $error['error_message'] ?? null]);

        $status = $response['code'];

        # 201 is documented, some services say 200 or 202. 2xx means the service
        # took it; what the device does afterwards is never reported back.
        if ($status >= 200 && $status < 300) return $this->result(true, $status, false, null);

        # 404 never existed, 410 unsubscribed or site data cleared. Both are
        # permanent, so the row is deleted rather than retried - otherwise the
        # table fills with browsers that are never coming back.
        $gone = in_array($status, [404, 410]);

        return $this->result(false, $status, $gone, $this->explain($status, $response['error'] ?? null, (string) $response['body']));
    }

    /**
     * Validate what `PushManager.subscribe()` produced.
     *
     * @param array $input
     * @return array
     * @throws \InvalidArgumentException
     */
    public function subscription(array $input): array
    {
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        $p256dh   = (string) ($input['keys']['p256dh'] ?? $input['p256dh'] ?? '');
        $auth     = (string) ($input['keys']['auth'] ?? $input['auth'] ?? '');

        $this->guardEndpoint($endpoint);

        # Checked here rather than at send time: otherwise a subscription that
        # can never be encrypted for is discovered once per broadcast.
        if (strlen(VAPID::bytes($p256dh)) !== 65) throw new \InvalidArgumentException('Push: subscription key p256dh is not a P-256 point.');
        if (strlen(Str::base64UrlDecode($auth)) !== 16) throw new \InvalidArgumentException('Push: subscription auth secret is not 16 bytes.');

        return [
            'endpoint' => $endpoint,
            'p256dh'   => $p256dh,
            'auth'     => $auth,
        ];
    }

    /**
     * @return array
     */
    public function client(): array
    {
        return [
            'channel'    => 'webpush',
            'app'        => $this->app,
            'public_key' => $this->config['public_key'] ?? '',
        ];
    }

    /**
     * Refuse an endpoint that does not belong to a push service.
     *
     * The endpoint comes from the browser and the server POSTs to whatever it is
     * told, so without this an attacker who can subscribe can aim that request
     * at an internal address. https only, never a private address. `hosts` in
     * config narrows it further to known services.
     *
     * @param string $endpoint
     * @return void
     * @throws \InvalidArgumentException
     */
    private function guardEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);

        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) throw new \InvalidArgumentException('Push: an endpoint must be an https url.');

        $host  = $parts['host'];
        $hosts = array_filter((array) (config('push-notification.hosts') ?: []));

        if ($hosts) {
            $allowed = false;
            foreach ($hosts as $suffix) if ($host === $suffix || str_ends_with($host, ".$suffix")) $allowed = true;
            if (!$allowed) throw new \InvalidArgumentException("Push: `$host` is not an allowed push service.");
        }

        # Resolved once. Not proof against a name that answers differently a
        # second later, but it closes the case that costs nothing to try.
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
            );

        # A name nothing answers for is left to curl: refusing here would turn a
        # dns hiccup into a rejected subscription.
        if (!$addresses) $addresses = array_filter([gethostbyname($host)], fn($ip) => filter_var($ip, FILTER_VALIDATE_IP));

        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)) continue;
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new \InvalidArgumentException("Push: `$host` resolves to a private address.");
        }
    }

    /**
     * The `sub` claim. RFC 8292 requires it and push services check it.
     *
     * @return string
     */
    private function subject(): string
    {
        $subject = $this->config['subject'] ?? '';
        if (!$subject) throw new \RuntimeException("Push: application `{$this->app}` has no `subject` in config/push-notification.php - a push service will reject an unsigned-for sender.");

        return $subject;
    }

    /**
     * Say what a rejection means in terms the operator can act on.
     *
     * @param int         $status
     * @param string|null $transport
     * @param string      $body
     * @return string
     */
    private function explain(int $status, ?string $transport, string $body): string
    {
        $reason = match (true) {
            $status === 0   => 'no answer from the push service' . ($transport ? " ($transport)" : ''),
            $status === 400 => 'the push service rejected the request as malformed',
            $status === 401,
            $status === 403 => 'VAPID was rejected - the key pair does not match the one this subscription was taken with, or `subject` is not acceptable',
            $status === 404,
            $status === 410 => 'the subscription no longer exists',
            $status === 413 => 'payload too large for this push service',
            $status === 429 => 'rate limited by the push service',
            $status >= 500  => 'the push service is failing',
            default         => 'rejected',
        };

        $body = trim(strip_tags($body));

        return "HTTP $status: $reason" . ($body !== '' ? ' - ' . \zFramework\Core\Facades\Str::limit($body, 200) : '');
    }

    /**
     * @param bool        $ok
     * @param int         $status
     * @param bool        $gone
     * @param string|null $error
     * @return array
     */
    private function result(bool $ok, int $status, bool $gone, ?string $error): array
    {
        return compact('ok', 'status', 'gone', 'error');
    }
}
