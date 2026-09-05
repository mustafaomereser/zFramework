<?php

use zFramework\Core\Facades\Auth;

$signedIn = Auth::check();
$userId   = $signedIn ? Auth::id() : null;
?>
@extends('app.main')
@section('body')
<div class="my-4">
    <div class="d-flex align-items-end justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h2 mb-1">Pusher Channels</h1>
            <p class="text-secondary mb-0">The server publishes, every open tab hears it. Open this page twice to see it.</p>
        </div>
        <a href="{{ route('examples') }}" class="btn btn-sm border"><i class="fad fa-arrow-left me-1"></i> Examples</a>
    </div>

    <?php if (!$configured) : ?>
    <div class="alert alert-warning">
        <b>Not configured.</b> Create a Channels app at <a href="https://dashboard.pusher.com" target="_blank">dashboard.pusher.com</a>, paste <code>app_id</code>, <code>key</code>, <code>secret</code> and <code>cluster</code> into <code>config/pusher.php</code>, then <code>php terminal pusher status</code>. The buttons below stay disabled until then; the code is still worth reading.
    </div>
    <?php else : ?>
    <div class="alert alert-light border d-flex align-items-center gap-3 py-2">
        <span id="conn" class="badge text-bg-secondary">connecting…</span>
        <small class="text-secondary">key <code><?= htmlspecialchars($client['key']) ?></code> · cluster <code><?= htmlspecialchars($client['cluster']) ?></code> · socket <code id="socket">-</code></small>
    </div>
    <?php endif ?>

    <div class="row g-3">

        {{-- 1. Chat --}}
        <div class="col-lg-6">
            <div class="card zf-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-comments me-2 text-primary"></i>Chat</h5>
                    <p class="text-secondary small">One trigger, every tab gets the line - except the one that typed it, which already showed it. That is what the socket id is for.</p>
                    <div class="zf-log mb-2" id="chat-log"><div class="text-secondary small">nothing yet</div></div>
                    <form id="chat-form" class="input-group input-group-sm">
                        <?= csrf() ?>
                        <input type="hidden" name="kind" value="chat">
                        <input type="text" name="text" class="form-control" placeholder="say something" maxlength="200" autocomplete="off" <?= $configured ? '' : 'disabled' ?>>
                        <button class="btn btn-primary" type="submit" <?= $configured ? '' : 'disabled' ?>>Send</button>
                    </form>
                </div>
                <details class="card-footer small">
                    <summary class="text-secondary">the code</summary>
<pre class="mb-0"><code>// server
Pusher::trigger('examples', 'chat', ['who' => $who, 'text' => $text], request('socket_id'));

// page
LivePusher.on('examples', 'chat', line => append(line));
const socket_id = await LivePusher.socketId();   // sent with the form</code></pre>
                </details>
            </div>
        </div>

        {{-- 2. Ping --}}
        <div class="col-lg-6">
            <div class="card zf-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-bullseye-pointer me-2 text-success"></i>Ping</h5>
                    <p class="text-secondary small">The simplest shape: a button here, an event everywhere. Each tab counts what it heard.</p>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <button class="btn btn-success btn-sm" id="ping-btn" <?= $configured ? '' : 'disabled' ?>><i class="fad fa-hand-point-up me-1"></i> Ping everyone</button>
                        <div>heard <b id="ping-count">0</b> ping(s)</div>
                    </div>
                    <div class="zf-log" id="ping-log"><div class="text-secondary small">nothing yet</div></div>
                </div>
                <details class="card-footer small">
                    <summary class="text-secondary">the code</summary>
<pre class="mb-0"><code>Pusher::trigger('examples', 'ping', ['who' => $who, 'at' => date('H:i:s')]);

LivePusher.on('examples', 'ping', p => count++);</code></pre>
                </details>
            </div>
        </div>

        {{-- 3. Progress --}}
        <div class="col-lg-6">
            <div class="card zf-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-tasks me-2 text-warning"></i>Progress</h5>
                    <p class="text-secondary small">A job that reports as it goes - ten events from one request, the bar moves in every tab. A real job would do the same from the queue.</p>
                    <button class="btn btn-warning btn-sm mb-3" id="progress-btn" <?= $configured ? '' : 'disabled' ?>><i class="fad fa-play me-1"></i> Start a 2.5 s job</button>
                    <div class="progress" role="progressbar" style="height: 24px">
                        <div class="progress-bar progress-bar-striped" id="progress-bar" style="width: 0%">0%</div>
                    </div>
                    <small class="text-secondary" id="progress-who"></small>
                </div>
                <details class="card-footer small">
                    <summary class="text-secondary">the code</summary>
