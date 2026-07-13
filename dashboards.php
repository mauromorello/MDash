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
$dashboards = [];
$error = '';
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

    mdashEnsureDashboardAiColumn($pdo);
    mdashEnsureAiDbTable($pdo);
    mdashEnsureDashboardDatasourceMapTable($pdo);
} catch (PDOException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_dashboard' && $pdo) {
    $dashboardId = (int)($_POST['id'] ?? 0);
    if ($dashboardId > 0) {
        try {
            $pdo->prepare('DELETE FROM dashboard_datasources WHERE id_dashboard = ?')->execute([$dashboardId]);
            $stmt = $pdo->prepare('DELETE FROM dashboards WHERE id = ?');
            $stmt->execute([$dashboardId]);
            header('Location: dashboards.php?deleted=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Error while deleting the dashboard: ' . $e->getMessage();
        }
    }
}

if ($pdo) {
    try {
        $stmt = $pdo->query(
            'SELECT d.*, u.filename AS datasource_filename, a.title AS ai_title, a.provider AS ai_provider, a.model AS ai_model,
                    (
                        SELECT GROUP_CONCAT(CONCAT("#", ud.id, " - ", ud.filename) ORDER BY dd.sort_order SEPARATOR "\n")
                        FROM dashboard_datasources dd
                        LEFT JOIN uploads ud ON ud.id = dd.id_datasource
                        WHERE dd.id_dashboard = d.id
                    ) AS datasource_list
             FROM dashboards d
             LEFT JOIN uploads u ON u.id = d.id_datasource
             LEFT JOIN ai_db a ON a.id = d.id_ai_db
             ORDER BY d.id DESC'
        );
        $dashboards = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Error while loading dashboards: ' . $e->getMessage();
    }
}

if (!empty($_GET['created'])) {
    $message = 'Dashboard created successfully.';
} elseif (!empty($_GET['deleted'])) {
    $message = 'Dashboard deleted successfully.';
} elseif (!empty($_GET['updated'])) {
    $message = 'Dashboard updated successfully.';
}
?>
<?php $pageTitle = 'Dashboard List'; include __DIR__ . '/header.php'; ?>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Dashboard List</h1>
                <div class="meta">Manage created dashboards, inspect prompts, and edit details.</div>
            </div>
            <div class="inline-actions">
                <a href="dashboard_builder.php">New dashboard</a>
                <a href="ai_db.php">AI profiles</a>
                <a href="makeup.php">Makeup library</a>
                <a href="results.php">Generated results</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (empty($dashboards)): ?>
            <div class="card">
                <p>No dashboards have been created yet.</p>
                <p><a href="dashboard_builder.php">Create the first dashboard</a></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Created At</th>
                            <th>Data Source</th>
                            <th>Makeup ID</th>
                            <th>Template ID</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dashboards as $dashboard): ?>
                            <tr>
                                <td><?php echo h($dashboard['id']); ?></td>
                                <td><?php echo h($dashboard['title']); ?></td>
                                <td><?php echo h($dashboard['date_creation']); ?></td>
                                <td>
                                    <?php if (!empty($dashboard['datasource_list'])): ?>
                                        <div class="meta prewrap-meta"><?php echo h($dashboard['datasource_list']); ?></div>
                                    <?php elseif (!empty($dashboard['id_datasource'])): ?>
                                        #<?php echo h($dashboard['id_datasource']); ?>
                                        <?php if (!empty($dashboard['datasource_filename'])): ?>
                                            <div class="meta"><?php echo h($dashboard['datasource_filename']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($dashboard['id_makeup']); ?></td>
                                <td><?php echo h($dashboard['id_template']); ?></td>
                                <td>
                                    <div class="inline-actions">
                                        <a href="dashboard_prompt.php?id=<?php echo h($dashboard['id']); ?>">Generate prompt</a>
                                        <a href="edit_dashboard.php?id=<?php echo h($dashboard['id']); ?>">Edit</a>
                                        <form method="post" onsubmit="return confirm('Delete this dashboard?');">
                                            <input type="hidden" name="action" value="delete_dashboard">
                                            <input type="hidden" name="id" value="<?php echo h($dashboard['id']); ?>">
                                            <button type="submit" class="btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="preview-<?php echo h($dashboard['id']); ?>" class="preview-row">
                                <td colspan="7">
                                    <div class="preview-panel">
                                        <h3>Data filter prompt</h3>
                                        <p><?php echo h($dashboard['data_filter_prompt']); ?></p>
                                        <h3>Data manipulation prompt</h3>
                                        <p><?php echo h($dashboard['data_manipulation_prompt']); ?></p>
                                        <h3>Dashboard prompt 1</h3>
                                        <p><?php echo h($dashboard['dashboard_prompt_1']); ?></p>
                                        <h3>Dashboard prompt 2</h3>
                                        <p><?php echo h($dashboard['dashboard_prompt_2']); ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>
        document.querySelectorAll('.preview-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = button.getAttribute('data-target');
                const row = document.getElementById(targetId);
                if (!row) {
                    return;
                }
                row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
            });
        });

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


