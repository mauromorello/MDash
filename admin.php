<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

function getUserFromSessionOrCookie() {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['username'])) {
        return [
            'id' => (int)$_SESSION['user_id'],
            'username' => (string)$_SESSION['username'],
            'login_time' => $_SESSION['login_time'] ?? null,
            'is_admin' => (int)($_SESSION['is_admin'] ?? 0),
            'role' => (string)($_SESSION['role'] ?? 'user'),
        ];
    }

    if (!empty($_COOKIE['mdash_user'])) {
        $cookieUser = json_decode(urldecode($_COOKIE['mdash_user']), true);
        if (is_array($cookieUser) && !empty($cookieUser['id'])) {
            return [
                'id' => (int)$cookieUser['id'],
                'username' => (string)($cookieUser['username'] ?? 'user'),
                'login_time' => $cookieUser['login_time'] ?? null,
                'is_admin' => (int)($cookieUser['is_admin'] ?? 0),
                'role' => (string)($cookieUser['role'] ?? 'user'),
            ];
        }
    }

    return null;
}

$user = getUserFromSessionOrCookie();
if (!$user) {
    header('Location: index.php');
    exit;
}

if ((int)($user['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pageTitle = 'Admin';
$pageHeadExtra = <<<'HTML'
    <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@5.5.2/dist/css/tabulator.min.css">
    <script src="https://unpkg.com/tabulator-tables@5.5.2/dist/js/tabulator.min.js"></script>
HTML;
include __DIR__ . '/header.php';
?>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <h1 class="admin-title">Admin Console</h1>
        <div class="meta-note">Dynamic database administration. UI adapts to current schema each time it loads.</div>

        <div class="admin-layout">
            <aside class="admin-sidebar">
                <h2 class="admin-section-title">Database Tables</h2>
                <div class="admin-toolbar">
                    <button type="button" id="refreshTablesBtn" class="secondary">Refresh</button>
                </div>
                <div id="tablesList" class="table-list"></div>
            </aside>

            <section class="admin-main">
                <h2 class="admin-section-title">Table Data Browser</h2>
                <div class="admin-toolbar">
                    <div class="field admin-toolbar-field">
                        <label for="pageSizeSelect">Rows per page</label>
                        <select id="pageSizeSelect">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <button type="button" id="toggleCreateRowBtn" class="secondary">Create row</button>
                    <button type="button" id="reloadCurrentTableBtn" class="secondary">Reload table</button>
                </div>

                <div id="tableMeta" class="meta-note">Select a table from the left panel.</div>
                <div id="createRowPanel" class="create-row-panel hidden">
                    <h3 class="admin-create-row-title">Create New Row</h3>
                    <form id="createRowForm">
                        <div id="createRowFields" class="create-row-grid"></div>
                        <div class="inline-actions">
                            <button type="submit">Insert row</button>
                            <button type="button" id="cancelCreateRowBtn" class="secondary">Cancel</button>
                            <span id="createRowMessage" class="meta-note meta-note-reset"></span>
                        </div>
                    </form>
                </div>
                <div class="admin-grid-wrap" id="tableGrid"></div>
                <div id="tableSchema" class="schema-wrap hidden"></div>
            </section>
        </div>
    </div>

    <script>
        function api(action, payload) {
            const form = new FormData();
            form.append('action', action);
            const body = payload || {};
            Object.keys(body).forEach(function (key) {
                const value = body[key];
                if (value === undefined || value === null) {
                    return;
                }
                if (typeof value === 'object') {
                    form.append(key, JSON.stringify(value));
                } else {
                    form.append(key, String(value));
                }
            });
            return fetch('_act_db.php', { method: 'POST', body: form }).then(function (r) { return r.json(); });
        }

        let tableGrid = null;
        let currentTableName = '';
        let currentSchema = [];
        let currentPk = [];

        function normalizeBoolean(v) {
            return v === true || v === 1 || v === '1' ? 1 : 0;
        }

        function sanitizeValue(value) {
            if (value === null || value === undefined) {
                return value;
            }

            if (Array.isArray(value)) {
                return value.map(sanitizeValue);
            }

            if (typeof value === 'object') {
                const out = {};
                Object.keys(value).forEach(function (k) {
                    out[k] = sanitizeValue(value[k]);
                });
                return out;
            }

            if (typeof value === 'string') {
                return value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, ' ');
            }

            return value;
        }

        function sanitizeRows(rows) {
            return (rows || []).map(function (row) {
                return sanitizeValue(row);
            });
        }

        function renderSchema(columns) {
            const schemaWrap = document.getElementById('tableSchema');
            if (!columns || columns.length === 0) {
                schemaWrap.classList.add('hidden');
                schemaWrap.innerHTML = '';
                return;
            }

            let html = '<table><thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>';
            columns.forEach(function (c) {
                html += '<tr>'
                    + '<td>' + String(c.Field || '') + '</td>'
                    + '<td>' + String(c.Type || '') + '</td>'
                    + '<td>' + String(c.Null || '') + '</td>'
                    + '<td>' + String(c.Key || '') + '</td>'
                    + '<td>' + String(c.Default || '') + '</td>'
                    + '<td>' + String(c.Extra || '') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table>';

            schemaWrap.innerHTML = html;
            schemaWrap.classList.remove('hidden');
        }

        function buildTableColumns(columns, pkFields) {
            const mapped = columns.map(function (col) {
                const fieldName = String(col.Field || '');
                const isPrimary = pkFields.indexOf(fieldName) >= 0;
                const isEditable = !isPrimary;
                const type = String(col.Type || '').toLowerCase();

                let editor = false;
                if (isEditable) {
                    if (type.indexOf('tinyint(1)') >= 0) {
                        editor = true;
                    } else if (type.indexOf('text') >= 0) {
                        editor = 'textarea';
                    } else {
                        editor = 'input';
                    }
                }

                return {
                    title: fieldName + (isPrimary ? ' (PK)' : ''),
                    field: fieldName,
                    editor: editor,
                    formatter: (type.indexOf('tinyint(1)') >= 0) ? 'tickCross' : 'plaintext',
                    headerFilter: 'input',
                    widthGrow: 1,
                };
            });

            mapped.push({
                title: 'Delete',
                formatter: function () { return '<button class="btn-danger admin-delete-btn">Delete</button>'; },
                hozAlign: 'center',
                headerSort: false,
                width: 110,
                cellClick: function (_e, cell) {
                    const rowData = cell.getRow().getData();
                    if (!confirm('Delete selected row?')) {
                        return;
                    }

                    const pkPayload = {};
                    currentPk.forEach(function (pk) {
                        pkPayload[pk] = rowData[pk];
                    });

                    api('delete_row_dynamic', {
                        table: currentTableName,
                        pk: pkPayload,
                    }).then(function (res) {
                        if (!res.success) {
                            alert(res.message || 'Delete failed');
                            return;
                        }
                        cell.getRow().delete();
                    });
                }
            });

            return mapped;
        }

        function buildCreateRowForm(columns) {
            const fieldsWrap = document.getElementById('createRowFields');
            const message = document.getElementById('createRowMessage');
            if (!fieldsWrap) {
                return;
            }

            fieldsWrap.innerHTML = '';
            if (message) {
                message.textContent = '';
            }

            columns.forEach(function (col) {
                const fieldName = String(col.Field || '');
                const extra = String(col.Extra || '').toLowerCase();
                if (!fieldName || extra.indexOf('auto_increment') >= 0) {
                    return;
                }

                const type = String(col.Type || '').toLowerCase();
                const nullable = String(col.Null || 'NO') === 'YES';

                const field = document.createElement('div');
                field.className = 'field';

                const label = document.createElement('label');
                label.setAttribute('for', 'create_' + fieldName);
                label.textContent = fieldName + (nullable ? ' (nullable)' : '');
                field.appendChild(label);

                let input;
                if (type.indexOf('tinyint(1)') >= 0) {
                    input = document.createElement('select');
                    input.innerHTML = '<option value="">(empty)</option><option value="0">0</option><option value="1">1</option>';
                } else if (type.indexOf('text') >= 0) {
                    input = document.createElement('textarea');
                    input.rows = 2;
                } else {
                    input = document.createElement('input');
                    input.type = 'text';
                }

                input.id = 'create_' + fieldName;
                input.setAttribute('data-field', fieldName);
                input.setAttribute('data-nullable', nullable ? '1' : '0');
                input.setAttribute('data-type', type);
                field.appendChild(input);
                fieldsWrap.appendChild(field);
            });
        }

        function ensureTableGrid(columns, pkFields) {
            const pageSize = Number(document.getElementById('pageSizeSelect').value || 25);
            if (tableGrid) {
                tableGrid.destroy();
                tableGrid = null;
            }

            tableGrid = new Tabulator('#tableGrid', {
                layout: 'fitDataStretch',
                placeholder: 'No data',
                pagination: true,
                paginationMode: 'remote',
                paginationSize: pageSize,
                ajaxURL: '_act_db.php',
                ajaxConfig: 'POST',
                ajaxContentType: 'form',
                ajaxParams: {
                    action: 'get_rows_paginated',
                    table: currentTableName,
                    page_size: pageSize,
                },
                ajaxResponse: function (_url, params, response) {
                    if (!response || !response.success) {
                        alert(response && response.message ? response.message : 'Unable to load rows');
                        return [];
                    }

                    const meta = document.getElementById('tableMeta');
                    if (meta) {
                        meta.textContent = 'Table: ' + currentTableName + ' | Rows: ' + (response.data.total_rows || 0) + ' | Page: ' + (response.data.page || 1) + '/' + (response.data.last_page || 1);
                    }

                    return {
                        data: sanitizeRows(response.data.rows || []),
                        last_page: response.data.last_page || 1,
                    };
                },
                columns: buildTableColumns(columns, pkFields),
                cellEdited: function (cell) {
                    const rowData = cell.getRow().getData();
                    const field = cell.getField();
                    const value = rowData[field];

                    const pkPayload = {};
                    pkFields.forEach(function (pk) {
                        pkPayload[pk] = rowData[pk];
                    });

                    const changes = {};
                    changes[field] = value;

                    api('update_row_dynamic', {
                        table: currentTableName,
                        pk: pkPayload,
                        changes: changes,
                    }).then(function (res) {
                        if (!res.success) {
                            alert(res.message || 'Update failed');
                            tableGrid.replaceData();
                        }
                    });
                }
            });
        }

        function loadTable(tableName) {
            currentTableName = tableName;
            const tableMeta = document.getElementById('tableMeta');
            if (tableMeta) {
                tableMeta.textContent = 'Loading schema for ' + tableName + '...';
            }

            api('get_schema', { table: tableName }).then(function (schemaRes) {
                if (!schemaRes.success) {
                    alert(schemaRes.message || 'Schema read failed');
                    return;
                }

                const columns = (schemaRes.data && schemaRes.data.columns) ? schemaRes.data.columns : [];
                currentSchema = columns;
                currentPk = columns.filter(function (c) { return String(c.Key || '') === 'PRI'; }).map(function (c) { return String(c.Field || ''); });

                renderSchema(columns);
                buildCreateRowForm(columns);
                ensureTableGrid(columns, currentPk);
                const pageSize = Number(document.getElementById('pageSizeSelect').value || 25);
                tableGrid.setData('_act_db.php', {
                    action: 'get_rows_paginated',
                    table: currentTableName,
                    page_size: pageSize,
                });

                document.querySelectorAll('#tablesList button').forEach(function (btn) {
                    btn.classList.remove('active');
                    if (btn.dataset.table === tableName) {
                        btn.classList.add('active');
                    }
                });
            });
        }

        function loadTables() {
            const list = document.getElementById('tablesList');
            list.innerHTML = '<div class="meta-note">Loading tables...</div>';

            api('list_tables').then(function (res) {
                if (!res.success) {
                    list.innerHTML = '<div class="meta-note">' + (res.message || 'Unable to list tables') + '</div>';
                    return;
                }

                const tables = (res.data && res.data.tables) ? res.data.tables : [];
                if (tables.length === 0) {
                    list.innerHTML = '<div class="meta-note">No tables found.</div>';
                    return;
                }

                list.innerHTML = '';
                tables.forEach(function (t, idx) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = t;
                    btn.dataset.table = t;
                    btn.addEventListener('click', function () {
                        loadTable(t);
                    });
                    list.appendChild(btn);

                    if (idx === 0) {
                        loadTable(t);
                    }
                });
            });
        }

        document.getElementById('refreshTablesBtn').addEventListener('click', function () {
            loadTables();
        });

        document.getElementById('reloadCurrentTableBtn').addEventListener('click', function () {
            if (!currentTableName) {
                return;
            }
            loadTable(currentTableName);
        });

        document.getElementById('toggleCreateRowBtn').addEventListener('click', function () {
            const panel = document.getElementById('createRowPanel');
            if (!panel) {
                return;
            }
            panel.classList.toggle('hidden');
        });

        document.getElementById('cancelCreateRowBtn').addEventListener('click', function () {
            const panel = document.getElementById('createRowPanel');
            if (panel) {
                panel.classList.add('hidden');
            }
        });

        document.getElementById('createRowForm').addEventListener('submit', function (evt) {
            evt.preventDefault();
            if (!currentTableName || !currentSchema.length) {
                return;
            }

            const row = {};
            const message = document.getElementById('createRowMessage');
            const inputs = document.querySelectorAll('#createRowFields [data-field]');

            inputs.forEach(function (input) {
                const field = input.getAttribute('data-field');
                const nullable = input.getAttribute('data-nullable') === '1';
                const type = String(input.getAttribute('data-type') || '');
                let value = input.value;

                if (value === '' && nullable) {
                    value = null;
                } else if (type.indexOf('tinyint(1)') >= 0 && value !== '') {
                    value = Number(value) ? 1 : 0;
                }
                row[field] = value;
            });

            if (message) {
                message.textContent = 'Inserting...';
            }

            api('create_row_dynamic', {
                table: currentTableName,
                row: row,
            }).then(function (res) {
                if (!res.success) {
                    if (message) {
                        message.textContent = res.message || 'Insert failed';
                    }
                    return;
                }

                if (message) {
                    message.textContent = 'Row inserted.';
                }
                document.getElementById('createRowForm').reset();
                loadTable(currentTableName);
            });
        });

        document.getElementById('pageSizeSelect').addEventListener('change', function () {
            if (!tableGrid || !currentTableName) {
                return;
            }
            const pageSize = Number(document.getElementById('pageSizeSelect').value || 25);
            tableGrid.setPageSize(pageSize);
            tableGrid.setData('_act_db.php', {
                action: 'get_rows_paginated',
                table: currentTableName,
                page_size: pageSize,
            });
        });

        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function () {
                fetch('_act.php', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'logout' })
                }).finally(() => {
                    document.cookie = 'mdash_user=; path=/; max-age=0';
                    window.location.href = 'index.php';
                });
            });
        }

        loadTables();
    </script>
</body>
</html>


