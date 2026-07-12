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
                'username' => $user['username'] ?? 'user',
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
$uploads = [];
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
        "CREATE TABLE IF NOT EXISTS uploads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            tags VARCHAR(255) NOT NULL,
            long_description TEXT NOT NULL,
            prompt_1 MEDIUMTEXT NOT NULL,
            data_discovery_prompt MEDIUMTEXT NOT NULL,
            prompt_2 MEDIUMTEXT NOT NULL,
            id_owner INT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt = $pdo->prepare(
        'SELECT u.*, usr.username AS owner_username FROM uploads u LEFT JOIN users usr ON usr.id = u.id_owner WHERE (u.id_owner = ? OR u.is_public = 1) ORDER BY u.id DESC'
    );
    $stmt->execute([(int)$user['id']]);
    $uploads = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_upload' && $pdo) {
    $uploadId = (int)($_POST['id'] ?? 0);
    if ($uploadId > 0) {
        $stmt = $pdo->prepare('SELECT id_owner, filename, path FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1');
        $stmt->execute([$uploadId, (int)$user['id']]);
        $uploadRow = $stmt->fetch();

        if ($uploadRow) {
            $filePath = __DIR__ . DIRECTORY_SEPARATOR . ltrim((string)$uploadRow['path'], '/\\');
            $baseDir = dirname($filePath);
            $uploadDir = dirname($baseDir);

            $deleteStmt = $pdo->prepare('DELETE FROM uploads WHERE id = ? AND id_owner = ?');
            $deleteStmt->execute([$uploadId, (int)$user['id']]);

            if (is_file($filePath)) {
                @unlink($filePath);
            }
            if (is_dir($baseDir)) {
                @rmdir($baseDir);
            }
            if (is_dir($uploadDir) && $uploadDir === __DIR__ . DIRECTORY_SEPARATOR . 'uploads' && count(scandir($uploadDir)) <= 2) {
                @rmdir($uploadDir);
            }

            $message = 'Upload deleted successfully.';
            header('Location: database_list.php?deleted=1');
            exit;
        }
    }
    $message = 'Unable to delete upload.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Source List</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<link rel="stylesheet" href="assets/app.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/app.css'); ?>">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Data source list</h1>
                <div class="meta">Browse your files and public files shared by other users.</div>
            </div>
            <a href="main.php">Back to home</a>
        </div>

        <?php if ($message): ?>
            <p class="message"><?php echo h($message); ?></p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p class="message error">Database access error: <?php echo h($error); ?></p>
        <?php elseif (empty($uploads)): ?>
            <p class="empty">No uploaded files yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>File</th>
                            <th>Owner</th>
                            <th>Description</th>
                            <th>Tag</th>
                            <th>Visibility</th>
                            <th>Path</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uploads as $row): ?>
                            <tr>
                                <td><?php echo h($row['id']); ?></td>
                                <td>
                                    <?php echo h($row['filename']); ?>
                                    <?php if (!empty($row['path']) && is_file(__DIR__ . DIRECTORY_SEPARATOR . $row['path'])): ?>
                                        <div><a href="<?php echo h($row['path']); ?>" target="_blank">Open file</a></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$row['id_owner'] === (int)$user['id']): ?>
                                        <span class="pill">You</span>
                                    <?php else: ?>
                                        <?php echo h($row['owner_username'] ?: 'User #' . $row['id_owner']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($row['description'] ?: '-'); ?></td>
                                <td><?php echo h($row['tags'] ?: '-'); ?></td>
                                <td><span class="pill"><?php echo ((int)$row['is_public'] === 1) ? 'Public' : 'Private'; ?></span></td>
                                <td><?php echo h($row['path'] ?: '-'); ?></td>
                                <td>
                                    <?php if ((int)$row['id_owner'] === (int)$user['id']): ?>
                                        <div class="db-actions-stack">
                                            <a href="edit_upload.php?id=<?php echo h($row['id']); ?>">Edit</a>
                                            <form method="post" onsubmit="return confirm('Delete this upload with its file and directory?');">
                                                <input type="hidden" name="action" value="delete_upload">
                                                <input type="hidden" name="id" value="<?php echo h($row['id']); ?>">
                                                <button type="submit" class="btn-small">Delete</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        -
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



