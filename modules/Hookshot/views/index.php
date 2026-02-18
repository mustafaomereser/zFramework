@extends('app.main')
@section('body')

<link rel="stylesheet" href="<?= asset('/assets/css/hookshot.css') ?>" />

<div class="my-5">

    <!-- TOOLBAR -->
    <div class="ra-toolbar">
        <div class="ra-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" class="ra-search" id="raSearch" placeholder="Search by URL or key...">
        </div>
        <div class="ra-filters">
            <button class="ra-filter-btn ra-active" data-method="ALL">ALL</button>
            <button class="ra-filter-btn" data-method="GET">GET</button>
            <button class="ra-filter-btn" data-method="POST">POST</button>
            <button class="ra-filter-btn" data-method="PUT">PUT</button>
            <button class="ra-filter-btn" data-method="PATCH">PATCH</button>
            <button class="ra-filter-btn" data-method="DELETE">DELETE</button>
        </div>
    </div>

    <!-- META BAR -->
    <div class="ra-meta">
        <div class="ra-count">Showing <strong id="raVisibleCount">0</strong> of <strong id="raTotalCount">0</strong> routes</div>
        <div class="ra-actions">
            <button class="ra-action-btn" id="raExpandAll">Expand All</button>
            <button class="ra-action-btn" id="raCollapseAll">Collapse All</button>
        </div>
    </div>

    <!-- ACCORDION — grouped by prefix -->
    <div id="routeAccordion">
        <?php
        // Group routes by prefix
        $grouped = [];
        foreach (\zFramework\Core\Route::$routes as $key => $route) {
            $prefix = @$route['groups']['pre'] ?? '';
            $grouped[$prefix][] = ['key' => $key, 'route' => $route];
        }
        ksort($grouped);
        $totalRoutes = count(\zFramework\Core\Route::$routes);
        ?>

        <?php foreach ($grouped as $prefix => $routes): ?>

            <?php if (count($grouped) > 1): ?>
                <div class="ra-group" data-prefix="<?= htmlspecialchars($prefix) ?>">
                    <div class="ra-group-header">
                        <i class="fas fa-folder ra-group-icon"></i>
                        <span class="ra-group-label"><?= $prefix !== '' ? htmlspecialchars($prefix) : '/' ?></span>
                        <kbd class="ra-group-count"><?= count($routes) ?></kbd>
                    </div>
                <?php endif ?>

                <?php foreach ($routes as $entry):
                    $key         = $entry['key'];
                    $route       = $entry['route'];
                    $method      = strtoupper($route['method'] ?: 'ANY');
                    $url         = $route['url'] ? "/" . ltrim(rtrim($route['url'], '/'), '/') : '#';
                    $methodClass = 'm-' . strtolower($method);
                    $csrfNeeded  = ($method !== 'GET' && !@$route['groups']['no-csrf']) ? 'Yes' : 'No';
                    $prefix_val  = @$route['groups']['pre'] ?? 'None';
                    $params      = json_encode($route['parameters'] ?? [], JSON_PRETTY_PRINT);
                    $middlewares = json_encode(array_column($route['groups']['middlewares'] ?? [], 0), JSON_PRETTY_PRINT);
                    // For non-GET/POST methods, actual HTTP method will be POST + _method field
                    $httpMethod  = in_array($method, ['GET', 'POST', 'ANY']) ? $method : 'POST';
                    $needsMethodField = !in_array($method, ['GET', 'POST', 'ANY']);
                ?>

                    <div class="ra-item"
                        data-method="<?= htmlspecialchars($method) ?>"
                        data-search="<?= htmlspecialchars(strtolower($url . ' ' . $key)) ?>">

                        <div class="ra-header">
                            <button class="ra-toggle" type="button">
                                <i class="fas fa-chevron-right ra-chevron"></i>
                                <span class="ra-method <?= $methodClass ?>"><?= $method ?></span>
                                <div class="ra-url-wrap">
                                    <span class="ra-url"><?= htmlspecialchars($url) ?></span>
                                    <span class="ra-key"><?= htmlspecialchars($key) ?></span>
                                </div>
                            </button>
                            <div class="ra-header-actions">
                                <button class="ra-copy-btn" type="button" data-url-copy="<?= htmlspecialchars($url) ?>">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                                <button class="ra-try-btn testRouteBtn" type="button"
                                    data-method="<?= htmlspecialchars($httpMethod) ?>"
                                    data-real-method="<?= htmlspecialchars($method) ?>"
                                    data-needs-method-field="<?= $needsMethodField ? '1' : '0' ?>"
                                    data-url="<?= htmlspecialchars($url) ?>">
                                    <i class="fas fa-terminal"></i> Try it
                                </button>
                            </div>
                        </div>

                        <div class="ra-body">
                            <div class="ra-body-grid">
                                <div class="ra-field">
                                    <div class="ra-field-label">CSRF Token</div>
                                    <div class="ra-field-val <?= $csrfNeeded === 'Yes' ? 'csrf-yes' : 'csrf-no' ?>"><?= $csrfNeeded ?></div>
                                </div>
                                <div class="ra-field">
                                    <div class="ra-field-label">Prefix</div>
                                    <div class="ra-field-val highlight"><?= htmlspecialchars($prefix_val) ?></div>
                                </div>
                                <div class="ra-field">
                                    <div class="ra-field-label">Parameters</div>
                                    <pre class="ra-field-val"><?= htmlspecialchars($params) ?></pre>
                                </div>
                                <div class="ra-field">
                                    <div class="ra-field-label">Middlewares</div>
                                    <pre class="ra-field-val"><?= htmlspecialchars($middlewares) ?></pre>
                                </div>
                                <?php if ($needsMethodField): ?>
                                    <div class="ra-field ra-field--info">
                                        <div class="ra-field-label">HTTP Spoofing</div>
                                        <div class="ra-field-val">POST + <code>_method=<?= $method ?></code></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>

                <?php if (count($grouped) > 1): ?>
                </div><!-- /.ra-group -->
            <?php endif ?>

        <?php endforeach ?>
    </div>

    <div class="ra-empty" id="raEmpty">
        <i class="fas fa-route fa-2x mb-2 d-block"></i>
        No routes match your search.
    </div>

