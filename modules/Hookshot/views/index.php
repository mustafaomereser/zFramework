<!DOCTYPE html>
<html lang="en" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hookshot</title>

    <link rel="stylesheet" href="https://pro.fontawesome.com/releases/v5.15.4/css/all.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="/hookshot/assets/hookshot-css" />

</head>

<body>
    <div class="d-flex w-100" main-container>
        <div class="col-4 h-100" style="overflow-y: auto">
            <div class="px-2">
                <!-- TOOLBAR -->
                <div>
                    <h3>Routes</h3>
                </div>
                <div class="ra-toolbar">
                    <div class="ra-search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" class="ra-search" id="raSearch" placeholder="Search by URL or key...">
                    </div>
                    <div class="ra-filters">
                        <button class="ra-filter-btn ra-active" data-method="ALL">ALL</button>
                        <button class="ra-filter-btn" data-method="ANY">ANY</button>
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

                <!-- ACCORDION — nested prefix tree -->
                <div id="routeAccordion">
                    <?php
                    // Build a prefix tree
                    // Each route's prefix is split by '/' to create nesting
                    // Structure: tree[segment][segment]... = ['__routes' => [...]]
                    function buildPrefixTree(array $routes): array
                    {
                        $tree = [];
                        foreach ($routes as $key => $route) {
                            $prefix = trim(@$route['groups']['pre'] ?? '', '/');
                            if (strstr($prefix, 'hookshot')) continue;
                            $segments = $prefix !== '' ? explode('/', $prefix) : [''];
                            $node = &$tree;
                            foreach ($segments as $seg) {
                                if (!isset($node[$seg])) $node[$seg] = [];
                                $node = &$node[$seg];
                            }
                            $node['__routes'][] = ['key' => $key, 'route' => $route];
                            unset($node);
                        }
                        return $tree;
                    }

                    function renderPrefixTree(array $tree, int $depth = 0, string $pathSoFar = ''): void
                    {
                        if (!empty($tree['__routes'])) foreach ($tree['__routes'] as $entry) renderRouteItem($entry['key'], $entry['route']);

                        foreach ($tree as $segment => $subtree) {
                            if ($segment === '__routes') continue;
                            $fullPath   = $pathSoFar !== '' ? $pathSoFar . '/' . $segment : $segment;
                            $routeCount = countRoutesInTree($subtree);
                            // Start open by default
                            echo '<div class="ra-folder ra-folder-open" data-path="' . htmlspecialchars($fullPath) . '">';
                            echo '<div class="ra-folder-header">';
                            echo    '<i class="fas fa-chevron-right ra-folder-chevron" style="transform:rotate(90deg)"></i>';
                            echo    '<i class="fas fa-folder ra-folder-icon-closed" style="display:none"></i>';
                            echo    '<i class="fas fa-folder-open ra-folder-icon-open"></i>';
                            echo    '<span class="ra-folder-name">' . htmlspecialchars($segment) . '</span>';
                            echo    '<span class="ra-folder-path">/' . htmlspecialchars($fullPath) . '</span>';
                            echo    '<kbd class="ra-folder-count">' . $routeCount . '</kbd>';
                            echo '</div>';
                            echo '<div class="ra-folder-body">';
                            echo    '<div class="ra-meta">';
                            echo        '<div class="ra-count">Has <strong>' . $routeCount . '</strong> routes</div>';
                            echo        '<div class="ra-actions">';
                            echo           '<button class="ra-action-btn" expand-all-items>Expand All</button>';
                            echo           '<button class="ra-action-btn" ra-collapse-all-items>Collapse All</button>';
                            echo        '</div>';
                            echo     '</div>';
                            renderPrefixTree($subtree, $depth + 1, $fullPath);
                            echo   '</div>';
                            echo '</div>';
                        }
                    }

                    function countRoutesInTree(array $tree): int
                    {
                        $count = count($tree['__routes'] ?? []);
                        foreach ($tree as $k => $v) if ($k !== '__routes' && is_array($v)) $count += countRoutesInTree($v);
                        return $count;
                    }

                    function renderRouteItem(string $key, array $route): void
                    {
                        $method      = strtoupper($route['method'] ?: 'ANY');
                        $url         = $route['url'] ? "/" . ltrim(rtrim($route['url'], '/'), '/') : '#';
                        $methodClass = 'm-' . strtolower($method);
                        $csrfNeeded  = ($method !== 'GET' && !@$route['groups']['no-csrf']) ? 'Yes' : 'No';
                        $prefix_val  = @$route['groups']['pre'] ?? 'None';
                        $params      = json_encode($route['parameters'] ?? [], JSON_PRETTY_PRINT);
                        $middlewares = json_encode($route['groups']['middlewares'][0] ?? [], JSON_PRETTY_PRINT);
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
                                    <?php endif ?>
                                </div>
                            </div>
                        </div>
                    <?php
                    }

                    $tree = buildPrefixTree(\zFramework\Core\Route::$routes);
                    $totalRoutes = count(\zFramework\Core\Route::$routes);
                    renderPrefixTree($tree);
                    ?>
                </div>

                <div class="ra-empty" id="raEmpty">
                    <i class="fas fa-route fa-2x mb-2 d-block"></i>
                    No routes match your search.
                </div>
            </div>
        </div>
        <div class="col-8">
            <div class="hookshot position-sticky top-0 h-100" id="hookshot">
                <div class="row h-100 w-100">
                    <div class="col-8">
                        <div class="row g-2 mb-3">
                            <div class="col-md-2">
                                <select id="method" class="form-select method-any" disabled>
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
                        <div class="row align-items-center mb-2">
                            <div class="col-6">
                                <div class="d-flex align-items-center gap-2">
                                    <label for="request-type-xmlhttprequest" class="hs-env-hint">
                                        <input type="radio" name="request_type" class="form-check-input" id="request-type-xmlhttprequest" value="xmlhttprequest" style="background-color: var(--bg-input)"> XmlHttpRequest
                                    </label>
                                    <label for="request-type-fetch" class="hs-env-hint">
                                        <input type="radio" name="request_type" class="form-check-input" id="request-type-fetch" value="fetch" style="background-color: var(--bg-input)"> Fetch
                                    </label>
                                </div>
                            </div>
                            <div class="col-6 text-end">
                                <div id="shortcutHint">
                                    <kbd>Ctrl</kbd> + <kbd>Enter</kbd> to send
                                </div>
                            </div>
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
                        <div response-content>
                            <div id="responseInfoBar">
                                <div class="response-meta">
                                    <span>Status: <span id="statusBadge" class="badge"></span></span>
                                    <span id="responseTime"></span>
                                    <span id="responseSize"></span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="response-view-toggle">
                                        <button id="viewPretty" class="active">Pretty</button>
                                        <button id="viewRaw">Raw</button>
                                    </div>
                                    <button id="copyBtn" style="display: none">⎘ Copy</button>
                                    <button id="resultFullscreenBtn" class="btn" style="display: none">⤢ Fullscreen</button>
                                </div>
                            </div>
                            <div class="hs-response-wrap">
                                <iframe id="htmlFrame" style="width:100%;height:100%;border:none;display:none;"></iframe>
                                <pre id="jsonOutput" style="display:none;"></pre>
                            </div>
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
                            <div class="mt-2">
                                <span class="hs-sidebar-title">Constants</span>
                                <div id="envConstants" class="hs-env-tabs"></div>
                            </div>
                        </div>
                        <div class="row align-items-center mb-2">
                            <div class="col-6">
                                <div class="hs-sidebar-title mb-0">History</div>
                            </div>
                            <div class="col-6 text-end">
                                <span style="color:var(--danger);font-size:11px;cursor:pointer" class="js-clear-history">Clear History</span>
                            </div>
                        </div>
                        <ul id="historyList" class="list-group small"></ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/hookshot/assets/hookshot-js"></script>
    <script>
        $('[main-container]').css('height', window.innerHeight);

        window._hsConstants = {
            csrf: "<?= zFramework\Core\Csrf::get() ?>",
            server: "<?= host() ?>",
        };
    </script>
</body>

</html>