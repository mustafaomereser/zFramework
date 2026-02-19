/* ── route tree list ── */
$(function () {
    const $items = $('.ra-item');
    const $folders = $('.ra-folder');
    const total = $items.length;
    let activeMethod = 'ALL';

    $('#raTotalCount').text(total);
    $('#raVisibleCount').text(total);

    // Folder toggle — use direct child selectors to avoid cascading into nested folders
    $(document).on('click', '.ra-folder-header', function (e) {
        e.stopPropagation();
        const $folder = $(this).closest('.ra-folder');
        const isOpen = $folder.hasClass('ra-folder-open');
        if (isOpen) {
            $folder.removeClass('ra-folder-open');
            // Only touch THIS header's direct icons
            $(this).children('.ra-folder-chevron').css('transform', '');
            $(this).children('.ra-folder-icon-closed').show();
            $(this).children('.ra-folder-icon-open').hide();
        } else {
            $folder.addClass('ra-folder-open');
            $(this).children('.ra-folder-chevron').css('transform', 'rotate(90deg)');
            $(this).children('.ra-folder-icon-closed').hide();
            $(this).children('.ra-folder-icon-open').show();
        }
    });

    // Route item toggle
    $(document).on('click', '.ra-toggle', function () {
        $(this).closest('.ra-item').toggleClass('ra-open');
    });

    // Copy URL
    $(document).on('click', '.ra-copy-btn', function (e) {
        e.stopPropagation();
        const url = $(this).attr('data-url-copy');
        const $btn = $(this);
        const doMark = () => {
            $btn.addClass('copied').html('<i class="fas fa-check"></i> Copied');
            setTimeout(() => $btn.removeClass('copied').html('<i class="fas fa-copy"></i> Copy'), 1800);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(doMark);
        } else {
            const $ta = $('<textarea>').css({
                position: 'fixed',
                top: 0,
                left: 0,
                opacity: 0
            }).val(url).appendTo('body');
            $ta[0].select();
            try {
                document.execCommand('copy');
                doMark();
            } catch (e) { }
            $ta.remove();
        }
    });

    // Filter buttons
    $(document).on('click', '.ra-filter-btn', function () {
        activeMethod = $(this).data('method');
        $('.ra-filter-btn').removeClass('ra-active');
        $(this).addClass('ra-active');
        applyFilter();
    });

    $('#raSearch').on('input', applyFilter);

    function applyFilter() {
        const q = $('#raSearch').val().toLowerCase().trim();
        let visible = 0;

        $items.each(function () {
            const methodOk = activeMethod === 'ALL' || $(this).attr('data-method') === activeMethod;
            const searchOk = !q || $(this).attr('data-search').indexOf(q) !== -1;
            if (methodOk && searchOk) {
                $(this).show();
                visible++;
            } else {
                $(this).hide().removeClass('ra-open');
            }
        });

        // Hide/show folders based on visible children
        // Process deepest first
        $('.ra-folder').get().reverse().forEach(function (el) {
            const hasVisible = $(el).find('.ra-item').filter((__, item) => $(item).css('display') != 'none').length > 0;
            if (hasVisible) $(el).show();
            else $(el).hide();
        });

        $('#raVisibleCount').text(visible);
        $('#raEmpty').toggle(visible === 0);
    }

    $('#raExpandAll').on('click', () => {
        // $items.filter(':visible').addClass('ra-open');
        $folders.addClass('ra-folder-open');
    });
    $('#raCollapseAll').on('click', () => {
        $folders.removeClass('ra-folder-open');
        $items.removeClass('ra-open');
    });

    $folders.each((_, folder) => {
        folder = $(folder);
        let items = folder.find('.ra-item');
        folder.find('[expand-all-items]').on('click', () => items.filter(':visible').addClass('ra-open'));
        folder.find('[ra-collapse-all-items]').on('click', () => items.removeClass('ra-open'));
    });
});