<pre class="mb-0"><code>for ($step = 1; $step <= 10; $step++) {
    usleep(250_000);
    Pusher::triggerNow('examples', 'progress', ['percent' => $step * 10]);   // inline: this request sends it
}

LivePusher.on('examples', 'progress', p => bar.style.width = p.percent + '%');</code></pre>
                </details>
            </div>
        </div>

        {{-- 4. Private --}}
        <div class="col-lg-6">
            <div class="card zf-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-lock me-2 text-danger"></i>Private channel</h5>
                    <?php if ($signedIn) : ?>
                    <p class="text-secondary small">You are subscribed to <code>private-examples-user-<?= $userId ?></code>. The page asked <code>/pusher/auth</code> for permission; a guest is refused there. Another user's tab never hears this.</p>
                    <button class="btn btn-danger btn-sm mb-2" id="private-btn" <?= $configured ? '' : 'disabled' ?>><i class="fad fa-paper-plane me-1"></i> Send something only to me</button>
                    <div class="zf-log" id="private-log"><div class="text-secondary small">nothing yet</div></div>
                    <?php else : ?>
                    <p class="text-secondary small">A <code>private-</code> channel needs the server's signature, and <code>/pusher/auth</code> only signs for a signed-in user. <a href="{{ route('auth-form') }}">Sign in</a> to try it - the seeded admin is <code>admin@localhost.com / admin</code>.</p>
                    <?php endif ?>
                </div>
                <details class="card-footer small">
                    <summary class="text-secondary">the code</summary>
<pre class="mb-0"><code>// App/Controllers/PusherController.php::auth() decides who may join, then:
Pusher::authenticate($channel, $socketId);

Pusher::trigger('private-examples-user-' . Auth::id(), 'note', ['text' => '...']);

LivePusher.on('private-examples-user-<?= $userId ?? 'ID' ?>', 'note', n => show(n.text));</code></pre>
                </details>
            </div>
        </div>

        {{-- 5. Presence --}}
        <div class="col-12">
            <div class="card zf-card">
                <div class="card-body">
                    <h5 class="card-title"><i class="fad fa-users me-2 text-info"></i>Presence channel</h5>
                    <?php if ($signedIn) : ?>
                    <p class="text-secondary small">Who is on this page right now. <code>presence-examples-room</code> carries a member list; <code>/pusher/auth</code> attached your name as <code>user_info</code>. Open a second browser signed in as someone else.</p>
                    <div class="d-flex flex-wrap gap-2" id="members"><span class="text-secondary small">joining…</span></div>
                    <?php else : ?>
                    <p class="text-secondary small">Presence channels are private channels that also list their members. <a href="{{ route('auth-form') }}">Sign in</a> to appear in the room.</p>
                    <?php endif ?>
                </div>
                <details class="card-footer small">
                    <summary class="text-secondary">the code</summary>
<pre class="mb-0"><code>LivePusher.on('presence-examples-room', {
    'pusher:subscription_succeeded': members => members.each(m => add(m)),
    'pusher:member_added':           m => add(m),
    'pusher:member_removed':         m => remove(m.id),
});</code></pre>
                </details>
            </div>
        </div>

    </div>
</div>
@endsection

