<?php
session_start();

function getUserFromSessionOrCookie() {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['username'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'login_time' => $_SESSION['login_time'] ?? null,
        ];
    }

    if (!empty($_COOKIE['mdash_user'])) {
        $user = json_decode(urldecode($_COOKIE['mdash_user']), true);
        if (is_array($user) && !empty($user['id'])) {
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'] ?? 'utente',
                'login_time' => $user['login_time'] ?? null,
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
    <style>
        body { margin: 0; font-family: Arial, sans-serif; }
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
        <button id="logoutBtn">Logout</button>
    </div>
    <div class="main-content">
        <h1>Benvenuto</h1>
        <p>Questa è la pagina principale.</p>
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
