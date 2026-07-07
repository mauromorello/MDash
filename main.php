<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

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
</head>
<body>
    <div class="user-ribbon">
        <div>
            <a href="main.php" class="brand brand-home">Mdash</a>
            <div class="info">Utente: <?php echo h($user['username']); ?> | Login: <?php echo h($user['login_time'] ?? date('Y-m-d H:i:s')); ?></div>
        </div>
        <div class="actions">
            <?php if (!empty($user['is_admin'])): ?>
                <a href="admin.php">Admin Console</a>
            <?php endif; ?>
            <button id="logoutBtn" type="button">Logout</button>
        </div>
    </div>
    <div class="main-home-content">
        <div class="page main-home-panel">
            <h1>Benvenuto</h1>
            <p>Seleziona una delle azioni disponibili per iniziare.</p>
            <div class="grid main-home-actions">
                <a href="upload.php" class="btn btn-primary">Upload</a>
                <a href="database_list.php" class="btn btn-secondary">Data pool</a>
                <a href="dashboard_builder.php" class="btn btn-accent">Dashboard builder</a>
                <a href="dashboards.php" class="btn btn-warning">Dashboard pool</a>
                <a href="templates.php" class="btn btn-secondary">Templates</a>
                <a href="final.php" class="btn btn-primary">My ready dashboards</a>
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