@section('footer')
<?php if ($configured) : ?>
<script src="{{ asset('/assets/js/pusher.js') }}"></script>
<script>
(async () => {
    const $log = (id, html) => {
        const box = document.getElementById(id);
        if (box.dataset.empty !== '0') { box.innerHTML = ''; box.dataset.empty = '0'; }
        box.insertAdjacentHTML('beforeend', html);
        box.scrollTop = box.scrollHeight;
    };
    const esc  = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const send = async body => {
        body._token = document.querySelector('input[name="_token"]').value;
        const r = await fetch('{{ route("pusher.examples.send") }}', { method: 'POST', body: new URLSearchParams(body), credentials: 'same-origin' });
        const j = await r.json();
        if (!j.status) $.notify(j.reason || ('HTTP ' + j.http)).show('error');
        return j;
    };

    // The form must never fall through to a plain GET, connected or not.
    document.getElementById('chat-form').addEventListener('submit', e => e.preventDefault());

    // Connection state, for the badge. A failure here (no config, a blocked
    // CDN, a wrong cluster) is said on the badge and the page stays usable.
    const conn = document.getElementById('conn');
    let pusher;
    try {
        pusher = await LivePusher.connect();
    } catch (error) {
        conn.textContent = 'failed: ' + error.message;
        conn.className   = 'badge text-bg-danger';
        return;
    }
    pusher.connection.bind('state_change', s => { conn.textContent = s.current; conn.className = 'badge ' + (s.current === 'connected' ? 'text-bg-success' : 'text-bg-secondary'); });
    pusher.connection.bind('error', e => { conn.textContent = 'error: ' + (e.error && e.error.data && e.error.data.message || e.type || 'connection'); conn.className = 'badge text-bg-danger'; });
    document.getElementById('socket').textContent = await LivePusher.socketId();

    // 1. Chat
    LivePusher.on('examples', 'chat', l => $log('chat-log', `<div><small class="text-secondary">${l.at}</small> <b>${esc(l.who)}</b>: ${esc(l.text)}</div>`));
    document.getElementById('chat-form').addEventListener('submit', async e => {
        e.preventDefault();
        const input = e.target.text;
        if (!input.value.trim()) return;
        $log('chat-log', `<div><small class="text-secondary">${new Date().toTimeString().slice(0, 8)}</small> <b>you</b>: ${esc(input.value)}</div>`);
        const text = input.value; input.value = '';
        await send({ kind: 'chat', text, socket_id: await LivePusher.socketId() });
    });

    // 2. Ping
    let pings = 0;
    LivePusher.on('examples', 'ping', p => { document.getElementById('ping-count').textContent = ++pings; $log('ping-log', `<div><small class="text-secondary">${p.at}</small> ${esc(p.who)} pinged</div>`); });
    document.getElementById('ping-btn').onclick = () => send({ kind: 'ping' });

    // 3. Progress
    const bar = document.getElementById('progress-bar');
    LivePusher.on('examples', 'progress', p => {
        bar.style.width = p.percent + '%'; bar.textContent = p.percent + '%';
        bar.classList.toggle('progress-bar-animated', p.percent < 100);
        document.getElementById('progress-who').textContent = p.percent < 100 ? `${p.who} is running a job…` : `${p.who}'s job finished`;
    });
    document.getElementById('progress-btn').onclick = e => { e.target.disabled = true; send({ kind: 'progress' }).finally(() => e.target.disabled = false); };

    <?php if ($signedIn) : ?>
    // 4. Private
    LivePusher.on('private-examples-user-<?= $userId ?>', 'note', n => $log('private-log', `<div><i class="fad fa-lock me-1"></i>${esc(n.text)}</div>`));
    document.getElementById('private-btn').onclick = () => send({ kind: 'private' });

    // 5. Presence
    const members = document.getElementById('members');
    const chip = m => `<span class="badge rounded-pill text-bg-light border py-2 px-3" data-id="${esc(m.id)}"><i class="fad fa-user-circle me-1"></i>${esc(m.info && m.info.name || m.id)}</span>`;
    LivePusher.on('presence-examples-room', {
        'pusher:subscription_succeeded': ms => { members.innerHTML = ''; ms.each(m => members.insertAdjacentHTML('beforeend', chip(m))); },
        'pusher:member_added':           m  => members.insertAdjacentHTML('beforeend', chip(m)),
        'pusher:member_removed':         m  => { const el = members.querySelector(`[data-id="${CSS.escape(String(m.id))}"]`); if (el) el.remove(); },
        'pusher:subscription_error':     e  => members.innerHTML = `<span class="text-danger small">refused: ${esc(e.error || e.status || 'auth')}</span>`
    });
    <?php endif ?>
})();
</script>
<?php endif ?>
@endsection
