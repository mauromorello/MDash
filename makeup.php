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

function normalizePalette(string $paletteRaw): array {
    $default = ['#2563EB', '#0F766E', '#7C3AED', '#F59E0B', '#DC2626'];
    $decoded = json_decode($paletteRaw, true);
    if (!is_array($decoded)) {
        return $default;
    }

    $colors = [];
    foreach ($decoded as $color) {
        $hex = strtoupper(trim((string)$color));
        if (preg_match('/^#[0-9A-F]{6}$/', $hex)) {
            $colors[] = $hex;
        }
        if (count($colors) === 5) {
            break;
        }
    }

    while (count($colors) < 5) {
        $colors[] = $default[count($colors)];
    }

    return $colors;
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS makeup (
            id_makeup INT NOT NULL,
            date_makeup DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_owner INT NOT NULL,
            prompt_makeup TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            is_private INT NOT NULL DEFAULT 1,
            is_hidden INT NOT NULL DEFAULT 0,
            name VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            palette TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            PRIMARY KEY (id_makeup),
            INDEX idx_makeup_owner (id_owner),
            INDEX idx_makeup_private_hidden (is_private, is_hidden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_hidden') {
        $idMakeup = (int)($_POST['id_makeup'] ?? 0);
        $hiddenValue = (int)($_POST['hidden_value'] ?? 0) === 1 ? 1 : 0;

        $stmt = $pdo->prepare('UPDATE makeup SET is_hidden = ? WHERE id_makeup = ? AND id_owner = ?');
        $stmt->execute([$hiddenValue, $idMakeup, (int)$user['id']]);
        $message = $hiddenValue === 1 ? 'Makeup hidden successfully.' : 'Makeup revealed successfully.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_makeup') {
        $idMakeup = (int)($_POST['id_makeup'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM makeup WHERE id_makeup = ? AND id_owner = ?');
        $stmt->execute([$idMakeup, (int)$user['id']]);
        $message = 'Makeup deleted successfully.';
    }

    $where = $showHidden
        ? '((m.id_owner = :user_id) OR (m.is_private = 0 AND m.is_hidden = 0))'
        : '((m.id_owner = :user_id AND m.is_hidden = 0) OR (m.is_private = 0 AND m.is_hidden = 0))';

    $stmt = $pdo->prepare(
        'SELECT m.*, u.username AS owner_username
         FROM makeup m
         LEFT JOIN users u ON u.id = m.id_owner
         WHERE ' . $where . '
         ORDER BY m.id_makeup DESC'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Makeup Library</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Makeup Library</h1>
                <div class="meta">Manage your makeup profiles and browse public ones.</div>
            </div>
            <div class="inline-actions">
                <a href="insert_makeup.php">New makeup</a>
                <?php if ($showHidden): ?>
                    <a href="makeup.php">Hide hidden</a>
                <?php else: ?>
                    <a href="makeup.php?show_hidden=1">Reveal hidden</a>
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
                <p class="empty">No makeup profiles found.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Palette</th>
                            <th>Owner</th>
                            <th>Visibility</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php $palette = normalizePalette((string)($row['palette'] ?? '[]')); ?>
                            <tr>
                                <td><?php echo h($row['id_makeup']); ?></td>
                                <td><?php echo h($row['name']); ?></td>
                                <td>
                                    <div class="palette-mini-row">
                                        <?php foreach ($palette as $color): ?>
                                            <span class="palette-mini-swatch" style="background: <?php echo h($color); ?>;"></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?php echo h(((int)$row['id_owner'] === (int)$user['id']) ? 'You' : ($row['owner_username'] ?: ('User #' . $row['id_owner']))); ?></td>
                                <td>
                                    <?php echo ((int)$row['is_private'] === 1) ? 'Private' : 'Public'; ?>
                                    <?php if ((int)$row['is_hidden'] === 1): ?>
                                        / Hidden
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$row['id_owner'] === (int)$user['id']): ?>
                                        <div class="inline-actions">
                                            <a href="edit_makeup.php?id=<?php echo h($row['id_makeup']); ?>">Edit</a>
                                            <form method="post">
                                                <input type="hidden" name="action" value="set_hidden">
                                                <input type="hidden" name="id_makeup" value="<?php echo h($row['id_makeup']); ?>">
                                                <input type="hidden" name="hidden_value" value="<?php echo ((int)$row['is_hidden'] === 1) ? '0' : '1'; ?>">
                                                <button type="submit" class="secondary"><?php echo ((int)$row['is_hidden'] === 1) ? 'Reveal' : 'Hide'; ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Delete this makeup profile?');">
                                                <input type="hidden" name="action" value="delete_makeup">
                                                <input type="hidden" name="id_makeup" value="<?php echo h($row['id_makeup']); ?>">
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

