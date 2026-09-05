<?php

namespace zFramework\Kernel\Modules;

use zFramework\Core\Facades\Pusher as PusherFacade;
use zFramework\Kernel\Terminal;

class Pusher
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * The application named on the command line, or the default one.
     *
     * @param int $position
     * @return PusherFacade|null
     */
    private static function app(int $position): ?PusherFacade
    {
        try {
            return PusherFacade::app(@Terminal::$commands[$position] ?: null);
        } catch (\InvalidArgumentException $e) {
            Terminal::text('[color=red]' . $e->getMessage() . '[/color]');
            return null;
        }
    }

    /**
     * Description: Show which server and app the config points at, and whether the API answers
     * Usage: php terminal pusher status {app}
     * @param {app} (second argument, optional) key under `apps`, default the configured default
     */
    public static function status()
    {
        if (!$pusher = self::app(2)) return;
        $c = $pusher->config();

        Terminal::text('[color=yellow]app[/color]       ' . $pusher->app);
        Terminal::text('[color=yellow]app_id[/color]    ' . ($c['app_id'] !== '' ? $c['app_id'] : '[color=red](empty)[/color]'));
        Terminal::text('[color=yellow]key[/color]       ' . ($c['key'] !== '' ? $c['key'] : '[color=red](empty)[/color]'));
        Terminal::text('[color=yellow]secret[/color]    ' . ($c['secret'] !== '' ? str_repeat('*', 8) . substr($c['secret'], -4) : '[color=red](empty)[/color]'));
        Terminal::text('[color=yellow]endpoint[/color]  ' . $pusher->endpoint() . ($c['host'] !== '' ? ' (self-hosted)' : " (cluster {$c['cluster']})"));
        Terminal::text('[color=yellow]dispatch[/color]  ' . $c['dispatch'] . ($c['dispatch'] === 'queue' ? " (queue `{$c['queue_name']}`)" : ''));

        if (!$pusher->available()) return Terminal::text("\n[color=red]Not configured - paste app_id, key and secret from dashboard.pusher.com into config/pusher.php apps.{$pusher->app}.[/color]");

        $result = $pusher->get('/channels');
        if ($result['ok']) {
            $count = count($result['data']['channels'] ?? []);
            return Terminal::text("\n[color=green]The API answers: $count occupied channel(s) right now.[/color]");
        }

        Terminal::text("\n[color=red]The API refused: HTTP {$result['status']} " . ($result['error'] ?: $result['body']) . '[/color]');
        if ($result['status'] === 401) Terminal::text('[color=dark-gray]401 is the key or secret; 404 is the app_id or the cluster.[/color]');
    }

    /**
     * Description: Send one event now and report what Pusher answered
     * Usage: php terminal pusher test {app} {channel} {event}
     * @param {app} (second argument, optional) key under `apps`; `-` for the default
     * @param {channel} (third argument, optional) default `zf-test`
     * @param {event} (fourth argument, optional) default `ping`
     */
    public static function test()
    {
        if (@Terminal::$commands[2] === '-') Terminal::$commands[2] = null;
        if (!$pusher = self::app(2)) return;
        if (!$pusher->available()) return Terminal::text("[color=red]Not configured - config/pusher.php apps.{$pusher->app} needs app_id, key and secret.[/color]");

        $channel = @Terminal::$commands[3] ?: 'zf-test';
        $event   = @Terminal::$commands[4] ?: 'ping';

        try {
            $result = $pusher->triggerNow($channel, $event, ['at' => date('c'), 'from' => gethostname()]);
        } catch (\InvalidArgumentException $e) {
            return Terminal::text('[color=red]' . $e->getMessage() . '[/color]');
        }

        if ($result['ok']) return Terminal::text("[color=green]Sent `$event` to `$channel` as `{$pusher->app}` (HTTP {$result['status']}).[/color]\n[color=dark-gray]Open the app's Debug Console at dashboard.pusher.com - the event is listed there whether or not a page is subscribed.[/color]");

        Terminal::text("[color=red]Not sent: HTTP {$result['status']} " . ($result['error'] ?: $result['body']) . '[/color]');
    }
}
