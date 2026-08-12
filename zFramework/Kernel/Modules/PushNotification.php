<?php

namespace zFramework\Kernel\Modules;

use App\Models\PushNotificationSubscriptions;
use zFramework\Core\Facades\PushNotification\PushNotification as PushNotificationFacade;
use zFramework\Core\Facades\Str;
use zFramework\Core\Facades\PushNotification\Encryption;
use zFramework\Core\Facades\PushNotification\VAPID;
use zFramework\Kernel\Terminal;

class PushNotification
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Generate a VAPID key pair for an application
     * Usage: php terminal push-notification keys {app}
     * @param {app} (second argument, optional) name shown in the output, default `app`
     */
    public static function keys()
    {
        $app  = @Terminal::$commands[2] ?: (config('push-notification.default') ?: 'app');
        $keys = VAPID::generate();

        Terminal::text("[color=green]VAPID key pair for `$app`:[/color]\n");
        Terminal::text("[color=yellow]'public_key'[/color]  => '" . $keys['public'] . "',");
        Terminal::text("[color=yellow]'private_key'[/color] => '" . $keys['private'] . "',\n");
        Terminal::text("[color=dark-gray]Paste both into config/push-notification.php under apps.$app, and set `subject` to a mailto:\nor https: url a push service can reach you at.\n\nGenerate this once. Replacing a key pair invalidates every subscription taken\nwith the old one - those devices stop receiving and have to subscribe again.[/color]");
    }

    /**
     * Description: Check the encryption against the RFC 8291 test vectors
     * Usage: php terminal push-notification test
     */
    public static function test()
    {
        # Values from RFC 8291 §5. Says whether this installation's openssl
        # produces what the standard requires - the only way to find out before
        # a browser silently discards a message it could not decrypt.
        $expected = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

        try {
            $body = Encryption::encrypt(
                'When I grow up, I want to be a watermelon',
                'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
                'BTBZMqHH6r4Tts7J_aSIgg',
                [
                    'public'  => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
                    'private' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
                ],
                Str::base64UrlDecode('DGv6ra1nlYgDCS1FRnbzlw')
            );

            $encryption = hash_equals($expected, Str::base64UrlEncode($body));
        } catch (\Throwable $e) {
            $encryption = false;
            Terminal::text('[color=red]' . $e->getMessage() . '[/color]');
        }

        Terminal::text(($encryption ? '[color=green]ok  ' : '[color=red]FAIL') . ' aes128gcm payload encryption (RFC 8291 §5)[/color]');

        # Generation and signing use openssl differently; a build can do one and
        # not the other.
        try {
            $keys      = VAPID::generate();
            $token     = VAPID::token(['aud' => 'https://push.example.net', 'exp' => time() + 60, 'sub' => 'mailto:test@example.com'], $keys['public'], $keys['private']);
            $signature = count(explode('.', $token)) === 3 && strlen(Str::base64UrlDecode(explode('.', $token)[2])) === 64;
        } catch (\Throwable $e) {
            $signature = false;
            Terminal::text('[color=red]' . $e->getMessage() . '[/color]');
        }

        Terminal::text(($signature ? '[color=green]ok  ' : '[color=red]FAIL') . ' VAPID key generation and ES256 signing (RFC 8292)[/color]');

        if ($encryption && $signature) return Terminal::text("\n[color=green]Web push works on this installation.[/color]");

        Terminal::text("\n[color=red]Web push cannot deliver here.[/color] [color=dark-gray]Both checks need ext-openssl with prime256v1;\nsee the openssl.cnf note in zFramework/Core/Facades/PushNotification/.[/color]");
    }

    /**
     * Description: Send a notification from the command line
     * Usage: php terminal push-notification send {app} --title=Hello --body=Text --url=/ --user=3 --topic=news --all
     * @param {app}          (second argument, optional) application key, default from config
     * @param --title={text} (optional) notification title, default `Test notification`
     * @param --body={text}  (optional) notification body
     * @param --url={path}   (optional) where clicking it lands
     * @param --user={id}    (optional) send to one user's devices
     * @param --topic={name} (optional) send to devices subscribed to a topic
     * @param --all          (optional) send to every subscriber of the application
     */
    public static function send()
    {
        $app     = @Terminal::$commands[2] ?: (config('push-notification.default') ?: 'app');
        $user    = Terminal::$parameters['--user']  ?? null;
        $topic   = Terminal::$parameters['--topic'] ?? null;
        $all     = in_array('--all', Terminal::$parameters);

        if (!$user && !$topic && !$all) return Terminal::text('[color=red]Nothing to send to. Pass --user={id}, --topic={name} or --all.[/color]');

        $payload = array_filter([
            'title' => Terminal::$parameters['--title'] ?? 'Test notification',
            'body'  => Terminal::$parameters['--body']  ?? null,
            'url'   => Terminal::$parameters['--url']   ?? null,
        ]);

        try {
            PushNotificationFacade::app($app);
            if ($user)  PushNotificationFacade::toUser((int) $user);
            if ($topic) PushNotificationFacade::toTopic($topic);
            if ($all)   PushNotificationFacade::toAll();

            $report = PushNotificationFacade::send($payload);
        } catch (\Throwable $e) {
            return Terminal::text('[color=red]' . $e->getMessage() . '[/color]');
        }

        Terminal::text("[color=green]`$app`:[/color] " . implode(', ', [
            $report['queued'] . ' queued',
            $report['sent'] . ' sent',
            $report['failed'] . ' failed',
            $report['removed'] . ' removed',
        ]));

        foreach (array_slice($report['errors'], 0, 10) as $error) Terminal::text('[color=yellow]-> ' . $error['error'] . '[/color]');
        if (count($report['errors']) > 10) Terminal::text('[color=dark-gray]... and ' . (count($report['errors']) - 10) . ' more[/color]');
    }

    /**
     * Description: Show who is subscribed
     * Usage: php terminal push-notification subscribers {app}
     * @param {app} (second argument, optional) limit to one application
     */
    public static function subscribers()
    {
        $app  = @Terminal::$commands[2] ?: null;
        $rows = (new PushNotificationSubscriptions)->select(['app', 'channel', 'COUNT(*) AS total', 'SUM(failures > 0) AS failing', 'MAX(last_sent_at) AS last_sent_at']);

        if ($app) $rows->where('app', $app);

        $rows = $rows->groupBy(['app', 'channel'])->get();

        if (!$rows) return Terminal::text('[color=yellow]No subscriptions.[/color]');

        foreach ($rows as $row) Terminal::text(
            "[color=green]" . $row['app'] . "[/color] [color=dark-gray](" . $row['channel'] . ")[/color] "
                . $row['total'] . " subscriber(s)"
                . ($row['failing'] ? ", [color=yellow]" . $row['failing'] . " failing[/color]" : '')
                . ($row['last_sent_at'] ? " [color=dark-gray]last sent " . $row['last_sent_at'] . "[/color]" : " [color=dark-gray]never sent to[/color]")
        );
    }

    /**
     * Description: Delete subscriptions that keep failing
     * Usage: php terminal push-notification prune {app} --failures=10
     * @param {app}            (second argument, optional) limit to one application
     * @param --failures={n}   (optional) threshold, default push.max_failures
     */
    public static function prune()
    {
        $app       = @Terminal::$commands[2] ?: null;
        $threshold = (int) (Terminal::$parameters['--failures'] ?? config('push-notification.max_failures') ?? 10);

        if ($threshold < 1) return Terminal::text('[color=red]A threshold below 1 would delete every subscription.[/color]');

        $query = (new PushNotificationSubscriptions)->where('failures', '>=', $threshold);
        if ($app) $query->where('app', $app);

        $deleted = $query->delete();

        Terminal::text("[color=green]$deleted subscription(s) removed[/color] [color=dark-gray](failures >= $threshold)[/color]");
    }
}
