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

function extractPromptSection(string $finalPrompt, string $sectionName): string {
    if ($finalPrompt === '') {
        return '';
    }

    $pattern = '/\[' . preg_quote($sectionName, '/') . '\]\s*(.+?)(?:\n\[[^\]]+\]|$)/si';
    if (!preg_match($pattern, $finalPrompt, $matches)) {
        return '';
    }

    return trim((string)($matches[1] ?? ''));
}

function extractLabeledValue(string $text, string $label): string {
    if ($text === '') {
        return '';
    }

    $pattern = '/^' . preg_quote($label, '/') . ':\s*(.+)$/mi';
    if (!preg_match($pattern, $text, $matches)) {
        return '';
    }

    return trim((string)($matches[1] ?? ''));
}

function cleanSingleLine(string $value): string {
    if ($value === '') {
        return '';
    }

    $firstLine = preg_split('/\R/', $value, 2);
    $normalized = trim((string)($firstLine[0] ?? $value));
    return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
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
                                        'SELECT r.id, r.id_owner, r.is_public, r.is_hidden, r.path, r.thumbnail_path, r.final_prompt, r.ai_title, r.ai_provider, r.ai_model, r.tags, r.n_views, r.n_download, r.n_clone, u.username AS owner_username
         FROM results r
         LEFT JOIN users u ON u.id = r.id_owner
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

        $ownerLabel = (string)($row['owner_username'] ?? '') !== ''
            ? (string)$row['owner_username']
            : ('User #' . (int)$row['id_owner']);

        $finalPrompt = (string)($row['final_prompt'] ?? '');
        $dataSourceSection = extractPromptSection($finalPrompt, 'Data source');
        $dataSourceName = cleanSingleLine(extractLabeledValue($dataSourceSection, 'File name'));
        if ($dataSourceName === '') {
            $dataSourceName = cleanSingleLine(extractLabeledValue($dataSourceSection, 'Relative file path'));
        }
        if ($dataSourceName === '') {
            $dataSourceName = 'N/A';
        }

        $description = cleanSingleLine(extractLabeledValue($dataSourceSection, 'Description'));
        if ($description === '') {
            $description = cleanSingleLine(extractPromptSection($finalPrompt, 'Dashboard prompt'));
        }
        if ($description === '') {
            $description = 'N/A';
        }

        $tags = cleanSingleLine((string)($row['tags'] ?? ''));
        if ($tags === '') {
            $tags = cleanSingleLine(extractLabeledValue($dataSourceSection, 'Tags'));
        }
        if ($tags === '') {
            $tags = 'N/A';
        }

        $aiTitle = trim((string)($row['ai_title'] ?? ''));
        $aiProvider = trim((string)($row['ai_provider'] ?? ''));
        $aiModel = trim((string)($row['ai_model'] ?? ''));
        $aiUsedParts = [];
        if ($aiTitle !== '') {
            $aiUsedParts[] = $aiTitle;
        }
        if ($aiProvider !== '' || $aiModel !== '') {
            $aiUsedParts[] = trim($aiProvider . ($aiModel !== '' ? ' / ' . $aiModel : ''));
        }
        $aiUsed = trim(implode(' - ', array_filter($aiUsedParts, static fn($part) => $part !== '')));
        if ($aiUsed === '') {
            $aiUsed = 'N/A';
        }

        $readyDashboards[] = [
            'id' => (int)$row['id'],
            'id_owner' => (int)$row['id_owner'],
            'title' => extractDashboardTitle($row),
            'tracked_path' => 'results.php?action=open_result&result_id=' . (int)$row['id'],
            'thumbnail_path' => $thumbnailPath,
            'creator' => $ownerLabel,
            'data_source' => $dataSourceName,
            'ai_used' => $aiUsed,
            'description' => $description,
            'tags' => $tags,
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
    <link rel="stylesheet" href="assets/app.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/app.css'); ?>">
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
                    <div class="main-ready-filter">
                        <label for="mainReadySearch" class="main-ready-filter-label">Filter by title, tags, author</label>
                        <input type="text" id="mainReadySearch" placeholder="Type title, tags, or username..." autocomplete="off">
                    </div>
                    <div class="main-ready-grid">
                        <?php foreach ($readyDashboards as $dashboard): ?>
                            <div class="main-ready-card" data-title="<?php echo h(strtolower((string)$dashboard['title'])); ?>" data-tags="<?php echo h(strtolower((string)$dashboard['tags'])); ?>" data-creator="<?php echo h(strtolower((string)$dashboard['creator'])); ?>">
                                <div class="main-ready-thumb-wrap">
                                    <a class="main-ready-thumb-link" href="<?php echo h($dashboard['tracked_path']); ?>" target="_blank" rel="noopener" title="Open dashboard">
                                        <img src="<?php echo h($dashboard['thumbnail_path']); ?>" alt="Thumbnail dashboard <?php echo h((int)$dashboard['id']); ?>" class="main-ready-thumb">
                                    </a>
                                    <div class="main-ready-tooltip" role="tooltip" aria-label="Dashboard details">
                                        <div class="main-ready-tooltip-title"><?php echo h($dashboard['title']); ?></div>
                                        <div class="main-ready-tooltip-row"><span>Creator</span><strong><?php echo h($dashboard['creator']); ?></strong></div>
                                        <div class="main-ready-tooltip-row"><span>Tags</span><strong><?php echo h($dashboard['tags']); ?></strong></div>
                                        <div class="main-ready-tooltip-row"><span>Data</span><strong><?php echo h($dashboard['data_source']); ?></strong></div>
                                        <div class="main-ready-tooltip-row"><span>AI Used</span><strong><?php echo h($dashboard['ai_used']); ?></strong></div>
                                        <div class="main-ready-tooltip-row"><span>Description</span><strong><?php echo h($dashboard['description']); ?></strong></div>
                                    </div>
                                    <?php if ((int)$dashboard['id_owner'] === (int)$user['id']): ?>
                                        <a class="main-ready-edit-btn" href="edit_result.php?id=<?php echo h((int)$dashboard['id']); ?>" title="Edit dashboard">Edit</a>
                                    <?php endif; ?>
                                </div>
                                <h3><a class="main-ready-title-link" href="<?php echo h($dashboard['tracked_path']); ?>" target="_blank" rel="noopener"><?php echo h($dashboard['title']); ?></a></h3>
                                <div class="result-stats-badges main-ready-stats" aria-label="Dashboard stats">
                                    <span class="result-stat-badge" title="Views">&#128065;&#65039; <?php echo h((int)$dashboard['n_views']); ?></span>
                                    <span class="result-stat-badge" title="Downloads">&#11015;&#65039; <?php echo h((int)$dashboard['n_download']); ?></span>
                                    <span class="result-stat-badge" title="Clones">&#129516; <?php echo h((int)$dashboard['n_clone']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
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

        const mainReadySearch = document.getElementById('mainReadySearch');
        const mainReadyCards = document.querySelectorAll('.main-ready-card');

        if (mainReadySearch && mainReadyCards.length > 0) {
            mainReadySearch.addEventListener('input', function () {
                const query = String(mainReadySearch.value || '').toLowerCase().trim();
                mainReadyCards.forEach((card) => {
                    const title = String(card.getAttribute('data-title') || '');
                    const tags = String(card.getAttribute('data-tags') || '');
                    const creator = String(card.getAttribute('data-creator') || '');
                    const haystack = title + ' ' + tags + ' ' + creator;
                    card.classList.toggle('is-filtered-out', query !== '' && !haystack.includes(query));
                });
            });
        }
    </script>
</body>
</html>


