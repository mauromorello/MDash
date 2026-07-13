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
$error = '';
$message = '';
$uploads = [];
$templates = [];
$makeups = [];
$formData = [
    'title' => '',
    'id_datasources' => [],
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
            id_ai_db INT NOT NULL DEFAULT 0,
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

    $makeupStmt = $pdo->prepare(
        "SELECT m.id_makeup, m.name, m.id_owner, m.is_private, m.is_hidden, u.username AS owner_username
         FROM makeup m
         LEFT JOIN users u ON u.id = m.id_owner
         WHERE (m.id_owner = ? OR m.is_private = 0) AND m.is_hidden = 0
         ORDER BY m.id_makeup DESC"
    );
    $makeupStmt->execute([(int)$user['id']]);
    $makeups = $makeupStmt->fetchAll();

    mdashEnsureDashboardAiColumn($pdo);
    mdashEnsureDashboardDatasourceMapTable($pdo);
} catch (PDOException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_dashboard') {
    $formData['title'] = trim((string)($_POST['title'] ?? ''));
    $formData['id_makeup'] = trim((string)($_POST['id_makeup'] ?? '0'));
    $formData['data_filter_prompt'] = trim((string)($_POST['data_filter_prompt'] ?? ''));
    $formData['data_manipulation_prompt'] = trim((string)($_POST['data_manipulation_prompt'] ?? ''));
    $formData['dashboard_prompt_1'] = trim((string)($_POST['dashboard_prompt_1'] ?? ''));
    $formData['dashboard_prompt_2'] = trim((string)($_POST['dashboard_prompt_2'] ?? ''));
    $formData['id_template'] = trim((string)($_POST['id_template'] ?? '0'));

    $postedDatasourceIds = $_POST['id_datasources'] ?? [];
    if (!is_array($postedDatasourceIds)) {
        $postedDatasourceIds = [];
    }

    $selectedDatasourceIds = [];
    foreach ($postedDatasourceIds as $postedId) {
        $id = (int)$postedId;
        if ($id > 0 && !in_array($id, $selectedDatasourceIds, true)) {
            $selectedDatasourceIds[] = $id;
        }
    }
    $formData['id_datasources'] = $selectedDatasourceIds;

    if (!$pdo) {
        $error = 'Database error: ' . $error;
    } elseif ($formData['title'] === '') {
        $error = 'Dashboard title is required.';
    } else {
        try {
            $idTemplate = (int)($formData['id_template'] !== '' ? $formData['id_template'] : 0);

            if ((int)$formData['id_makeup'] > 0) {
                $makeupCheckStmt = $pdo->prepare('SELECT id_makeup FROM makeup WHERE id_makeup = ? AND (id_owner = ? OR is_private = 0) LIMIT 1');
                $makeupCheckStmt->execute([(int)$formData['id_makeup'], (int)$user['id']]);
                if (!$makeupCheckStmt->fetch()) {
                    throw new RuntimeException('Selected makeup not found or not accessible.');
                }
            }

            if ($idTemplate > 0) {
                $templateStmt = $pdo->prepare('SELECT id, title, prompt FROM templates WHERE id = ? AND (id_owner = ? OR is_public = 1) AND is_hidden = 0 LIMIT 1');
                $templateStmt->execute([$idTemplate, (int)$user['id']]);

                $selectedTemplate = $templateStmt->fetch();
                if (!$selectedTemplate) {
                    throw new RuntimeException('Selected template not found or not accessible.');
                }
            }

            if (!empty($selectedDatasourceIds)) {
                $checkDatasource = $pdo->prepare('SELECT id FROM uploads WHERE id = ? LIMIT 1');
                foreach ($selectedDatasourceIds as $selectedDatasourceId) {
                    $checkDatasource->execute([$selectedDatasourceId]);
                    if (!$checkDatasource->fetch()) {
                        throw new RuntimeException('Selected data source #' . $selectedDatasourceId . ' not found.');
                    }
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO dashboards (title, id_datasource, id_makeup, id_ai_db, data_filter_prompt, data_manipulation_prompt, dashboard_prompt_1, dashboard_prompt_2, id_template) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $legacyDatasourceId = !empty($selectedDatasourceIds) ? (int)$selectedDatasourceIds[0] : null;
            $stmt->execute([
                $formData['title'],
                $legacyDatasourceId,
                (int)($formData['id_makeup'] !== '' ? $formData['id_makeup'] : 0),
                0,
                $formData['data_filter_prompt'],
                $formData['data_manipulation_prompt'],
                $formData['dashboard_prompt_1'],
                $formData['dashboard_prompt_2'],
                $idTemplate,
            ]);

            $dashboardId = (int)$pdo->lastInsertId();
            mdashReplaceDashboardDatasources($pdo, $dashboardId, $selectedDatasourceIds);

            header('Location: dashboards.php?created=1');
            exit;
        } catch (Throwable $e) {
            $error = 'Error while saving the dashboard: ' . $e->getMessage();
        }
    }
}
?>
<?php $pageTitle = 'Dashboard builder'; include __DIR__ . '/header.php'; ?>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

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
                        <label for="id_datasources">Data sources</label>
                        <select id="id_datasources" name="id_datasources[]" multiple size="8">
                            <?php foreach ($uploads as $upload): ?>
                                <option value="<?php echo h($upload['id']); ?>"<?php echo in_array((int)$upload['id'], $formData['id_datasources'], true) ? ' selected' : ''; ?>>
                                    #<?php echo h($upload['id']); ?> - <?php echo h($upload['filename']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="meta">Select one or more data sources (Ctrl/Cmd + click for multi-select).</div>
                    </div>
                    <div class="field">
                        <label for="id_makeup">Makeup</label>
                        <select id="id_makeup" name="id_makeup">
                            <option value="0">No makeup</option>
                            <?php foreach ($makeups as $makeup): ?>
                                <option value="<?php echo h($makeup['id_makeup']); ?>"<?php echo ((string)$formData['id_makeup'] === (string)$makeup['id_makeup']) ? ' selected' : ''; ?>>
                                    #<?php echo h($makeup['id_makeup']); ?> - <?php echo h($makeup['name']); ?><?php echo ((int)($makeup['id_owner'] ?? 0) !== (int)$user['id']) ? ' (created by ' . h($makeup['owner_username'] ?? ('user #' . $makeup['id_owner'])) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="meta">Choose a makeup profile to include style instructions and color palette in the final prompt.</div>
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


