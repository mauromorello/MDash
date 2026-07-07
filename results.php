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

    $stmt = $pdo->query(
        'SELECT r.*, t.title AS template_title, u.username AS owner_username
         FROM results r
         LEFT JOIN templates t ON t.id = r.id_template
         LEFT JOIN users u ON u.id = r.id_owner
         ORDER BY r.id DESC'
    );
    $results = $stmt->fetchAll();
} catch (PDOException $e) {
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
                <div class="meta">Review every generated dashboard and open its saved HTML output.</div>
            </div>
            <a href="dashboard_builder.php">New dashboard</a>
        </div>

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
                            <span>Owner: <?php echo h($result['owner_username'] ?: ('User #' . $result['id_owner'])); ?></span>
                            <span>Visibility: <?php echo ((int)$result['is_public'] === 1) ? 'Public' : 'Private'; ?><?php echo ((int)$result['is_hidden'] === 1) ? ' / Hidden' : ''; ?></span>
                        </div>
                        <div class="inline-actions">
                            <a href="<?php echo h($result['path']); ?>" target="_blank" rel="noopener">Open dashboard</a>
                            <a href="dashboards.php">Back to dashboards</a>
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
    </script>
</body>
</html>
