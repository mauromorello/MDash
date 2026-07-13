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

$pageTitle = 'Create User';
include __DIR__ . '/header.php';
?>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Create user</h1>
                <div class="meta">Dedicated admin page for adding new users with email.</div>
            </div>
            <a href="admin.php" class="btn-secondary">Back to admin</a>
        </div>

        <form id="createUserForm" class="card admin-form-card">
            <div class="form-grid">
                <div class="field">
                    <label for="newUsername">Username</label>
                    <input type="text" id="newUsername" required>
                </div>
                <div class="field">
                    <label for="newUserEmail">Email</label>
                    <input type="text" id="newUserEmail" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="newUserPassword">Password</label>
                    <input type="text" id="newUserPassword" required autocomplete="new-password">
                </div>
            </div>

            <div class="inline-actions admin-inline-actions-spaced">
                <label><input type="checkbox" id="newIsAdmin"> Admin</label>
                <label><input type="checkbox" id="newIsManager"> Manager</label>
                <label><input type="checkbox" id="newIsEnabled" checked> Enabled</label>
                <button type="button" id="generatePasswordBtn" class="secondary">Generate password</button>
            </div>

            <div class="inline-actions">
                <button type="submit">Add user</button>
                <div id="createUserMessage" class="meta-note meta-note-reset"></div>
            </div>
        </form>
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

        document.getElementById('generatePasswordBtn').addEventListener('click', function () {
            document.getElementById('newUserPassword').value = generatePassword(16);
            document.getElementById('createUserMessage').textContent = 'Password generated.';
        });

        document.getElementById('createUserForm').addEventListener('submit', function (evt) {
            evt.preventDefault();

            const payload = {
                username: document.getElementById('newUsername').value.trim(),
                email: document.getElementById('newUserEmail').value.trim(),
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
    </script>
</body>
</html>
