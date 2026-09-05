<?php

namespace zFramework\Core\Facades;

use zFramework\Core\Jobs\SendPusherEvent;

/**
 * Pusher Channels: publish an event to every browser subscribed to a channel.
 *
 *   Pusher::trigger('orders', 'created', ['id' => 812, 'total' => '49.90']);
 *   Pusher::app('admin')->trigger('audit', 'login', $row);
 *
 * The page side is Pusher's own javascript (`new Pusher(key, {cluster})` →
 * `subscribe('orders')` → `bind('created', fn)`); this class is the server side
 * of the same protocol, over its REST API with no SDK in between - a POST with
 * an HMAC signature, and that is all the SDK does too. A self-hosted Soketi
 * speaks the same API and is a `host` in config/pusher.php.
 *
 * One instance per application in config/pusher.php `apps`: app() picks one,
 * and every static call is the default app's - there is no state left behind
 * between requests, only config read on each call.
 *
 * trigger() does not wait for Pusher: config `dispatch` decides whether the
 * call runs after the response (default), through the queue, or inline.
 * triggerNow() is the call itself and reports the HTTP status - what the
 * terminal `pusher test` and the queue job use.
 *
 * Private and presence channels ask the server whether this socket may join:
 * authenticate() signs that answer; the endpoint that calls it is the
 * application's (App/Controllers/PusherController.php), because that is where
 * "may this user see this channel" belongs.
 *
 * @method static bool   available()
 * @method static array  config()
 * @method static string endpoint()
 * @method static array  client()
 * @method static bool   trigger(string|array $channels, string $event, mixed $data = [], ?string $socketId = null)
 * @method static array  triggerNow(string|array $channels, string $event, mixed $data = [], ?string $socketId = null)
 * @method static array  sign(string $method, string $path, string $body = '', array $query = [], ?int $timestamp = null)
 * @method static array  authenticate(string $channel, string $socketId, ?array $user = null)
 * @method static bool   webhook(string $body, string $key, string $signature)
 * @method static array  get(string $path, array $query = [])
 *
 * @method bool   available()
 * @method array  config()
 * @method string endpoint()
 * @method array  client()
 * @method bool   trigger(string|array $channels, string $event, mixed $data = [], ?string $socketId = null)
 * @method array  triggerNow(string|array $channels, string $event, mixed $data = [], ?string $socketId = null)
 * @method array  sign(string $method, string $path, string $body = '', array $query = [], ?int $timestamp = null)
 * @method array  authenticate(string $channel, string $socketId, ?array $user = null)
 * @method bool   webhook(string $body, string $key, string $signature)
 * @method array  get(string $path, array $query = [])
 */
class Pusher
{
    /**
     * Pusher's own limits. A trigger over them is refused here with the reason,
     * rather than a 400 from the API after the response has gone out.
     */
    private const MAX_CHANNELS   = 100;
    private const MAX_DATA_BYTES = 10240;
    private const CHANNEL_RULE   = '/^[A-Za-z0-9_\-=@,.;]{1,164}$/';
    private const SOCKET_RULE    = '/^\d+\.\d+$/';

    /**
     * Key in config/pusher.php `apps`.
     */
    public readonly string $app;

    /**
     * @param string|null $app Defaults to config `default`.
     */
    public function __construct(?string $app = null)
    {
        $this->app = $app ?? (string) (config('pusher.default') ?: 'app');
    }

    /**
     * The instance for one application.
     *
     * @param string|null $app
     * @return static
     * @throws \InvalidArgumentException Not in config.
     */
    public static function app(?string $app = null): static
    {
        $instance = new static($app);
        if (!is_array(config("pusher.apps.{$instance->app}") ?: null)) throw new \InvalidArgumentException("Pusher: no application `{$instance->app}` in config/pusher.php.");
        return $instance;
    }

