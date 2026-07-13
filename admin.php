<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
<?php
$pageTitle = 'Admin';
$pageHeadExtra = <<<'HTML'
    <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@5.5.2/dist/css/tabulator.min.css">
    <script src="https://unpkg.com/tabulator-tables@5.5.2/dist/js/tabulator.min.js"></script>
    <style>
        .admin-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 16px;
        }

        .admin-sidebar,
        .admin-main {
            background: linear-gradient(180deg, #ffffff 0%, #f6f9ff 100%);
            border: 1px solid var(--border-strong);
            border-radius: 12px;
            padding: 14px;
            box-shadow: var(--shadow-soft);
        }

        .admin-section-title {
            margin: 0 0 8px;
            font-size: 1.05rem;
        }

        .table-list {
            display: grid;
            gap: 6px;
            max-height: 60vh;
            overflow: auto;
        }

        .table-list button {
            text-align: left;
            background: #f8fbff;
            color: #0f172a;
            border: 1px solid var(--border-strong);
            border-radius: 10px;
        }

        .table-list button.active {
            background: linear-gradient(180deg, #e8efff 0%, #dce8ff 100%);
            border-color: #99b1ff;
            color: #1e40af;
        }

        .meta-note {
            color: var(--muted);
            font-size: 0.9rem;
            margin: 6px 0 10px;
        }

        .admin-grid-wrap {
            min-height: 280px;
            border: 1px solid var(--border-strong);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .schema-wrap {
            margin-top: 10px;
            border: 1px solid var(--border-strong);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .schema-wrap table {
            width: 100%;
            border-collapse: collapse;
        }

        .schema-wrap th,
        .schema-wrap td {
            border: 1px solid var(--border);
            padding: 6px;
            text-align: left;
            font-size: 0.9rem;
        }

        .create-row-panel {
            margin: 10px 0;
            border: 1px solid var(--border-strong);
            border-radius: 10px;
            padding: 10px;
            background: linear-gradient(180deg, #f9fbff 0%, #f2f7ff 100%);
        }

        .create-row-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }

        .create-row-grid .field {
            margin: 0;
        }

        @media (max-width: 1000px) {
            .admin-layout {
                grid-template-columns: 1fr;
            }

            .table-list {
                max-height: 220px;
            }
        }
    </style>
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
                <h2 class="admin-section-title">User Management</h2>
                <form id="createUserForm" class="card" style="margin-bottom: 12px;">
                    <div class="form-grid">
                        <div class="field">
                            <label for="newUsername">Username</label>
                            <input type="text" id="newUsername" required>
                        </div>
                        <div class="field">
                            <label for="newUserPassword">Password</label>
                            <input type="text" id="newUserPassword" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="inline-actions" style="margin-bottom: 10px;">
                        <label><input type="checkbox" id="newIsAdmin"> Admin</label>
                        <label><input type="checkbox" id="newIsManager"> Manager</label>
                        <label><input type="checkbox" id="newIsEnabled" checked> Enabled</label>
                        <button type="button" id="generatePasswordBtn" class="secondary">Generate password</button>
                    </div>
                    <div class="inline-actions">
                        <button type="submit">Add user</button>
                        <div id="createUserMessage" class="meta-note" style="margin: 0;"></div>
                    </div>
                </form>

                <div class="admin-grid-wrap" id="usersGrid"></div>

                <hr style="margin: 18px 0; border: 0; border-top: 1px solid var(--border);">

                <h2 class="admin-section-title">Table Data Browser</h2>
                <div class="admin-toolbar">
                    <div class="field" style="margin: 0; min-width: 140px;">
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
                <div id="createRowPanel" class="create-row-panel" style="display:none;">
                    <h3 style="margin:0 0 8px; font-size:1rem;">Create New Row</h3>
                    <form id="createRowForm">
                        <div id="createRowFields" class="create-row-grid"></div>
                        <div class="inline-actions">
                            <button type="submit">Insert row</button>
                            <button type="button" id="cancelCreateRowBtn" class="secondary">Cancel</button>
                            <span id="createRowMessage" class="meta-note" style="margin:0;"></span>
                        </div>
                    </form>
                </div>
                <div class="admin-grid-wrap" id="tableGrid"></div>
                <div id="tableSchema" class="schema-wrap" style="display:none;"></div>
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

        function generatePassword(length) {
            const len = Number(length || 16);
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
            const values = new Uint32Array(len);
            window.crypto.getRandomValues(values);
            let out = '';
            for (let i = 0; i < len; i += 1) {
                out += chars[values[i] % chars.length];
            }
            return out;
        }

        let usersGrid = null;
        let tableGrid = null;
        let currentTableName = '';
        let currentSchema = [];
        let currentPk = [];

        function normalizeBoolean(v) {
            return v === true || v === 1 || v === '1' ? 1 : 0;
        }

        function buildUsersGrid() {
            usersGrid = new Tabulator('#usersGrid', {
                layout: 'fitColumns',
                placeholder: 'No users found',
                pagination: true,
                paginationMode: 'local',
                paginationSize: 10,
                columns: [
                    { title: 'ID', field: 'id', width: 70, hozAlign: 'right' },
                    { title: 'Username', field: 'username', editor: 'input' },
                    {
                        title: 'Password',
                        field: 'password',
                        editor: 'input',
                        formatter: function () { return ''; },
                    },
                    { title: 'Admin', field: 'is_admin', editor: true, formatter: 'tickCross', hozAlign: 'center' },
                    { title: 'Manager', field: 'is_manager', editor: true, formatter: 'tickCross', hozAlign: 'center' },
                    { title: 'Enabled', field: 'is_enabled', editor: true, formatter: 'tickCross', hozAlign: 'center' },
                    { title: 'Created', field: 'created_at' },
                    { title: 'Updated', field: 'updated_at' },
                    {
                        title: 'Delete',
                        formatter: function () { return '<button class="btn-danger" style="padding:6px 10px;">Delete</button>'; },
                        hozAlign: 'center',
                        cellClick: function (_e, cell) {
                            const row = cell.getRow();
                            const data = row.getData();
                            if (!confirm('Delete user "' + data.username + '"?')) {
                                return;
                            }
                            api('delete_user', { id: data.id }).then(function (res) {
                                if (!res.success) {
                                    alert(res.message || 'Delete failed');
                                    return;
                                }
                                row.delete();
                            });
                        }
                    }
                ],
                cellEdited: function (cell) {
                    const rowData = cell.getRow().getData();
                    const payload = {
                        id: rowData.id,
                        username: String(rowData.username || '').trim(),
                        is_admin: normalizeBoolean(rowData.is_admin),
                        is_manager: normalizeBoolean(rowData.is_manager),
                        is_enabled: normalizeBoolean(rowData.is_enabled),
                    };
                    if (String(rowData.password || '').trim() !== '') {
                        payload.password = rowData.password;
                    }
                    api('update_user', payload).then(function (res) {
                        if (!res.success) {
                            alert(res.message || 'Update failed');
                            loadUsers();
                            return;
                        }
                        loadUsers();
                    });
                }
            });
        }

        function loadUsers() {
            api('list_users').then(function (res) {
                if (!res.success) {
                    alert(res.message || 'Unable to load users');
                    return;
                }
                const rows = (res.data && res.data.users ? res.data.users : []).map(function (u) {
                    u.password = '';
                    return u;
                });
                if (!usersGrid) {
                    buildUsersGrid();
                }
                usersGrid.setData(rows);
            });
        }

        function renderSchema(columns) {
            const schemaWrap = document.getElementById('tableSchema');
            if (!columns || columns.length === 0) {
                schemaWrap.style.display = 'none';
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
            schemaWrap.style.display = 'block';
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
                    formatter: (type.indexOf('tinyint(1)') >= 0) ? 'tickCross' : undefined,
                    headerFilter: 'input',
                    widthGrow: 1,
                };
            });

            mapped.push({
                title: 'Delete',
                formatter: function () { return '<button class="btn-danger" style="padding:6px 10px;">Delete</button>'; },
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
                tableGrid.setColumns(buildTableColumns(columns, pkFields));
                tableGrid.setPageSize(pageSize);
                return;
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
                        data: response.data.rows || [],
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

        document.getElementById('generatePasswordBtn').addEventListener('click', function () {
            document.getElementById('newUserPassword').value = generatePassword(16);
            document.getElementById('createUserMessage').textContent = 'Password generated.';
        });

        document.getElementById('createUserForm').addEventListener('submit', function (evt) {
            evt.preventDefault();
            const payload = {
                username: document.getElementById('newUsername').value.trim(),
                password: document.getElementById('newUserPassword').value,
                is_admin: document.getElementById('newIsAdmin').checked ? 1 : 0,
                is_manager: document.getElementById('newIsManager').checked ? 1 : 0,
                is_enabled: document.getElementById('newIsEnabled').checked ? 1 : 0,
            };

            const messageBox = document.getElementById('createUserMessage');
            messageBox.textContent = 'Creating...';

            api('create_user', payload).then(function (res) {
                if (!res.success) {
                    messageBox.textContent = res.message || 'Unable to create user';
                    return;
                }
                messageBox.textContent = 'User created.';
                document.getElementById('createUserForm').reset();
                document.getElementById('newIsEnabled').checked = true;
                loadUsers();
            });
        });

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
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        });

        document.getElementById('cancelCreateRowBtn').addEventListener('click', function () {
            const panel = document.getElementById('createRowPanel');
            if (panel) {
                panel.style.display = 'none';
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

        loadUsers();
        loadTables();
    </script>
</body>
</html>


