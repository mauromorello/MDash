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
$error = '';
$message = '';
$uploads = [];
$templates = [];
$formData = [
    'title' => '',
    'id_datasource' => '',
    'id_makeup' => '0',
    'data_filter_prompt' => '',
    'data_manipulation_prompt' => '',
    'dashboard_prompt_1' => '',
    'dashboard_prompt_2' => '',
    'id_template' => '0',
];

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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            prompt MEDIUMTEXT NOT NULL,
            `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_owner INT NOT NULL,
            is_hidden TINYINT(1) NOT NULL DEFAULT 0,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_templates_owner (id_owner),
            INDEX idx_templates_date (`date`),
            INDEX idx_templates_hidden_public (is_hidden, is_public)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $tableExists = $pdo->query("SHOW TABLES LIKE 'dashboards'")->fetchColumn();
    if ($tableExists) {
        $idColumn = $pdo->query("SHOW COLUMNS FROM dashboards LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if ($idColumn && stripos((string)$idColumn['Extra'], 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE dashboards MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }
    }

    $hiddenColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'is_hidden'")->fetch(PDO::FETCH_ASSOC);
    if (!$hiddenColumn) {
        $pdo->exec("ALTER TABLE templates ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
    }

    $publicColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'is_public'")->fetch(PDO::FETCH_ASSOC);
    if (!$publicColumn) {
        $pdo->exec("ALTER TABLE templates ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0");
    }

    $uploadsStmt = $pdo->query("SELECT id, filename, description FROM uploads ORDER BY id DESC");
    $uploads = $uploadsStmt->fetchAll();

    $templatesStmt = $pdo->prepare(
        "SELECT t.id, t.title, t.prompt, t.id_owner, t.is_public, t.is_hidden, u.username AS owner_username
         FROM templates t
         LEFT JOIN users u ON u.id = t.id_owner
         WHERE (t.id_owner = ? OR t.is_public = 1) AND t.is_hidden = 0
         ORDER BY t.id DESC"
    );
    $templatesStmt->execute([(int)$user['id']]);
    $templates = $templatesStmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_dashboard') {
    foreach ($formData as $key => $value) {
        $formData[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if (!$pdo) {
        $error = 'Database error: ' . $error;
    } elseif ($formData['title'] === '') {
        $error = 'Dashboard title is required.';
    } else {
        try {
            $idTemplate = (int)($formData['id_template'] !== '' ? $formData['id_template'] : 0);
            $dashboardPrompt2 = $formData['dashboard_prompt_2'];

            $verboseInstructionBlock =
                "[Verbose operating instructions for dashboard generation]\n" .
                "- Data source section: clearly identify source path, file name, and business context before any transformation.\n" .
                "- Field interpretation section: explain meaning, semantic type, unit, expected values, and anomaly handling for each relevant field.\n" .
                "- Physical file construction section: produce a complete standalone HTML output with coherent CSS and only required scripts.\n" .
                "- Dashboard layout section: structure header, KPI area, charts, tables, and notes with responsive behavior for desktop and mobile.\n" .
                "- Quality rules section: include clear labels, legends, empty-state handling, and fallback behavior for missing data.\n" .
                "- Traceability section: state which blocks are raw data views and which are filtered, aggregated, or derived computations.";

            $dashboardPrompt2 = trim((string)$dashboardPrompt2);
            $dashboardPrompt2 = $dashboardPrompt2 === ''
                ? $verboseInstructionBlock
                : ($dashboardPrompt2 . "\n\n" . $verboseInstructionBlock);

            if ($idTemplate > 0) {
                $templateStmt = $pdo->prepare('SELECT id, title, prompt FROM templates WHERE id = ? AND (id_owner = ? OR is_public = 1) AND is_hidden = 0 LIMIT 1');
                $templateStmt->execute([$idTemplate, (int)$user['id']]);

                $selectedTemplate = $templateStmt->fetch();
                if (!$selectedTemplate) {
                    throw new RuntimeException('Selected template not found or not accessible.');
                }

                $templatePrompt = trim((string)($selectedTemplate['prompt'] ?? ''));
                if ($templatePrompt !== '') {
                    $templateBlock = "[Template #" . (int)$selectedTemplate['id'] . ' - ' . (string)$selectedTemplate['title'] . "]\n" . $templatePrompt;
                    $dashboardPrompt2 = $dashboardPrompt2 . "\n\n" . $templateBlock;
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO dashboards (title, id_datasource, id_makeup, data_filter_prompt, data_manipulation_prompt, dashboard_prompt_1, dashboard_prompt_2, id_template) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $formData['title'],
                $formData['id_datasource'] !== '' ? (int)$formData['id_datasource'] : null,
                (int)($formData['id_makeup'] !== '' ? $formData['id_makeup'] : 0),
                $formData['data_filter_prompt'],
                $formData['data_manipulation_prompt'],
                $formData['dashboard_prompt_1'],
                $dashboardPrompt2,
                $idTemplate,
            ]);
            header('Location: dashboards.php?created=1');
            exit;
        } catch (Throwable $e) {
            $error = 'Error while saving the dashboard: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard builder</title>
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
                <h1>Dashboard builder</h1>
                <div class="meta">Create a dashboard starting from an uploaded data source.</div>
            </div>
            <a href="dashboards.php">Go to dashboard list</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (!empty($_GET['updated'])): ?>
            <div class="message">Dashboard updated successfully.</div>
        <?php endif; ?>

        <div class="card">
            <form method="post">
                <input type="hidden" name="action" value="create_dashboard">

                <div class="field">
                        <label for="title">Dashboard title</label>
                    <input type="text" id="title" name="title" value="<?php echo h($formData['title']); ?>" required>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="id_datasource">Data source</label>
                        <select id="id_datasource" name="id_datasource">
                            <option value="">None</option>
                            <?php foreach ($uploads as $upload): ?>
                                <option value="<?php echo h($upload['id']); ?>"<?php echo ((string)$formData['id_datasource'] === (string)$upload['id']) ? ' selected' : ''; ?>>
                                    #<?php echo h($upload['id']); ?> - <?php echo h($upload['filename']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="id_makeup">Makeup ID</label>
                        <input type="text" id="id_makeup" name="id_makeup" value="<?php echo h($formData['id_makeup']); ?>">
                    </div>
                </div>

                <div class="field">
                    <label for="data_filter_prompt">Data filter prompt</label>
                    <textarea id="data_filter_prompt" name="data_filter_prompt"><?php echo h($formData['data_filter_prompt']); ?></textarea>
                </div>

                <div class="field">
                    <label for="data_manipulation_prompt">Data manipulation prompt</label>
                    <textarea id="data_manipulation_prompt" name="data_manipulation_prompt"><?php echo h($formData['data_manipulation_prompt']); ?></textarea>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="dashboard_prompt_1">Dashboard prompt 1</label>
                        <textarea id="dashboard_prompt_1" name="dashboard_prompt_1"><?php echo h($formData['dashboard_prompt_1']); ?></textarea>
                    </div>
                    <div class="field">
                        <label for="dashboard_prompt_2">Dashboard prompt 2</label>
                        <textarea id="dashboard_prompt_2" name="dashboard_prompt_2"><?php echo h($formData['dashboard_prompt_2']); ?></textarea>
                    </div>
                </div>

                <div class="field">
                    <label for="id_template">Template prompt</label>
                    <select id="id_template" name="id_template">
                        <option value="0">No template</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo h($template['id']); ?>"<?php echo ((string)$formData['id_template'] === (string)$template['id']) ? ' selected' : ''; ?>>
                                #<?php echo h($template['id']); ?> - <?php echo h($template['title']); ?><?php echo ((int)($template['is_public'] ?? 0) === 1 && (int)($template['id_owner'] ?? 0) !== (int)$user['id']) ? ' (created by ' . h($template['owner_username'] ?? ('user #' . $template['id_owner'])) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="meta">The selected template prompt will be appended to Dashboard prompt 2 during save.</div>
                </div>

                <div class="inline-actions">
                    <button type="submit">Save dashboard</button>
                    <a href="dashboards.php" class="btn-secondary">Cancel</a>
                </div>
            </form>
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
    </script>
</body>
</html>
