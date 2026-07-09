<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

function getUserFromCookie(){
    if (!empty($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'user',
            'is_admin' => $_SESSION['is_admin'] ?? 0,
        ];
    }
    if (empty($_COOKIE['mdash_user'])) return null;
    $u = json_decode(urldecode($_COOKIE['mdash_user']), true);
    if (!is_array($u) || empty($u['id'])) return null;
    return [
        'id' => (int)$u['id'],
        'username' => $u['username'] ?? 'user',
        'is_admin' => (int)($u['is_admin'] ?? 0),
    ];
}

$me = getUserFromCookie();
if (!$me) {
    header('Location: index.php');
    exit;
}
if ((int)$me['is_admin'] !== 1) {
    echo "<h2>Unauthorized access</h2><p>You need admin privileges.</p>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@5.4.4/dist/css/tabulator.min.css">
    <script src="https://unpkg.com/tabulator-tables@5.4.4/dist/js/tabulator.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="admin-page">
    <h2>Admin Console</h2>
    <p>Welcome, <?php echo htmlspecialchars($me['username']); ?> (id <?php echo htmlspecialchars($me['id']); ?>).</p>

    <div id="user-management">
        <h3>User Management</h3>
        <div id="create-user-panel">
            <h4>Create New User</h4>
            <form id="createUserForm">
                <input type="text" id="newUsername" placeholder="Username" required>
                <div class="create-user-row">
                    <input type="password" id="newUserPassword" class="flex-grow-input" placeholder="Password" required autocomplete="new-password">
                    <button type="button" id="generatePasswordBtn">Generate Password</button>
                </div>
                <label><input type="checkbox" id="newIsAdmin"> Admin</label>
                <label><input type="checkbox" id="newIsManager"> Manager</label>
                <label><input type="checkbox" id="newIsEnabled" checked> Enabled</label>
                <button type="submit">Create User</button>
            </form>
            <div id="createUserMessage"></div>
        </div>
        <div id="usersTable"></div>
    </div>

    <h3>Database Tables</h3>
    <div id="tables" class="admin-table-buttons"></div>

    <h3 id="tableTitle" class="hidden">Table: <span id="tableName"></span></h3>
    <div id="tableSchema"></div>
    <div id="tableRows"></div>
    </div>

    <script>
        function loadTables(){
            const cont = document.getElementById('tables');
            cont.innerHTML = 'Loading...';
            api('list_tables').then(res=>{
                if(!res.success){ cont.innerHTML = 'No tables: '+res.message; return; }
                cont.innerHTML = '';
                res.data.tables.forEach(t=>{
                    const btn = document.createElement('button');
                    btn.textContent = t;
                    btn.addEventListener('click', ()=>{ loadTable(t); });
                    cont.appendChild(btn);
                });
            });
        }

        function loadTable(table){
            document.getElementById('tableTitle').style.display = 'block';
            document.getElementById('tableName').textContent = table;
            document.getElementById('tableSchema').innerHTML = 'Loading schema...';
            document.getElementById('tableRows').innerHTML = '';
            api('get_schema', {table: table}).then(res=>{
                if(!res.success){ document.getElementById('tableSchema').innerText = res.message; return; }
                let html = '<table class="plain-table"><tr>' + res.data.columns.map(c=>'<th>'+c.Field+'</th>').join('') + '<th>Actions</th></tr>';
                html += '</table>';
                document.getElementById('tableSchema').innerHTML = html;
                loadRows(table);
            });
        }

        function loadRows(table){
            document.getElementById('tableRows').innerHTML = 'Loading rows...';
            api('get_rows', {table: table, limit: 200}).then(res=>{
                if(!res.success){ document.getElementById('tableRows').innerText = res.message; return; }
                const rows = res.data.rows;
                if (!rows || rows.length===0){ document.getElementById('tableRows').innerText = 'No records.'; return; }
                const cols = Object.keys(rows[0]);
                let html = '<table class="plain-table"><thead><tr>' + cols.map(c=>'<th>'+c+'</th>').join('') + '<th>Actions</th></tr></thead><tbody>';
                rows.forEach(r=>{
                    html += '<tr data-id="'+(r.id||'')+'">';
                    cols.forEach(c=>{
                        const val = r[c]===null? '': String(r[c]);
                        html += '<td><input class="cell-edit" data-col="'+c+'" value="'+val.replace(/"/g,'&quot;')+'" /></td>';
                    });
                    html += '<td class="row-actions"><button class="save">Save</button></td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                document.getElementById('tableRows').innerHTML = html;
                document.querySelectorAll('#tableRows .save').forEach(btn=>{
                    btn.addEventListener('click', function(){
                        const tr = this.closest('tr');
                        const id = tr.getAttribute('data-id');
                        const inputs = tr.querySelectorAll('input.cell-edit');
                        const data = {id: id};
                        inputs.forEach(inp=>{ data[inp.dataset.col]=inp.value; });
                        api('update_row', {table: table, data: JSON.stringify(data)}).then(res=>{
                            alert(res.message || JSON.stringify(res));
                            loadRows(table);
                        });
                    });
                });
            });
        }

        function api(action, body){
            body = body || {};
            const fd = new FormData();
            fd.append('action', action);
            for(const k in body) {
                if (body[k] !== undefined && body[k] !== null) {
                    fd.append(k, body[k]);
                }
            }
            return fetch('_act_db.php', {method:'POST', body: fd}).then(r=>r.json());
        }

        let usersTable;
        function initUsersTable(){
            usersTable = new Tabulator('#usersTable', {
                layout: 'fitColumns',
                placeholder: 'No users found',
                reactiveData: true,
                columns: [
                    {title:'ID', field:'id', width:60, headerSort:false},
                    {title:'Username', field:'username', editor:'input'},
                    {title:'Password', field:'password', editor:'input', formatter:function(cell){ return ''; }, placeholder:'(new password)'},
                    {title:'Admin', field:'is_admin', formatter:'tickCross', editor:'tickCross', hozAlign:'center'},
                    {title:'Manager', field:'is_manager', formatter:'tickCross', editor:'tickCross', hozAlign:'center'},
                    {title:'Enabled', field:'is_enabled', formatter:'tickCross', editor:'tickCross', hozAlign:'center'},
                    {title:'Created', field:'created_at', headerSort:false},
                    {title:'Updated', field:'updated_at', headerSort:false},
                    {title:'Actions', field:'actions', headerSort:false, formatter:function(){ return '<button class="deleteBtn">Delete</button>'; }, hozAlign:'center', cellClick:function(e, cell){
                        const row = cell.getRow();
                        const data = row.getData();
                        if (!confirm('Delete user "' + data.username + '"?')) return;
                        api('delete_user', {id:data.id}).then(res=>{
                            if(res.success){ row.delete(); }
                            alert(res.message || 'Server response');
                        });
                    }}
                ],
                cellEdited:function(cell){
                    const row = cell.getRow();
                    const data = row.getData();
                    const payload = {
                        id: data.id,
                        username: data.username,
                        is_admin: data.is_admin ? 1 : 0,
                        is_manager: data.is_manager ? 1 : 0,
                        is_enabled: data.is_enabled ? 1 : 0,
                    };
                    if (data.password && data.password.trim() !== '') {
                        payload.password = data.password;
                    }
                    api('update_user', payload).then(res=>{
                        if (!res.success) {
                            alert('Update error: ' + res.message);
                        } else {
                            loadUsers();
                        }
                    });
                }
            });
        }

        function loadUsers(){
            api('list_users').then(res=>{
                if(!res.success){ document.getElementById('usersTable').textContent = 'Error: '+res.message; return; }
                if (!usersTable) initUsersTable();
                const rows = res.data.users.map(user => ({...user, password: ''}));
                usersTable.setData(rows);
            });
        }

        function createUser(evt) {
            evt.preventDefault();
            const username = document.getElementById('newUsername').value.trim();
            const password = document.getElementById('newUserPassword').value;
            const isAdmin = document.getElementById('newIsAdmin').checked ? 1 : 0;
            const isManager = document.getElementById('newIsManager').checked ? 1 : 0;
            const isEnabled = document.getElementById('newIsEnabled').checked ? 1 : 0;
            const msgDiv = document.getElementById('createUserMessage');
            msgDiv.textContent = 'Creating...';

            const fd = new FormData();
            fd.append('action', 'create_user');
            fd.append('username', username);
            fd.append('password', password);
            fd.append('is_admin', isAdmin);
            fd.append('is_manager', isManager);
            fd.append('is_enabled', isEnabled);

            fetch('_act_db.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        msgDiv.textContent = 'User created successfully!';
                        document.getElementById('createUserForm').reset();
                        loadUsers();
                    } else {
                        msgDiv.textContent = 'Error: ' + res.message;
                    }
                })
                .catch(err => {
                    msgDiv.textContent = 'Server communication error.';
                    console.error(err);
                });
        }

        function generatePassword(length = 16) {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
            let password = '';
            const values = new Uint32Array(length);
            window.crypto.getRandomValues(values);
            for (let i = 0; i < length; i++) {
                password += chars[values[i] % chars.length];
            }
            return password;
        }

        document.getElementById('generatePasswordBtn').addEventListener('click', function(){
            const passwordInput = document.getElementById('newUserPassword');
            const generated = generatePassword(16);
            passwordInput.value = generated;
            const msgDiv = document.getElementById('createUserMessage');
            msgDiv.textContent = 'Password generated automatically.';
        });

        document.getElementById('logoutBtn').addEventListener('click', function(){
            fetch('_act.php', {method:'POST', body: new URLSearchParams({action:'logout'})}).then(()=>{
                document.cookie = 'mdash_user=; path=/; max-age=0';
                window.location.href = 'index.php';
            });
        });

        // init
        loadTables();
        loadUsers();
        document.getElementById('createUserForm').addEventListener('submit', createUser);
    </script>
</body>
</html>
