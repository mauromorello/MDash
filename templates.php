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
                'username' => $user['username'] ?? 'user',
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Templates</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Templates</h1>
                <div class="meta"><span class="pill">My templates</span> Create, edit, and remove prompt templates for the dashboard builder.</div>
            </div>
            <a href="dashboard_builder.php">Go to dashboard builder</a>
        </div>

        <div id="messageBox" class="message hidden"></div>

        <div class="card">
            <h2 class="compact-title">New template</h2>
            <form id="createTemplateForm">
                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" maxlength="255" required>
                </div>
                <div class="field">
                    <label for="prompt">Prompt</label>
                    <textarea id="prompt" name="prompt" placeholder="Enter template prompt"></textarea>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="is_public">Visibility</label>
                        <select id="is_public" name="is_public">
                            <option value="0">Private</option>
                            <option value="1">Public</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="is_hidden">Status</label>
                        <select id="is_hidden" name="is_hidden">
                            <option value="0">Visible</option>
                            <option value="1">Hidden</option>
                        </select>
                    </div>
                </div>
                <button type="submit">Save template</button>
            </form>
        </div>

        <div class="card">
            <h2 class="compact-title">Template list</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Prompt</th>
                            <th>Date</th>
                            <th>Creator</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="templatesRows">
                        <tr><td colspan="7" class="empty">Loading templates...</td></tr>
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
            box.classList.remove('hidden');
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
                body.innerHTML = '<tr><td colspan="7" class="empty">No templates available.</td></tr>';
                return;
            }

            body.innerHTML = '';
            rows.forEach(function (row) {
                const tr = document.createElement('tr');
                const promptPreview = (row.prompt || '').length > 160 ? (row.prompt || '').slice(0, 160) + '...' : (row.prompt || '');
                const isOwner = Number(row.is_owner || 0) === 1;
                const isPublic = Number(row.is_public || 0) === 1;
                const isHidden = Number(row.is_hidden || 0) === 1;
                const creator = row.owner_username || ('User #' + String(row.id_owner || ''));

                let statusText = isPublic ? 'Public' : 'Private';
                if (isHidden) {
                    statusText += ' | Hidden';
                }

                const actionsHtml = isOwner
                    ? '<div class="inline-actions">' +
                        '<a href="edit_template.php?id=' + encodeURIComponent(row.id) + '">Edit</a>' +
                        '<button type="button" class="btn-danger delete-template" data-id="' + String(row.id || '') + '">Delete</button>' +
                      '</div>'
                    : '<span class="meta">Read only</span>';

                tr.innerHTML = '' +
                    '<td>' + String(row.id || '') + '</td>' +
                    '<td>' + String(row.title || '') + '</td>' +
                    '<td><div class="prompt-preview">' + String(promptPreview || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div></td>' +
                    '<td>' + String(row.date || '') + '</td>' +
                    '<td>' + (isPublic ? String(creator).replace(/</g, '&lt;').replace(/>/g, '&gt;') : '-') + '</td>' +
                    '<td>' + statusText + '</td>' +
                    '<td>' + actionsHtml + '</td>';

                body.appendChild(tr);
            });

            document.querySelectorAll('.delete-template').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const id = btn.getAttribute('data-id');
                    if (!id) {
                        return;
                    }
                    if (!confirm('Delete this template?')) {
                        return;
                    }
                    api('delete_template', { id: id }).then(function (res) {
                        if (!res.success) {
                            showMessage(res.message || 'Template delete error.', true);
                            return;
                        }
                        showMessage('Template deleted successfully.', false);
                        loadTemplates();
                    }).catch(function () {
                        showMessage('Network error while deleting template.', true);
                    });
                });
            });
        }

        function loadTemplates() {
            api('list_templates', {}).then(function (res) {
                if (!res.success) {
                    showMessage(res.message || 'Template loading error.', true);
                    renderRows([]);
                    return;
                }
                renderRows((res.data && res.data.templates) ? res.data.templates : []);
            }).catch(function () {
                showMessage('Network error while loading templates.', true);
                renderRows([]);
            });
        }

        document.getElementById('createTemplateForm').addEventListener('submit', function (event) {
            event.preventDefault();
            const title = document.getElementById('title').value.trim();
            const prompt = document.getElementById('prompt').value.trim();
            const isPublic = Number(document.getElementById('is_public').value) === 1 ? 1 : 0;
            const isHidden = Number(document.getElementById('is_hidden').value) === 1 ? 1 : 0;

            if (!title) {
                showMessage('Template title is required.', true);
                return;
            }

            api('create_template', { title: title, prompt: prompt, is_public: isPublic, is_hidden: isHidden }).then(function (res) {
                if (!res.success) {
                    showMessage(res.message || 'Template creation error.', true);
                    return;
                }
                showMessage('Template created successfully.', false);
                document.getElementById('createTemplateForm').reset();
                loadTemplates();
            }).catch(function () {
                showMessage('Network error while creating template.', true);
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