    /**
     * Pusher::trigger(...) is Pusher::app()->trigger(...).
     *
     * The methods below are protected on purpose: PHP only routes a static call
     * through here when the name is not callable as written, so a public
     * instance method named trigger() would make Pusher::trigger() an error
     * instead of the default app's call. From outside, both spellings land on
     * the same code; the @method lines above are what an editor sees.
     *
     * @param string $method
     * @param array  $arguments
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return (new static())->__call($method, $arguments);
    }

    /**
     * @param string $method
     * @param array  $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (!in_array($method, self::API, true)) throw new \BadMethodCallException("Pusher: no method $method().");
        return $this->$method(...$arguments);
    }

    /**
     * What __call() lets through.
     */
    private const API = ['available', 'config', 'endpoint', 'client', 'trigger', 'triggerNow', 'sign', 'authenticate', 'webhook', 'get'];

    /**
     * Whether this application has its three credentials.
     *
     * @return bool
     */
    protected function available(): bool
    {
        $c = $this->config();
        return $c['app_id'] !== '' && $c['key'] !== '' && $c['secret'] !== '';
    }

    /**
     * This application's entry with the defaults filled in, plus the shared keys.
     *
     * @return array
     */
    protected function config(): array
    {
        $shared = (array) (config('pusher') ?: []);
        $c      = (array) ($shared['apps'][$this->app] ?? []);
        return [
            'app_id'     => (string) ($c['app_id'] ?? ''),
            'key'        => (string) ($c['key'] ?? ''),
            'secret'     => (string) ($c['secret'] ?? ''),
            'cluster'    => (string) ($c['cluster'] ?? 'mt1'),
            'host'       => (string) ($c['host'] ?? ''),
            'port'       => $c['port'] ?? null,
            'scheme'     => (string) ($c['scheme'] ?? 'https'),
            'dispatch'   => (string) ($shared['dispatch'] ?? 'defer'),
            'queue_name' => (string) ($shared['queue_name'] ?? 'pusher'),
            'timeout'    => (int) ($shared['timeout'] ?? 5),
        ];
    }

    /**
     * Base url of the API: Pusher's cluster host or the configured one.
     *
     * @return string
     */
    protected function endpoint(): string
    {
        $c    = $this->config();
        $host = $c['host'] !== '' ? $c['host'] : "api-{$c['cluster']}.pusher.com";
        $port = $c['port'] ? ':' . (int) $c['port'] : '';
        return "{$c['scheme']}://$host$port";
    }

    /**
     * What a page needs to connect: the public key and where to. Never the secret.
     *
     * @return array
     */
    protected function client(): array
    {
        $c   = $this->config();
        $out = ['key' => $c['key'], 'cluster' => $c['cluster']];
        if ($c['host'] !== '') {
            $out['wsHost']            = $c['host'];
            $out['forceTLS']          = $c['scheme'] === 'https';
            $out['enabledTransports'] = ['ws', 'wss'];
            if ($c['port']) $out[$c['scheme'] === 'https' ? 'wssPort' : 'wsPort'] = (int) $c['port'];
        }
        return $out;
    }

    /**
     * Publish an event - when and how config `dispatch` says.
     *
     * @param string|array $channels One name or up to 100.
     * @param string       $event    Up to 200 characters. Names starting with `pusher:` are Pusher's own.
     * @param mixed        $data     Anything json_encode() takes, or a string sent as is. 10 KB at most, encoded.
     * @param string|null  $socketId The sender's socket: that connection does not receive its own event.
     * @return bool  Accepted for sending (defer/queue), or sent with a 2xx (inline). False when not configured.
     * @throws \InvalidArgumentException A channel name, the event name or the size Pusher would refuse.
     */
    protected function trigger(string|array $channels, string $event, mixed $data = [], ?string $socketId = null): bool
    {
        $payload = $this->payload($channels, $event, $data, $socketId);

        # Not configured: nothing to send to, and nothing to break either - the
        # call site is the same on a machine without an account.
        if (!$this->available()) return false;

        $c = $this->config();
        if ($c['dispatch'] === 'inline') return $this->triggerNow($channels, $event, $data, $socketId)['ok'];
        if ($c['dispatch'] === 'queue') return Queue::push([SendPusherEvent::class, 'handle'], $payload + ['app' => $this->app], $c['queue_name']);

        $app = $this->app;
        Defer::after(fn() => (new static($app))->triggerNow($payload['channels'], $payload['name'], $payload['data'], $payload['socket_id'] ?? null), "pusher:{$payload['name']}");
        return true;
    }

