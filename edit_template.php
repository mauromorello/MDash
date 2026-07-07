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

$templateId = (int)($_GET['id'] ?? 0);
if ($templateId <= 0) {
    header('Location: templates.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica template</title>
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
                <h1>Modifica template</h1>
                <div class="meta">Aggiorna titolo e prompt del template selezionato.</div>
            </div>
            <a href="templates.php">Torna ai template</a>
        </div>

        <div id="messageBox" class="message" style="display:none;"></div>

        <div class="card">
            <form id="editTemplateForm">
                <input type="hidden" id="template_id" value="<?php echo h($templateId); ?>">

                <div class="field">
                    <label for="title">Titolo</label>
                    <input type="text" id="title" name="title" maxlength="255" required>
                </div>

                <div class="field">
                    <label for="prompt">Prompt</label>
                    <textarea id="prompt" name="prompt"></textarea>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="is_public">Visibilità</label>
                        <select id="is_public" name="is_public">
                            <option value="0">Privato</option>
                            <option value="1">Pubblico</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="is_hidden">Stato</label>
                        <select id="is_hidden" name="is_hidden">
                            <option value="0">Visibile</option>
                            <option value="1">Nascosto</option>
                        </select>
                    </div>
                </div>

                <div class="inline-actions">
                    <button type="submit">Salva modifiche</button>
                    <a href="templates.php" class="btn-secondary">Annulla</a>
                </div>
            </form>
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

        function loadTemplate() {
            const id = document.getElementById('template_id').value;
            api('get_template', { id: id }).then(function (res) {
                if (!res.success || !res.data || !res.data.template) {
                    showMessage(res.message || 'Template non trovato.', true);
                    return;
                }
                document.getElementById('title').value = res.data.template.title || '';
                document.getElementById('prompt').value = res.data.template.prompt || '';
                document.getElementById('is_public').value = Number(res.data.template.is_public || 0) === 1 ? '1' : '0';
                document.getElementById('is_hidden').value = Number(res.data.template.is_hidden || 0) === 1 ? '1' : '0';
            }).catch(function () {
                showMessage('Errore di rete durante caricamento template.', true);
            });
        }

        document.getElementById('editTemplateForm').addEventListener('submit', function (event) {
            event.preventDefault();
            const id = document.getElementById('template_id').value;
            const title = document.getElementById('title').value.trim();
            const prompt = document.getElementById('prompt').value.trim();
            const isPublic = Number(document.getElementById('is_public').value) === 1 ? 1 : 0;
            const isHidden = Number(document.getElementById('is_hidden').value) === 1 ? 1 : 0;

            if (!title) {
                showMessage('Il titolo del template è obbligatorio.', true);
                return;
            }

            api('update_template', { id: id, title: title, prompt: prompt, is_public: isPublic, is_hidden: isHidden }).then(function (res) {
                if (!res.success) {
                    showMessage(res.message || 'Errore aggiornamento template.', true);
                    return;
                }
                showMessage('Template aggiornato correttamente.', false);
            }).catch(function () {
                showMessage('Errore di rete durante aggiornamento template.', true);
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

        loadTemplate();
    </script>
</body>
</html>