</div>

<!-- HOOKSHOT OFFCANVAS -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="hookshot" style="width:1200px;">
    <div class="offcanvas-header">
        <h5 class="fw-bold">End Point Tester</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <div class="row">
            <div class="col-8">
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
                        <div id="urlWrapper" class="form-control d-flex flex-wrap align-items-center" style="min-height:38px; gap:5px;"></div>
                        <input type="hidden" id="url">
                    </div>
                    <div class="col-md-2">
                        <button id="sendBtn" class="btn btn-success w-100">
                            <span class="hs-spinner"></span>
                            <span class="hs-label">Send</span>
                        </button>
                    </div>
                </div>
                <div id="shortcutHint" class="mb-2 text-end">
                    <kbd>Ctrl</kbd> + <kbd>Enter</kbd> to send
                </div>
                <ul class="nav nav-pills mb-3 small fw-semibold gap-2" role="tablist">
                    <li class="nav-item"><button class="nav-link active px-3 py-1" data-bs-toggle="tab" data-bs-target="#paramsTab">Params <span class="tab-count" id="paramsCount" style="display:none"></span></button></li>
                    <li class="nav-item"><button class="nav-link px-3 py-1" data-bs-toggle="tab" data-bs-target="#headersTab">Headers <span class="tab-count" id="headersCount" style="display:none"></span></button></li>
                    <li class="nav-item"><button class="nav-link px-3 py-1" data-bs-toggle="tab" data-bs-target="#authTab">Auth <span class="tab-badge" id="authBadge" style="display:none"></span></button></li>
                    <li class="nav-item"><button class="nav-link px-3 py-1" data-bs-toggle="tab" data-bs-target="#bodyTab">Body <span class="tab-badge" id="bodyBadge" style="display:none"></span></button></li>
                </ul>
                <div class="tab-content mb-3">
                    <div class="tab-pane fade show active" id="paramsTab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <div id="queryContainer"></div>
                                <button type="button" class="btn btn-sm btn-light rounded js-add-kv" data-target="queryContainer" data-count="paramsCount">+ Add Param</button>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="headersTab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <div id="headersContainer"></div>
                                <button type="button" class="btn btn-sm btn-light rounded js-add-kv" data-target="headersContainer" data-count="headersCount">+ Add Header</button>
                            </div>
                        </div>
                    </div>
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
                                    <input type="text" id="authUser" class="form-control form-control-sm mb-2" placeholder="username">
                                    <label for="authPass">Password</label>
                                    <input type="password" id="authPass" class="form-control form-control-sm" placeholder="••••••••">
                                </div>
                                <div id="bearerAuthField" class="mt-3" style="display:none">
                                    <label for="authToken">Token</label>
                                    <input type="text" id="authToken" class="form-control form-control-sm" placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...">
                                </div>
                            </div>
                        </div>
                    </div>
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
                                <div id="bodyJson" class="body-section mt-3" style="display:none">
                                    <label>JSON Body</label>
                                    <textarea id="bodyJsonInput" class="form-control form-control-sm" rows="7" placeholder='{"key": "value"}'></textarea>
                                </div>
                                <div id="bodyFormUrlencoded" class="body-section mt-3" style="display:none">
                                    <label>Fields</label>
                                    <div id="bodyUrlencodedContainer"></div>
                                    <button type="button" class="btn btn-sm btn-light rounded js-add-kv" data-target="bodyUrlencodedContainer" data-count="">+ Add Field</button>
                                </div>
                                <div id="bodyFormData" class="body-section mt-3" style="display:none">
                                    <label>Fields</label>
                                    <div id="bodyFormDataContainer"></div>
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="button" class="btn btn-sm btn-light rounded js-add-kv" data-target="bodyFormDataContainer" data-count="">+ Add Field</button>
                                        <button type="button" class="btn btn-sm btn-light rounded js-add-file" data-target="bodyFormDataContainer">+ Add File</button>
                                    </div>
                                </div>
                                <div id="bodyRaw" class="body-section mt-3" style="display:none">
                                    <label id="bodyRawLabel">Raw Body</label>
                                    <textarea id="bodyRawInput" class="form-control form-control-sm" rows="7" placeholder="Plain text or XML..." data-xml-placeholder="&lt;?xml version=&quot;1.0&quot;?&gt;&#10;&lt;root&gt;&#10;  &lt;item&gt;value&lt;/item&gt;&#10;&lt;/root&gt;"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
                <div class="hs-response-wrap">
                    <iframe id="htmlFrame" style="width:100%;height:100%;border:none;display:none;"></iframe>
                    <pre id="jsonOutput" style="display:none;"></pre>
                </div>
            </div>
            <div class="col-4">
                <div class="hs-env-panel mb-4">
                    <div class="hs-env-header">
                        <span class="hs-sidebar-title">Environments</span>
                        <button class="hs-env-add-btn js-add-env" title="New environment">+</button>
                    </div>
                    <div id="envActiveStrip" class="hs-env-active-strip mb-2"></div>
                    <div id="envTabs" class="hs-env-tabs"></div>
                    <div id="envVarsWrap" class="mt-2">
                        <div id="envVarsContainer"></div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-light rounded flex-grow-1 js-add-env-var">+ Add Variable</button>
                        </div>
                        <div class="hs-env-hint mt-2">Use <code>{name}</code> in any field — URL, params, headers, auth, body.</div>
                    </div>
                    <div>
                        <span class="hs-sidebar-title">Constants</span>
                        <div id="envConstants" class="hs-env-tabs"></div>
                    </div>
                </div>
                <div class="hs-sidebar-title mb-2">History</div>
                <ul id="historyList" class="list-group small"></ul>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $(function() {

        /* ── ACCORDION ── */
        const $items = $('.ra-item');
        const total = $items.length;
        let activeMethod = 'ALL';

        $('#raTotalCount').text(total);
        $('#raVisibleCount').text(total);

        // Toggle open/close
        $(document).on('click', '.ra-toggle', function() {
            $(this).closest('.ra-item').toggleClass('ra-open');
        });

        // Copy URL — use attribute directly to avoid jQuery data() encoding issues
        $(document).on('click', '.ra-copy-btn', function(e) {
            e.stopPropagation();
            const url = $(this).attr('data-url-copy');
            const $btn = $(this);
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function() {
                    $btn.addClass('copied').html('<i class="fas fa-check"></i> Copied');
                    setTimeout(function() {
                        $btn.removeClass('copied').html('<i class="fas fa-copy"></i> Copy');
                    }, 1800);
                });
            } else {
                // fallback
                const $ta = $('<textarea>').css({
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    opacity: 0
                }).val(url).appendTo('body');
                $ta[0].select();
                try {
                    document.execCommand('copy');
                    $btn.addClass('copied').html('<i class="fas fa-check"></i> Copied');
                    setTimeout(function() {
                        $btn.removeClass('copied').html('<i class="fas fa-copy"></i> Copy');
                    }, 1800);
                } catch (e) {}
                $ta.remove();
            }
        });

        // Filter by method
        $(document).on('click', '.ra-filter-btn', function() {
            activeMethod = $(this).data('method');
            $('.ra-filter-btn').removeClass('ra-active');
            $(this).addClass('ra-active');
            applyFilter();
        });

        $('#raSearch').on('input', applyFilter);

        function applyFilter() {
            const q = $('#raSearch').val().toLowerCase().trim();
            let visible = 0;

            $items.each(function() {
                const itemMethod = $(this).attr('data-method');
                const itemSearch = $(this).attr('data-search');

                const methodOk = activeMethod === 'ALL' || itemMethod === activeMethod;
                const searchOk = !q || itemSearch.indexOf(q) !== -1;

                if (methodOk && searchOk) {
                    $(this).show();
                    visible++;
                } else {
                    $(this).hide().removeClass('ra-open');
                }
            });

            // Show/hide group headers based on visible children
            $('.ra-group').each(function() {
                const hasVisible = $(this).find('.ra-item:visible').length > 0;
                $(this).find('.ra-group-header').toggle(hasVisible);
            });

            $('#raVisibleCount').text(visible);
            $('#raEmpty').toggle(visible === 0);
        }

        $('#raExpandAll').on('click', function() {
            $items.filter(':visible').addClass('ra-open');
        });
        $('#raCollapseAll').on('click', function() {
            $items.removeClass('ra-open');
        });

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
            $.each(env, function(k, v) {
                flat[k] = v.value || '';
            });
            $.each(window._hsConstants || {}, function(k, v) {
                if (flat[k] === undefined) flat[k] = v;
            });
            return flat;
        }

        function setMethodColor(method) {
            $('#method').removeClass('method-get method-post method-put method-patch method-delete method-any')
                .addClass('method-' + (method || 'any').toLowerCase());
        }

        $(document).on('click', '.testRouteBtn', function() {
            const httpMethod = $(this).data('method'); // POST or GET (actual HTTP)
            realMethod = $(this).data('real-method'); // PUT, DELETE, PATCH etc.
            mustMethod = httpMethod;
            needsMethodField = $(this).data('needs-method-field') === 1 || $(this).data('needs-method-field') === '1';
            const url = $(this).data('url');

            $('#method').val(realMethod !== 'ANY' ? realMethod : 'GET');
            setMethodColor(realMethod !== 'ANY' ? realMethod : 'any');

            rawUrlTemplate = url;
            paramValues = {};
            renderUrl();

            // If this method needs spoofing, switch body to form-urlencoded and pre-fill _method
            if (needsMethodField) {
                $('#bodyType').val('form-urlencoded').trigger('change');
                $('#bodyUrlencodedContainer').empty();
                addKeyValue('bodyUrlencodedContainer', '');
                const $last = $('#bodyUrlencodedContainer .kv-row').last();
                $last.find('.kv-key').val('_method');
                $last.find('.kv-val').val(realMethod).trigger('input');
            }

            bootstrap.Offcanvas.getOrCreateInstance('#hookshot').show();
        });

        function renderUrl() {
            const $w = $('#urlWrapper').empty();
            const re = /\{(\??)([^}]+)\}/g;
            let li = 0,
                m;
            while ((m = re.exec(rawUrlTemplate)) !== null) {
                $w.append(document.createTextNode(rawUrlTemplate.substring(li, m.index)));
                const name = m[2];
                $('<button>').attr('type', 'button').addClass('btn btn-sm btn-light rounded')
                    .text(paramValues[name] ?? name).data('param', name)
                    .on('click', function() {
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
            $i.on('blur', save).on('keydown', function(e) {
                if (e.key === 'Enter') save();
            });
            $btn.replaceWith($i);
            $i.trigger('focus');
        }

        function resolveValue(val) {
            if (!val) return val;
            const vars = getActiveVars();
            return String(val).replace(/\{([^}]+)\}/g, function(_, n) {
                return vars[n] !== undefined ? vars[n] : '{' + n + '}';
            });
        }

        function updateHiddenUrl() {
            let u = rawUrlTemplate;
            $.each(paramValues, function(k, v) {
                u = u.replace(new RegExp('\\{\\??' + k + '\\}'), resolveValue(v));
            });
            $('#url').val(u);
        }

        function buildUrl(template, params) {
            let u = template || '';
            $.each(params || {}, function(k, v) {
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
                raw.split(/(\{[^}]+\})/g).forEach(function(part) {
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
            $('[data-hl-update]').each(function() {
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
        $('#bodyType').on('change', function() {
            const v = $(this).val();
            $('.body-section').hide();
            if (v === 'json') $('#bodyJson').show();
            else if (v === 'form-urlencoded') $('#bodyFormUrlencoded').show();
            else if (v === 'form-data') $('#bodyFormData').show();
            else if (v === 'raw') {
                $('#bodyRawLabel').text('Raw Text');
                $('#bodyRawInput').attr('placeholder', 'Plain text...');
                $('#bodyRaw').show();
            } else if (v === 'xml') {
                $('#bodyRawLabel').text('XML Body');
                $('#bodyRawInput').attr('placeholder', $('#bodyRawInput').data('xml-placeholder'));
                $('#bodyRaw').show();
            }
            const $b = $('#bodyBadge');
            if (v && BODY_LABELS[v]) $b.text(BODY_LABELS[v]).show();
            else $b.hide();
        });

        const AUTH_LABELS = {
            basic: 'Basic',
            bearer: 'Bearer'
        };
        $('#authType').on('change', function() {
            const v = $(this).val();
            $('#basicAuthFields,#bearerAuthField').hide();
            if (v === 'basic') $('#basicAuthFields').show();
            if (v === 'bearer') $('#bearerAuthField').show();
            const $b = $('#authBadge');
            if (v && AUTH_LABELS[v]) $b.text(AUTH_LABELS[v]).show();
            else $b.hide();
        });

        function collectHeaders() {
            const h = collectCustomHeaders(),
                at = $('#authType').val();
            if (at === 'bearer') {
                const t = resolveValue($('#authToken').val().trim());
                if (t) h['Authorization'] = 'Bearer ' + t;
            } else if (at === 'basic') {
                const u = resolveValue($('#authUser').val().trim()),
                    p = resolveValue($('#authPass').val().trim());
                if (u) h['Authorization'] = 'Basic ' + btoa(u + ':' + p);
            }
            return h;
        }

        function collectQueryParams() {
            const p = {};
            $('#queryContainer .kv-row').each(function() {
                const k = $(this).find('.kv-key').val().trim(),
                    v = resolveValue($(this).find('.kv-val').val().trim());
                if (k) p[k] = v;
            });
            return p;
        }

        function collectBody() {
            const bt = $('#bodyType').val();
            if (!bt) return {
                body: null,
                contentType: null
            };
            if (bt === 'json') {
                const r = resolveValue($('#bodyJsonInput').val().trim());
                return r ? {
                    body: r,
                    contentType: 'application/json'
                } : {
                    body: null,
                    contentType: null
                };
            }
            if (bt === 'raw') {
                const r = resolveValue($('#bodyRawInput').val().trim());
                return r ? {
                    body: r,
                    contentType: 'text/plain'
                } : {
                    body: null,
                    contentType: null
                };
            }
            if (bt === 'xml') {
                const r = resolveValue($('#bodyRawInput').val().trim());
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
                $('#bodyUrlencodedContainer .kv-row').each(function() {
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
                $('#bodyFormDataContainer .kv-row').each(function() {
                    const k = $(this).find('.kv-key').val().trim(),
                        v = resolveValue($(this).find('.kv-val').val().trim());
                    if (k) {
                        fd.append(k, v);
                        has = true;
                    }
                });
                $('#bodyFormDataContainer .file-row').each(function() {
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

        $('#sendBtn').on('click', async function() {
            let url = $('#url').val().trim();
            if (!url) {
                alert('URL empty');
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
                if (body && !['GET', 'HEAD'].includes(method.toUpperCase())) opts.body = body;
                const response = await fetch(url, opts);
                const elapsed = Math.round(performance.now() - start),
                    ct = response.headers.get('content-type') || '';
                $('#responseTime').text(elapsed + ' ms');
                setStatus(response.status);
                const snapshot = {
                    queryParams: collectRawQueryParams(),
                    headers: collectRawCustomHeaders(),
                    authType: $('#authType').val(),
                    authUser: $('#authUser').val(),
                    authPass: $('#authPass').val(),
                    authToken: $('#authToken').val(),
                    bodyType: $('#bodyType').val(),
                    bodyJson: $('#bodyJsonInput').val(),
                    bodyRaw: $('#bodyRawInput').val(),
                    bodyUrlenc: collectKvRows('bodyUrlencodedContainer'),
                    bodyForm: collectKvRows('bodyFormDataContainer')
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
                $('#copyBtn').show();
            } catch (err) {
                alert('Request failed: ' + err.message);
            } finally {
                $btn.prop('disabled', false).removeClass('loading');
            }
        });

        $(document).on('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const $b = $('#sendBtn');
                if (!$b.prop('disabled')) $b.trigger('click');
            }
        });

        function setStatus(code) {
            const c = code >= 200 && code < 300 ? 'bg-success' : code >= 400 ? 'bg-danger' : 'bg-warning';
            $('#statusBadge').text(code).attr('class', 'badge ' + c);
        }

        function updateResponseSize(t) {
            const b = new Blob([t]).size;
            $('#responseSize').text(b < 1024 ? b + ' B' : (b / 1024).toFixed(1) + ' KB');
        }

        function renderResponse() {
            if (!lastRawResponse) return;
            if (isHtmlResponse && currentView === 'pretty') {
                $('#jsonOutput').hide();
                $('#htmlFrame').show().prop('srcdoc', lastRawResponse);
            } else {
                $('#htmlFrame').hide();
                const $p = $('#jsonOutput').show();
                if (currentView === 'pretty' && lastParsedJSON !== null) $p.html(syntaxHighlight(JSON.stringify(lastParsedJSON, null, 2)));
                else $p.text(lastRawResponse);
            }
        }

        function showHTML(html) {
            lastRawResponse = html;
            isHtmlResponse = true;
            renderResponse();
        }

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

        $('#copyBtn').on('click', function() {
            if (!lastRawResponse) return;
            const $btn = $(this);

            function markCopied() {
                $btn.text('✓ Copied').addClass('copied');
                setTimeout(function() {
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
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function(m) {
                let c = 'json-number';
                if (/^"/.test(m) && /:$/.test(m)) c = 'json-key';
                else if (/^"/.test(m)) c = 'json-string';
                else if (/true|false/.test(m)) c = 'json-bool';
                else if (/null/.test(m)) c = 'json-null';
                return '<span class="' + c + '">' + m + '</span>';
            });
        }

        $(document).on('click', '.js-add-kv', function() {
            addKeyValue($(this).data('target'), $(this).data('count'));
        });
        $(document).on('click', '.js-add-file', function() {
            addFileRow($(this).data('target'));
        });

        function addFileRow(cid) {
            const $row = $(`<div class="file-row mb-2"><div class="row g-2 align-items-center"><div class="col-4"><input type="text" class="form-control form-control-sm file-key" placeholder="Field name"></div><div class="col-7"><label class="hs-file-label"><span class="hs-file-label-text">Choose file(s)...</span><input type="file" class="file-input" multiple style="display:none"></label></div><div class="col-1"><button type="button" class="btn btn-sm w-100 js-remove-kv">✕</button></div></div><div class="hs-file-list mt-1"></div></div>`);
            $row.find('.file-input').on('change', function() {
                const n = Array.from(this.files).map(f => f.name);
                const $l = $row.find('.hs-file-list').empty();
                n.forEach(function(name) {
                    $l.append($('<span>').addClass('hs-file-chip').text(name));
                });
                $row.find('.hs-file-label-text').text(n.length === 1 ? n[0] : n.length + ' files selected');
            });
            $row.find('.hs-file-label').on('click', function() {
                $row.find('.file-input').trigger('click');
            });
            $('#' + cid).append($row);
        }

        function addKeyValue(cid, countId) {
            const $row = $(`<div class="row g-2 mb-2 kv-row"><div class="col-5"><input type="text" class="form-control form-control-sm kv-key" placeholder="Key"></div><div class="col-6"><input type="text" class="form-control form-control-sm kv-val" placeholder="Value"></div><div class="col-1"><button type="button" class="btn btn-sm w-100 js-remove-kv">✕</button></div></div>`);
            $('#' + cid).append($row);
            const $vi = $row.find('.kv-val');
            $vi.on('dragover', function(e) {
                    e.preventDefault();
                    e.originalEvent.dataTransfer.dropEffect = 'copy';
                    $(this).addClass('hs-drop-active');
                })
                .on('dragleave drop', function() {
                    $(this).removeClass('hs-drop-active');
                })
                .on('drop', function(e) {
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
        }

        $(document).on('click', '.js-remove-kv', function() {
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
            const c = $('#' + cid + ' .kv-row').length;
            c > 0 ? $('#' + countId).show().text(c) : $('#' + countId).hide();
        }

        function collectCustomHeaders() {
            const h = {};
            $('#headersContainer .kv-row').each(function() {
                const k = $(this).find('.kv-key').val().trim(),
                    v = resolveValue($(this).find('.kv-val').val().trim());
                if (k) h[k] = v;
            });
            return h;
        }

        function collectRawCustomHeaders() {
            const h = {};
            $('#headersContainer .kv-row').each(function() {
                const k = $(this).find('.kv-key').val().trim(),
                    v = $(this).find('.kv-val').val().trim();
                if (k) h[k] = v;
            });
            return h;
        }

        function collectRawQueryParams() {
            const p = {};
            $('#queryContainer .kv-row').each(function() {
                const k = $(this).find('.kv-key').val().trim(),
                    v = $(this).find('.kv-val').val().trim();
                if (k) p[k] = v;
            });
            return p;
        }

        function collectKvRows(cid) {
            const r = [];
            $('#' + cid + ' .kv-row').each(function() {
                r.push({
                    key: $(this).find('.kv-key').val().trim(),
                    val: $(this).find('.kv-val').val().trim()
                });
            });
            return r;
        }

        function restoreSnapshot(item) {
            const s = item.snapshot || {};
            $('#method').val(item.method);
            setMethodColor(item.method);
            rawUrlTemplate = item.template;
            paramValues = item.params || {};
            renderUrl();
            $('#queryContainer').empty();
            $('#paramsCount').hide();
            if (s.queryParams) {
                $.each(s.queryParams, function(k, v) {
                    addKeyValue('queryContainer', 'paramsCount');
                    const $l = $('#queryContainer .kv-row').last();
                    $l.find('.kv-key').val(k);
                    $l.find('.kv-val').val(v).trigger('input');
                });
                updateTabCount('queryContainer', 'paramsCount');
            }
            $('#headersContainer').empty();
            $('#headersCount').hide();
            if (s.headers) {
                $.each(s.headers, function(k, v) {
                    addKeyValue('headersContainer', 'headersCount');
                    const $l = $('#headersContainer .kv-row').last();
                    $l.find('.kv-key').val(k);
                    $l.find('.kv-val').val(v).trigger('input');
                });
                updateTabCount('headersContainer', 'headersCount');
            }
            const at = s.authType || '';
            $('#authType').val(at).trigger('change');
            if (at === 'basic') {
                $('#authUser').val(s.authUser || '');
                $('#authPass').val(s.authPass || '');
            } else if (at === 'bearer') {
                $('#authToken').val(s.authToken || '');
            }
            const bt = s.bodyType || '';
            $('#bodyType').val(bt).trigger('change');
            if (bt === 'json') $('#bodyJsonInput').val(s.bodyJson || '');
            else if (bt === 'raw' || bt === 'xml') $('#bodyRawInput').val(s.bodyRaw || '');
            else if (bt === 'form-urlencoded') {
                $('#bodyUrlencodedContainer').empty();
                (s.bodyUrlenc || []).forEach(function(r) {
                    addKeyValue('bodyUrlencodedContainer', '');
                    const $l = $('#bodyUrlencodedContainer .kv-row').last();
                    $l.find('.kv-key').val(r.key);
                    $l.find('.kv-val').val(r.val).trigger('input');
                });
            } else if (bt === 'form-data') {
                $('#bodyFormDataContainer').empty();
                (s.bodyForm || []).forEach(function(r) {
                    addKeyValue('bodyFormDataContainer', '');
                    const $l = $('#bodyFormDataContainer .kv-row').last();
                    $l.find('.kv-key').val(r.key);
                    $l.find('.kv-val').val(r.val).trigger('input');
                });
            }
        }

        renderEnvTabs();
        renderEnvVars();

        function renderEnvStrip() {
            const env = environments[activeEnv] || {},
                c = Object.keys(env).length;
            $('#envActiveStrip').html('<span class="hs-env-active-dot"></span><span class="hs-env-active-name">' + escHtml(activeEnv) + '</span><span class="hs-env-active-count">' + c + ' var' + (c !== 1 ? 's' : '') + '</span>');
        }

        function renderEnvTabs() {
            renderEnvStrip();
            const $tabs = $('#envTabs').empty();
            $('#envConstants').empty();
            $.each(window._hsConstants || {}, function(k, v) {
                appendEnvVarRow($('#envConstants'), k, v || '', false, true);
            });
            $.each(environments, function(name) {
                const isActive = name === activeEnv;
                const $tab = $('<div>').addClass('hs-env-tab' + (isActive ? ' active' : '')).text(name);
                $tab.on('click', function() {
                    if (activeEnv === name) return;
                    activeEnv = name;
                    localStorage.setItem(ENV_ACTIVE, activeEnv);
                    renderEnvTabs();
                    renderEnvVars();
                    renderUrl();
                    refreshHighlights();
                });
                $tab.on('dblclick', function(e) {
                    e.stopPropagation();
                    renameEnv(name, $tab);
                });
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
            $i.on('blur', commit).on('keydown', function(e) {
                if (e.key === 'Enter') commit();
                if (e.key === 'Escape') renderEnvTabs();
            });
            $tab.replaceWith($i);
            $i.trigger('focus').trigger('select');
        }

        $(document).on('click', '.js-add-env', function() {
            const n = 'Env ' + (Object.keys(environments).length + 1);
            environments[n] = {};
            activeEnv = n;
            localStorage.setItem(ENV_ACTIVE, activeEnv);
            persistEnvs();
            renderEnvTabs();
            renderEnvVars();
        });

        function renderEnvVars() {
            const $c = $('#envVarsContainer').empty(),
                env = environments[activeEnv] || {};
            $.each(env, function(k, m) {
                appendEnvVarRow($c, k, m.value || '', m.sensitive || false);
            });
        }

        function appendEnvVarRow($container, key, value, sensitive, constant) {
            constant = constant || false;
            const $row = $('<div>').addClass('env-var-row mb-2');
            const $inner = $('<div>').addClass('d-flex align-items-center gap-1');
            const $brace = $('<span>').addClass('hs-const-brace').attr('draggable', 'true').attr('title', key ? 'Drag to insert {' + key + '}' : '{}').text('{}')
                .on('dragstart', function(e) {
                    const vn = $ki.val().trim();
                    if (!vn) {
                        e.preventDefault();
                        return;
                    }
                    e.originalEvent.dataTransfer.setData('text/plain', '{' + vn + '}');
                    e.originalEvent.dataTransfer.effectAllowed = 'copy';
                    $(this).addClass('hs-brace-dragging');
                })
                .on('dragend', function() {
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
                $eye.on('click', function() {
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
            $ki.on('change blur', function() {
                if (!constant) saveEnvVars();
            });
            $vi.on('change blur', function() {
                if (!constant) saveEnvVars();
            });
            $container.append($row);
        }

        $(document).on('click', '.js-add-env-var', function() {
            if (!environments[activeEnv]) environments[activeEnv] = {};
            appendEnvVarRow($('#envVarsContainer'), '', '', false);
        });
        $(document).on('click', '.js-remove-env-var', function() {
            $(this).closest('.env-var-row').remove();
            saveEnvVars();
        });

        function saveEnvVars() {
            const env = {};
            $('#envVarsContainer .env-var-row').each(function() {
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
            h = h.filter(function(i) {
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
            localStorage.setItem(HISTORY_KEY, JSON.stringify(h.slice(0, 30)));
            loadHistory();
        }

        function loadHistory() {
            const $list = $('#historyList').empty(),
                h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            if (!h.length) {
                $list.append('<li class="list-group-item text-muted text-center">No history</li>');
                return;
            }
            $.each(h, function(idx, item) {
                const fu = buildUrl(item.template, item.params);
                const bc = item.status >= 200 && item.status < 300 ? 'bg-success' : item.status >= 400 ? 'bg-danger' : 'bg-warning';
                const mc = (item.method || 'any').toLowerCase();
                const $li = $(`<li class="list-group-item small"><div class="d-flex justify-content-between align-items-start"><div class="history-restore flex-grow-1 me-2"><span class="method-badge ${mc}">${item.method}</span><span class="badge ${bc} ms-1">${item.status??'-'}</span><small class="ms-1" style="color:var(--warning)">${item.time??0} ms</small><div class="text-truncate mt-1" style="color:var(--text-2);max-width:160px">${escHtml(fu)}</div></div><button type="button" class="btn btn-sm history-delete">✕</button></div></li>`);
                $li.find('.history-restore').on('click', function() {
                    restoreSnapshot(item);
                });
                $li.find('.history-delete').on('click', function(e) {
                    e.stopPropagation();
                    deleteHistory(idx);
                });
                $list.append($li);
            });
            $list.append('<li class="list-group-item text-center p-2 js-clear-history"><span style="color:var(--danger);font-size:11px;cursor:pointer">Clear History</span></li>');
        }

        $(document).on('click', '.js-clear-history', function() {
            if (confirm('Clear all history?')) {
                localStorage.removeItem(HISTORY_KEY);
                loadHistory();
            }
        });

        function deleteHistory(idx) {
            const h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            h.splice(idx, 1);
            localStorage.setItem(HISTORY_KEY, JSON.stringify(h));
            loadHistory();
        }

    });

    window._hsConstants = {
        csrf: "<?= zFramework\Core\Csrf::get() ?>",
        baseUrl: "<?= host() ?>",
    };
</script>
@endsection