    /**
     * The HTTP call itself, now.
     *
     * @param string|array $channels
     * @param string       $event
     * @param mixed        $data
     * @param string|null  $socketId
     * @return array{ok: bool, status: int, body: string, error: string|null}
     */
    protected function triggerNow(string|array $channels, string $event, mixed $data = [], ?string $socketId = null): array
    {
        if (!$this->available()) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => "config/pusher.php apps.{$this->app} has no app_id/key/secret"];

        $body = json_encode($this->payload($channels, $event, $data, $socketId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->request('POST', '/apps/' . $this->config()['app_id'] . '/events', $body);
    }

    /**
     * The event as the API wants it: data as one json string, channels as a list.
     *
     * @param string|array $channels
     * @param string       $event
     * @param mixed        $data
     * @param string|null  $socketId
     * @return array
     */
    private function payload(string|array $channels, string $event, mixed $data, ?string $socketId): array
    {
        $channels = array_values(array_unique(array_map('strval', (array) $channels)));
        if (!$channels) throw new \InvalidArgumentException('Pusher: at least one channel.');
        if (count($channels) > self::MAX_CHANNELS) throw new \InvalidArgumentException('Pusher: at most ' . self::MAX_CHANNELS . ' channels per trigger.');
        foreach ($channels as $channel) if (!preg_match(self::CHANNEL_RULE, $channel)) throw new \InvalidArgumentException("Pusher: `$channel` is not a channel name (letters, digits, _ - = @ , . ; up to 164).");

        if ($event === '' || strlen($event) > 200) throw new \InvalidArgumentException('Pusher: an event name is 1-200 characters.');
        if ($socketId !== null && !preg_match(self::SOCKET_RULE, $socketId)) throw new \InvalidArgumentException("Pusher: `$socketId` is not a socket id.");

        $encoded = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_DATA_BYTES) throw new \InvalidArgumentException('Pusher: event data is over ' . self::MAX_DATA_BYTES . ' bytes (' . strlen($encoded) . ').');

        $payload = ['name' => $event, 'channels' => $channels, 'data' => $encoded];
        if ($socketId !== null) $payload['socket_id'] = $socketId;
        return $payload;
    }

