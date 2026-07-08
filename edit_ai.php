<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/ai_shared.php';

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
$ai = null;
$aiId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$formData = [
    'title' => '',
    'provider' => '',
    'model' => '',
    'api_key' => '',
    'web_end_point' => '',
    'is_public' => '0',
    'is_hidden' => '0',
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

    mdashEnsureAiDbTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_ai') {
        foreach ($formData as $key => $value) {
            $formData[$key] = trim((string)($_POST[$key] ?? $value));
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid AI profile ID.');
        }
        if ($formData['title'] === '') {
            throw new RuntimeException('AI profile title is required.');
        }
        if ($formData['provider'] === '') {
            throw new RuntimeException('AI provider is required.');
        }
        if ($formData['model'] === '') {
            throw new RuntimeException('AI model is required.');
        }
        if ($formData['api_key'] === '') {
            throw new RuntimeException('AI API key is required.');
        }
        if ($formData['web_end_point'] === '') {
            throw new RuntimeException('AI endpoint is required.');
        }

        $stmt = $pdo->prepare(
            'UPDATE ai_db SET title = ?, provider = ?, model = ?, api_key = ?, web_end_point = ?, is_public = ?, is_hidden = ?, date_creation = NOW() WHERE id = ? AND id_owner = ?'
        );
        $stmt->execute([
            $formData['title'],
            $formData['provider'],
            $formData['model'],
            $formData['api_key'],
            $formData['web_end_point'],
            (int)($formData['is_public'] === '1' ? 1 : 0),
            (int)($formData['is_hidden'] === '1' ? 1 : 0),
            $id,
            (int)$user['id'],
        ]);

        header('Location: ai_db.php?updated=1');
        exit;
    }

    if ($aiId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM ai_db WHERE id = ? LIMIT 1');
        $stmt->execute([$aiId]);
        $ai = $stmt->fetch();

        if (!$ai) {
            throw new RuntimeException('AI profile not found.');
        }
        if ((int)$ai['id_owner'] !== (int)$user['id']) {
            throw new RuntimeException('You can only edit your own AI profiles.');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $formData = [
                'title' => (string)($ai['title'] ?? ''),
                'provider' => (string)($ai['provider'] ?? ''),
                'model' => (string)($ai['model'] ?? ''),
                'api_key' => (string)($ai['api_key'] ?? ''),
                'web_end_point' => (string)($ai['web_end_point'] ?? ''),
                'is_public' => (string)((int)($ai['is_public'] ?? 0)),
                'is_hidden' => (string)((int)($ai['is_hidden'] ?? 0)),
            ];
        }
    } else {
        throw new RuntimeException('Invalid AI profile ID.');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit AI Profile</title>
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
                <h1>Edit AI Profile</h1>
                <div class="meta">Update the endpoint, key, and visibility of the selected profile.</div>
            </div>
            <a href="ai_db.php">Back to list</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($ai): ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="save_ai">
                    <input type="hidden" name="id" value="<?php echo h($ai['id']); ?>">

                    <div class="field">
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" maxlength="255" value="<?php echo h($formData['title']); ?>" required>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="provider">Provider</label>
                            <input type="text" id="provider" name="provider" maxlength="100" value="<?php echo h($formData['provider']); ?>" required>
                        </div>
                        <div class="field">
                            <label for="model">Model</label>
                            <input type="text" id="model" name="model" maxlength="100" value="<?php echo h($formData['model']); ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="api_key">API key</label>
                        <textarea id="api_key" name="api_key" rows="3" required><?php echo h($formData['api_key']); ?></textarea>
                    </div>
                    <div class="field">
                        <label for="web_end_point">Web endpoint</label>
                        <textarea id="web_end_point" name="web_end_point" rows="3" required><?php echo h($formData['web_end_point']); ?></textarea>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="is_public">Visibility</label>
                            <select id="is_public" name="is_public">
                                <option value="0"<?php echo $formData['is_public'] === '0' ? ' selected' : ''; ?>>Private</option>
                                <option value="1"<?php echo $formData['is_public'] === '1' ? ' selected' : ''; ?>>Public</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="is_hidden">Status</label>
                            <select id="is_hidden" name="is_hidden">
                                <option value="0"<?php echo $formData['is_hidden'] === '0' ? ' selected' : ''; ?>>Visible</option>
                                <option value="1"<?php echo $formData['is_hidden'] === '1' ? ' selected' : ''; ?>>Hidden</option>
                            </select>
                        </div>
                    </div>
                    <div class="inline-actions">
                        <button type="submit">Save changes</button>
                        <a href="ai_db.php" class="btn-secondary">Cancel</a>
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