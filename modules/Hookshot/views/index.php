@extends('app.main')
@section('body')
<div class="my-5">
    <div class="accordion" id="routeAccordion">
        <?php foreach (\zFramework\Core\Route::$routes as $key => $route): ?>
            <?php
            $id         = uniqid();
            $collapseId = 'collapse' . $id;
            $headingId  = 'heading' . $id;

            $method = strtoupper($route['method'] ?: 'ANY');
            $url    = $route['url'] ? "/" . ltrim(rtrim($route['url'], '/'), '/') : '#';

            $badge = match ($method) {
                'GET'    => 'success',
                'POST'   => 'primary',
                'PUT'    => 'warning',
                'DELETE' => 'danger',
                default  => 'secondary',
            };
            ?>

            <div class="accordion-item shadow-sm rounded">
                <h2 class="accordion-header d-flex align-items-stretch" id="<?= $headingId ?>">
                    <button class="accordion-button collapsed fw-semibold" type="button"
                        data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
                        <span class="me-3">
                            <?= htmlspecialchars($url) ?>
                            <small class="text-muted"><?= htmlspecialchars($key) ?></small>
                        </span>
                        <span class="badge bg-<?= $badge ?>"><?= $method ?></span>
                    </button>

                    <button class="btn btn-light testRouteBtn rounded-0" data-method="<?= $method ?>" data-url="<?= htmlspecialchars($url) ?>" style="width:150px">
                        Try it
                    </button>
                </h2>

                <div id="<?= $collapseId ?>" class="accordion-collapse collapse" data-bs-parent="#routeAccordion">
                    <div class="accordion-body">
                        <div class="mb-2"><strong>KEY:</strong> <code><?= htmlspecialchars($key) ?></code></div>
                        <div class="mb-2"><strong>URL:</strong> <code><?= htmlspecialchars($url) ?></code></div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <strong>Need CSRF Token</strong>
                                <pre class="bg-light p-2 rounded small"><?= $method == 'GET' || @$route['groups']['no-csrf'] ? 'No' : 'Yes' ?></pre>
                            </div>
                            <div class="col-md-6">
                                <strong>Prefix</strong>
                                <pre class="bg-light p-2 rounded small"><?= @$route['groups']['pre'] ?? '<kbd>None</kbd>' ?></pre>
                            </div>
                            <div class="col-md-6">
                                <strong>Parameters</strong>
                                <pre class="bg-light p-2 rounded small"><?= htmlspecialchars(json_encode($route['parameters'] ?? [], JSON_PRETTY_PRINT)) ?></pre>
                            </div>
                            <div class="col-md-6">
                                <strong>Middlewares</strong>
                                <pre class="bg-light p-2 rounded small"><?= htmlspecialchars(json_encode(array_column($route['groups']['middlewares'] ?? [], 0), JSON_PRETTY_PRINT)) ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</div>

<link rel="stylesheet" href="<?= asset('/assets/css/hookshot.css') ?>" />

