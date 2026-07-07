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

function normalizeSection(string $title, string $content): string {
    return "[" . $title . "]\n" . trim($content) . "\n";
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
$dashboard = null;
$upload = null;
$template = null;
$dashboardId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$promptTitle = '';
$masterPrompt = '';

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
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

if ($pdo && $dashboardId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM dashboards WHERE id = ? LIMIT 1');
        $stmt->execute([$dashboardId]);
        $dashboard = $stmt->fetch();

        if ($dashboard && !empty($dashboard['id_datasource'])) {
            $uploadStmt = $pdo->prepare('SELECT * FROM uploads WHERE id = ? LIMIT 1');
            $uploadStmt->execute([(int)$dashboard['id_datasource']]);
            $upload = $uploadStmt->fetch();
        }

        if ($dashboard && !empty($dashboard['id_template'])) {
            $templateStmt = $pdo->prepare(
                'SELECT t.id, t.title, t.prompt, t.id_owner, t.is_public, t.is_hidden, u.username AS owner_username
                 FROM templates t
                 LEFT JOIN users u ON u.id = t.id_owner
                 WHERE t.id = ? AND (t.id_owner = ? OR t.is_public = 1) AND t.is_hidden = 0
                 LIMIT 1'
            );
            $templateStmt->execute([(int)$dashboard['id_template'], (int)$user['id']]);
            $template = $templateStmt->fetch();
        }
    } catch (PDOException $e) {
        $error = 'Error while loading data: ' . $e->getMessage();
    }
}

if (!$dashboard && $error === '') {
    $error = 'Dashboard not found.';
}

if ($dashboard) {
    $promptTitle = trim((string)($dashboard['title'] ?? 'Dashboard without title'));

    $sections = [];
    $sections[] = normalizeSection('Dashboard title', $promptTitle);
    $sections[] = normalizeSection(
        'Dashboard prompt',
        "Data filter prompt:\n" . ($dashboard['data_filter_prompt'] ?? '') . "\n\n" .
        "Data manipulation prompt:\n" . ($dashboard['data_manipulation_prompt'] ?? '') . "\n\n" .
        "Dashboard prompt 1:\n" . ($dashboard['dashboard_prompt_1'] ?? '') . "\n\n" .
        "Dashboard prompt 2:\n" . ($dashboard['dashboard_prompt_2'] ?? '')
    );
    $sections[] = normalizeSection(
        'Template',
        "Template ID: " . (string)($dashboard['id_template'] ?? 0) . "\n" .
        "Template title: " . ($template['title'] ?? 'N/A') . "\n\n" .
        ($template && !empty($template['prompt']) ? (string)$template['prompt'] : '')
    );
    $sections[] = normalizeSection(
        'Makeup',
        ''
    );
    $sections[] = normalizeSection(
        'Error output',
        $error !== '' ? $error : 'None'
    );

    $masterPrompt = implode("\n", $sections);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_prompt') {
    $promptTitle = trim((string)($_POST['prompt_title'] ?? $promptTitle));
    $masterPrompt = trim((string)($_POST['master_prompt'] ?? $masterPrompt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Prompt</title>
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
                <h1>Dashboard prompt</h1>
                <div class="meta">Generate and refine the final prompt that will be sent to the AI.</div>
            </div>
            <a href="dashboards.php">Back to dashboard list</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php else: ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="preview_prompt">
                    <input type="hidden" name="id" value="<?php echo h($dashboardId); ?>">

                    <div class="field">
                        <label for="prompt_title">Title</label>
                        <input type="text" id="prompt_title" name="prompt_title" value="<?php echo h($promptTitle); ?>">
                    </div>

                    <div class="field">
                        <label for="master_prompt">Prompt</label>
                        <textarea id="master_prompt" name="master_prompt" style="min-height: 520px;"><?php echo h($masterPrompt); ?></textarea>
                    </div>

                    <div class="inline-actions">
                        <button type="submit">Refresh preview</button>
                        <button type="button" class="secondary" id="copyPromptBtn">Copy prompt</button>
                        <a href="edit_dashboard.php?id=<?php echo h($dashboardId); ?>" class="btn-secondary">Edit dashboard</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const copyPromptBtn = document.getElementById('copyPromptBtn');
        if (copyPromptBtn) {
            copyPromptBtn.addEventListener('click', function () {
                const promptField = document.getElementById('master_prompt');
                if (!promptField) {
                    return;
                }
                promptField.select();
                promptField.setSelectionRange(0, promptField.value.length);
                navigator.clipboard.writeText(promptField.value).catch(function () {});
            });
        }

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