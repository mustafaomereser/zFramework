// zFramework error page. Inlined into the report by render/html.php.
// Highlighting is done server-side; this only wires navigation, tabs, the
// theme, the IDE link and the keyboard.
(function () {
    'use strict';

    var body = document.body;

    // ── theme ──────────────────────────────────────────────
    function setTheme(t) {
        body.setAttribute('data-theme', t);
        document.getElementById('ico-dark').hidden = t !== 'dark';
        document.getElementById('ico-light').hidden = t !== 'light';
        try { localStorage.setItem('zf-error-theme', t); } catch (e) {}
    }
    function toggleTheme() { setTheme(body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); }
    try { var saved = localStorage.getItem('zf-error-theme'); if (saved) setTheme(saved); } catch (e) {}
    document.getElementById('theme').addEventListener('click', toggleTheme);

    // ── exception chain ───────────────────────────────────
    var chains = document.querySelectorAll('[data-chain]');
    function showChain(i) {
        document.querySelectorAll('[data-pick]').forEach(function (b) { b.classList.toggle('active', b.dataset.pick == i); });
        chains.forEach(function (c) { c.hidden = c.dataset.chain != i; });
        var first = document.querySelector('[data-chain="' + i + '"]:not([hidden]) .frame.default') || document.querySelector('[data-chain="' + i + '"] .frame');
        if (first) pick(first);
    }
    document.querySelectorAll('[data-pick]').forEach(function (b) { b.addEventListener('click', function () { showChain(b.dataset.pick); }); });

    // ── frames ────────────────────────────────────────────
    function pick(frame) {
        var list = frame.closest('.frames');
        list.querySelectorAll('.frame').forEach(function (f) { f.classList.remove('active'); });
        frame.classList.add('active');
        var chain = frame.closest('[data-chain]');
        chain.querySelectorAll('.snippet').forEach(function (s) { s.classList.toggle('active', s.dataset.index === frame.dataset.index); });
        var err = chain.querySelector('.snippet.active .line.err');
        if (err) err.scrollIntoView({ block: 'center' });
        frame.scrollIntoView({ block: 'nearest' });
    }
    document.querySelectorAll('.frame').forEach(function (f) { f.addEventListener('click', function () { pick(f); }); });

    var hide = document.getElementById('hide-framework');
    function applyHide() {
        document.querySelectorAll('.frames').forEach(function (l) { l.classList.toggle('hide-framework', hide.checked); });
    }
    // Checked unless it was unchecked before - the framework's own frames are the
    // ones that are hardly ever the answer. Only a change is remembered, never the default.
    try { var h = localStorage.getItem('zf-error-frames'); if (h !== null) hide.checked = h === 'app'; } catch (e) {}
    hide.addEventListener('change', function () { applyHide(); try { localStorage.setItem('zf-error-frames', hide.checked ? 'app' : 'all'); } catch (e) {} });
    applyHide();

    showChain(0);

    // ── tabs ──────────────────────────────────────────────
    document.querySelectorAll('.tabs button').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.tabs button').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab').forEach(function (s) { s.classList.remove('active'); });
            t.classList.add('active');
            document.getElementById(t.dataset.tab).classList.add('active');
        });
    });

    // ── IDE ───────────────────────────────────────────────
    var ide = document.getElementById('ide');
    try { var savedIde = localStorage.getItem('zf-error-ide'); if (savedIde) ide.value = savedIde; } catch (e) {}
    ide.addEventListener('change', function () { try { localStorage.setItem('zf-error-ide', ide.value); } catch (e) {} });

    window.goIDE = function (file, line) {
        var links = {
            vscode:   'vscode://file/' + file + ':' + line,
            cursor:   'cursor://file/' + file + ':' + line,
            phpstorm: 'phpstorm://open?file=' + encodeURIComponent(file) + '&line=' + line,
            sublime:  'subl://open?url=file://' + file + '&line=' + line,
            idea:     'idea://open?file=' + encodeURIComponent(file) + '&line=' + line
        };
        window.location.href = links[ide.value] || links.vscode;
    };

    function openActive() {
        var s = document.querySelector('[data-chain]:not([hidden]) .snippet.active .btn.ide');
        if (s) s.click();
    }

    // ── copy ──────────────────────────────────────────────
    var toast = document.getElementById('toast');
    function say(msg) { toast.textContent = msg; toast.classList.add('show'); setTimeout(function () { toast.classList.remove('show'); }, 1400); }
    document.getElementById('copy').addEventListener('click', function () {
        var text = document.getElementById('as-text').textContent;
        if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { say('Copied as text'); });
        else say('Clipboard unavailable');
    });

    // ── keyboard ──────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        var tag = document.activeElement && document.activeElement.tagName;
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || e.ctrlKey || e.metaKey || e.altKey) return;

        var frames = Array.prototype.slice.call(document.querySelectorAll('[data-chain]:not([hidden]) .frame')).filter(function (f) { return f.offsetParent !== null; });
        var idx = frames.findIndex(function (f) { return f.classList.contains('active'); });

        switch (e.key) {
            case 'ArrowDown': case 'j': if (idx < frames.length - 1) pick(frames[idx + 1]); e.preventDefault(); break;
            case 'ArrowUp':   case 'k': if (idx > 0) pick(frames[idx - 1]); e.preventDefault(); break;
            case 't': toggleTheme(); break;
            case 'o': openActive(); break;
            case 'f': hide.checked = !hide.checked; hide.dispatchEvent(new Event('change')); break;
        }
    });
})();
