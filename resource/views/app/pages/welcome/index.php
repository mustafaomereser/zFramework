@extends('app.main')
@section('body')
<div class="my-4">

    <div class="zf-hero text-center mb-4">
        <h1 class="display-6 fw-semibold mb-2"><?= _l('lang.welcome') ?></h1>
        <p class="lead text-secondary mb-3">A PHP framework that reads like Laravel and boots in a millisecond. Rows are arrays, the terminal is yours, nothing loads until it is used.</p>
        <div class="d-flex justify-content-center flex-wrap gap-2">
            <a href="{{ route('examples') }}" class="btn btn-dark"><i class="fad fa-flask me-1"></i> <?= _l('lang.examples') ?></a>
            <a href="https://docs.zframework.dev" target="_blank" class="btn border"><i class="fad fa-book me-1"></i> Docs</a>
            <a href="https://github.com/mustafaomereser/zFramework" target="_blank" class="btn border"><i class="fab fa-github me-1"></i> GitHub</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['fad fa-route',            'Route',        'Groups, prefixes, throttling, resource controllers, dynamic route files, a route cache.'],
            ['fad fa-database',         'Model & DB',   'One query builder over MySQL and PostgreSQL, relations, pivots, observers, migrations in both dialects.'],
            ['fad fa-leaf',             'MongoDB',      'MongoModel beside the SQL models - relations that cross the store, atomic verbs, indexes.'],
            ['fad fa-broadcast-tower',  'Live & push',  'Pusher Channels for the open page, web push for the closed one. No SDK on either.'],
            ['fad fa-shield-check',     'Auth & Csrf',  'Cookie or Redis sessions, remember-me, api tokens, bcrypt - and a password change ends every session.'],
            ['fad fa-layer-group',      'Cache & Queue', 'APCu + Redis, page cache with tags, a Redis job queue, work deferred past the response.'],
            ['fad fa-terminal',         'Terminal',     'make, migrate, seed, tests, bench, state check, backups, AutoSSL, cPanel - one tool.'],
            ['fad fa-vial',             'Tests',        'Its own runner: plain PHP files in tests/, one process each, a real server for the http ones.'],
        ] as [$icon, $title, $text]) : ?>
        <div class="col-md-6 col-lg-3">
            <div class="card zf-card h-100">
                <div class="card-body">
                    <div class="zf-icon"><i class="<?= $icon ?>"></i></div>
                    <h6 class="card-title mb-1"><?= $title ?></h6>
                    <p class="card-text small text-secondary mb-0"><?= $text ?></p>
                </div>
            </div>
        </div>
        <?php endforeach ?>
    </div>

    <div class="card zf-card zf-terminal">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="fad fa-terminal me-2"></i>Terminal</span>
            <small class="text-secondary">the same <code>php terminal</code>, from the page - try <code>help</code></small>
        </div>
        <pre class="card-body mb-0" id="terminal-body">you can read for more information documention.</pre>
        <div class="card-footer p-0">
            <form id="terminal-form">
                <?= csrf() ?>
                <input type="text" name="command" class="form-control border-0 rounded-0 rounded-bottom" placeholder="Command to Helper Terminal." autocomplete="off">
            </form>
        </div>
    </div>
</div>
@endsection

@section('footer')
<script>
    // Command history like a shell: up/down walk it, a half-typed line is kept
    // while browsing, and it survives a reload (localStorage, last 50).
    (() => {
        const input = document.querySelector('[name="command"]');
        const key   = 'zf-terminal-history';
        let history = [], cursor = 0, draft = '';
        try { history = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) {}
        cursor = history.length;

        window.terminalHistory = command => {
            command = command.trim();
            if (!command) return;
            if (history[history.length - 1] !== command) history.push(command);
            history = history.slice(-50);
            cursor  = history.length;
            draft   = '';
            try { localStorage.setItem(key, JSON.stringify(history)); } catch (e) {}
        };

        input.addEventListener('keydown', e => {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
            e.preventDefault();
            if (cursor === history.length) draft = input.value;
            cursor = e.key === 'ArrowUp' ? Math.max(0, cursor - 1) : Math.min(history.length, cursor + 1);
            input.value = cursor === history.length ? draft : history[cursor];
            input.setSelectionRange(input.value.length, input.value.length);
        });
    })();

    $('#terminal-form').sbmt((form, btn) => {
        terminalHistory($('[name="command"]').val());
        $('[name="command"]').attr('disabled', 'true').addClass('disabled');
        $('#terminal-body').html(`<div class="d-flex align-items-center justify-content-center h-100 w-100"><div><i class="fa fa-spin fa-spinner me-2"></i> <?= _l('lang.loading') ?></div></div>`);
        $.post('<?= route("store") ?>', $.core.SToA(form), e => ($('[name="command"]').removeAttr('disabled').removeClass('disabled').val(null).focus(), $('#terminal-body').html(String(e).replace(/^(\s|<br\s*\/?>)+/i, '')).scrollTop(99999999999))).error_callback = e => $('#terminal-body').html(JSON.parse(e.responseText).message);
    }).trigger('submit');
</script>
@endsection
