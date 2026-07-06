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
            'is_admin' => (int)($_SESSION['is_admin'] ?? 0),
        ];
    }

    if (!empty($_COOKIE['mdash_user'])) {
        $user = json_decode(urldecode($_COOKIE['mdash_user']), true);
        if (is_array($user) && !empty($user['id'])) {
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'] ?? 'utente',
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

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdo = null;
$dashboards = [];
$error = '';
$message = '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dashboards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_datasource INT DEFAULT NULL,
            id_makeup INT NOT NULL DEFAULT 0,
            data_filter_prompt TEXT NOT NULL,
            data_manipulation_prompt TEXT NOT NULL,
            dashboard_prompt_1 TEXT NOT NULL,
            dashboard_prompt_2 TEXT NOT NULL,
            id_template INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_dashboard' && $pdo) {
    $dashboardId = (int)($_POST['id'] ?? 0);
    if ($dashboardId > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM dashboards WHERE id = ?');
            $stmt->execute([$dashboardId]);
            header('Location: dashboards.php?deleted=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Errore durante l\'eliminazione della dashboard: ' . $e->getMessage();
        }
    }
}

if ($pdo) {
    try {
        $stmt = $pdo->query(
            'SELECT d.*, u.filename AS datasource_filename FROM dashboards d LEFT JOIN uploads u ON u.id = d.id_datasource ORDER BY d.id DESC'
        );
        $dashboards = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Errore durante la lettura delle dashboard: ' . $e->getMessage();
    }
}

if (!empty($_GET['created'])) {
    $message = 'Dashboard creata correttamente.';
} elseif (!empty($_GET['deleted'])) {
    $message = 'Dashboard eliminata correttamente.';
} elseif (!empty($_GET['updated'])) {
    $message = 'Dashboard aggiornata correttamente.';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elenco dashboard</title>
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
                <h1>Elenco dashboard</h1>
                <div class="meta">Gestisci le dashboard create, controlla i prompt e modifica i dettagli.</div>
            </div>
            <a href="dashboard_builder.php">Nuova dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (empty($dashboards)): ?>
            <div class="card">
                <p>Non sono ancora presenti dashboard.</p>
                <p><a href="dashboard_builder.php">Crea la prima dashboard</a></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Titolo</th>
                            <th>Data creazione</th>
                            <th>Datasource</th>
                            <th>ID makeup</th>
                            <th>ID template</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dashboards as $dashboard): ?>
                            <tr>
                                <td><?php echo h($dashboard['id']); ?></td>
                                <td><?php echo h($dashboard['title']); ?></td>
                                <td><?php echo h($dashboard['date_creation']); ?></td>
                                <td>
                                    <?php if (!empty($dashboard['id_datasource'])): ?>
                                        #<?php echo h($dashboard['id_datasource']); ?>
                                        <?php if (!empty($dashboard['datasource_filename'])): ?>
                                            <div class="meta"><?php echo h($dashboard['datasource_filename']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($dashboard['id_makeup']); ?></td>
                                <td><?php echo h($dashboard['id_template']); ?></td>
                                <td>
                                    <div class="inline-actions">
                                        <a href="dashboard_prompt.php?id=<?php echo h($dashboard['id']); ?>">Genera prompt</a>
                                        <button type="button" class="secondary preview-toggle" data-target="preview-<?php echo h($dashboard['id']); ?>">Preview prompt</button>
                                        <a href="edit_dashboard.php?id=<?php echo h($dashboard['id']); ?>">Modifica</a>
                                        <form method="post" onsubmit="return confirm('Eliminare questa dashboard?');" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_dashboard">
                                            <input type="hidden" name="id" value="<?php echo h($dashboard['id']); ?>">
                                            <button type="submit" class="btn-danger">Elimina</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="preview-<?php echo h($dashboard['id']); ?>" class="preview-row">
                                <td colspan="7">
                                    <div class="preview-panel">
                                        <h3>Data filter prompt</h3>
                                        <p><?php echo h($dashboard['data_filter_prompt']); ?></p>
                                        <h3>Data manipulation prompt</h3>
                                        <p><?php echo h($dashboard['data_manipulation_prompt']); ?></p>
                                        <h3>Dashboard prompt 1</h3>
                                        <p><?php echo h($dashboard['dashboard_prompt_1']); ?></p>
                                        <h3>Dashboard prompt 2</h3>
                                        <p><?php echo h($dashboard['dashboard_prompt_2']); ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>
        document.querySelectorAll('.preview-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = button.getAttribute('data-target');
                const row = document.getElementById(targetId);
                if (!row) {
                    return;
                }
                row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
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