function createEndPointTester(parent) {
    $(function () {
        const _PARENT = $(parent);

        /* ── HOOKSHOT ── */
        let rawUrlTemplate = '';
        let paramValues = {};
        let needsMethodField = false;
        let realMethod = '';
        let mustMethod = '';
        let lastRawResponse = '';
        let lastParsedJSON = null;
        let isHtmlResponse = false;
        let currentView = 'pretty';
        const HISTORY_KEY = 'hookshotHistory';
        const ENV_KEY = 'hookshotEnvironments';
        const ENV_ACTIVE = 'hookshotActiveEnv';

        let environments = JSON.parse(localStorage.getItem(ENV_KEY) || 'null') || {
            'Default': {}
        };
        let activeEnv = localStorage.getItem(ENV_ACTIVE) || 'Default';
        if (!environments[activeEnv]) activeEnv = Object.keys(environments)[0];

        function getActiveVars() {
            const env = environments[activeEnv] || {},
                flat = {};
            $.each(env, function (k, v) {
                flat[k] = v.value || '';
            });
            $.each(window._hsConstants || {}, function (k, v) {
                if (flat[k] === undefined) flat[k] = v;
            });
            return flat;
        }

        function resetAll() {
            _PARENT.find('#method').val('ANY');
            setMethodColor('ANY');
            _PARENT.find('#urlWrapper').removeClass('border-danger text-danger').empty();
            _PARENT.find('#queryContainer').empty();
            _PARENT.find('#bodyFormDataContainer').empty();
            _PARENT.find('#bodyUrlencodedContainer').empty();
            _PARENT.find('#headersContainer').empty();
        }

        function setMethodColor(method) {
            _PARENT.find('#method').removeClass('method-get method-post method-put method-patch method-delete method-any').addClass('method-' + (method || 'any').toLowerCase());
        }

        _PARENT.find('#method').on('input', function () {
            _PARENT.find('[data-bs-target="#bodyTab"], #bodyTab').css('opacity', 1).removeAttr('disabled').tooltip("dispose");
            if (['GET', 'HEAD', 'ANY'].includes(this.value.toUpperCase())) _PARENT.find('[data-bs-target="#bodyTab"], #bodyTab').attr('disabled', true).css('opacity', .3).tooltip('dispose').tooltip({
                title: '<small>Body can not use with GET, HEAD</small>',
                html: true
            });
        });

        $(document).on('click', '.testRouteBtn', function () {
            resetAll();

            const httpMethod = $(this).data('method'); // POST or GET (actual HTTP)
            realMethod = $(this).data('real-method'); // PUT, DELETE, PATCH etc.
            mustMethod = realMethod !== 'ANY' ? realMethod : 'GET';
            needsMethodField = $(this).data('needs-method-field') === 1 || $(this).data('needs-method-field') === '1';
            const url = $(this).data('url');

            _PARENT.find('#method').val(realMethod).trigger('input');
            setMethodColor(realMethod !== 'ANY' ? realMethod : 'any');

            rawUrlTemplate = url;
            paramValues = {};
            renderUrl();

            if (mustMethod != 'GET') {
                const $row = addKeyValue('queryContainer', '');
                $row.find('.kv-key').val('_token');
                $row.find('.kv-val').val('{csrf}').trigger('input');
            }
            // If this method needs spoofing, switch body to form-data and pre-fill _method
            if (needsMethodField) {
                _PARENT.find('#bodyType').val('form-data').trigger('change');
                const $row = addKeyValue('bodyFormDataContainer', '');
                $row.find('.kv-key').val('_method');
                $row.find('.kv-val').val(realMethod).trigger('input');
            }

            if (_PARENT.find('[response-content]').attr('is-fullscreen')) _PARENT.find('#resultFullscreenBtn').click();

            // bootstrap.Offcanvas.getOrCreateInstance('#hookshot').show();
        });

        function renderUrl() {
            const $w = _PARENT.find('#urlWrapper').removeClass('border-danger text-danger').empty();
            const re = /\{(\??)([^}]+)\}/g;
            let li = 0,
                m;
            while ((m = re.exec(rawUrlTemplate)) !== null) {
                $w.append(document.createTextNode(rawUrlTemplate.substring(li, m.index)));
                const name = m[2];
                $('<button>').attr('type', 'button').addClass('btn btn-sm btn-light rounded')
                    .text(paramValues[name] ?? name).data('param', name)
                    .on('click', function () {
                        makeEditable($(this), name);
                    }).appendTo($w);
                li = re.lastIndex;
            }
            $w.append(document.createTextNode(rawUrlTemplate.substring(li)));
            updateHiddenUrl();
        }

        function makeEditable($btn, name) {
            const $i = $('<input type="text">').addClass('form-control form-control-sm').css('width', '100px').val(paramValues[name] ?? '');

            function save() {
                paramValues[name] = $i.val() || name;
                renderUrl();
            }
            $i.on('blur', save).on('keydown', function (e) {
                if (e.key === 'Enter') save();
            });
            $btn.replaceWith($i);
            $i.trigger('focus');
        }

        function resolveValue(val) {
            if (!val) return val;
            const vars = getActiveVars();
            return String(val).replace(/\{([^}]+)\}/g, function (_, n) {
                return vars[n] !== undefined ? vars[n] : '{' + n + '}';
            });
        }

        function updateHiddenUrl() {
            let u = rawUrlTemplate;
            $.each(paramValues, function (k, v) {
                u = u.replace(new RegExp('\\{\\??' + k + '\\}'), resolveValue(v));
            });
            _PARENT.find('#url').val(u);
        }

        function buildUrl(template, params) {
            let u = template || '';
            $.each(params || {}, function (k, v) {
                u = u.replace(new RegExp('\\{\\??' + k + '\\}'), v);
            });
            return u;
        }

        function wrapWithHighlight($input) {
            const $wrap = $('<div>').addClass('hs-hl-wrap');
            const $ol = $('<div>').addClass('hs-hl-overlay');
            $input.before($wrap);
            $wrap.append($input).append($ol);

            function update() {
                const vars = getActiveVars(),
                    raw = $input.val();
                if (!raw) {
                    $ol.html('');
                    return;
                }
                let html = '';
                raw.split(/(\{[^}]+\})/g).forEach(function (part) {
                    if (/^\{[^}]+\}$/.test(part)) {
                        const n = part.slice(1, -1),
                            known = vars[n] !== undefined;
                        html += '<span class="hs-hl-chip ' + (known ? 'known' : 'unknown') + '" title="' + (known ? n + ' = ' + vars[n] : n + ' — undefined') + '">' + escHtml(known ? vars[n] : n) + '</span>';
                    } else if (part) {
                        html += '<span class="hs-hl-plain">' + escHtml(part) + '</span>';
                    }
                });
                $ol.html(html);
            }
            $input.on('input keyup change', update);
            update();
            $input.data('hl-update', update);
        }

        function escHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function refreshHighlights() {
            _PARENT.find('[data-hl-update]').each(function () {
                const f = $(this).data('hl-update');
                if (f) f();
            });
        }

        const BODY_LABELS = {
            json: 'JSON',
            'form-urlencoded': 'URL Enc',
            'form-data': 'Form',
            raw: 'Raw',
            xml: 'XML'
        };
        _PARENT.find('#bodyType').on('change', function () {
            const v = $(this).val();
            _PARENT.find('.body-section').hide();
            if (v === 'json') _PARENT.find('#bodyJson').show();
            else if (v === 'form-urlencoded') _PARENT.find('#bodyFormUrlencoded').show();
            else if (v === 'form-data') _PARENT.find('#bodyFormData').show();
            else if (v === 'raw') {
                _PARENT.find('#bodyRawLabel').text('Raw Text');
                _PARENT.find('#bodyRawInput').attr('placeholder', 'Plain text...');
                _PARENT.find('#bodyRaw').show();
            } else if (v === 'xml') {
                _PARENT.find('#bodyRawLabel').text('XML Body');
                _PARENT.find('#bodyRawInput').attr('placeholder', _PARENT.find('#bodyRawInput').data('xml-placeholder'));
                _PARENT.find('#bodyRaw').show();
            }
            const $b = _PARENT.find('#bodyBadge');
            if (v && BODY_LABELS[v]) $b.text(BODY_LABELS[v]).show();
            else $b.hide();
        });

        const AUTH_LABELS = {
            basic: 'Basic',
            bearer: 'Bearer'
        };
        _PARENT.find('#authType').on('change', function () {
            const v = $(this).val();
            _PARENT.find('#basicAuthFields,#bearerAuthField').hide();
            if (v === 'basic') _PARENT.find('#basicAuthFields').show();
            if (v === 'bearer') _PARENT.find('#bearerAuthField').show();
            const $b = _PARENT.find('#authBadge');
            if (v && AUTH_LABELS[v]) $b.text(AUTH_LABELS[v]).show();
            else $b.hide();
        });

        function collectHeaders() {
            const h = collectCustomHeaders(),
                at = _PARENT.find('#authType').val();
            if (at === 'bearer') {
                const t = resolveValue(_PARENT.find('#authToken').val().trim());
                if (t) h['Authorization'] = 'Bearer ' + t;
            } else if (at === 'basic') {
                const u = resolveValue(_PARENT.find('#authUser').val().trim()),
                    p = resolveValue(_PARENT.find('#authPass').val().trim());
                if (u) h['Authorization'] = 'Basic ' + btoa(u + ':' + p);
            }
            return h;
        }

        function collectQueryParams() {
            const p = {};
            _PARENT.find('#queryContainer .kv-row').each(function () {
                if ($(this).find('.kv-active').length && !$(this).find('.kv-active').is(':checked')) return;

                const k = $(this).find('.kv-key').val().trim(),
                    v = resolveValue($(this).find('.kv-val').val().trim());
                if (k) p[k] = v;
            });
            return p;
        }

        function collectBody() {
            const bt = _PARENT.find('#bodyType').val();
            if (!bt) return {
                body: null,
                contentType: null
            };
            if (bt === 'json') {
                const r = resolveValue(_PARENT.find('#bodyJsonInput').val().trim());
                return r ? {
                    body: r,
                    contentType: 'application/json'
                } : {
                    body: null,
                    contentType: null
                };
            }
            if (bt === 'raw') {
                const r = resolveValue(_PARENT.find('#bodyRawInput').val().trim());
                return r ? {
                    body: r,
                    contentType: 'text/plain'
                } : {
                    body: null,
                    contentType: null
                };
            }
            if (bt === 'xml') {
                const r = resolveValue(_PARENT.find('#bodyRawInput').val().trim());
                return r ? {
                    body: r,
                    contentType: 'application/xml'
                } : {
                    body: null,
                    contentType: null
                };
            }
            if (bt === 'form-urlencoded') {
                const parts = [];
                _PARENT.find('#bodyUrlencodedContainer .kv-row').each(function () {
                    if ($(this).find('.kv-active').length && !$(this).find('.kv-active').is(':checked')) return;

                    const k = $(this).find('.kv-key').val().trim(),
                        v = resolveValue($(this).find('.kv-val').val().trim());
                    if (k) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
                });
                return parts.length ? {
                    body: parts.join('&'),
                    contentType: 'application/x-www-form-urlencoded'
                } : {
                    body: null,
                    contentType: null
                };
            }
            if (bt === 'form-data') {
                const fd = new FormData();
                let has = false;
                _PARENT.find('#bodyFormDataContainer .kv-row').each(function () {
                    if ($(this).find('.kv-active').length && !$(this).find('.kv-active').is(':checked')) return;

                    const k = $(this).find('.kv-key').val().trim(),
                        v = resolveValue($(this).find('.kv-val').val().trim());
                    if (k) {
                        fd.append(k, v);
                        has = true;
                    }
                });
                _PARENT.find('#bodyFormDataContainer .file-row').each(function () {
                    if ($(this).find('.kv-active').length && !$(this).find('.kv-active').is(':checked')) return;

                    const k = $(this).find('.file-key').val().trim(),
                        fe = $(this).find('.file-input')[0],
                        files = fe ? fe.files : [];
                    if (k && files.length) {
                        for (let i = 0; i < files.length; i++) fd.append(k, files[i], files[i].name);
                        has = true;
                    }
                });
                return has ? {
                    body: fd,
                    contentType: null
                } : {
                    body: null,
                    contentType: null
                };
            }
            return {
                body: null,
                contentType: null
            };
        }

        _PARENT.find('#sendBtn').on('click', async function () {
            let url = _PARENT.find('#url').val().trim();
            if (!url) {
                _PARENT.find('#urlWrapper').addClass('border-danger text-danger').html('URL is empty');
                return;
            }
            const qp = collectQueryParams(),
                qs = $.param(qp);
            if (qs) url += (url.includes('?') ? '&' : '?') + qs;
            const method = mustMethod,
                headers = collectHeaders(),
                {
                    body,
                    contentType
                } = collectBody();
            if (contentType) headers['Content-Type'] = contentType;
            const $btn = $(this).prop('disabled', true).addClass('loading');
            _PARENT.find('#copyBtn').hide();
            _PARENT.find('#resultFullscreenBtn').hide();
            lastRawResponse = '';
            lastParsedJSON = null;
            isHtmlResponse = false;
            _PARENT.find('#jsonOutput').hide().text('');
            _PARENT.find('#htmlFrame').hide().prop('srcdoc', '');
            const start = performance.now();
            try {
                const opts = {
                    method,
                    headers
                };
                if (body && !['GET', 'HEAD', 'ANY'].includes(method.toUpperCase())) opts.body = body;
                const response = await fetch(url, opts);
                const elapsed = Math.round(performance.now() - start),
                    ct = response.headers.get('content-type') || '';
                _PARENT.find('#responseTime').text(elapsed + ' ms');
                setStatus(response.status);
                const snapshot = {
                    queryParams: collectRawQueryParams(),
                    headers: collectRawCustomHeaders(),
                    bodyUrlenc: collectRawKvRows('bodyUrlencodedContainer'),
                    bodyForm: collectRawKvRows('bodyFormDataContainer'),
                    authType: _PARENT.find('#authType').val(),
                    authUser: _PARENT.find('#authUser').val(),
                    authPass: _PARENT.find('#authPass').val(),
                    authToken: _PARENT.find('#authToken').val(),
                    bodyType: _PARENT.find('#bodyType').val(),
                    bodyJson: _PARENT.find('#bodyJsonInput').val(),
                    bodyRaw: _PARENT.find('#bodyRawInput').val()
                };
                saveHistory(method, rawUrlTemplate, paramValues, response.status, elapsed, snapshot);
                if (ct.includes('application/json')) {
                    const data = await response.json();
                    lastParsedJSON = data;
                    lastRawResponse = JSON.stringify(data, null, 2);
                    isHtmlResponse = false;
                    updateResponseSize(lastRawResponse);
                    renderResponse();
                } else {
                    const text = await response.text();
                    lastRawResponse = text;
                    isHtmlResponse = ct.includes('text/html');
                    updateResponseSize(text);
                    if (isHtmlResponse) showHTML(text);
                    else {
                        lastParsedJSON = null;
                        renderResponse();
                    }
                }
                _PARENT.find('#copyBtn').show();
                _PARENT.find('#resultFullscreenBtn').show();
            } catch (err) {
                alert('Request failed: ' + err.message);
            } finally {
                $btn.prop('disabled', false).removeClass('loading');
            }
        });

        $(document).on('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const $b = _PARENT.find('#sendBtn');
                if (!$b.prop('disabled')) $b.trigger('click');
            }
        });

        function setStatus(code) {
            const c = code >= 200 && code < 300 ? 'bg-success' : code >= 400 ? 'bg-danger' : 'bg-warning';
            _PARENT.find('#statusBadge').text(code).attr('class', 'badge ' + c);
        }

        function updateResponseSize(t) {
            const b = new Blob([t]).size;
            _PARENT.find('#responseSize').text(b < 1024 ? b + ' B' : (b / 1024).toFixed(1) + ' KB');
        }

        function renderResponse() {
            if (!lastRawResponse) return;
            if (isHtmlResponse && currentView === 'pretty') {
                _PARENT.find('#jsonOutput').hide();
                _PARENT.find('#htmlFrame').show().prop('srcdoc', lastRawResponse);
            } else {
                _PARENT.find('#htmlFrame').hide();
                const $p = _PARENT.find('#jsonOutput').show();
                if (currentView === 'pretty' && lastParsedJSON !== null) $p.html(syntaxHighlight(JSON.stringify(lastParsedJSON, null, 2)));
                else $p.text(lastRawResponse);
            }
        }

        function showHTML(html) {
            lastRawResponse = html;
            isHtmlResponse = true;
            renderResponse();
        }

        $(document).on('click', '#viewPretty', function () {
            if (currentView === 'pretty') return;
            currentView = 'pretty';
            $(this).addClass('active');
            _PARENT.find('#viewRaw').removeClass('active');
            renderResponse();
        });
        $(document).on('click', '#viewRaw', function () {
            if (currentView === 'raw') return;
            currentView = 'raw';
            $(this).addClass('active');
            _PARENT.find('#viewPretty').removeClass('active');
            renderResponse();
        });

        _PARENT.find('#resultFullscreenBtn').on('click', function () {
            let content = _PARENT.find('[response-content]');

            if (content.attr('is-fullscreen') == 1) {
                content.removeAttr('is-fullscreen').removeAttr('style');
                _PARENT.find('.hs-response-wrap').removeAttr('style');
            } else {
                _PARENT.find('.hs-response-wrap').css('height', '100%');
                content.attr('is-fullscreen', 1).attr('style', `position: absolute; top: 0; width: 100%; height: ${_PARENT.innerHeight()}px; left: 0; z-index: 1000;`);
            }
        });

        _PARENT.find('#copyBtn').on('click', function () {
            if (!lastRawResponse) return;
            const $btn = $(this);

            function markCopied() {
                $btn.text('✓ Copied').addClass('copied');
                setTimeout(function () {
                    $btn.text('⎘ Copy').removeClass('copied');
                }, 1800);
            }
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(lastRawResponse).then(markCopied);
            else {
                const $ta = $('<textarea>').css({
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    opacity: 0
                }).val(lastRawResponse).appendTo('body');
                $ta[0].focus();
                $ta[0].select();
                try {
                    document.execCommand('copy');
                    markCopied();
                } catch (e) {
                    alert('Copy failed.');
                }
                $ta.remove();
            }
        });

        function syntaxHighlight(json) {
            json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (m) {
                let c = 'json-number';
                if (/^"/.test(m) && /:$/.test(m)) c = 'json-key';
                else if (/^"/.test(m)) c = 'json-string';
                else if (/true|false/.test(m)) c = 'json-bool';
                else if (/null/.test(m)) c = 'json-null';
                return '<span class="' + c + '">' + m + '</span>';
            });
        }

        $(document).on('click', '.js-add-kv', function () {
            addKeyValue($(this).data('target'), $(this).data('count'));
        });
        $(document).on('click', '.js-add-file', function () {
            addFileRow($(this).data('target'));
        });

        function addFileRow(cid) {
            const $row = $(`
                    <div class="file-row mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input kv-active" style="background-color: var(--bg-input)" checked>
                            <div class="row align-items-center flex-fill g-2">
                                <div class="col-6"><input type="text" class="form-control form-control-sm file-key" placeholder="Field name"></div>
                                <div class="col-6"><label class="hs-file-label"><span class="hs-file-label-text">Choose file(s)...</span><input type="file" class="file-input" multiple style="display:none"></label></div>
                            </div>
                            <button type="button" class="btn btn-sm js-remove-kv">✕</button>
                        </div>
                        <div class="hs-file-list mt-1"></div>
                    </div>
                `);

            $row.find('.kv-active').on('change', function () {
                if ($(this).is(':checked')) $row.css('opacity', 1);
                else $row.css('opacity', .3);
            });

            $row.find('.file-input').on('change', function () {
                const n = Array.from(this.files).map(f => f.name);
                const $l = $row.find('.hs-file-list').empty();
                n.forEach(function (name) {
                    $l.append($('<span>').addClass('hs-file-chip').text(name));
                });
                $row.find('.hs-file-label-text').text(n.length === 1 ? n[0] : n.length + ' files selected');
            });
            $row.find('.hs-file-label').on('click', function () {
                $row.find('.file-input').trigger('click');
            });
            _PARENT.find('#' + cid).append($row);

            return $row;
        }

        function addKeyValue(cid, countId) {
            const $row = $(`
                    <div class="kv-row d-flex align-items-center gap-2 mb-3">
                        <input type="checkbox" class="form-check-input kv-active" style="background-color: var(--bg-input)" checked>
                        <div class="row align-items-center flex-fill g-2">
                            <div class="col-6"><input type="text" class="form-control form-control-sm kv-key" placeholder="Key"></div>
                            <div class="col-6"><input type="text" class="form-control form-control-sm kv-val" placeholder="Value"></div>
                        </div>
                        <button type="button" class="btn btn-sm js-remove-kv">✕</button>
                    </div>
                `);

            _PARENT.find('#' + cid).append($row);

            $row.find('.kv-active').on('change', function () {
                if ($(this).is(':checked')) $row.css('opacity', 1);
                else $row.css('opacity', .3);
            });

            const $vi = $row.find('.kv-val');
            $vi.on('dragover', function (e) {
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'copy';
                $(this).addClass('hs-drop-active');
            })
                .on('dragleave drop', function () {
                    $(this).removeClass('hs-drop-active');
                })
                .on('drop', function (e) {
                    e.preventDefault();
                    const t = e.originalEvent.dataTransfer.getData('text/plain');
                    if (!t) return;
                    const el = this,
                        s = el.selectionStart ?? el.value.length,
                        en = el.selectionEnd ?? el.value.length;
                    el.value = el.value.slice(0, s) + t + el.value.slice(en);
                    el.selectionStart = el.selectionEnd = s + t.length;
                    $(el).trigger('input');
                });
            wrapWithHighlight($vi);
            if (countId) updateTabCount(cid, countId);

            return $row;
        }

        _PARENT.on('click', '.js-remove-kv', function () {
            const $row = $(this).closest('.kv-row,.file-row'),
                container = $row.parent().closest('[id]').attr('id');
            const cm = {
                queryContainer: 'paramsCount',
                headersContainer: 'headersCount'
            };
            $row.remove();
            if (cm[container]) updateTabCount(container, cm[container]);
        });

        function updateTabCount(cid, countId) {
            const c = _PARENT.find('#' + cid + ' .kv-row').length;
            c > 0 ? _PARENT.find('#' + countId).show().text(c) : _PARENT.find('#' + countId).hide();
        }

        function collectCustomHeaders() {
            const h = {};
            _PARENT.find('#headersContainer .kv-row').each(function () {
                if ($(this).find('.kv-active').length && !$(this).find('.kv-active').is(':checked')) return;

                const k = $(this).find('.kv-key').val().trim(),
                    v = resolveValue($(this).find('.kv-val').val().trim());
                if (k) h[k] = v;
            });
            return h;
        }

        function collectRawCustomHeaders() {
            const h = {};
            _PARENT.find('#headersContainer .kv-row').each(function () {
                const k = $(this).find('.kv-key').val().trim(),
                    v = $(this).find('.kv-val').val().trim(),
                    a = $(this).find('.kv-active').is(':checked');
                if (k) h[k] = {
                    v: v,
                    a: a
                };
            });
            return h;
        }

        function collectRawQueryParams() {
            const p = {};
            _PARENT.find('#queryContainer .kv-row').each(function () {
                const k = $(this).find('.kv-key').val().trim(),
                    v = $(this).find('.kv-val').val().trim(),
                    a = $(this).find('.kv-active').is(':checked');
                if (k) p[k] = {
                    v: v,
                    a: a
                };
            });
            return p;
        }

        function collectRawKvRows(cid) {
            const r = [];
            _PARENT.find('#' + cid).find('.kv-row').each(function () {
                r.push({
                    key: $(this).find('.kv-key').val().trim(),
                    val: $(this).find('.kv-val').val().trim(),
                    active: $(this).find('.kv-active').is(':checked')
                });
            });
            return r;
        }

        function restoreSnapshot(item) {
            const s = item.snapshot || {};
            _PARENT.find('#method').val(item.method).trigger('input');
            setMethodColor(item.method);
            rawUrlTemplate = item.template;
            paramValues = item.params || {};
            renderUrl();
            _PARENT.find('#queryContainer').empty();
            _PARENT.find('#paramsCount').hide();
            if (s.queryParams) {
                $.each(s.queryParams, function (k, v) {
                    const $l = addKeyValue('queryContainer', 'paramsCount');
                    $l.find('.kv-key').val(k);
                    $l.find('.kv-val').val(v.v).trigger('input');
                    $l.find('.kv-active').prop('checked', v.a).trigger('change');
                });
                updateTabCount('queryContainer', 'paramsCount');
            }
            _PARENT.find('#headersContainer').empty();
            _PARENT.find('#headersCount').hide();
            if (s.headers) {
                $.each(s.headers, function (k, v) {
                    const $l = addKeyValue('headersContainer', 'headersCount');
                    $l.find('.kv-key').val(k);
                    $l.find('.kv-val').val(v.v).trigger('input');
                    $l.find('.kv-active').prop('checked', v.a).trigger('change');
                });
                updateTabCount('headersContainer', 'headersCount');
            }
            const at = s.authType || '';
            _PARENT.find('#authType').val(at).trigger('change');
            if (at === 'basic') {
                _PARENT.find('#authUser').val(s.authUser || '');
                _PARENT.find('#authPass').val(s.authPass || '');
            } else if (at === 'bearer') {
                _PARENT.find('#authToken').val(s.authToken || '');
            }
            const bt = s.bodyType || '';
            _PARENT.find('#bodyType').val(bt).trigger('change');
            if (bt === 'json') _PARENT.find('#bodyJsonInput').val(s.bodyJson || '');
            else if (bt === 'raw' || bt === 'xml') _PARENT.find('#bodyRawInput').val(s.bodyRaw || '');
            else if (bt === 'form-urlencoded') {
                _PARENT.find('#bodyUrlencodedContainer').empty();
                (s.bodyUrlenc || []).forEach(function (r) {
                    const $l = addKeyValue('bodyUrlencodedContainer', '');
                    $l.find('.kv-key').val(r.key);
                    $l.find('.kv-val').val(r.val).trigger('input');
                    $l.find('.kv-active').prop('checked', r.active).trigger('change');
                });
            } else if (bt === 'form-data') {
                _PARENT.find('#bodyFormDataContainer').empty();
                (s.bodyForm || []).forEach(function (r) {
                    const $l = addKeyValue('bodyFormDataContainer', '');
                    $l.find('.kv-key').val(r.key);
                    $l.find('.kv-val').val(r.val).trigger('input');
                    $l.find('.kv-active').prop('checked', r.active).trigger('change');
                });
            }
        }

        renderEnvTabs();
        renderEnvVars();

        function renderEnvStrip() {
            const env = environments[activeEnv] || {},
                c = Object.keys(env).length;
            _PARENT.find('#envActiveStrip').html('<span class="hs-env-active-dot"></span><span class="hs-env-active-name">' + escHtml(activeEnv) + '</span><span class="hs-env-active-count">' + c + ' var' + (c !== 1 ? 's' : '') + '</span>');
        }

        function renderEnvTabs() {
            renderEnvStrip();
            const $tabs = _PARENT.find('#envTabs').empty();
            _PARENT.find('#envConstants').empty();
            $.each(window._hsConstants || {}, function (k, v) {
                appendEnvVarRow(_PARENT.find('#envConstants'), k, v || '', false, true);
            });
            $.each(environments, function (name) {
                const isActive = name === activeEnv;
                const $tab = $('<div>').addClass('hs-env-tab' + (isActive ? ' active' : '')).text(name);
                $tab.on('click', function () {
                    if (activeEnv === name) return;
                    activeEnv = name;
                    localStorage.setItem(ENV_ACTIVE, activeEnv);
                    renderEnvTabs();
                    renderEnvVars();
                    renderUrl();
                    refreshHighlights();
                });
                $tab.on('dblclick', function (e) {
                    e.stopPropagation();
                    renameEnv(name, $tab);
                });
                $tab.on('contextmenu', function (e) {
                    e.preventDefault();
                    if (Object.keys(environments).length <= 1) return;
                    if (!confirm('Delete environment "' + name + '"?')) return;
                    delete environments[name];
                    if (activeEnv === name) activeEnv = Object.keys(environments)[0];
                    persistEnvs();
                    renderEnvTabs();
                    renderEnvVars();
                });
                $tabs.append($tab);
            });
        }

        function renameEnv(oldName, $tab) {
            const $i = $('<input type="text">').addClass('hs-env-rename-input').val(oldName);

            function commit() {
                const n = $i.val().trim();
                if (!n || n === oldName) {
                    renderEnvTabs();
                    return;
                }
                if (environments[n]) {
                    alert('Exists.');
                    renderEnvTabs();
                    return;
                }
                const v = environments[oldName];
                delete environments[oldName];
                environments[n] = v;
                if (activeEnv === oldName) activeEnv = n;
                persistEnvs();
                renderEnvTabs();
            }
            $i.on('blur', commit).on('keydown', function (e) {
                if (e.key === 'Enter') commit();
                if (e.key === 'Escape') renderEnvTabs();
            });
            $tab.replaceWith($i);
            $i.trigger('focus').trigger('select');
        }

        $(document).on('click', '.js-add-env', function () {
            const n = 'Env ' + (Object.keys(environments).length + 1);
            environments[n] = {};
            activeEnv = n;
            localStorage.setItem(ENV_ACTIVE, activeEnv);
            persistEnvs();
            renderEnvTabs();
            renderEnvVars();
        });

        function renderEnvVars() {
            const $c = _PARENT.find('#envVarsContainer').empty(),
                env = environments[activeEnv] || {};
            $.each(env, function (k, m) {
                appendEnvVarRow($c, k, m.value || '', m.sensitive || false);
            });
        }

        function appendEnvVarRow($container, key, value, sensitive, constant) {
            constant = constant || false;
            const $row = $('<div>').addClass('env-var-row mb-2');
            const $inner = $('<div>').addClass('d-flex align-items-center gap-1');
            const $brace = $('<span>').addClass('hs-const-brace').attr('draggable', 'true').attr('title', key ? 'Drag to insert {' + key + '}' : '{}').text('{}')
                .on('dragstart', function (e) {
                    const vn = $ki.val().trim();
                    if (!vn) {
                        e.preventDefault();
                        return;
                    }
                    e.originalEvent.dataTransfer.setData('text/plain', '{' + vn + '}');
                    e.originalEvent.dataTransfer.effectAllowed = 'copy';
                    $(this).addClass('hs-brace-dragging');
                })
                .on('dragend', function () {
                    $(this).removeClass('hs-brace-dragging');
                });
            const $ki = $('<input type="text">').addClass('form-control form-control-sm env-var-key').attr('placeholder', 'variable_name').css({
                flex: '1 1 80px'
            }).val(key);
            if (constant) $ki.prop('readonly', true);
            const $arrow = $('<span>').css({
                color: 'var(--text-3)',
                fontSize: '11px',
                flexShrink: 0
            }).text('→');
            const $vw = $('<div>').css({
                flex: '2 1 120px',
                position: 'relative'
            });
            const $vi = $('<input>').attr('type', sensitive ? 'password' : 'text').addClass('form-control form-control-sm env-var-val').attr('placeholder', 'value').css('padding-right', constant ? '' : '28px').val(value);
            if (constant) $vi.prop('readonly', true);
            $vw.append($vi);
            if (!constant) {
                const $eye = $('<button type="button">').addClass('hs-eye-btn js-toggle-sensitive').attr('title', sensitive ? 'Show' : 'Mark sensitive').text(sensitive ? '🔒' : '👁');
                $eye.on('click', function () {
                    const ns = $vi.attr('type') === 'text';
                    $vi.attr('type', ns ? 'password' : 'text');
                    $(this).text(ns ? '🔒' : '👁');
                    saveEnvVars();
                });
                $vw.append($eye);
            }
            $inner.append($brace, $ki, $arrow, $vw);
            if (!constant) {
                const $del = $('<button type="button">').addClass('btn btn-sm js-remove-env-var').css({
                    background: 'var(--bg-input)',
                    border: '1px solid var(--border)',
                    color: 'var(--danger)',
                    borderRadius: '6px',
                    flexShrink: 0
                }).text('✕');
                $inner.append($del);
            }
            $row.append($inner);
            $ki.on('change blur', function () {
                if (!constant) saveEnvVars();
            });
            $vi.on('change blur', function () {
                if (!constant) saveEnvVars();
            });
            $container.append($row);
        }

        $(document).on('click', '.js-add-env-var', function () {
            if (!environments[activeEnv]) environments[activeEnv] = {};
            appendEnvVarRow(_PARENT.find('#envVarsContainer'), '', '', false);
        });
        $(document).on('click', '.js-remove-env-var', function () {
            $(this).closest('.env-var-row').remove();
            saveEnvVars();
        });

        function saveEnvVars() {
            const env = {};
            _PARENT.find('#envVarsContainer .env-var-row').each(function () {
                const k = $(this).find('.env-var-key').val().trim(),
                    v = $(this).find('.env-var-val').val(),
                    s = $(this).find('.env-var-val').attr('type') === 'password';
                if (k) env[k] = {
                    value: v,
                    sensitive: s
                };
            });
            environments[activeEnv] = env;
            persistEnvs();
            renderEnvStrip();
            renderUrl();
            refreshHighlights();
        }

        function persistEnvs() {
            localStorage.setItem(ENV_KEY, JSON.stringify(environments));
        }

        loadHistory();

        function saveHistory(method, template, params, status, time, snapshot) {
            let h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            const fu = buildUrl(template, params);
            h = h.filter(function (i) {
                return !(i.method === method && buildUrl(i.template, i.params) === fu);
            });
            h.unshift({
                method,
                template,
                params,
                status,
                time,
                snapshot: snapshot || {},
                date: new Date().toISOString()
            });
            localStorage.setItem(HISTORY_KEY, JSON.stringify(h));
            loadHistory();
        }

        function loadHistory() {
            resetAll();

            const $list = _PARENT.find('#historyList').empty(),
                h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            if (!h.length) {
                $list.append('<li class="list-group-item text-muted text-center">No history</li>');
                return;
            }
            $.each(h, function (idx, item) {
                const fu = buildUrl(item.template, item.params);
                const bc = item.status >= 200 && item.status < 300 ? 'bg-success' : item.status >= 400 ? 'bg-danger' : 'bg-warning';
                const mc = (item.method || 'any').toLowerCase();
                const $li = $(`<li class="list-group-item small"><div class="d-flex justify-content-between align-items-start"><div class="history-restore flex-grow-1 me-2"><span class="method-badge ${mc}">${item.method}</span><span class="badge ${bc} ms-1">${item.status ?? '-'}</span><small class="ms-1" style="color:var(--warning)">${item.time ?? 0} ms</small><div class="text-truncate mt-1" style="color:var(--text-2);max-width:160px">${escHtml(fu)}</div></div><button type="button" class="btn btn-sm history-delete">✕</button></div></li>`);
                $li.find('.history-restore').on('click', function () {
                    restoreSnapshot(item);
                });
                $li.find('.history-delete').on('click', function (e) {
                    e.stopPropagation();
                    deleteHistory(idx);
                });
                $list.append($li);
            });
            $list.append('<li class="list-group-item text-center p-2 js-clear-history"><span style="color:var(--danger);font-size:11px;cursor:pointer">Clear History</span></li>');
        }

        $(document).on('click', '.js-clear-history', function () {
            localStorage.removeItem(HISTORY_KEY);
            loadHistory();
        });

        function deleteHistory(idx) {
            const h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            h.splice(idx, 1);
            localStorage.setItem(HISTORY_KEY, JSON.stringify(h));
            loadHistory();
        }

    });
}

createEndPointTester('#hookshot');