<div class="offcanvas offcanvas-end" tabindex="-1" id="hookshot" style="width:1200px;">
    <div class="offcanvas-header">
        <h5 class="fw-bold">End Point Tester</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>

    <div class="offcanvas-body">
        <div class="row">
            <div class="col-8">

                <!-- REQUEST BAR -->
                <div class="row g-2 mb-3">
                    <div class="col-md-2">
                        <select id="method" class="form-select" disabled>
                            <option>ANY</option>
                            <option>GET</option>
                            <option>POST</option>
                            <option>PUT</option>
                            <option>PATCH</option>
                            <option>DELETE</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <div id="urlWrapper" class="form-control d-flex flex-wrap align-items-center"
                            style="min-height:38px; gap:5px;"></div>
                        <input type="hidden" id="url">
                    </div>
                    <div class="col-md-2">
                        <button id="sendBtn" class="btn btn-success w-100">
                            <span class="hs-spinner"></span>
                            <span class="hs-label">Send</span>
                        </button>
                    </div>
                </div>

                <!-- shortcut hint -->
                <div id="shortcutHint" class="mb-2 text-end">
                    <kbd>Ctrl</kbd> + <kbd>Enter</kbd> to send
                </div>

                <!-- TABS -->
                <ul class="nav nav-pills mb-3 small fw-semibold gap-2" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active px-3 py-1" data-bs-toggle="tab" data-bs-target="#paramsTab">
                            Params <span class="tab-count" id="paramsCount" style="display:none"></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link px-3 py-1" data-bs-toggle="tab" data-bs-target="#headersTab">
                            Headers <span class="tab-count" id="headersCount" style="display:none"></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link px-3 py-1" data-bs-toggle="tab" data-bs-target="#authTab">
                            Auth <span class="tab-badge" id="authBadge" style="display:none"></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link px-3 py-1" data-bs-toggle="tab" data-bs-target="#bodyTab">
                            Body <span class="tab-badge" id="bodyBadge" style="display:none"></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content mb-3">

                    <!-- PARAMS -->
                    <div class="tab-pane fade show active" id="paramsTab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <div id="queryContainer"></div>
                                <button type="button" class="btn btn-sm btn-light rounded mt-2 js-add-kv"
                                    data-target="queryContainer" data-count="paramsCount">
                                    + Add Param
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- HEADERS -->
                    <div class="tab-pane fade" id="headersTab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <div id="headersContainer"></div>
                                <button type="button" class="btn btn-sm btn-light rounded mt-2 js-add-kv"
                                    data-target="headersContainer" data-count="headersCount">
                                    + Add Header
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- AUTH -->
                    <div class="tab-pane fade" id="authTab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">

                                <label for="authType">Auth Type</label>
                                <select id="authType" class="form-select form-select-sm">
                                    <option value="">No Auth</option>
                                    <option value="basic">Basic Auth</option>
                                    <option value="bearer">Bearer Token</option>
                                </select>

                                <div id="basicAuthFields" class="mt-3" style="display:none">
                                    <label for="authUser">Username</label>
                                    <input type="text" id="authUser"
                                        class="form-control form-control-sm mb-2"
                                        placeholder="username">
                                    <label for="authPass">Password</label>
                                    <input type="password" id="authPass"
                                        class="form-control form-control-sm"
                                        placeholder="••••••••">
                                </div>

                                <div id="bearerAuthField" class="mt-3" style="display:none">
                                    <label for="authToken">Token</label>
                                    <input type="text" id="authToken"
                                        class="form-control form-control-sm"
                                        placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...">
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- BODY -->
                    <div class="tab-pane fade" id="bodyTab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">

                                <label for="bodyType">Body Type</label>
                                <select id="bodyType" class="form-select form-select-sm">
                                    <option value="">None</option>
                                    <option value="json">JSON</option>
                                    <option value="form-urlencoded">Form URL Encoded</option>
                                    <option value="form-data">Form Data (multipart)</option>
                                    <option value="raw">Raw Text</option>
                                    <option value="xml">XML</option>
                                </select>

                                <!-- JSON -->
                                <div id="bodyJson" class="body-section mt-3" style="display:none">
                                    <label>JSON Body</label>
                                    <textarea id="bodyJsonInput" class="form-control form-control-sm"
                                        rows="7"
                                        placeholder='{"key": "value", "name": "zFramework"}'></textarea>
                                </div>

                                <!-- Form URL Encoded -->
                                <div id="bodyFormUrlencoded" class="body-section mt-3" style="display:none">
                                    <label>Fields</label>
                                    <div id="bodyUrlencodedContainer"></div>
                                    <button type="button" class="btn btn-sm btn-light rounded mt-2 js-add-kv"
                                        data-target="bodyUrlencodedContainer" data-count="">
                                        + Add Field
                                    </button>
                                </div>

                                <!-- Form Data multipart -->
                                <div id="bodyFormData" class="body-section mt-3" style="display:none">
                                    <label>Fields</label>
                                    <div id="bodyFormDataContainer"></div>
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="button" class="btn btn-sm btn-light rounded js-add-kv"
                                            data-target="bodyFormDataContainer" data-count="">
                                            + Add Field
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light rounded js-add-file"
                                            data-target="bodyFormDataContainer">
                                            + Add File
                                        </button>
                                    </div>
                                </div>

                                <!-- Raw / XML -->
                                <div id="bodyRaw" class="body-section mt-3" style="display:none">
                                    <label id="bodyRawLabel">Raw Body</label>
                                    <textarea id="bodyRawInput" class="form-control form-control-sm"
                                        rows="7"
                                        placeholder="Plain text or XML..."
                                        data-xml-placeholder="&lt;?xml version=&quot;1.0&quot;?&gt;&#10;&lt;root&gt;&#10;  &lt;item&gt;value&lt;/item&gt;&#10;&lt;/root&gt;"></textarea>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>

                <!-- RESPONSE INFO BAR -->
                <div id="responseInfoBar">
                    <div class="response-meta">
                        <span>Status: <span id="statusBadge" class="badge bg-secondary">-</span></span>
                        <span id="responseTime">- ms</span>
                        <span id="responseSize"></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="response-view-toggle">
                            <button id="viewPretty" class="active">Pretty</button>
                            <button id="viewRaw">Raw</button>
                        </div>
                        <button id="copyBtn" style="display:none">⎘ Copy</button>
                    </div>
                </div>

                <!-- RESPONSE OUTPUT -->
                <div class="hs-response-wrap">
                    <iframe id="htmlFrame" style="width:100%;height:100%;border:none;display:none;"></iframe>
                    <pre id="jsonOutput" style="display:none;"></pre>
                </div>

            </div>

            <!-- SIDEBAR: ENVIRONMENTS + HISTORY -->
            <div class="col-4">

                <!-- ENVIRONMENTS -->
                <div class="hs-env-panel mb-4">
                    <div class="hs-env-header">
                        <span class="hs-sidebar-title">Environments</span>
                        <button class="hs-env-add-btn js-add-env" title="New environment">+</button>
                    </div>

                    <!-- active env info strip -->
                    <div id="envActiveStrip" class="hs-env-active-strip mb-2"></div>

                    <!-- env tabs -->
                    <div id="envTabs" class="hs-env-tabs"></div>

                    <!-- active env variables -->
                    <div id="envVarsWrap" class="mt-2">
                        <div id="envVarsContainer"></div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-light rounded flex-grow-1 js-add-env-var">
                                + Add Variable
                            </button>
                        </div>
                        <div class="hs-env-hint mt-2">
                            Use <code>{name}</code> in any field — URL, params, headers, auth, body.
                        </div>
                    </div>

                    <div>
                        <span class="hs-sidebar-title">Constants</span>
                        <div id="envConstants" class="hs-env-tabs"></div>
                    </div>
                </div>

                <!-- HISTORY -->
                <div class="hs-sidebar-title mb-2">History</div>
                <ul id="historyList" class="list-group small"></ul>

            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $(function() {

        /* ============================================================
           STATE
        ============================================================ */
        let rawUrlTemplate = '';
        let paramValues = {};
        let lastRawResponse = '';
        let lastParsedJSON = null;
        let isHtmlResponse = false;
        let currentView = 'pretty';
        const HISTORY_KEY = 'hookshotHistory';
        const ENV_KEY = 'hookshotEnvironments';
        const ENV_ACTIVE = 'hookshotActiveEnv';

        // environments: { envName: { varName: {value, sensitive} } }
        let environments = JSON.parse(localStorage.getItem(ENV_KEY) || 'null') || {
            'Default': {}
        };
        let activeEnv = localStorage.getItem(ENV_ACTIVE) || 'Default';
        // ensure activeEnv exists
        if (!environments[activeEnv]) activeEnv = Object.keys(environments)[0];

        // computed flat map used by resolveValue
        function getActiveVars() {
            const env = environments[activeEnv] || {};
            const flat = {};
            $.each(env, function(k, v) {
                flat[k] = v.value || '';
            });

            // also merge backend-seeded constants (window._hsConstants)
            $.each(window._hsConstants || {}, function(k, v) {
                if (flat[k] === undefined) flat[k] = v;
            });
            return flat;
        }

        /* ============================================================
           METHOD COLOR
        ============================================================ */
        function setMethodColor(method) {
            $('#method')
                .removeClass('method-get method-post method-put method-patch method-delete method-any')
                .addClass('method-' + (method || 'any').toLowerCase());
        }

        /* ============================================================
           TEST BUTTON
        ============================================================ */
        $(document).on('click', '.testRouteBtn', function() {
            const method = $(this).data('method');
            const url = $(this).data('url');

            $('#method').val(method !== 'ANY' ? method : 'GET');
            setMethodColor(method !== 'ANY' ? method : 'any');

            rawUrlTemplate = url;
            paramValues = {};
            renderUrl();

            bootstrap.Offcanvas.getOrCreateInstance('#hookshot').show();
        });

        /* ============================================================
           URL RENDER
        ============================================================ */
        function renderUrl() {
            const $wrapper = $('#urlWrapper').empty();
            const regex = /\{(\??)([^}]+)\}/g;
            let lastIndex = 0;
            let match;

            while ((match = regex.exec(rawUrlTemplate)) !== null) {
                $wrapper.append(document.createTextNode(rawUrlTemplate.substring(lastIndex, match.index)));

                const name = match[2];
                $('<button>')
                    .attr('type', 'button')
                    .addClass('btn btn-sm btn-light rounded')
                    .text(paramValues[name] ?? name)
                    .data('param', name)
                    .on('click', function() {
                        makeEditable($(this), name);
                    })
                    .appendTo($wrapper);

                lastIndex = regex.lastIndex;
            }

            $wrapper.append(document.createTextNode(rawUrlTemplate.substring(lastIndex)));
            updateHiddenUrl();
        }

        function makeEditable($btn, name) {
            const $input = $('<input type="text">')
                .addClass('form-control form-control-sm')
                .css('width', '100px')
                .val(paramValues[name] ?? '');

            function save() {
                paramValues[name] = $input.val() || name;
                renderUrl();
            }

            $input.on('blur', save).on('keydown', function(e) {
                if (e.key === 'Enter') save();
            });

            $btn.replaceWith($input);
            $input.trigger('focus');
        }

        function resolveValue(val) {
            if (!val) return val;
            const vars = getActiveVars();
            return String(val).replace(/\{([^}]+)\}/g, function(_, name) {
                return vars[name] !== undefined ? vars[name] : '{' + name + '}';
            });
        }

        function updateHiddenUrl() {
            let finalUrl = rawUrlTemplate;
            $.each(paramValues, function(key, val) {
                finalUrl = finalUrl.replace(new RegExp('\\{\\??' + key + '\\}'), resolveValue(val));
            });
            $('#url').val(finalUrl);
        }

        function buildUrl(template, params) {
            let url = template || '';
            $.each(params || {}, function(key, val) {
                url = url.replace(new RegExp('\\{\\??' + key + '\\}'), val);
            });
            return url;
        }

        /* ============================================================
           HIGHLIGHT OVERLAY — {var} chips inside normal inputs
           Keeps input fully functional, overlays colored spans on top
        ============================================================ */
        function wrapWithHighlight($input) {
            const $wrap = $('<div>').addClass('hs-hl-wrap');
            const $overlay = $('<div>').addClass('hs-hl-overlay hs-env-hint');
            $input.before($wrap);
            $wrap.append($input).append($overlay);

            function update() {
                const vars = getActiveVars();
                const raw = $input.val();
                // build html from tokens
                const parts = raw.split(/(\{[^}]+\})/g);
                let html = '';
                parts.forEach(function(part) {
                    if (/^\{[^}]+\}$/.test(part)) {
                        const name = part.slice(1, -1);
                        const known = vars[name] !== undefined;
                        const cls = known ? 'hs-hl-chip known text-success' : 'hs-hl-chip unknown text-danger';
                        const title = known ? name + ' = ' + vars[name] : name + ' — undefined';
                        html += '<code class="' + cls + '" title="' + title + '">' + (vars[name] || name) + '</code>';
                    } else {
                        html += '<span class="hs-hl-plain text-light">' + escHtml(part) + '</span>';
                    }
                });
                $overlay.html(html || '<span class="hs-hl-ph">' + escHtml($input.attr('placeholder') || '') + '</span>');
            }

            $input.on('input keyup change blur focus', update);
            update();

            // store update fn so we can refresh on env change
            $input.data('hl-update', update);
        }

        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // refresh all highlight overlays (called on env switch)
        function refreshHighlights() {
            $('[data-hl-update]').each(function() {
                const fn = $(this).data('hl-update');
                if (fn) fn();
            });
        }

        /* ============================================================
           BODY TYPE TOGGLE
        ============================================================ */
        const BODY_LABELS = {
            'json': 'JSON',
            'form-urlencoded': 'URL Enc',
            'form-data': 'Form',
            'raw': 'Raw',
            'xml': 'XML',
        };

        $('#bodyType').on('change', function() {
            const val = $(this).val();

            $('.body-section').hide();

            if (val === 'json') {
                $('#bodyJson').show();
            } else if (val === 'form-urlencoded') {
                $('#bodyFormUrlencoded').show();
            } else if (val === 'form-data') {
                $('#bodyFormData').show();
            } else if (val === 'raw') {
                $('#bodyRawLabel').text('Raw Text');
                $('#bodyRawInput').attr('placeholder', 'Plain text content...');
                $('#bodyRaw').show();
            } else if (val === 'xml') {
                $('#bodyRawLabel').text('XML Body');
                $('#bodyRawInput').attr('placeholder', $('#bodyRawInput').data('xml-placeholder'));
                $('#bodyRaw').show();
            }

            // update body tab badge
            const $badge = $('#bodyBadge');
            if (val && BODY_LABELS[val]) {
                $badge.text(BODY_LABELS[val]).show();
            } else {
                $badge.hide();
            }
        });

        /* ============================================================
           AUTH TOGGLE
        ============================================================ */
        const AUTH_LABELS = {
            'basic': 'Basic',
            'bearer': 'Bearer',
        };

        $('#authType').on('change', function() {
            const val = $(this).val();
            $('#basicAuthFields, #bearerAuthField').hide();
            if (val === 'basic') $('#basicAuthFields').show();
            if (val === 'bearer') $('#bearerAuthField').show();

            // update auth tab badge
            const $badge = $('#authBadge');
            if (val && AUTH_LABELS[val]) {
                $badge.text(AUTH_LABELS[val]).show();
            } else {
                $badge.hide();
            }
        });

        /* ============================================================
           COLLECT REQUEST DATA
        ============================================================ */
        function collectHeaders() {
            const headers = collectCustomHeaders();

            const authType = $('#authType').val();
            if (authType === 'bearer') {
                const token = resolveValue($('#authToken').val().trim());
                if (token) headers['Authorization'] = 'Bearer ' + token;
            } else if (authType === 'basic') {
                const user = resolveValue($('#authUser').val().trim());
                const pass = resolveValue($('#authPass').val().trim());
                if (user) headers['Authorization'] = 'Basic ' + btoa(user + ':' + pass);
            }

            return headers;
        }

        function collectQueryParams() {
            const params = {};
            $('#queryContainer .kv-row').each(function() {
                const key = $(this).find('.kv-key').val().trim();
                const val = resolveValue($(this).find('.kv-val').val().trim());
                if (key) params[key] = val;
            });
            return params;
        }

        function collectBody() {
            const bodyType = $('#bodyType').val();
            if (!bodyType) return {
                body: null,
                contentType: null
            };

            if (bodyType === 'json') {
                const raw = resolveValue($('#bodyJsonInput').val().trim());
                if (!raw) return {
                    body: null,
                    contentType: null
                };
                return {
                    body: raw,
                    contentType: 'application/json'
                };
            }

            if (bodyType === 'raw') {
                const raw = resolveValue($('#bodyRawInput').val().trim());
                if (!raw) return {
                    body: null,
                    contentType: null
                };
                return {
                    body: raw,
                    contentType: 'text/plain'
                };
            }

            if (bodyType === 'xml') {
                const raw = resolveValue($('#bodyRawInput').val().trim());
                if (!raw) return {
                    body: null,
                    contentType: null
                };
                return {
                    body: raw,
                    contentType: 'application/xml'
                };
            }

            if (bodyType === 'form-urlencoded') {
                const parts = [];
                $('#bodyUrlencodedContainer .kv-row').each(function() {
                    const key = $(this).find('.kv-key').val().trim();
                    const val = resolveValue($(this).find('.kv-val').val().trim());
                    if (key) parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(val));
                });
                if (!parts.length) return {
                    body: null,
                    contentType: null
                };
                return {
                    body: parts.join('&'),
                    contentType: 'application/x-www-form-urlencoded'
                };
            }

            if (bodyType === 'form-data') {
                const fd = new FormData();
                let hasFields = false;

                // text fields
                $('#bodyFormDataContainer .kv-row').each(function() {
                    const key = $(this).find('.kv-key').val().trim();
                    const val = resolveValue($(this).find('.kv-val').val().trim());
                    if (key) {
                        fd.append(key, val);
                        hasFields = true;
                    }
                });

                // file rows
                $('#bodyFormDataContainer .file-row').each(function() {
                    const key = $(this).find('.file-key').val().trim();
                    const fileEl = $(this).find('.file-input')[0];
                    const files = fileEl ? fileEl.files : [];
                    if (key && files.length) {
                        for (let i = 0; i < files.length; i++) {
                            fd.append(key, files[i], files[i].name);
                        }
                        hasFields = true;
                    }
                });

                if (!hasFields) return {
                    body: null,
                    contentType: null
                };
                return {
                    body: fd,
                    contentType: null
                };
            }

            return {
                body: null,
                contentType: null
            };
        }

        /* ============================================================
           SEND REQUEST
        ============================================================ */
        $('#sendBtn').on('click', async function() {
            let url = $('#url').val().trim();
            if (!url) {
                alert('URL empty');
                return;
            }

            // append query params
            const qp = collectQueryParams();
            const qs = $.param(qp);
            if (qs) url += (url.includes('?') ? '&' : '?') + qs;

            const method = $('#method').val();
            const headers = collectHeaders();
            const {
                body,
                contentType
            } = collectBody();

            if (contentType) headers['Content-Type'] = contentType;

            // loading
            const $btn = $(this).prop('disabled', true).addClass('loading');
            $('#copyBtn').hide();
            lastRawResponse = '';
            lastParsedJSON = null;
            isHtmlResponse = false;
            $('#jsonOutput').hide().text('');
            $('#htmlFrame').hide().prop('srcdoc', '');

            const start = performance.now();

            try {
                const opts = {
                    method,
                    headers
                };
                if (body && !['GET', 'HEAD'].includes(method.toUpperCase())) {
                    opts.body = body;
                }

                const response = await fetch(url, opts);
                const elapsed = Math.round(performance.now() - start);
                const ct = response.headers.get('content-type') || '';

                $('#responseTime').text(elapsed + ' ms');
                setStatus(response.status);
                // snapshot entire request state for history
                const snapshot = {
                    queryParams: collectQueryParams(),
                    headers: collectCustomHeaders(),
                    authType: $('#authType').val(),
                    authUser: $('#authUser').val(),
                    authPass: $('#authPass').val(),
                    authToken: $('#authToken').val(),
                    bodyType: $('#bodyType').val(),
                    bodyJson: $('#bodyJsonInput').val(),
                    bodyRaw: $('#bodyRawInput').val(),
                    bodyUrlenc: collectKvRows('bodyUrlencodedContainer'),
                    bodyForm: collectKvRows('bodyFormDataContainer'),
                };
                saveHistory(method, rawUrlTemplate, paramValues, response.status, elapsed, snapshot);

                if (ct.includes('application/json')) {
                    const data = await response.json();
                    lastParsedJSON = data;
                    lastRawResponse = JSON.stringify(data, null, 2);
                    isHtmlResponse = false;
                    updateResponseSize(lastRawResponse);
                    renderJsonOutput();
                } else {
                    const text = await response.text();
                    lastRawResponse = text;
                    isHtmlResponse = ct.includes('text/html');
                    updateResponseSize(text);

                    if (isHtmlResponse) {
                        // showHTML now just triggers renderResponse
                        showHTML(text);
                    } else {
                        lastParsedJSON = null;
                        renderResponse();
                    }
                }

                $('#copyBtn').show();

            } catch (err) {
                alert('Request failed: ' + err.message);
            } finally {
                $btn.prop('disabled', false).removeClass('loading');
            }
        });

        /* ============================================================
           KEYBOARD SHORTCUT
        ============================================================ */
        $(document).on('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const $btn = $('#sendBtn');
                if (!$btn.prop('disabled')) $btn.trigger('click');
            }
        });

        /* ============================================================
           RESPONSE RENDER
        ============================================================ */
        function setStatus(code) {
            const cls = code >= 200 && code < 300 ? 'bg-success' :
                code >= 400 ? 'bg-danger' :
                'bg-warning';
            $('#statusBadge').text(code).attr('class', 'badge ' + cls);
        }

        function updateResponseSize(text) {
            const bytes = new Blob([text]).size;
            const label = bytes < 1024 ? bytes + ' B' : (bytes / 1024).toFixed(1) + ' KB';
            $('#responseSize').text(label);
        }

        // Central render — decides iframe vs pre based on view + response type
        function renderResponse() {
            if (!lastRawResponse) return;

            if (isHtmlResponse && currentView === 'pretty') {
                // pretty HTML → show in iframe
                $('#jsonOutput').hide();
                $('#htmlFrame').show().prop('srcdoc', lastRawResponse);
            } else {
                // raw text, raw HTML, JSON pretty, JSON raw → all go in <pre>
                $('#htmlFrame').hide();
                const $pre = $('#jsonOutput').show();
                if (currentView === 'pretty' && lastParsedJSON !== null) {
                    $pre.html(syntaxHighlight(JSON.stringify(lastParsedJSON, null, 2)));
                } else {
                    $pre.text(lastRawResponse);
                }
            }
        }

        // keep old name as alias so existing send handler still works
        function renderJsonOutput() {
            renderResponse();
        }

        function showHTML(html) {
            lastRawResponse = html;
            isHtmlResponse = true;
            renderResponse();
        }

        /* ============================================================
           PRETTY / RAW TOGGLE — event delegation for offcanvas safety
        ============================================================ */
        $(document).on('click', '#viewPretty', function() {
            if (currentView === 'pretty') return;
            currentView = 'pretty';
            $(this).addClass('active');
            $('#viewRaw').removeClass('active');
            renderResponse();
        });

        $(document).on('click', '#viewRaw', function() {
            if (currentView === 'raw') return;
            currentView = 'raw';
            $(this).addClass('active');
            $('#viewPretty').removeClass('active');
            renderResponse();
        });

        /* ============================================================
           COPY — with localhost fallback
        ============================================================ */
        $('#copyBtn').on('click', function() {
            if (!lastRawResponse) return;
            const $btn = $(this);

            function markCopied() {
                $btn.text('✓ Copied').addClass('copied');
                setTimeout(function() {
                    $btn.text('⎘ Copy').removeClass('copied');
                }, 1800);
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(lastRawResponse).then(markCopied);
            } else {
                // fallback for http/localhost
                const $ta = $('<textarea>')
                    .css({
                        position: 'fixed',
                        top: 0,
                        left: 0,
                        opacity: 0
                    })
                    .val(lastRawResponse)
                    .appendTo('body');
                $ta[0].focus();
                $ta[0].select();
                try {
                    document.execCommand('copy');
                    markCopied();
                } catch (e) {
                    alert('Copy failed. Please copy manually.');
                }
                $ta.remove();
            }
        });

        /* ============================================================
           JSON SYNTAX HIGHLIGHT
        ============================================================ */
        function syntaxHighlight(json) {
            json = json
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            return json.replace(
                /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
                function(match) {
                    let cls = 'json-number';
                    if (/^"/.test(match) && /:$/.test(match)) cls = 'json-key';
                    else if (/^"/.test(match)) cls = 'json-string';
                    else if (/true|false/.test(match)) cls = 'json-bool';
                    else if (/null/.test(match)) cls = 'json-null';
                    return '<span class="' + cls + '">' + match + '</span>';
                }
            );
        }

        /* ============================================================
           KEY-VALUE ROWS (shared for params, headers, body fields)
        ============================================================ */
        $(document).on('click', '.js-add-kv', function() {
            const targetId = $(this).data('target');
            const countId = $(this).data('count');
            addKeyValue(targetId, countId);
        });

        $(document).on('click', '.js-add-file', function() {
            const targetId = $(this).data('target');
            addFileRow(targetId);
        });

        function addFileRow(containerId) {
            const $row = $(`
            <div class="file-row mb-2">
                <div class="row g-2 align-items-center">
                    <div class="col-4">
                        <input type="text" class="form-control form-control-sm file-key" placeholder="Field name">
                    </div>
                    <div class="col-7">
                        <label class="hs-file-label">
                            <span class="hs-file-label-text">Choose file(s)...</span>
                            <input type="file" class="file-input" multiple style="display:none">
                        </label>
                    </div>
                    <div class="col-1">
                        <button type="button" class="btn btn-sm w-100 js-remove-kv">✕</button>
                    </div>
                </div>
                <div class="hs-file-list mt-1"></div>
            </div>
        `);

            // show file names after selection
            $row.find('.file-input').on('change', function() {
                const names = Array.from(this.files).map(f => f.name);
                const $list = $row.find('.hs-file-list');
                $list.empty();
                names.forEach(function(name) {
                    $list.append($('<span>').addClass('hs-file-chip').text(name));
                });
                $row.find('.hs-file-label-text').text(
                    names.length === 1 ? names[0] : names.length + ' files selected'
                );
            });

            // clicking the label triggers the hidden input
            $row.find('.hs-file-label').on('click', function() {
                $row.find('.file-input').trigger('click');
            });

            $('#' + containerId).append($row);
        }

        function addKeyValue(containerId, countId) {
            const $row = $(`
            <div class="row g-2 mb-2 kv-row">
                <div class="col-5">
                    <input type="text" class="form-control form-control-sm kv-key" placeholder="Key">
                </div>
                <div class="col-6">
                    <input type="text" class="form-control form-control-sm kv-val" placeholder="Value">
                </div>
                <div class="col-1">
                    <button type="button" class="btn btn-sm w-100 js-remove-kv">✕</button>
                </div>
            </div>
        `);

            $('#' + containerId).append($row);

            // wrap val input with highlight overlay
            const $valInput = $row.find('.kv-val');
            wrapWithHighlight($valInput);

            if (countId) updateTabCount(containerId, countId);
        }

        $(document).on('click', '.js-remove-kv', function() {
            const $row = $(this).closest('.kv-row');
            const container = $row.parent().attr('id');
            // only update badge for params/headers
            const countMap = {
                queryContainer: 'paramsCount',
                headersContainer: 'headersCount'
            };
            $row.remove();
            if (countMap[container]) updateTabCount(container, countMap[container]);
        });

        function updateTabCount(containerId, countId) {
            const count = $('#' + containerId + ' .kv-row').length;
            count > 0 ?
                $('#' + countId).show().text(count) :
                $('#' + countId).hide();
        }

        /* ============================================================
           COLLECT HELPERS (also used by history snapshot)
        ============================================================ */
        function collectCustomHeaders() {
            const headers = {};
            $('#headersContainer .kv-row').each(function() {
                const key = $(this).find('.kv-key').val().trim();
                const val = resolveValue($(this).find('.kv-val').val().trim());
                if (key) headers[key] = val;
            });
            return headers;
        }

        function collectKvRows(containerId) {
            const rows = [];
            $('#' + containerId + ' .kv-row').each(function() {
                rows.push({
                    key: $(this).find('.kv-key').val().trim(),
                    val: $(this).find('.kv-val').val().trim()
                });
            });
            return rows;
        }

        function restoreSnapshot(item) {
            const s = item.snapshot || {};

            // method + url
            $('#method').val(item.method);
            setMethodColor(item.method);
            rawUrlTemplate = item.template;
            paramValues = item.params || {};
            renderUrl();

            // query params
            $('#queryContainer').empty();
            $('#paramsCount').hide();
            if (s.queryParams) {
                $.each(s.queryParams, function(key, val) {
                    addKeyValue('queryContainer', 'paramsCount');
                    const $last = $('#queryContainer .kv-row').last();
                    $last.find('.kv-key').val(key);
                    $last.find('.kv-val').val(val);
                });
                updateTabCount('queryContainer', 'paramsCount');
            }

            // custom headers
            $('#headersContainer').empty();
            $('#headersCount').hide();
            if (s.headers) {
                $.each(s.headers, function(key, val) {
                    addKeyValue('headersContainer', 'headersCount');
                    const $last = $('#headersContainer .kv-row').last();
                    $last.find('.kv-key').val(key);
                    $last.find('.kv-val').val(val);
                });
                updateTabCount('headersContainer', 'headersCount');
            }

            // auth
            const authType = s.authType || '';
            $('#authType').val(authType).trigger('change');
            if (authType === 'basic') {
                $('#authUser').val(s.authUser || '');
                $('#authPass').val(s.authPass || '');
            } else if (authType === 'bearer') {
                $('#authToken').val(s.authToken || '');
            }

            // body
            const bodyType = s.bodyType || '';
            $('#bodyType').val(bodyType).trigger('change');

            if (bodyType === 'json') {
                $('#bodyJsonInput').val(s.bodyJson || '');
            } else if (bodyType === 'raw' || bodyType === 'xml') {
                $('#bodyRawInput').val(s.bodyRaw || '');
            } else if (bodyType === 'form-urlencoded') {
                $('#bodyUrlencodedContainer').empty();
                (s.bodyUrlenc || []).forEach(function(row) {
                    addKeyValue('bodyUrlencodedContainer', '');
                    const $last = $('#bodyUrlencodedContainer .kv-row').last();
                    $last.find('.kv-key').val(row.key);
                    $last.find('.kv-val').val(row.val);
                });
            } else if (bodyType === 'form-data') {
                $('#bodyFormDataContainer').empty();
                (s.bodyForm || []).forEach(function(row) {
                    addKeyValue('bodyFormDataContainer', '');
                    const $last = $('#bodyFormDataContainer .kv-row').last();
                    $last.find('.kv-key').val(row.key);
                    $last.find('.kv-val').val(row.val);
                });
                // note: files can't be restored (browser security), skipped intentionally
            }
        }

        /* ============================================================
           ENVIRONMENTS
        ============================================================ */
        renderEnvTabs();
        renderEnvVars();

        // ── Active env strip ──────────────────────────────────────
        function renderEnvStrip() {
            const env = environments[activeEnv] || {};
            const count = Object.keys(env).length;
            $('#envActiveStrip').html(
                '<span class="hs-env-active-dot"></span>' +
                '<span class="hs-env-active-name">' + activeEnv + '</span>' +
                '<span class="hs-env-active-count">' + count + ' var' + (count !== 1 ? 's' : '') + '</span>'
            );
        }

        // ── Tab rendering ──────────────────────────────────────────
        function renderEnvTabs() {
            renderEnvStrip();
            const $tabs = $('#envTabs').empty();

            $('#envConstants').empty();
            $.each(window._hsConstants || {}, function(k, v) {
                appendEnvVarRow($('#envConstants'), k, v || '', false, true);
            });

            $.each(environments, function(name) {
                const isActive = name === activeEnv;
                const $tab = $('<div>')
                    .addClass('hs-env-tab' + (isActive ? ' active' : ''))
                    .text(name);

                // switch env on click
                $tab.on('click', function() {
                    if (activeEnv === name) return;
                    activeEnv = name;
                    localStorage.setItem(ENV_ACTIVE, activeEnv);
                    renderEnvTabs();
                    renderEnvVars();
                    renderUrl();
                    refreshHighlights();
                    refreshAllSmartInputs();
                });

                // double-click to rename
                $tab.on('dblclick', function(e) {
                    e.stopPropagation();
                    renameEnv(name, $tab);
                });

                // right-click context to delete (if not the only env)
                $tab.on('contextmenu', function(e) {
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
            const $input = $('<input type="text">')
                .addClass('hs-env-rename-input')
                .val(oldName);

            function commit() {
                const newName = $input.val().trim();
                if (!newName || newName === oldName) {
                    renderEnvTabs();
                    return;
                }
                if (environments[newName]) {
                    alert('Environment "' + newName + '" already exists.');
                    renderEnvTabs();
                    return;
                }
                // rename key
                const vars = environments[oldName];
                delete environments[oldName];
                environments[newName] = vars;
                if (activeEnv === oldName) activeEnv = newName;
                persistEnvs();
                renderEnvTabs();
            }

            $input.on('blur', commit).on('keydown', function(e) {
                if (e.key === 'Enter') commit();
                if (e.key === 'Escape') renderEnvTabs();
            });

            $tab.replaceWith($input);
            $input.trigger('focus').trigger('select');
        }

        // ── Add new env ────────────────────────────────────────────
        $(document).on('click', '.js-add-env', function() {
            const name = 'Env ' + (Object.keys(environments).length + 1);
            environments[name] = {};
            activeEnv = name;
            localStorage.setItem(ENV_ACTIVE, activeEnv);
            persistEnvs();
            renderEnvTabs();
            renderEnvVars();
        });

        // ── Variable rendering ─────────────────────────────────────
        function renderEnvVars() {
            const $container = $('#envVarsContainer').empty();
            const env = environments[activeEnv] || {};
            $.each(env, function(key, meta) {
                appendEnvVarRow($container, key, meta.value || '', meta.sensitive || false);
            });
        }

        function appendEnvVarRow($container, key, value, sensitive, constant = false) {
            const masked = sensitive;
            const $row = $(`
            <div class="env-var-row mb-2">
                <div class="d-flex align-items-center gap-1">
                    <span class="hs-const-brace">{}</span>
                    <input type="text"
                        class="form-control form-control-sm env-var-key"
                        placeholder="variable_name"
                        style="flex:1 1 80px" ${constant ? 'readonly' : ''}>
                    <span style="color:var(--text-3);font-size:11px;flex-shrink:0">→</span>
                    <div style="flex:2 1 120px;position:relative">
                        <input
                            type="${masked ? 'password' : 'text'}"
                            class="form-control form-control-sm env-var-val"
                            placeholder="value"
                            style="padding-right:28px" ${constant ? 'readonly' : ''}>
                            ${constant ? `` : `
                        <button type="button" class="hs-eye-btn js-toggle-sensitive"
                            title="${masked ? 'Show value' : 'Mark sensitive'}">
                            ${masked ? '🔒' : '👁'}`}
                        </button>
                    </div>
        ${constant ? `` : `<button type="button" class="btn btn-sm js-remove-env-var"
                        style="background:var(--bg-input);border:1px solid var(--border);color:var(--danger);border-radius:6px;flex-shrink:0">✕</button>`}
                </div>
            </div>
        `);

            $row.find('.env-var-key').val(key);
            $row.find('.env-var-val').val(value);

            // save on any change
            $row.find('.env-var-key, .env-var-val').on('change blur', function() {
                saveEnvVars();
            });

            // sensitive toggle
            $row.find('.js-toggle-sensitive').on('click', function() {
                const $val = $row.find('.env-var-val');
                const nowSensitive = $val.attr('type') === 'text';
                $val.attr('type', nowSensitive ? 'password' : 'text');
                $(this).text(nowSensitive ? '🔒' : '👁');
                saveEnvVars();
            });

            $container.append($row);
        }

        // ── Add variable ───────────────────────────────────────────
        $(document).on('click', '.js-add-env-var', function() {
            if (!environments[activeEnv]) environments[activeEnv] = {};
            appendEnvVarRow($('#envVarsContainer'), '', '', false);
        });

        // ── Remove variable ────────────────────────────────────────
        $(document).on('click', '.js-remove-env-var', function() {
            $(this).closest('.env-var-row').remove();
            saveEnvVars();
        });

        // ── Persist ────────────────────────────────────────────────
        function saveEnvVars() {
            const env = {};
            $('#envVarsContainer .env-var-row').each(function() {
                const k = $(this).find('.env-var-key').val().trim();
                const v = $(this).find('.env-var-val').val();
                const sensitive = $(this).find('.env-var-val').attr('type') === 'password';
                if (k) env[k] = {
                    value: v,
                    sensitive
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

        /* ============================================================
           HISTORY
        ============================================================ */
        loadHistory();

        function saveHistory(method, template, params, status, time, snapshot) {
            let history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            const finalUrl = buildUrl(template, params);

            history = history.filter(function(item) {
                return !(item.method === method && buildUrl(item.template, item.params) === finalUrl);
            });

            history.unshift({
                method,
                template,
                params,
                status,
                time,
                snapshot: snapshot || {},
                date: new Date().toISOString()
            });
            localStorage.setItem(HISTORY_KEY, JSON.stringify(history.slice(0, 30)));
            loadHistory();
        }

        function loadHistory() {
            const $list = $('#historyList').empty();
            const history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');

            if (!history.length) {
                $list.append('<li class="list-group-item text-muted text-center">No history</li>');
                return;
            }

            $.each(history, function(index, item) {
                const finalUrl = buildUrl(item.template, item.params);
                const badgeCls = item.status >= 200 && item.status < 300 ? 'bg-success' :
                    item.status >= 400 ? 'bg-danger' : 'bg-warning';
                const methodCls = (item.method || 'any').toLowerCase();

                const $li = $(`
                <li class="list-group-item small">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="history-restore flex-grow-1 me-2">
                            <span class="method-badge ${methodCls}">${item.method}</span>
                            <span class="badge ${badgeCls} ms-1">${item.status ?? '-'}</span>
                            <small class="ms-1" style="color:var(--warning)">${item.time ?? 0} ms</small>
                            <div class="text-truncate mt-1" style="color:var(--text-2);max-width:160px">
                                ${finalUrl}
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm history-delete">✕</button>
                    </div>
                </li>
            `);

                $li.find('.history-restore').on('click', function() {
                    restoreSnapshot(item);
                });

                $li.find('.history-delete').on('click', function(e) {
                    e.stopPropagation();
                    deleteHistory(index);
                });

                $list.append($li);
            });

            $list.append(`
            <li class="list-group-item text-center p-2 js-clear-history">
                <span style="color:var(--danger);font-size:11px;cursor:pointer">Clear History</span>
            </li>
        `);
        }

        $(document).on('click', '.js-clear-history', function() {
            if (confirm('Clear all history?')) {
                localStorage.removeItem(HISTORY_KEY);
                loadHistory();
            }
        });

        function deleteHistory(index) {
            const history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            history.splice(index, 1);
            localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
            loadHistory();
        }

    });

    window._hsConstants = {
        csrf: "<?= zFramework\Core\Csrf::get() ?>",
        baseUrl: "<?= host() ?>",
    };
</script>
@endsection