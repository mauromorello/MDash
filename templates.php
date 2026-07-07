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
            'username' => $_SESSION['username'],
            'login_time' => $_SESSION['login_time'] ?? null,
            'is_admin' => (int)($_SESSION['is_admin'] ?? 0),
        ];
    }

    if (!empty($_COOKIE['mdash_user'])) {
        $user = json_decode(urldecode($_COOKIE['mdash_user']), true);
        if (is_array($user) && !empty($user['id'])) {
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'] ?? 'utente',
                'login_time' => $user['login_time'] ?? null,
                'is_admin' => (int)($user['is_admin'] ?? 0),
            ];
        }
    }

    return null;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$user = getUserFromSessionOrCookie();
if (!$user) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Templates</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="user-ribbon">
        <a href="main.php" class="brand brand-home">Mdash</a>
        <div class="info">Utente: <?php echo h($user['username']); ?> | Login: <?php echo h($user['login_time'] ?? date('Y-m-d H:i:s')); ?></div>
        <div class="actions">
            <?php if (!empty($user['is_admin'])): ?>
                <a href="admin.php">Admin Console</a>
            <?php endif; ?>
            <button id="logoutBtn" type="button">Logout</button>
        </div>
    </div>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Templates</h1>
                <div class="meta">Crea, modifica ed elimina i template prompt da usare nel builder dashboard.</div>
            </div>
            <a href="dashboard_builder.php">Vai al dashboard builder</a>
        </div>

        <div id="messageBox" class="message" style="display:none;"></div>

        <div class="card">
            <h2 style="margin-top:0;">Nuovo template</h2>
            <form id="createTemplateForm">
                <div class="field">
                    <label for="title">Titolo</label>
                    <input type="text" id="title" name="title" maxlength="255" required>
                </div>
                <div class="field">
                    <label for="prompt">Prompt</label>
                    <textarea id="prompt" name="prompt" placeholder="Inserisci il prompt template"></textarea>
                </div>
                <button type="submit">Salva template</button>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Elenco template</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Titolo</th>
                            <th>Prompt</th>
                            <th>Data</th>
                            <th>Owner</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody id="templatesRows">
                        <tr><td colspan="6" class="empty">Caricamento template...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function showMessage(text, isError) {
            const box = document.getElementById('messageBox');
            box.textContent = text;
            box.className = 'message' + (isError ? ' error' : '');
            box.style.display = 'block';
        }

        function api(action, payload) {
            const fd = new FormData();
            fd.append('action', action);
            Object.keys(payload || {}).forEach(function (key) {
                if (payload[key] !== undefined && payload[key] !== null) {
                    fd.append(key, payload[key]);
                }
            });
            return fetch('_act_db.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
        }

        function renderRows(rows) {
            const body = document.getElementById('templatesRows');
            if (!rows || rows.length === 0) {
                body.innerHTML = '<tr><td colspan="6" class="empty">Nessun template disponibile.</td></tr>';
                return;
            }

            body.innerHTML = '';
            rows.forEach(function (row) {
                const tr = document.createElement('tr');
                const promptPreview = (row.prompt || '').length > 160 ? (row.prompt || '').slice(0, 160) + '...' : (row.prompt || '');

                tr.innerHTML = '' +
                    '<td>' + String(row.id || '') + '</td>' +
                    '<td>' + String(row.title || '') + '</td>' +
                    '<td><div style="white-space:pre-wrap;">' + String(promptPreview || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div></td>' +
                    '<td>' + String(row.date || '') + '</td>' +
                    '<td>' + String(row.id_owner || '') + '</td>' +
                    '<td>' +
                        '<div class="inline-actions">' +
                            '<a href="edit_template.php?id=' + encodeURIComponent(row.id) + '">Modifica</a>' +
                            '<button type="button" class="btn-danger delete-template" data-id="' + String(row.id || '') + '">Elimina</button>' +
                        '</div>' +
                    '</td>';

                body.appendChild(tr);
            });

            document.querySelectorAll('.delete-template').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const id = btn.getAttribute('data-id');
                    if (!id) {
                        return;
                    }
                    if (!confirm('Eliminare questo template?')) {
                        return;
                    }
                    api('delete_template', { id: id }).then(function (res) {
                        if (!res.success) {
                            showMessage(res.message || 'Errore eliminazione template.', true);
                            return;
                        }
                        showMessage('Template eliminato correttamente.', false);
                        loadTemplates();
                    }).catch(function () {
                        showMessage('Errore di rete durante eliminazione template.', true);
                    });
                });
            });
        }

        function loadTemplates() {
            api('list_templates', {}).then(function (res) {
                if (!res.success) {
                    showMessage(res.message || 'Errore caricamento template.', true);
                    renderRows([]);
                    return;
                }
                renderRows((res.data && res.data.templates) ? res.data.templates : []);
            }).catch(function () {
                showMessage('Errore di rete durante caricamento template.', true);
                renderRows([]);
            });
        }

        document.getElementById('createTemplateForm').addEventListener('submit', function (event) {
            event.preventDefault();
            const title = document.getElementById('title').value.trim();
            const prompt = document.getElementById('prompt').value.trim();

            if (!title) {
                showMessage('Il titolo del template è obbligatorio.', true);
                return;
            }

            api('create_template', { title: title, prompt: prompt }).then(function (res) {
                if (!res.success) {
                    showMessage(res.message || 'Errore creazione template.', true);
                    return;
                }
                showMessage('Template creato correttamente.', false);
                document.getElementById('createTemplateForm').reset();
                loadTemplates();
            }).catch(function () {
                showMessage('Errore di rete durante creazione template.', true);
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

        loadTemplates();
    </script>
</body>
</html>
