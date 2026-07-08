<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/ai_shared.php';

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

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdo = null;
$error = '';
$message = '';
$rows = [];
$showHidden = isset($_GET['show_hidden']) && (int)$_GET['show_hidden'] === 1;

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

    mdashEnsureAiDbTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_hidden') {
        $idAi = (int)($_POST['id_ai_db'] ?? 0);
        $hiddenValue = (int)($_POST['hidden_value'] ?? 0) === 1 ? 1 : 0;

        $stmt = $pdo->prepare('UPDATE ai_db SET is_hidden = ? WHERE id = ? AND id_owner = ?');
        $stmt->execute([$hiddenValue, $idAi, (int)$user['id']]);
        header('Location: ai_db.php?' . ($hiddenValue === 1 ? 'hidden=1' : 'revealed=1'));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ai_db') {
        $idAi = (int)($_POST['id_ai_db'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM ai_db WHERE id = ? AND id_owner = ?');
        $stmt->execute([$idAi, (int)$user['id']]);
        header('Location: ai_db.php?deleted=1');
        exit;
    }

    $where = $showHidden
        ? '((a.id_owner = :user_id) OR (a.is_public = 1 AND a.is_hidden = 0))'
        : '((a.id_owner = :user_id AND a.is_hidden = 0) OR (a.is_public = 1 AND a.is_hidden = 0))';

    $stmt = $pdo->prepare(
        'SELECT a.*, u.username AS owner_username
         FROM ai_db a
         LEFT JOIN users u ON u.id = a.id_owner
         WHERE ' . $where . '
         ORDER BY a.id DESC'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!empty($_GET['created'])) {
    $message = 'AI profile created successfully.';
} elseif (!empty($_GET['updated'])) {
    $message = 'AI profile updated successfully.';
} elseif (!empty($_GET['deleted'])) {
    $message = 'AI profile deleted successfully.';
} elseif (!empty($_GET['hidden'])) {
    $message = 'AI profile hidden successfully.';
} elseif (!empty($_GET['revealed'])) {
    $message = 'AI profile revealed successfully.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Profiles</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="user-ribbon">
        <a href="main.php" class="brand brand-home">Mdash</a>
        <div class="info">User: <?php echo h($user['username']); ?> | Login: <?php echo h($user['login_time'] ?? date('Y-m-d H:i:s')); ?></div>
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
                <h1>AI Profiles</h1>
                <div class="meta">Store reusable AI endpoints and keys for dashboard generation.</div>
            </div>
            <div class="inline-actions">
                <a href="insert_ai.php">New AI profile</a>
                <?php if ($showHidden): ?>
                    <a href="ai_db.php">Hide hidden</a>
                <?php else: ?>
                    <a href="ai_db.php?show_hidden=1">Reveal hidden</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (empty($rows)): ?>
            <div class="card">
                <p class="empty">No AI profiles found.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Provider</th>
                            <th>Model</th>
                            <th>Endpoint</th>
                            <th>Owner</th>
                            <th>Visibility</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo h($row['id']); ?></td>
                                <td><?php echo h($row['title']); ?></td>
                                <td><?php echo h($row['provider']); ?></td>
                                <td><?php echo h($row['model']); ?></td>
                                <td class="wrap-anywhere"><?php echo h($row['web_end_point']); ?></td>
                                <td><?php echo h(((int)$row['id_owner'] === (int)$user['id']) ? 'You' : ($row['owner_username'] ?: ('User #' . $row['id_owner']))); ?></td>
                                <td>
                                    <?php echo ((int)$row['is_public'] === 1) ? 'Public' : 'Private'; ?>
                                    <?php if ((int)$row['is_hidden'] === 1): ?>
                                        / Hidden
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$row['id_owner'] === (int)$user['id']): ?>
                                        <div class="inline-actions">
                                            <a href="edit_ai.php?id=<?php echo h($row['id']); ?>">Edit</a>
                                            <form method="post">
                                                <input type="hidden" name="action" value="set_hidden">
                                                <input type="hidden" name="id_ai_db" value="<?php echo h($row['id']); ?>">
                                                <input type="hidden" name="hidden_value" value="<?php echo ((int)$row['is_hidden'] === 1) ? '0' : '1'; ?>">
                                                <button type="submit" class="secondary"><?php echo ((int)$row['is_hidden'] === 1) ? 'Reveal' : 'Hide'; ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Delete this AI profile?');">
                                                <input type="hidden" name="action" value="delete_ai_db">
                                                <input type="hidden" name="id_ai_db" value="<?php echo h($row['id']); ?>">
                                                <button type="submit" class="btn-danger">Delete</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="meta">Read only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
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