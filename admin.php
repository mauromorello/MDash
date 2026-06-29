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
    <style>
        body{font-family:Arial, Helvetica, sans-serif; padding:16px}
        #tables button{display:block;margin:6px 0}
        table{border-collapse:collapse;width:100%;}
        table td, table th{border:1px solid #ccc;padding:6px}
        .row-actions{white-space:nowrap}
        input.cell-edit{width:100%}
    </style>
</head>
<body>
    <h2>Admin Console</h2>
    <p>Benvenuto, <?php echo htmlspecialchars($me['username']); ?> (id <?php echo htmlspecialchars($me['id']); ?>) — <button id="logoutBtn">Logout</button></p>

    <h3>Liste tabelle</h3>
    <div id="tables"></div>

    <h3 id="tableTitle" style="display:none">Tabella: <span id="tableName"></span></h3>
    <div id="tableSchema"></div>
    <div id="tableRows"></div>

    <script>
        function api(action, body){
            body = body || {};
            const fd = new FormData();
            fd.append('action', action);
            for(const k in body) fd.append(k, body[k]);
            return fetch('_act_db.php', {method:'POST', body: fd}).then(r=>r.json());
        }

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

        document.getElementById('logoutBtn').addEventListener('click', function(){
            fetch('_act.php', {method:'POST', body: new URLSearchParams({action:'logout'})}).then(()=>{
                document.cookie = 'mdash_user=; path=/; max-age=0';
                window.location.href = 'index.php';
            });
        });

        // init
        loadTables();
    </script>
</body>
</html>