    /**
     * Sign a request the way the REST API checks it: the sorted query string
     * with the key, timestamp, version and body md5, HMAC-SHA256 with the secret.
     *
     * Public so the signing can be checked against Pusher's published vectors
     * without a network (tests/pusher.php).
     *
     * @param string   $method
     * @param string   $path      e.g. /apps/3/events
     * @param string   $body      Raw json, '' for a GET.
     * @param array    $query     Extra query parameters.
     * @param int|null $timestamp Defaults to now.
     * @return array The full query, auth_signature included.
     */
    protected function sign(string $method, string $path, string $body = '', array $query = [], ?int $timestamp = null): array
    {
        $c      = $this->config();
        $query += [
            'auth_key'       => $c['key'],
            'auth_timestamp' => (string) ($timestamp ?? time()),
            'auth_version'   => '1.0',
        ];
        if ($body !== '') $query['body_md5'] = md5($body);
        ksort($query);

        $line = implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($query), $query));
        $query['auth_signature'] = hash_hmac('sha256', strtoupper($method) . "\n$path\n$line", $c['secret']);
        return $query;
    }

    /**
     * Answer for a private- or presence- channel subscription.
     *
     *   Pusher::authenticate('private-orders', $socketId)
     *   Pusher::authenticate('presence-room-4', $socketId, ['user_id' => 7, 'user_info' => ['name' => 'Ada']])
     *
     * Whether this user may join is decided before calling - this only signs.
     *
     * @param string     $channel
     * @param string     $socketId The `socket_id` the page posted.
     * @param array|null $user     For presence channels: user_id (required) and user_info (shown to the others).
     * @return array  What the page expects back as json: auth, and channel_data for presence.
     * @throws \InvalidArgumentException Not a private/presence channel, a malformed socket id, presence without a user_id.
     */
    protected function authenticate(string $channel, string $socketId, ?array $user = null): array
    {
        # An answer signed with an empty secret is one any client could forge.
        if (!$this->available()) throw new \RuntimeException("Pusher: config/pusher.php apps.{$this->app} has no app_id/key/secret.");
        if (!preg_match(self::CHANNEL_RULE, $channel)) throw new \InvalidArgumentException("Pusher: `$channel` is not a channel name.");
        if (!preg_match(self::SOCKET_RULE, $socketId)) throw new \InvalidArgumentException("Pusher: `$socketId` is not a socket id.");

        $presence = str_starts_with($channel, 'presence-');
        if (!$presence && !str_starts_with($channel, 'private-')) throw new \InvalidArgumentException("Pusher: `$channel` is public, it needs no authentication.");

        $line = "$socketId:$channel";
        $out  = [];

        if ($presence) {
            if (!isset($user['user_id']) || $user['user_id'] === '') throw new \InvalidArgumentException('Pusher: a presence channel needs user_id.');
            $data = ['user_id' => (string) $user['user_id']];
            if (isset($user['user_info'])) $data['user_info'] = $user['user_info'];
            $out['channel_data'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ':' . $out['channel_data'];
        }

        $c = $this->config();
        return ['auth' => $c['key'] . ':' . hash_hmac('sha256', $line, $c['secret'])] + $out;
    }

    /**
     * Whether a webhook Pusher posted (channel occupied, member added...) really came from it.
     *
     * @param string $body      Raw request body.
     * @param string $key       The X-Pusher-Key header.
     * @param string $signature The X-Pusher-Signature header.
     * @return bool
     */
    protected function webhook(string $body, string $key, string $signature): bool
    {
        $c = $this->config();
        if ($c['secret'] === '' || !hash_equals($c['key'], $key)) return false;
        return hash_equals(hash_hmac('sha256', $body, $c['secret']), strtolower($signature));
    }

    /**
     * Ask the API something: channel list, one channel's state, presence users.
     *
     *   Pusher::get('/channels', ['filter_by_prefix' => 'presence-', 'info' => 'user_count'])
     *
     * @param string $path  Under /apps/{app_id}.
     * @param array  $query
     * @return array{ok: bool, status: int, body: string, error: string|null, data: mixed}
     */
    protected function get(string $path, array $query = []): array
    {
        if (!$this->available()) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => "config/pusher.php apps.{$this->app} has no app_id/key/secret", 'data' => null];

        $result         = $this->request('GET', '/apps/' . $this->config()['app_id'] . '/' . ltrim($path, '/'), '', $query);
        $result['data'] = $result['ok'] ? json_decode($result['body'], true) : null;
        return $result;
    }

    /**
     * One signed HTTP call.
     *
     * @param string $method
     * @param string $path
     * @param string $body
     * @param array  $query
     * @return array{ok: bool, status: int, body: string, error: string|null}
     */
    private function request(string $method, string $path, string $body = '', array $query = []): array
    {
        $timeout = $this->config()['timeout'];
        $url     = $this->endpoint() . $path . '?' . http_build_query($this->sign($method, $path, $body, $query));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($ch) ?: null;
        curl_close($ch);

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $response, 'error' => $error];
    }
}
