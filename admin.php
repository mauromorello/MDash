<?php
// pagina admin: controlla cookie/session per autenticazione
session_start();

function getUserFromCookie(){
    if (!empty($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'utente',
            'is_admin' => $_SESSION['is_admin'] ?? 0,
        ];
    }
    if (empty($_COOKIE['mdash_user'])) return null;
    $u = json_decode(urldecode($_COOKIE['mdash_user']), true);
    if (!is_array($u) || empty($u['id'])) return null;
    return [
        'id' => (int)$u['id'],
        'username' => $u['username'] ?? 'utente',
        'is_admin' => (int)($u['is_admin'] ?? 0),
    ];
}

$me = getUserFromCookie();
if (!$me) {
    header('Location: index.php');
    exit;
}
if ((int)$me['is_admin'] !== 1) {
    echo "<h2>Accesso non autorizzato</h2><p>Hai bisogno dei privilegi admin.</p>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin</title>
    <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@5.4.4/dist/css/tabulator.min.css">
    <style>
        body{font-family:Arial, Helvetica, sans-serif; padding:16px}
        #tables button{display:block;margin:6px 0}
        table{border-collapse:collapse;width:100%;}
        table td, table th{border:1px solid #ccc;padding:6px}
        .row-actions{white-space:nowrap}
        input.cell-edit{width:100%}
        #user-management { margin-top: 24px; border-top: 1px solid #ccc; padding-top: 16px; }
        #createUserForm input { display: block; margin-bottom: 8px; padding: 6px; min-width: 200px; }
        .tabulator-cell .tabulator-edit-select, .tabulator-cell .tabulator-edit-input { width: 100%; box-sizing: border-box; }
    </style>
    <script src="https://unpkg.com/tabulator-tables@5.4.4/dist/js/tabulator.min.js"></script>
</head>
<body>
    <h2>Admin Console</h2>
    <p>Benvenuto, <?php echo htmlspecialchars($me['username']); ?> (id <?php echo htmlspecialchars($me['id']); ?>) — <button id="logoutBtn">Logout</button></p>

    <div id="user-management">
        <h3>Gestione Utenti</h3>
        <div id="create-user-panel">
            <h4>Crea nuovo utente</h4>
            <form id="createUserForm">
                <input type="text" id="newUsername" placeholder="Username" required>
                <input type="password" id="newUserPassword" placeholder="Password" required>
                <label><input type="checkbox" id="newIsAdmin"> Admin</label>
                <label><input type="checkbox" id="newIsManager"> Manager</label>
                <label><input type="checkbox" id="newIsEnabled" checked> Abilitato</label>
                <button type="submit">Crea utente</button>
            </form>
            <div id="createUserMessage"></div>
        </div>
        <div id="usersTable"></div>
    </div>

    <h3>Liste tabelle</h3>
    <div id="tables"></div>

    <h3 id="tableTitle" style="display:none">Tabella: <span id="tableName"></span></h3>
    <div id="tableSchema"></div>
    <div id="tableRows"></div>

    <script>
        function loadTables(){
            const cont = document.getElementById('tables');
            cont.innerHTML = 'Caricamento...';
            api('list_tables').then(res=>{
                if(!res.success){ cont.innerHTML = 'Nessuna tabella: '+res.message; return; }
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
            document.getElementById('tableSchema').innerHTML = 'Caricamento schema...';
            document.getElementById('tableRows').innerHTML = '';
            api('get_schema', {table: table}).then(res=>{
                if(!res.success){ document.getElementById('tableSchema').innerText = res.message; return; }
                let html = '<table><tr>' + res.data.columns.map(c=>'<th>'+c.Field+'</th>').join('') + '<th>Azioni</th></tr>';
                html += '</table>';
                document.getElementById('tableSchema').innerHTML = html;
                loadRows(table);
            });
        }

        function loadRows(table){
            document.getElementById('tableRows').innerHTML = 'Caricamento righe...';
            api('get_rows', {table: table, limit: 200}).then(res=>{
                if(!res.success){ document.getElementById('tableRows').innerText = res.message; return; }
                const rows = res.data.rows;
                if (!rows || rows.length===0){ document.getElementById('tableRows').innerText = 'Nessun record.'; return; }
                const cols = Object.keys(rows[0]);
                let html = '<table><thead><tr>' + cols.map(c=>'<th>'+c+'</th>').join('') + '<th>Azioni</th></tr></thead><tbody>';
                rows.forEach(r=>{
                    html += '<tr data-id="'+(r.id||'')+'">';
                    cols.forEach(c=>{
                        const val = r[c]===null? '': String(r[c]);
                        html += '<td><input class="cell-edit" data-col="'+c+'" value="'+val.replace(/"/g,'&quot;')+'" /></td>';
                    });
                    html += '<td class="row-actions"><button class="save">Salva</button></td>';
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
                placeholder: 'Nessun utente trovato',
                reactiveData: true,
                columns: [
                    {title:'ID', field:'id', width:60, headerSort:false},
                    {title:'Username', field:'username', editor:'input'},
                    {title:'Admin', field:'is_admin', formatter:'tickCross', editor:'tickCross', hozAlign:'center'},
                    {title:'Manager', field:'is_manager', formatter:'tickCross', editor:'tickCross', hozAlign:'center'},
                    {title:'Abilitato', field:'is_enabled', formatter:'tickCross', editor:'tickCross', hozAlign:'center'},
                    {title:'Creato', field:'created_at', headerSort:false},
                    {title:'Aggiornato', field:'updated_at', headerSort:false},
                    {title:'Azioni', field:'actions', headerSort:false, formatter:function(){ return '<button class="deleteBtn">Elimina</button>'; }, hozAlign:'center', cellClick:function(e, cell){
                        const row = cell.getRow();
                        const data = row.getData();
                        if (!confirm('Eliminare l\'utente "' + data.username + '"?')) return;
                        api('delete_user', {id:data.id}).then(res=>{
                            if(res.success){ row.delete(); }
                            alert(res.message || 'Risposta server');
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
                    api('update_user', payload).then(res=>{
                        if (!res.success) {
                            alert('Errore aggiornamento: ' + res.message);
                        } else {
                            loadUsers();
                        }
                    });
                }
            });
        }

        function loadUsers(){
            api('list_users').then(res=>{
                if(!res.success){ document.getElementById('usersTable').textContent = 'Errore: '+res.message; return; }
                if (!usersTable) initUsersTable();
                usersTable.setData(res.data.users);
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
            msgDiv.textContent = 'Creazione...';

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
                        msgDiv.textContent = 'Utente creato con successo!';
                        document.getElementById('createUserForm').reset();
                        loadUsers();
                    } else {
                        msgDiv.textContent = 'Errore: ' + res.message;
                    }
                })
                .catch(err => {
                    msgDiv.textContent = 'Errore di comunicazione col server.';
                    console.error(err);
                });
        }

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
