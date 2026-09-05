<?php

use zFramework\Core\Facades\Auth;

$signedIn = Auth::check();
?>
@extends('app.main')
@section('body')
<div class="my-4">
    <div class="d-flex align-items-end justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h2 mb-1">Push notifications</h1>
            <p class="text-secondary mb-0">Reach this browser after the tab is closed. Subscribe here, then send from here, from another tab, or from the terminal.</p>
        </div>
        <a href="{{ route('examples') }}" class="btn btn-sm border"><i class="fad fa-arrow-left me-1"></i> Examples</a>
    </div>

    <?php if (!$configured) : ?>
    <div class="alert alert-warning">
        <b>Not configured.</b> Generate a key pair once - <code>php terminal push-notification keys <?= htmlspecialchars($app) ?></code> - and paste <code>public_key</code> and <code>private_key</code> into <code>config/push-notification.php</code> under <code>apps.<?= htmlspecialchars($app) ?></code>, with a <code>mailto:</code> subject. Then <code>php terminal db migrate</code> for the subscriptions table. No account with anyone is needed; the browser vendor delivers.
    </div>
    <?php endif ?>

    <div class="alert alert-light border py-2">
        <div class="d-flex flex-wrap align-items-center gap-3 small">
            <span>browser: <b id="st-support">…</b></span>
            <span>permission: <b id="st-permission">…</b></span>
            <span>this browser: <b id="st-status">…</b></span>
            <span>stored subscriptions: <b><?= (int) $stored ?></b><?= $signedIn ? ' · yours: <b>' . (int) $mine . '</b>' : '' ?></span>
        </div>
    </div>

    <div class="row g-3">

        {{-- 1. Subscribe --}}
        <div class="col-lg-6">
            <div class="card zf-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-bell-plus me-2 text-primary"></i>1. Subscribe this browser</h5>
                    <p class="text-secondary small">Registers <code>/service-worker.js</code>, asks for permission (once - a "no" is not asked again until the user changes it in site settings), and stores the subscription with the topic <code>demo</code>. Chrome, Firefox, Edge, Safari 16.4+; https or localhost only.</p>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button class="btn btn-primary btn-sm" id="sub-btn" <?= $configured ? '' : 'disabled' ?>><i class="fad fa-bell me-1"></i> Subscribe (topic: demo)</button>
                        <button class="btn btn-outline-secondary btn-sm" id="unsub-btn" <?= $configured ? '' : 'disabled' ?>><i class="fad fa-bell-slash me-1"></i> Unsubscribe</button>
                    </div>
                    <div class="zf-log" id="sub-log"><div class="text-secondary small">nothing yet</div></div>
                </div>
                <details class="card-footer small">
                    <summary class="text-secondary">the code</summary>
<pre class="mb-0"><code>&lt;script src="/assets/js/push-notification.js"&gt;&lt;/script&gt;

const result = await PushNotification.subscribe({ topics: ['demo'] });
if (!result.status) console.log('not subscribed:', result.reason);   // unsupported | denied | no-key | rejected

await PushNotification.status();   // unsupported | denied | prompt | subscribed | unsubscribed
await PushNotification.unsubscribe();</code></pre>
                </details>
            </div>
        </div>

        {{-- 2. Send --}}
        <div class="col-lg-6">
            <div class="card zf-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-paper-plane me-2 text-success"></i>2. Send</h5>
                    <p class="text-secondary small">The server picks who: this browser's subscription, everything you own, whoever asked for the topic, or everyone. Close the tab first if you want to see the point.</p>
                    <form id="send-form" class="mb-2">
                        <?= csrf() ?>
                        <div class="row g-2 mb-2">
                            <div class="col-5"><input type="text" name="title" class="form-control form-control-sm" value="Hello from zFramework" maxlength="80"></div>
                            <div class="col-7"><input type="text" name="body" class="form-control form-control-sm" value="Sent at <?= date('H:i:s') ?> - click me" maxlength="160"></div>
                        </div>
                        <input type="hidden" name="endpoint" id="endpoint">
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-success btn-sm" data-to="browser" <?= $configured ? '' : 'disabled' ?>>this browser</button>
                            <button class="btn btn-outline-success btn-sm" data-to="user" <?= $configured && $signedIn ? '' : 'disabled' ?> title="<?= $signedIn ? 'every device you subscribed with' : 'sign in first' ?>">me, every device</button>
                            <button class="btn btn-outline-success btn-sm" data-to="topic" <?= $configured ? '' : 'disabled' ?>>topic: demo</button>
                            <button class="btn btn-outline-success btn-sm" data-to="all" <?= $configured ? '' : 'disabled' ?>>everyone</button>
                        </div>
                    </form>
                    <div class="zf-log" id="send-log"><div class="text-secondary small">nothing yet</div></div>
                </div>
                <details class="card-footer small">
                    <summary class="text-secondary">the code</summary>
