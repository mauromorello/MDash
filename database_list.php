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

require_once __DIR__ . '/ai_shared.php';

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
$favoritesOnly = isset($_GET['favorites']) && (int)$_GET['favorites'] === 1;

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
    mdashEnsureFavoritesTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_favorite') {
        $uploadId = (int)($_POST['id'] ?? 0);
        if ($uploadId > 0) {
            mdashToggleFavorite($pdo, (int)$user['id'], 'data', $uploadId);
            header('Location: database_list.php' . ($favoritesOnly ? '?favorites=1' : ''));
            exit;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT u.*, usr.username AS owner_username,
                CASE WHEN f.favorite_id IS NULL THEN 0 ELSE 1 END AS is_favorite
         FROM uploads u
         LEFT JOIN users usr ON usr.id = u.id_owner
         LEFT JOIN user_favorites f ON f.favorite_type = "data" AND f.favorite_id = u.id AND f.id_owner = ?
         WHERE (u.id_owner = ? OR u.is_public = 1)
           AND (? = 0 OR f.favorite_id IS NOT NULL)
         ORDER BY u.id DESC'
    );
    $stmt->execute([(int)$user['id'], (int)$user['id'], $favoritesOnly ? 1 : 0]);
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
<?php $pageTitle = 'Data Source List'; include __DIR__ . '/header.php'; ?>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Data source list</h1>
                <div class="meta">Browse your files and public files shared by other users.</div>
            </div>
            <div class="inline-actions">
                <?php if ($favoritesOnly): ?>
                    <a href="database_list.php">All data sources</a>
                <?php else: ?>
                    <a href="database_list.php?favorites=1">Only favorites</a>
                <?php endif; ?>
                <a href="main.php">Back to home</a>
            </div>
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
                            <th>Fav</th>
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
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_favorite">
                                        <input type="hidden" name="id" value="<?php echo h($row['id']); ?>">
                                        <button type="submit" class="favorite-btn<?php echo (int)($row['is_favorite'] ?? 0) === 1 ? ' is-active' : ''; ?>" title="Toggle favorite" aria-label="Toggle favorite"><?php echo (int)($row['is_favorite'] ?? 0) === 1 ? '&#9733;' : '&#9734;'; ?></button>
                                    </form>
                                </td>
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



