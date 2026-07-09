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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="main-home-content">
        <div class="page main-home-panel">
            <h1>Welcome</h1>
            <p>Use the guided flow below, then open the section you need.</p>

            <div class="workflow-visual">
                <h2>Workflow Guide</h2>
                <p>Configure your stack first, then build dashboards, then manage your dashboard pool.</p>
                <div class="workflow-steps">
                    <div class="workflow-step">
                        <strong>1) Configuration</strong>
                        <span>AI profile, template, and markup setup.</span>
                    </div>
                    <div class="workflow-arrow">&rarr;</div>
                    <div class="workflow-step">
                        <strong>2) Dashboard Build</strong>
                        <span>Generate dashboard from data sources.</span>
                    </div>
                    <div class="workflow-arrow">&rarr;</div>
                    <div class="workflow-step">
                        <strong>3) Dashboard Pool</strong>
                        <span>Review and manage created dashboards.</span>
                    </div>
                </div>
            </div>

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
                </div>

                <div class="main-section-card">
                    <h2>Results</h2>
                    <div class="main-action-item">
                        <a href="results.php" class="btn btn-secondary">Results Hub</a>
                        <p class="main-action-desc">Review generated outputs, previews, and final artifacts.</p>
                    </div>
                </div>
            </div>
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
