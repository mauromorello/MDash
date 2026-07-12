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

$user = getUserFromSessionOrCookie();
if (!$user) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/ai_shared.php';

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$error = '';
$message = '';
$result = null;
$resultId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($resultId <= 0) {
    $error = 'Invalid result id.';
}

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

    if ($resultId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM results WHERE id = ? LIMIT 1');
        $stmt->execute([$resultId]);
        $result = $stmt->fetch();

        if (!$result) {
            $error = 'Result not found.';
        } elseif ((int)$result['id_owner'] !== (int)$user['id']) {
            http_response_code(403);
            $error = 'Not authorized. You can edit only your own dashboards.';
            $result = null;
        }
    }

    if (!$error && $result && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_result') {
        $path = trim((string)($_POST['path'] ?? ''));
        $idTemplate = (int)($_POST['id_template'] ?? 0);
        $idAiDb = (int)($_POST['id_ai_db'] ?? 0);
        $aiTitle = trim((string)($_POST['ai_title'] ?? ''));
        $aiProvider = trim((string)($_POST['ai_provider'] ?? ''));
        $aiModel = trim((string)($_POST['ai_model'] ?? ''));
        $finalPrompt = (string)($_POST['final_prompt'] ?? '');
        $thumbnailPath = trim((string)($_POST['thumbnail_path'] ?? ''));
        $htmlCode = (string)($_POST['html_code'] ?? '');
        $isPublic = (int)($_POST['is_public'] ?? 0) === 1 ? 1 : 0;
        $isHidden = (int)($_POST['is_hidden'] ?? 0) === 1 ? 1 : 0;
        $nViews = max(0, (int)($_POST['n_views'] ?? 0));
        $nDownload = max(0, (int)($_POST['n_download'] ?? 0));
        $nClone = max(0, (int)($_POST['n_clone'] ?? 0));
        $tags = trim((string)($_POST['tags'] ?? ''));

        if ($path === '') {
            throw new RuntimeException('Path cannot be empty.');
        }
        if (trim($htmlCode) === '') {
            throw new RuntimeException('HTML code cannot be empty.');
        }

        $diskPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $dirPath = dirname($diskPath);
        if (!is_dir($dirPath) && !mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
            throw new RuntimeException('Unable to create output directory for HTML file.');
        }
        file_put_contents($diskPath, $htmlCode);

        $updateStmt = $pdo->prepare(
            'UPDATE results
             SET path = ?, id_template = ?, id_ai_db = ?, ai_title = ?, ai_provider = ?, ai_model = ?, final_prompt = ?, thumbnail_path = ?, `HTML` = ?, is_public = ?, is_hidden = ?, n_views = ?, n_download = ?, n_clone = ?, tags = ?
             WHERE id = ? AND id_owner = ?'
        );
        $updateStmt->execute([
            $path,
            $idTemplate,
            $idAiDb,
            $aiTitle,
            $aiProvider,
            $aiModel,
            $finalPrompt,
            $thumbnailPath,
            $htmlCode,
            $isPublic,
            $isHidden,
            $nViews,
            $nDownload,
            $nClone,
            $tags,
            (int)$resultId,
            (int)$user['id'],
        ]);

        header('Location: edit_result.php?id=' . (int)$resultId . '&updated=1');
        exit;
    }

    if (!$error && !empty($_GET['updated'])) {
        $message = 'Result updated successfully.';
    }

    if (!$error && $resultId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM results WHERE id = ? AND id_owner = ? LIMIT 1');
        $stmt->execute([(int)$resultId, (int)$user['id']]);
        $result = $stmt->fetch() ?: null;
        if (!$result) {
            $error = 'Result not found.';
        }
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
    <title>Edit Result</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="stylesheet" href="assets/app.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/app.css'); ?>">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Edit Result</h1>
                <div class="meta">Edit full result record fields and generated HTML markup.</div>
            </div>
            <a href="results.php">Back to results</a>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (!$result): ?>
            <div class="card"><p class="empty">Result not available.</p></div>
        <?php else: ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="save_result">
                    <input type="hidden" name="id" value="<?php echo h((int)$result['id']); ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label>ID</label>
                            <input type="text" value="<?php echo h((int)$result['id']); ?>" readonly>
                        </div>
                        <div class="field">
                            <label>Owner ID</label>
                            <input type="text" value="<?php echo h((int)$result['id_owner']); ?>" readonly>
                        </div>
                    </div>

                    <div class="field">
                        <label for="path">Path</label>
                        <input type="text" id="path" name="path" value="<?php echo h($result['path'] ?? ''); ?>" required>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="id_template">Template ID</label>
                            <input type="text" id="id_template" name="id_template" value="<?php echo h((int)($result['id_template'] ?? 0)); ?>">
                        </div>
                        <div class="field">
                            <label for="id_ai_db">AI DB ID</label>
                            <input type="text" id="id_ai_db" name="id_ai_db" value="<?php echo h((int)($result['id_ai_db'] ?? 0)); ?>">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="ai_title">AI Title</label>
                            <input type="text" id="ai_title" name="ai_title" value="<?php echo h($result['ai_title'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="ai_provider">AI Provider</label>
                            <input type="text" id="ai_provider" name="ai_provider" value="<?php echo h($result['ai_provider'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="ai_model">AI Model</label>
                        <input type="text" id="ai_model" name="ai_model" value="<?php echo h($result['ai_model'] ?? ''); ?>">
                    </div>

                    <div class="field">
                        <label for="thumbnail_path">Thumbnail Path</label>
                        <input type="text" id="thumbnail_path" name="thumbnail_path" value="<?php echo h($result['thumbnail_path'] ?? ''); ?>">
                    </div>

                    <div class="field">
                        <label for="tags">Tags</label>
                        <textarea id="tags" name="tags"><?php echo h($result['tags'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="final_prompt">Final Prompt</label>
                        <textarea id="final_prompt" name="final_prompt" class="master-prompt-area"><?php echo h($result['final_prompt'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="is_public">Public</label>
                            <select id="is_public" name="is_public">
                                <option value="0"<?php echo ((int)($result['is_public'] ?? 0) === 0) ? ' selected' : ''; ?>>No</option>
                                <option value="1"<?php echo ((int)($result['is_public'] ?? 0) === 1) ? ' selected' : ''; ?>>Yes</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="is_hidden">Hidden</label>
                            <select id="is_hidden" name="is_hidden">
                                <option value="0"<?php echo ((int)($result['is_hidden'] ?? 0) === 0) ? ' selected' : ''; ?>>No</option>
                                <option value="1"<?php echo ((int)($result['is_hidden'] ?? 0) === 1) ? ' selected' : ''; ?>>Yes</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="n_views">Views</label>
                            <input type="text" id="n_views" name="n_views" value="<?php echo h((int)($result['n_views'] ?? 0)); ?>">
                        </div>
                        <div class="field">
                            <label for="n_download">Downloads</label>
                            <input type="text" id="n_download" name="n_download" value="<?php echo h((int)($result['n_download'] ?? 0)); ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="n_clone">Clones</label>
                        <input type="text" id="n_clone" name="n_clone" value="<?php echo h((int)($result['n_clone'] ?? 0)); ?>">
                    </div>

                    <div class="field">
                        <label for="html_code">HTML Markup</label>
                        <textarea id="html_code" name="html_code" class="generated-html-area" required><?php echo h($result['HTML'] ?? ''); ?></textarea>
                    </div>

                    <div class="inline-actions">
                        <button type="submit">Save result</button>
                        <a href="results.php" class="btn-secondary">Cancel</a>
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
