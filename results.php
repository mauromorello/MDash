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

function buildAbsolutePath(string $relativePath): string {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return $scheme . '://' . $host . ($basePath !== '' ? $basePath . '/' : '/') . $relativePath;
}

function removeDirectoryRecursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeDirectoryRecursive($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function parseDataUrlImage(string $dataUrl): array {
    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/i', $dataUrl, $matches)) {
        throw new RuntimeException('Invalid image data format.');
    }

    $type = strtolower($matches[1]);
    $base64 = $matches[2];
    $binary = base64_decode($base64, true);
    if ($binary === false) {
        throw new RuntimeException('Invalid base64 image data.');
    }

    if (strlen($binary) > 8 * 1024 * 1024) {
        throw new RuntimeException('Image is too large. Maximum allowed size is 8MB.');
    }

    $extension = $type === 'jpeg' ? 'jpg' : $type;

    return [
        'extension' => $extension,
        'binary' => $binary,
    ];
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
$results = [];
$error = '';
$message = '';
$showHidden = isset($_GET['show_hidden']) && (int)$_GET['show_hidden'] === 1;
$requestedAction = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$expectsJson = ($requestedAction === 'get_html_code') || ($requestedAction === 'save_html_code' && ($_POST['ajax'] ?? '') === '1');

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
    mdashEnsureAiDbTable($pdo);

    if (($_GET['action'] ?? '') === 'get_html_code') {
        $resultId = (int)($_GET['result_id'] ?? 0);
        header('Content-Type: application/json');

        if ($resultId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid result id.']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT id, id_owner, path, `HTML`, is_public, is_hidden
             FROM results
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$resultId]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Result not found.']);
            exit;
        }

        $isOwner = (int)$row['id_owner'] === (int)$user['id'];
        $isVisiblePublic = (int)$row['is_public'] === 1 && (int)$row['is_hidden'] === 0;
        if (!$isOwner && !$isVisiblePublic) {
            echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            exit;
        }

        $htmlCode = (string)($row['HTML'] ?? '');
        if ($htmlCode === '' && !empty($row['path'])) {
            $diskPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$row['path']);
            if (is_file($diskPath)) {
                $htmlCode = (string)file_get_contents($diskPath);
            }
        }

        echo json_encode(['success' => true, 'html' => $htmlCode]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $resultId = (int)($_POST['result_id'] ?? 0);

        if ($resultId > 0 && in_array($action, ['set_hidden', 'delete_result', 'save_thumbnail', 'save_html_code'], true)) {
            $ownerStmt = $pdo->prepare('SELECT id, id_owner, path FROM results WHERE id = ? LIMIT 1');
            $ownerStmt->execute([$resultId]);
            $ownerRow = $ownerStmt->fetch();

            if (!$ownerRow) {
                throw new RuntimeException('Result not found.');
            }

            if ((int)$ownerRow['id_owner'] !== (int)$user['id']) {
                throw new RuntimeException('You can only modify your own dashboards.');
            }

            if ($action === 'set_hidden') {
                $hiddenValue = (int)($_POST['hidden_value'] ?? 0) === 1 ? 1 : 0;
                $updateStmt = $pdo->prepare('UPDATE results SET is_hidden = ? WHERE id = ? AND id_owner = ?');
                $updateStmt->execute([$hiddenValue, $resultId, (int)$user['id']]);
                $message = $hiddenValue === 1 ? 'Dashboard hidden successfully.' : 'Dashboard revealed successfully.';
            }

            if ($action === 'delete_result') {
                $deleteStmt = $pdo->prepare('DELETE FROM results WHERE id = ? AND id_owner = ?');
                $deleteStmt->execute([$resultId, (int)$user['id']]);

                $resultFolder = __DIR__ . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . $resultId;
                removeDirectoryRecursive($resultFolder);
                $message = 'Dashboard deleted permanently.';
            }

            if ($action === 'save_thumbnail') {
                $thumbnailData = (string)($_POST['thumbnail_data'] ?? '');
                if ($thumbnailData === '') {
                    throw new RuntimeException('No screenshot data provided.');
                }

                $parsed = parseDataUrlImage($thumbnailData);
                $resultFolder = __DIR__ . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . $resultId;
                if (!is_dir($resultFolder) && !mkdir($resultFolder, 0777, true) && !is_dir($resultFolder)) {
                    throw new RuntimeException('Unable to create result folder for thumbnail.');
                }

                $relativeThumbPath = 'results/' . $resultId . '/thumbnail.' . $parsed['extension'];
                $diskThumbPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeThumbPath);
                file_put_contents($diskThumbPath, $parsed['binary']);

                $thumbStmt = $pdo->prepare('UPDATE results SET thumbnail_path = ? WHERE id = ? AND id_owner = ?');
                $thumbStmt->execute([$relativeThumbPath, $resultId, (int)$user['id']]);
                $message = 'Screenshot thumbnail saved successfully.';
            }

            if ($action === 'save_html_code') {
                $htmlCode = (string)($_POST['html_code'] ?? '');
                if (trim($htmlCode) === '') {
                    throw new RuntimeException('HTML code cannot be empty.');
                }

                $relativePath = (string)($ownerRow['path'] ?? '');
                if ($relativePath === '') {
                    throw new RuntimeException('Saved output path not found.');
                }

                $diskPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $dirPath = dirname($diskPath);
                if (!is_dir($dirPath) && !mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
                    throw new RuntimeException('Unable to create output directory for HTML file.');
                }

                file_put_contents($diskPath, $htmlCode);

                $htmlStmt = $pdo->prepare('UPDATE results SET `HTML` = ? WHERE id = ? AND id_owner = ?');
                $htmlStmt->execute([$htmlCode, $resultId, (int)$user['id']]);

                if (($_POST['ajax'] ?? '') === '1') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'HTML code saved successfully.']);
                    exit;
                }

                $message = 'HTML code saved successfully.';
            }
        }
    }

    $visibilityWhere = $showHidden
        ? '((r.id_owner = :user_id) OR (r.is_public = 1 AND r.is_hidden = 0))'
        : '((r.id_owner = :user_id AND r.is_hidden = 0) OR (r.is_public = 1 AND r.is_hidden = 0))';

    $stmt = $pdo->prepare(
           'SELECT r.*, t.title AS template_title, u.username AS owner_username, a.title AS ai_title, a.provider AS ai_provider, a.model AS ai_model
         FROM results r
         LEFT JOIN templates t ON t.id = r.id_template
         LEFT JOIN users u ON u.id = r.id_owner
            LEFT JOIN ai_db a ON a.id = r.id_ai_db
         WHERE ' . $visibilityWhere . '
         ORDER BY r.id DESC'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    $results = $stmt->fetchAll();
} catch (Throwable $e) {
    if ($expectsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material.min.css">
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
                <h1>Results</h1>
                <div class="meta">Review generated dashboards. You only see your dashboards or public ones.</div>
            </div>
            <a href="dashboard_builder.php">New dashboard</a>
        </div>

        <div class="card results-controls">
            <div class="meta">
                Hidden dashboards are excluded by default.
            </div>
            <?php if ($showHidden): ?>
                <a href="results.php" class="btn-secondary">Hide hidden dashboards</a>
            <?php else: ?>
                <a href="results.php?show_hidden=1" class="btn-secondary">Reveal hidden dashboards</a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (empty($results)): ?>
            <div class="card">
                <p class="empty">No generated dashboards found yet.</p>
            </div>
        <?php else: ?>
            <div class="results-grid">
                <?php foreach ($results as $result): ?>
                    <div class="card result-card">
                        <div class="result-owner">
                            Owner: <?php echo h($result['owner_username'] ?: ('User #' . $result['id_owner'])); ?>
                        </div>
                        <div class="thumbnail-box">
                            <?php if (!empty($result['thumbnail_path']) && is_file(__DIR__ . DIRECTORY_SEPARATOR . $result['thumbnail_path'])): ?>
                                <img src="<?php echo h($result['thumbnail_path']); ?>" alt="Thumbnail for result <?php echo h($result['id']); ?>">
                            <?php else: ?>
                                <span>No thumbnail available</span>
                            <?php endif; ?>
                        </div>
                        <div class="result-meta">
                            <strong>Result #<?php echo h($result['id']); ?></strong>
                            <span>Template: <?php echo h($result['template_title'] ?: ('#' . $result['id_template'])); ?></span>
                            <span>AI: <?php echo h($result['ai_title'] ?: ('#' . $result['id_ai_db'])); ?><?php echo !empty($result['ai_provider']) || !empty($result['ai_model']) ? ' (' . h($result['ai_provider']) . ' / ' . h($result['ai_model']) . ')' : ''; ?></span>
                            <span>Visibility: <?php echo ((int)$result['is_public'] === 1) ? 'Public' : 'Private'; ?><?php echo ((int)$result['is_hidden'] === 1) ? ' / Hidden' : ''; ?></span>
                            <span>Saved path: <?php echo h($result['path']); ?></span>
                        </div>

                        <div class="result-actions">
                            <a class="open-link" href="<?php echo h($result['path']); ?>" target="_blank" rel="noopener">Open dashboard</a>

                            <?php if ((int)$result['id_owner'] === (int)$user['id']): ?>
                                <button type="button" class="btn-ghost icon-btn paste-thumb-btn" data-result-id="<?php echo h($result['id']); ?>" title="Paste screenshot" aria-label="Paste screenshot">📋</button>

                                <button type="button" class="btn-ghost icon-btn edit-code-btn" data-result-id="<?php echo h($result['id']); ?>" title="Edit code" aria-label="Edit code">&lt;/&gt;</button>

                                <form method="post" class="thumbnail-form" id="thumbnailForm<?php echo h($result['id']); ?>">
                                    <input type="hidden" name="action" value="save_thumbnail">
                                    <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                    <input type="hidden" name="thumbnail_data" value="" class="thumbnail-data-input">
                                </form>

                                <form method="post">
                                    <input type="hidden" name="action" value="set_hidden">
                                    <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                    <input type="hidden" name="hidden_value" value="<?php echo ((int)$result['is_hidden'] === 1) ? '0' : '1'; ?>">
                                    <button type="submit" class="btn-ghost icon-btn" title="<?php echo ((int)$result['is_hidden'] === 1) ? 'Reveal dashboard' : 'Hide dashboard'; ?>" aria-label="<?php echo ((int)$result['is_hidden'] === 1) ? 'Reveal dashboard' : 'Hide dashboard'; ?>"><?php echo ((int)$result['is_hidden'] === 1) ? '👁️' : '🙈'; ?></button>
                                </form>

                                <form method="post" onsubmit="return confirm('Delete this dashboard permanently? This action removes DB record and files.');">
                                    <input type="hidden" name="action" value="delete_result">
                                    <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                    <button type="submit" class="btn-danger icon-btn" title="Delete permanently" aria-label="Delete permanently">🗑️</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="editCodeModal" class="code-modal hidden" role="dialog" aria-modal="true" aria-labelledby="codeModalTitle">
        <div class="code-modal-backdrop" data-close-modal="1"></div>
        <div class="code-modal-content">
            <div class="code-modal-header">
                <h2 id="codeModalTitle">Edit generated HTML</h2>
                <button type="button" id="closeCodeModalBtn" class="secondary">Cancel</button>
            </div>
            <div class="code-modal-body">
                <textarea id="codeEditorArea"></textarea>
            </div>
            <div class="code-modal-actions">
                <button type="button" id="saveCodeBtn">Save code</button>
                <button type="button" id="cancelCodeBtn" class="secondary">Cancel</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.15.1/beautify-html.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
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

        async function readImageFromClipboard() {
            if (!navigator.clipboard || !navigator.clipboard.read) {
                return '';
            }

            const items = await navigator.clipboard.read();
            for (const item of items) {
                for (const type of item.types) {
                    if (!type.startsWith('image/')) {
                        continue;
                    }

                    const blob = await item.getType(type);
                    const dataUrl = await new Promise((resolve, reject) => {
                        const reader = new FileReader();
                        reader.onload = () => resolve(String(reader.result || ''));
                        reader.onerror = () => reject(new Error('Unable to read screenshot data.'));
                        reader.readAsDataURL(blob);
                    });

                    return dataUrl;
                }
            }

            return '';
        }

        const pasteButtons = document.querySelectorAll('.paste-thumb-btn');
        pasteButtons.forEach((button) => {
            button.addEventListener('click', async function () {
                const resultId = button.getAttribute('data-result-id');
                const form = document.getElementById('thumbnailForm' + resultId);
                if (!form) {
                    return;
                }

                const input = form.querySelector('.thumbnail-data-input');
                if (!input) {
                    return;
                }

                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = '...';

                try {
                    const dataUrl = await readImageFromClipboard();
                    if (!dataUrl) {
                        alert('No image found in clipboard. Copy a screenshot first, then click again.');
                        return;
                    }

                    input.value = dataUrl;
                    form.submit();
                } catch (err) {
                    alert('Unable to read clipboard image. Browser permissions may be required.');
                } finally {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
        });

        const editCodeModal = document.getElementById('editCodeModal');
        const closeCodeModalBtn = document.getElementById('closeCodeModalBtn');
        const cancelCodeBtn = document.getElementById('cancelCodeBtn');
        const saveCodeBtn = document.getElementById('saveCodeBtn');
        const codeEditorArea = document.getElementById('codeEditorArea');
        const editCodeButtons = document.querySelectorAll('.edit-code-btn');
        let currentEditResultId = 0;
        let codeEditor = null;

        function ensureCodeEditor() {
            if (codeEditor || !codeEditorArea || typeof CodeMirror === 'undefined') {
                return;
            }

            codeEditor = CodeMirror.fromTextArea(codeEditorArea, {
                mode: 'text/html',
                theme: 'material',
                lineNumbers: true,
                lineWrapping: true,
                indentUnit: 2,
                tabSize: 2,
            });
            codeEditor.setSize('100%', '60vh');
        }

        function openCodeModal() {
            editCodeModal.classList.remove('hidden');
            ensureCodeEditor();
            if (codeEditor) {
                codeEditor.refresh();
            }
        }

        function closeCodeModal() {
            editCodeModal.classList.add('hidden');
            currentEditResultId = 0;
        }

        async function loadResultHtml(resultId) {
            const response = await fetch('results.php?action=get_html_code&result_id=' + encodeURIComponent(String(resultId)));
            const payload = await response.json();
            if (!payload || !payload.success) {
                throw new Error(payload && payload.message ? payload.message : 'Unable to load HTML code.');
            }

            return String(payload.html || '');
        }

        editCodeButtons.forEach((button) => {
            button.addEventListener('click', async function () {
                const resultId = Number(button.getAttribute('data-result-id') || '0');
                if (!resultId) {
                    return;
                }

                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = '...';

                try {
                    currentEditResultId = resultId;
                    let htmlCode = await loadResultHtml(resultId);

                    if (typeof html_beautify === 'function') {
                        htmlCode = html_beautify(htmlCode, {
                            indent_size: 2,
                            preserve_newlines: true,
                            wrap_line_length: 120,
                        });
                    }

                    openCodeModal();
                    if (codeEditor) {
                        codeEditor.setValue(htmlCode);
                        codeEditor.focus();
                    } else if (codeEditorArea) {
                        codeEditorArea.value = htmlCode;
                    }
                } catch (err) {
                    alert(err.message || 'Unable to load HTML code.');
                } finally {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
        });

        async function saveCodeChanges() {
            if (!currentEditResultId) {
                return;
            }

            const htmlCode = codeEditor ? codeEditor.getValue() : String(codeEditorArea.value || '');
            const formData = new FormData();
            formData.append('action', 'save_html_code');
            formData.append('result_id', String(currentEditResultId));
            formData.append('html_code', htmlCode);
            formData.append('ajax', '1');

            const response = await fetch('results.php', {
                method: 'POST',
                body: formData,
            });
            const payload = await response.json();
            if (!payload || !payload.success) {
                throw new Error(payload && payload.message ? payload.message : 'Unable to save HTML code.');
            }

            alert(payload.message || 'HTML code saved successfully.');
            closeCodeModal();
        }

        if (saveCodeBtn) {
            saveCodeBtn.addEventListener('click', async function () {
                saveCodeBtn.disabled = true;
                const originalText = saveCodeBtn.textContent;
                saveCodeBtn.textContent = 'Saving...';
                try {
                    await saveCodeChanges();
                } catch (err) {
                    alert(err.message || 'Unable to save HTML code.');
                } finally {
                    saveCodeBtn.disabled = false;
                    saveCodeBtn.textContent = originalText;
                }
            });
        }

        if (cancelCodeBtn) {
            cancelCodeBtn.addEventListener('click', function () {
                closeCodeModal();
            });
        }

        if (closeCodeModalBtn) {
            closeCodeModalBtn.addEventListener('click', function () {
                closeCodeModal();
            });
        }

        document.querySelectorAll('[data-close-modal="1"]').forEach((node) => {
            node.addEventListener('click', function () {
                closeCodeModal();
            });
        });
    </script>
</body>
</html>
