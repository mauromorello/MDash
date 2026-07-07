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
                'username' => $user['username'] ?? 'utente',
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

function buildAbsolutePath(string $relativePath): string {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return $scheme . '://' . $host . ($basePath !== '' ? $basePath . '/' : '/') . $relativePath;
}

function removeDirectoryRecursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeDirectoryRecursive($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function parseDataUrlImage(string $dataUrl): array {
    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/i', $dataUrl, $matches)) {
        throw new RuntimeException('Invalid image data format.');
    }

    $type = strtolower($matches[1]);
    $base64 = $matches[2];
    $binary = base64_decode($base64, true);
    if ($binary === false) {
        throw new RuntimeException('Invalid base64 image data.');
    }

    if (strlen($binary) > 8 * 1024 * 1024) {
        throw new RuntimeException('Image is too large. Maximum allowed size is 8MB.');
    }

    $extension = $type === 'jpeg' ? 'jpg' : $type;

    return [
        'extension' => $extension,
        'binary' => $binary,
    ];
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
$results = [];
$error = '';
$message = '';
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
        "CREATE TABLE IF NOT EXISTS results (
            id INT NOT NULL,
            path TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            id_template INT NOT NULL,
            final_prompt TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            thumbnail_path TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            id_owner INT NOT NULL,
            is_public INT NOT NULL DEFAULT '0',
            is_hidden INT NOT NULL DEFAULT '0',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $tableExists = $pdo->query("SHOW TABLES LIKE 'results'")->fetchColumn();
    if ($tableExists) {
        $idColumn = $pdo->query("SHOW COLUMNS FROM results LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if ($idColumn && stripos((string)$idColumn['Extra'], 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE results MODIFY COLUMN id INT NOT NULL");
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $resultId = (int)($_POST['result_id'] ?? 0);

        if ($resultId > 0 && in_array($action, ['set_hidden', 'delete_result', 'save_thumbnail'], true)) {
            $ownerStmt = $pdo->prepare('SELECT id, id_owner FROM results WHERE id = ? LIMIT 1');
            $ownerStmt->execute([$resultId]);
            $ownerRow = $ownerStmt->fetch();

            if (!$ownerRow) {
                throw new RuntimeException('Result not found.');
            }

            if ((int)$ownerRow['id_owner'] !== (int)$user['id']) {
                throw new RuntimeException('You can only modify your own dashboards.');
            }

            if ($action === 'set_hidden') {
                $hiddenValue = (int)($_POST['hidden_value'] ?? 0) === 1 ? 1 : 0;
                $updateStmt = $pdo->prepare('UPDATE results SET is_hidden = ? WHERE id = ? AND id_owner = ?');
                $updateStmt->execute([$hiddenValue, $resultId, (int)$user['id']]);
                $message = $hiddenValue === 1 ? 'Dashboard hidden successfully.' : 'Dashboard revealed successfully.';
            }

            if ($action === 'delete_result') {
                $deleteStmt = $pdo->prepare('DELETE FROM results WHERE id = ? AND id_owner = ?');
                $deleteStmt->execute([$resultId, (int)$user['id']]);

                $resultFolder = __DIR__ . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . $resultId;
                removeDirectoryRecursive($resultFolder);
                $message = 'Dashboard deleted permanently.';
            }

            if ($action === 'save_thumbnail') {
                $thumbnailData = (string)($_POST['thumbnail_data'] ?? '');
                if ($thumbnailData === '') {
                    throw new RuntimeException('No screenshot data provided.');
                }

                $parsed = parseDataUrlImage($thumbnailData);
                $resultFolder = __DIR__ . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . $resultId;
                if (!is_dir($resultFolder) && !mkdir($resultFolder, 0777, true) && !is_dir($resultFolder)) {
                    throw new RuntimeException('Unable to create result folder for thumbnail.');
                }

                $relativeThumbPath = 'results/' . $resultId . '/thumbnail.' . $parsed['extension'];
                $diskThumbPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeThumbPath);
                file_put_contents($diskThumbPath, $parsed['binary']);

                $thumbStmt = $pdo->prepare('UPDATE results SET thumbnail_path = ? WHERE id = ? AND id_owner = ?');
                $thumbStmt->execute([$relativeThumbPath, $resultId, (int)$user['id']]);
                $message = 'Screenshot thumbnail saved successfully.';
            }
        }
    }

    $visibilityWhere = $showHidden
        ? '((r.id_owner = :user_id) OR (r.is_public = 1 AND r.is_hidden = 0))'
        : '((r.id_owner = :user_id AND r.is_hidden = 0) OR (r.is_public = 1 AND r.is_hidden = 0))';

    $stmt = $pdo->prepare(
        'SELECT r.*, t.title AS template_title, u.username AS owner_username
         FROM results r
         LEFT JOIN templates t ON t.id = r.id_template
         LEFT JOIN users u ON u.id = r.id_owner
         WHERE ' . $visibilityWhere . '
         ORDER BY r.id DESC'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    $results = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results</title>
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }
        .result-card {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .thumbnail-box {
            min-height: 180px;
            border: 1px dashed var(--border);
            border-radius: 12px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            overflow: hidden;
        }
        .thumbnail-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .result-meta {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 0.95rem;
        }
        .result-meta strong {
            font-size: 1rem;
        }
        .result-owner {
            font-size: 0.88rem;
            font-weight: 700;
            color: #0f172a;
            background: #e2e8f0;
            border-radius: 999px;
            padding: 4px 10px;
            display: inline-block;
            width: fit-content;
        }
        .results-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 16px;
        }
        .result-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .result-actions form,
        .result-actions a,
        .result-actions button {
            margin: 0;
        }
        .result-actions button,
        .result-actions a {
            width: 100%;
            text-align: center;
        }
        .btn-ghost {
            background: #475569;
        }
        .btn-danger {
            background: #b91c1c;
        }
    </style>
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
                <h1>Results</h1>
                <div class="meta">Review generated dashboards. You only see your dashboards or public ones.</div>
            </div>
            <a href="dashboard_builder.php">New dashboard</a>
        </div>

        <div class="card results-controls">
            <div class="meta">
                Hidden dashboards are excluded by default.
            </div>
            <?php if ($showHidden): ?>
                <a href="results.php" class="btn-secondary">Hide hidden dashboards</a>
            <?php else: ?>
                <a href="results.php?show_hidden=1" class="btn-secondary">Reveal hidden dashboards</a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (empty($results)): ?>
            <div class="card">
                <p class="empty">No generated dashboards found yet.</p>
            </div>
        <?php else: ?>
            <div class="results-grid">
                <?php foreach ($results as $result): ?>
                    <div class="card result-card">
                        <div class="result-owner">
                            Owner: <?php echo h($result['owner_username'] ?: ('User #' . $result['id_owner'])); ?>
                        </div>
                        <div class="thumbnail-box">
                            <?php if (!empty($result['thumbnail_path']) && is_file(__DIR__ . DIRECTORY_SEPARATOR . $result['thumbnail_path'])): ?>
                                <img src="<?php echo h($result['thumbnail_path']); ?>" alt="Thumbnail for result <?php echo h($result['id']); ?>">
                            <?php else: ?>
                                <span>No thumbnail available</span>
                            <?php endif; ?>
                        </div>
                        <div class="result-meta">
                            <strong>Result #<?php echo h($result['id']); ?></strong>
                            <span>Template: <?php echo h($result['template_title'] ?: ('#' . $result['id_template'])); ?></span>
                            <span>Visibility: <?php echo ((int)$result['is_public'] === 1) ? 'Public' : 'Private'; ?><?php echo ((int)$result['is_hidden'] === 1) ? ' / Hidden' : ''; ?></span>
                            <span>Saved path: <?php echo h($result['path']); ?></span>
                        </div>

                        <div class="result-actions">
                            <a href="<?php echo h($result['path']); ?>" target="_blank" rel="noopener">Open dashboard</a>

                            <?php if ((int)$result['id_owner'] === (int)$user['id']): ?>
                                <button type="button" class="btn-ghost paste-thumb-btn" data-result-id="<?php echo h($result['id']); ?>">Paste screenshot</button>

                                <form method="post" class="thumbnail-form" id="thumbnailForm<?php echo h($result['id']); ?>">
                                    <input type="hidden" name="action" value="save_thumbnail">
                                    <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                    <input type="hidden" name="thumbnail_data" value="" class="thumbnail-data-input">
                                </form>

                                <form method="post">
                                    <input type="hidden" name="action" value="set_hidden">
                                    <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                    <input type="hidden" name="hidden_value" value="<?php echo ((int)$result['is_hidden'] === 1) ? '0' : '1'; ?>">
                                    <button type="submit" class="btn-ghost"><?php echo ((int)$result['is_hidden'] === 1) ? 'Reveal' : 'Hide'; ?></button>
                                </form>

                                <form method="post" onsubmit="return confirm('Delete this dashboard permanently? This action removes DB record and files.');">
                                    <input type="hidden" name="action" value="delete_result">
                                    <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                    <button type="submit" class="btn-danger">Delete permanently</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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

        async function readImageFromClipboard() {
            if (!navigator.clipboard || !navigator.clipboard.read) {
                return '';
            }

            const items = await navigator.clipboard.read();
            for (const item of items) {
                for (const type of item.types) {
                    if (!type.startsWith('image/')) {
                        continue;
                    }

                    const blob = await item.getType(type);
                    const dataUrl = await new Promise((resolve, reject) => {
                        const reader = new FileReader();
                        reader.onload = () => resolve(String(reader.result || ''));
                        reader.onerror = () => reject(new Error('Unable to read screenshot data.'));
                        reader.readAsDataURL(blob);
                    });

                    return dataUrl;
                }
            }

            return '';
        }

        const pasteButtons = document.querySelectorAll('.paste-thumb-btn');
        pasteButtons.forEach((button) => {
            button.addEventListener('click', async function () {
                const resultId = button.getAttribute('data-result-id');
                const form = document.getElementById('thumbnailForm' + resultId);
                if (!form) {
                    return;
                }

                const input = form.querySelector('.thumbnail-data-input');
                if (!input) {
                    return;
                }

                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = 'Reading clipboard...';

                try {
                    const dataUrl = await readImageFromClipboard();
                    if (!dataUrl) {
                        alert('No image found in clipboard. Copy a screenshot first, then click again.');
                        return;
                    }

                    input.value = dataUrl;
                    form.submit();
                } catch (err) {
                    alert('Unable to read clipboard image. Browser permissions may be required.');
                } finally {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
        });
    </script>
</body>
</html>