<pre class="mb-0"><code>PushNotification::toUser(Auth::id())->send(['title' => $title, 'body' => $body, 'url' => '/demo/push-notification']);
PushNotification::toTopic('demo')->send([...]);
PushNotification::toAll()->send([...]);
PushNotification::toSubscription($row)->send([...]);   // one known device

// returns ['queued' => n, 'sent' => n, 'failed' => n, 'removed' => n, 'errors' => [...]]
// queued when config queue=true and redis is up: php terminal queue work push-notification</code></pre>
                </details>
            </div>
        </div>

        {{-- 3. From outside --}}
        <div class="col-12">
            <div class="card zf-card">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-terminal me-2 text-dark"></i>3. From anywhere else</h5>
                    <p class="text-secondary small mb-2">A subscription is a row in <code>push_notification_subscriptions</code>; anything on the server can address it. Subscribe above, close this tab, then from a terminal:</p>
<pre class="mb-0"><code>php terminal push-notification send <?= htmlspecialchars($app) ?> --title="Still here" --body="The tab is closed and this arrived" --all
php terminal push-notification test      # the encryption against the RFC 8291 vectors - run this first on a new server</code></pre>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('footer')
<script src="{{ asset('/assets/js/push-notification.js') }}"></script>
<script>
(async () => {
    const $log = (id, html) => {
        const box = document.getElementById(id);
        if (box.dataset.empty !== '0') { box.innerHTML = ''; box.dataset.empty = '0'; }
        box.insertAdjacentHTML('beforeend', `<div><small class="text-secondary">${new Date().toTimeString().slice(0, 8)}</small> ${html}</div>`);
        box.scrollTop = box.scrollHeight;
    };
    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    const refresh = async () => {
        document.getElementById('st-support').textContent    = PushNotification.support() ? 'supported' : 'unsupported';
        document.getElementById('st-permission').textContent = ('Notification' in window) ? Notification.permission : '-';
        document.getElementById('st-status').textContent     = await PushNotification.status();

        // This browser's own endpoint, so "this browser" can address exactly it.
        const reg = 'serviceWorker' in navigator ? await navigator.serviceWorker.getRegistration('/') : null;
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        document.getElementById('endpoint').value = sub ? sub.endpoint : '';
    };
    await refresh();

    document.getElementById('sub-btn').onclick = async () => {
        const r = await PushNotification.subscribe({ topics: ['demo'] });
        $log('sub-log', r.status ? '<span class="text-success">subscribed</span> - the endpoint is stored server-side' : `<span class="text-danger">not subscribed:</span> ${esc(r.reason)}`);
        await refresh();
    };
    document.getElementById('unsub-btn').onclick = async () => {
        const r = await PushNotification.unsubscribe();
        $log('sub-log', r.status ? 'unsubscribed on both sides' : `<span class="text-danger">failed:</span> ${esc(r.reason)}`);
        await refresh();
    };

    document.querySelectorAll('#send-form [data-to]').forEach(btn => btn.onclick = async e => {
        e.preventDefault();
        const form = document.getElementById('send-form');
        const body = new URLSearchParams(new FormData(form));
        body.set('to', btn.dataset.to);
        if (btn.dataset.to === 'browser' && !body.get('endpoint')) return $log('send-log', '<span class="text-danger">this browser is not subscribed</span> - step 1 first');

        const r = await (await fetch('{{ route("push-notification.examples.send") }}', { method: 'POST', body, credentials: 'same-origin' })).json();
        if (!r.status) return $log('send-log', `<span class="text-danger">${esc(r.reason)}</span>`);
        const s = r.result;
        $log('send-log', `to <b>${esc(btn.dataset.to)}</b>: sent ${s.sent}, queued ${s.queued}, failed ${s.failed}, removed ${s.removed}` + (s.errors && s.errors.length ? ` - <span class="text-danger">${esc(JSON.stringify(s.errors).slice(0, 200))}</span>` : '') + (s.sent + s.queued === 0 ? ' <span class="text-secondary">(nobody matched)</span>' : ''));
    });
})();
</script>
@endsection
