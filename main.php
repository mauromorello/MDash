<?php
session_start();

function getUserFromSessionOrCookie() {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['username'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'login_time' => $_SESSION['login_time'] ?? null,
            'is_admin' => $_SESSION['is_admin'] ?? 0,
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

$user = getUserFromSessionOrCookie();
if (!$user) {
    header('Location: index.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main</title>
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .topbar { background: #222; color: #fff; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
        .topbar .brand { font-size: 1rem; font-weight: bold; }
        .topbar .info { font-size: 0.95rem; }
        .topbar button { background: #e53935; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; }
        .main-content { padding: 20px; }
    </style>
</head>
<body>
    <div class="topbar">
        <div>
            <div class="brand">MDash</div>
            <div class="info">Utente: <?php echo h($user['username']); ?> | Login: <?php echo h($user['login_time'] ?? date('Y-m-d H:i:s')); ?></div>
        </div>
        <?php if (!empty($user['is_admin'])): ?>
            <div><a href="admin.php" style="color:#fff; text-decoration:none; margin-left:20px;">Admin Console</a></div>
        <?php endif; ?>
        <button id="logoutBtn">Logout</button>
    </div>
    <div class="main-content">
        <div class="page" style="margin:0; padding:24px; box-shadow:none;">
            <h1>Benvenuto</h1>
            <p>Seleziona una delle azioni disponibili per iniziare.</p>
            <div class="grid" style="margin-top:24px;">
                <a href="upload.php" class="btn btn-primary">Upload</a>
                <a href="database_list.php" class="btn btn-secondary">Data pool</a>
                <a href="dashboard_builder.php" class="btn btn-accent">Dashboard builder</a>
                <a href="dashboards.php" class="btn btn-warning">Dashboard pool</a>
                <a href="final.php" class="btn btn-success">My ready dashboards</a>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('logoutBtn').addEventListener('click', function () {
            fetch('_act.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'logout' })
            }).finally(() => {
                document.cookie = 'mdash_user=; path=/; max-age=0';
                window.location.href = 'index.php';
            });
        });
    </script>
</body>
</html>
