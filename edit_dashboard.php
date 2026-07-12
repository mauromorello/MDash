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
$dashboard = null;
$uploads = [];
$templates = [];
$makeups = [];
$aiProfiles = [];
$error = '';
$message = '';
$dashboardId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$selectedDatasourceIds = [];

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

    $uploadsStmt = $pdo->query("SELECT id, filename, description FROM uploads ORDER BY id DESC");
    $uploads = $uploadsStmt->fetchAll();

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

    $hiddenColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'is_hidden'")->fetch(PDO::FETCH_ASSOC);
    if (!$hiddenColumn) {
        $pdo->exec("ALTER TABLE templates ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
    }

    $publicColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'is_public'")->fetch(PDO::FETCH_ASSOC);
    if (!$publicColumn) {
        $pdo->exec("ALTER TABLE templates ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0");
    }

    $templatesStmt = $pdo->prepare(
        "SELECT t.id, t.title, t.id_owner, t.is_public, u.username AS owner_username
         FROM templates t
         LEFT JOIN users u ON u.id = t.id_owner
         WHERE (t.id_owner = ? OR t.is_public = 1) AND t.is_hidden = 0
         ORDER BY t.id DESC"
    );
    $templatesStmt->execute([(int)$user['id']]);
    $templates = $templatesStmt->fetchAll();

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

    $makeupStmt = $pdo->prepare(
        "SELECT m.id_makeup, m.name, m.id_owner, m.is_private, m.is_hidden, u.username AS owner_username
         FROM makeup m
         LEFT JOIN users u ON u.id = m.id_owner
         WHERE (m.id_owner = ? OR m.is_private = 0) AND m.is_hidden = 0
         ORDER BY m.id_makeup DESC"
    );
    $makeupStmt->execute([(int)$user['id']]);
    $makeups = $makeupStmt->fetchAll();

    mdashEnsureAiDbTable($pdo);
    mdashEnsureDashboardAiColumn($pdo);
    mdashEnsureDashboardDatasourceMapTable($pdo);
    $aiProfiles = mdashFetchAccessibleAiProfiles($pdo, (int)$user['id'], true);
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_dashboard' && $pdo) {
    try {
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

        $selectedMakeupId = (int)($_POST['id_makeup'] ?? 0);
        if ($selectedMakeupId > 0) {
            $makeupCheckStmt = $pdo->prepare('SELECT id_makeup FROM makeup WHERE id_makeup = ? AND (id_owner = ? OR is_private = 0) LIMIT 1');
            $makeupCheckStmt->execute([$selectedMakeupId, (int)$user['id']]);
            if (!$makeupCheckStmt->fetch()) {
                throw new RuntimeException('Selected makeup not found or not accessible.');
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
            'UPDATE dashboards SET title = ?, id_datasource = ?, id_makeup = ?, id_ai_db = ?, data_filter_prompt = ?, data_manipulation_prompt = ?, dashboard_prompt_1 = ?, dashboard_prompt_2 = ?, id_template = ? WHERE id = ?'
        );

        $selectedAiId = (int)($_POST['id_ai_db'] ?? 0);
        if ($selectedAiId <= 0 && !empty($aiProfiles)) {
            $selectedAiId = (int)$aiProfiles[0]['id'];
        }
        if ($selectedAiId > 0) {
            $aiProfile = mdashFetchAccessibleAiProfile($pdo, $selectedAiId, (int)$user['id'], true);
            if (!$aiProfile) {
                throw new RuntimeException('Selected AI profile not found or not accessible.');
            }
        }

        $legacyDatasourceId = !empty($selectedDatasourceIds) ? (int)$selectedDatasourceIds[0] : null;
        $stmt->execute([
            trim((string)($_POST['title'] ?? '')),
            $legacyDatasourceId,
            $selectedMakeupId,
            $selectedAiId,
            trim((string)($_POST['data_filter_prompt'] ?? '')),
            trim((string)($_POST['data_manipulation_prompt'] ?? '')),
            trim((string)($_POST['dashboard_prompt_1'] ?? '')),
            trim((string)($_POST['dashboard_prompt_2'] ?? '')),
            (int)($_POST['id_template'] ?? 0),
            $dashboardId,
        ]);

        mdashReplaceDashboardDatasources($pdo, $dashboardId, $selectedDatasourceIds);

        header('Location: dashboards.php?updated=1');
        exit;
    } catch (Throwable $e) {
        $error = 'Error while updating dashboard: ' . $e->getMessage();
    }
}

if ($pdo && $dashboardId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM dashboards WHERE id = ? LIMIT 1');
        $stmt->execute([$dashboardId]);
        $dashboard = $stmt->fetch();
        if ($dashboard) {
            $selectedDatasourceIds = mdashFetchDashboardDatasourceIds($pdo, (int)$dashboard['id']);
            if (empty($selectedDatasourceIds) && !empty($dashboard['id_datasource'])) {
                $selectedDatasourceIds = [(int)$dashboard['id_datasource']];
            }
        }
        if (!$dashboard && $error === '') {
            $error = 'Dashboard not found.';
        }
    } catch (PDOException $e) {
        $error = 'Error while loading dashboard: ' . $e->getMessage();
    }
} elseif ($dashboardId <= 0 && $error === '') {
    $error = 'Invalid dashboard ID.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit dashboard</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<link rel="stylesheet" href="assets/app.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/app.css'); ?>">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Edit dashboard</h1>
                <div class="meta">Update the selected dashboard details.</div>
            </div>
            <a href="dashboards.php">Back to list</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($dashboard): ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="save_dashboard">
                    <input type="hidden" name="id" value="<?php echo h($dashboard['id']); ?>">

                    <div class="field">
                        <label for="title">Dashboard title</label>
                        <input type="text" id="title" name="title" value="<?php echo h($dashboard['title']); ?>" required>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="id_datasources">Data sources</label>
                            <select id="id_datasources" name="id_datasources[]" multiple size="8">
                                <?php foreach ($uploads as $upload): ?>
                                    <option value="<?php echo h($upload['id']); ?>"<?php echo in_array((int)$upload['id'], $selectedDatasourceIds, true) ? ' selected' : ''; ?>>
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
                                    <option value="<?php echo h($makeup['id_makeup']); ?>"<?php echo ((string)$dashboard['id_makeup'] === (string)$makeup['id_makeup']) ? ' selected' : ''; ?>>
                                        #<?php echo h($makeup['id_makeup']); ?> - <?php echo h($makeup['name']); ?><?php echo ((int)($makeup['id_owner'] ?? 0) !== (int)$user['id']) ? ' (created by ' . h($makeup['owner_username'] ?? ('user #' . $makeup['id_owner'])) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="meta">Choose a makeup profile to include style instructions and color palette in the final prompt.</div>
                        </div>
                    </div>

                    <div class="field">
                        <label for="id_ai_db">AI profile</label>
                        <select id="id_ai_db" name="id_ai_db">
                            <option value="0">No AI profile</option>
                            <?php foreach ($aiProfiles as $aiProfile): ?>
                                <option value="<?php echo h($aiProfile['id']); ?>"<?php echo ((string)$dashboard['id_ai_db'] === (string)$aiProfile['id']) ? ' selected' : ''; ?>>
                                    #<?php echo h($aiProfile['id']); ?> - <?php echo h($aiProfile['title']); ?> [<?php echo h($aiProfile['provider']); ?> / <?php echo h($aiProfile['model']); ?>]<?php echo ((int)($aiProfile['id_owner'] ?? 0) !== (int)$user['id']) ? ' (created by ' . h($aiProfile['owner_username'] ?? ('user #' . $aiProfile['id_owner'])) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="meta">The selected AI profile provides the API key and endpoint used during generation. Create or edit profiles in the AI Profiles library.</div>
                    </div>

                    <div class="field">
                        <label for="data_filter_prompt">Data filter prompt</label>
                        <textarea id="data_filter_prompt" name="data_filter_prompt"><?php echo h($dashboard['data_filter_prompt']); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="data_manipulation_prompt">Data manipulation prompt</label>
                        <textarea id="data_manipulation_prompt" name="data_manipulation_prompt"><?php echo h($dashboard['data_manipulation_prompt']); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="dashboard_prompt_1">Dashboard prompt 1</label>
                            <textarea id="dashboard_prompt_1" name="dashboard_prompt_1"><?php echo h($dashboard['dashboard_prompt_1']); ?></textarea>
                        </div>
                        <div class="field">
                            <label for="dashboard_prompt_2">Dashboard prompt 2</label>
                            <textarea id="dashboard_prompt_2" name="dashboard_prompt_2"><?php echo h($dashboard['dashboard_prompt_2']); ?></textarea>
                        </div>
                    </div>

                    <div class="field">
                        <label for="id_template">Template</label>
                        <select id="id_template" name="id_template">
                            <option value="0">No template</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo h($template['id']); ?>"<?php echo ((string)$dashboard['id_template'] === (string)$template['id']) ? ' selected' : ''; ?>>
                                    #<?php echo h($template['id']); ?> - <?php echo h($template['title']); ?><?php echo ((int)($template['is_public'] ?? 0) === 1 && (int)($template['id_owner'] ?? 0) !== (int)$user['id']) ? ' (created by ' . h($template['owner_username'] ?? ('user #' . $template['id_owner'])) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="inline-actions">
                        <button type="submit">Save changes</button>
                        <a href="dashboards.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
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

