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
            <h1>Welcome</h1>
            <p>Select a section to continue your workflow.</p>

            <div class="main-sections-grid">
                <div class="main-section-card">
                    <h2>Data Sources</h2>
                    <div class="main-action-item">
                        <a href="upload.php" class="btn btn-primary">Upload Files</a>
                        <p class="main-action-desc">Upload new CSV files and create source records.</p>
                    </div>
                    <div class="main-action-item">
                        <a href="database_list.php" class="btn btn-secondary">Data Pool</a>
                        <p class="main-action-desc">Browse and manage your available data sources.</p>
                    </div>
                </div>

                <div class="main-section-card">
                    <h2>Templates</h2>
                    <div class="main-action-item">
                        <a href="templates.php" class="btn btn-secondary">Template Library</a>
                        <p class="main-action-desc">Create reusable prompts and publish shared templates.</p>
                    </div>
                </div>

                <div class="main-section-card">
                    <h2>Dashboard Builder</h2>
                    <div class="main-action-item">
                        <a href="dashboard_builder.php" class="btn btn-accent">Create Dashboard</a>
                        <p class="main-action-desc">Build a dashboard by combining data, rules, and templates.</p>
                    </div>
                </div>

                <div class="main-section-card">
                    <h2>Dashboards</h2>
                    <div class="main-action-item">
                        <a href="dashboards.php" class="btn btn-warning">Dashboard Pool</a>
                        <p class="main-action-desc">Review, edit, and generate prompts from saved dashboards.</p>
                    </div>
                    <div class="main-action-item">
                        <a href="final.php" class="btn btn-primary">Ready Dashboards</a>
                        <p class="main-action-desc">Open your generated dashboard outputs ready to use.</p>
                    </div>
                </div>
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
