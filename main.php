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
                'username' => $user['username'] ?? 'user',
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

require_once __DIR__ . '/ai_shared.php';

function extractDashboardTitle(array $result): string {
    $finalPrompt = (string)($result['final_prompt'] ?? '');
    if ($finalPrompt !== '' && preg_match('/\[Dashboard title\]\s*(.+?)(?:\n\[|$)/si', $finalPrompt, $matches)) {
        $title = trim((string)($matches[1] ?? ''));
        if ($title !== '') {
            $firstLine = preg_split('/\R/', $title, 2);
            return trim((string)($firstLine[0] ?? $title));
        }
    }

    return 'Dashboard #' . (string)($result['id'] ?? '');
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$readyDashboards = [];

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

        mdashEnsureResultsAiColumns($pdo);

        $stmt = $pdo->prepare(
                                'SELECT r.id, r.id_owner, r.is_public, r.is_hidden, r.path, r.thumbnail_path, r.final_prompt, r.n_views, r.n_download, r.n_clone
         FROM results r
         WHERE ((r.id_owner = :user_id AND r.is_hidden = 0) OR (r.is_public = 1 AND r.is_hidden = 0))
           AND COALESCE(TRIM(r.thumbnail_path), "") <> ""
                 ORDER BY r.n_views DESC, r.id DESC
         LIMIT 80'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $thumbnailPath = trim((string)($row['thumbnail_path'] ?? ''));
        $absoluteThumb = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $thumbnailPath);
        if (!is_file($absoluteThumb)) {
            continue;
        }

        $readyDashboards[] = [
            'id' => (int)$row['id'],
            'id_owner' => (int)$row['id_owner'],
            'title' => extractDashboardTitle($row),
            'tracked_path' => 'results.php?action=open_result&result_id=' . (int)$row['id'],
            'thumbnail_path' => $thumbnailPath,
            'n_views' => (int)($row['n_views'] ?? 0),
            'n_download' => (int)($row['n_download'] ?? 0),
            'n_clone' => (int)($row['n_clone'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    $readyDashboards = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="main-home-content">
        <div class="page main-home-panel">


            <div class="main-sections-grid">
                <div class="main-section-card">
                    <h2>Config</h2>
                    <div class="main-action-item">
                        <a href="ai_db.php" class="btn btn-secondary">AI</a>
                        <p class="main-action-desc">Manage API keys, models, endpoints, and connection tests.</p>
                    </div>
                    <div class="main-action-item">
                        <a href="templates.php" class="btn btn-secondary">Template</a>
                        <p class="main-action-desc">Create and maintain dashboard prompt templates.</p>
                    </div>
                    <div class="main-action-item">
                        <a href="makeup.php" class="btn btn-secondary">Markup</a>
                        <p class="main-action-desc">Define visual style profiles for generated dashboards.</p>
                    </div>
                </div>

                <div class="main-section-card">
                    <h2>Data Source</h2>
                    <div class="main-action-item">
                        <a href="upload.php" class="btn btn-primary">Upload</a>
                        <p class="main-action-desc">Upload new files and create data source records.</p>
                    </div>
                    <div class="main-action-item">
                        <a href="database_list.php" class="btn btn-secondary">Data Pool</a>
                        <p class="main-action-desc">Browse and manage available data sources.</p>
                    </div>
                </div>

                <div class="main-section-card">
                    <h2>Dashboard</h2>
                    <div class="main-action-item">
                        <a href="dashboard_builder.php" class="btn btn-accent">Generate</a>
                        <p class="main-action-desc">Build dashboards by combining config and data sources.</p>
                    </div>
                    <div class="main-action-item">
                        <a href="dashboards.php" class="btn btn-warning">Dash Pool</a>
                        <p class="main-action-desc">Open your dashboard pool for review and edits.</p>
                    </div>
                    <div class="main-action-item">
                        <a href="results.php" class="btn btn-secondary">Dashboard</a>
                        <p class="main-action-desc">Open dashboard hub with all generated pages and operations.</p>
                    </div>
                </div>
            </div>

            <section class="main-ready-section">
                <div class="main-ready-header">
                    <h2>Dashboard Hub</h2>
                    <a href="results.php" class="btn btn-secondary">Dashboard Hub</a>
                </div>

                <?php if (empty($readyDashboards)): ?>
                    <p class="main-action-desc">No ready dashboards available with thumbnail yet.</p>
                <?php else: ?>
                    <div class="main-ready-grid">
                        <?php foreach ($readyDashboards as $dashboard): ?>
                            <div class="main-ready-card">
                                <div class="main-ready-thumb-wrap">
                                    <a class="main-ready-thumb-link" href="<?php echo h($dashboard['tracked_path']); ?>" target="_blank" rel="noopener" title="Open dashboard">
                                        <img src="<?php echo h($dashboard['thumbnail_path']); ?>" alt="Thumbnail dashboard <?php echo h((int)$dashboard['id']); ?>" class="main-ready-thumb">
                                    </a>
                                    <?php if ((int)$dashboard['id_owner'] === (int)$user['id']): ?>
                                        <a class="main-ready-edit-btn" href="results.php" title="Edit dashboard">Edit</a>
                                    <?php endif; ?>
                                </div>
                                <h3><a class="main-ready-title-link" href="<?php echo h($dashboard['tracked_path']); ?>" target="_blank" rel="noopener"><?php echo h($dashboard['title']); ?></a></h3>
                                <div class="result-stats-badges main-ready-stats" aria-label="Dashboard stats">
                                    <span class="result-stat-badge" title="Views">👁️ <?php echo h((int)$dashboard['n_views']); ?></span>
                                    <span class="result-stat-badge" title="Downloads">⬇️ <?php echo h((int)$dashboard['n_download']); ?></span>
                                    <span class="result-stat-badge" title="Clones">🧬 <?php echo h((int)$dashboard['n_clone']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
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

