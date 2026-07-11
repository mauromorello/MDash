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

function copyDirectoryRecursive(string $source, string $destination): void {
    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        throw new RuntimeException('Unable to create destination directory while cloning dashboard.');
    }

    $items = scandir($source);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($sourcePath)) {
            copyDirectoryRecursive($sourcePath, $destinationPath);
        } else {
            copy($sourcePath, $destinationPath);
        }
    }
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

function extractDashboardTitle(array $result): string {
    $finalPrompt = (string)($result['final_prompt'] ?? '');
    if ($finalPrompt !== '') {
        if (preg_match('/\[Dashboard title\]\s*(.+?)(?:\n\[|$)/si', $finalPrompt, $matches)) {
            $title = trim((string)($matches[1] ?? ''));
            if ($title !== '') {
                $firstLine = preg_split('/\R/', $title, 2);
                return trim((string)($firstLine[0] ?? $title));
            }
        }
    }

    return 'Dashboard #' . (string)($result['id'] ?? '');
}

function replaceDashboardTitleInPrompt(string $finalPrompt, string $newTitle): string {
    if (trim($finalPrompt) === '') {
        return '[Dashboard title]' . "\n" . $newTitle;
    }

    if (preg_match('/\[Dashboard title\]\s*(.+?)(?:\n\[|$)/si', $finalPrompt)) {
        return (string)preg_replace('/(\[Dashboard title\]\s*)(.+?)(?=(\n\[|$))/si', '$1' . $newTitle, $finalPrompt, 1);
    }

    return '[Dashboard title]' . "\n" . $newTitle . "\n\n" . $finalPrompt;
}

function getNextResultId(PDO $pdo): int {
    $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM results');
    $row = $stmt->fetch();
    return (int)($row['next_id'] ?? 1);
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
$expectsJson = ($requestedAction === 'get_html_code')
    || ($requestedAction === 'save_html_code' && ($_POST['ajax'] ?? '') === '1')
    || ($requestedAction === 'clone_result' && ($_POST['ajax'] ?? '') === '1');

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

    $getAction = (string)($_GET['action'] ?? '');
    if (in_array($getAction, ['open_result', 'download_result'], true)) {
        $resultId = (int)($_GET['result_id'] ?? 0);
        if ($resultId <= 0) {
            throw new RuntimeException('Invalid result id.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, id_owner, path, is_public, is_hidden
             FROM results
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$resultId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Result not found.');
        }

        $isOwner = (int)$row['id_owner'] === (int)$user['id'];
        $isVisiblePublic = (int)$row['is_public'] === 1 && (int)$row['is_hidden'] === 0;
        if (!$isOwner && !$isVisiblePublic) {
            throw new RuntimeException('Not authorized.');
        }

        $relativePath = trim((string)($row['path'] ?? ''));
        if ($relativePath === '') {
            throw new RuntimeException('Result path not found.');
        }

        if ($getAction === 'open_result') {
            $pdo->prepare('UPDATE results SET n_views = n_views + 1 WHERE id = ?')->execute([$resultId]);
            header('Location: ' . $relativePath);
            exit;
        }

        $pdo->prepare('UPDATE results SET n_download = n_download + 1 WHERE id = ?')->execute([$resultId]);

        $diskPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($diskPath)) {
            throw new RuntimeException('File not found on disk.');
        }

        $filename = basename($diskPath);
        $mimeType = (string)(mime_content_type($diskPath) ?: 'application/octet-stream');
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string)filesize($diskPath));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        readfile($diskPath);
        exit;
    }

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

        if ($resultId > 0 && in_array($action, ['set_hidden', 'set_public', 'delete_result', 'save_thumbnail', 'save_html_code', 'clone_result'], true)) {
            $ownerStmt = $pdo->prepare('SELECT id, id_owner, path FROM results WHERE id = ? LIMIT 1');
            $ownerStmt->execute([$resultId]);
            $ownerRow = $ownerStmt->fetch();

            if (!$ownerRow) {
                throw new RuntimeException('Result not found.');
            }

            $isOwner = (int)$ownerRow['id_owner'] === (int)$user['id'];
            $isClone = $action === 'clone_result';
            if (!$isOwner && !$isClone) {
                throw new RuntimeException('You can only modify your own dashboards.');
            }

            if ($isClone) {
                $visibilityStmt = $pdo->prepare('SELECT id, id_owner, is_public, is_hidden, path, thumbnail_path, id_template, id_ai_db, ai_title, ai_provider, ai_model, final_prompt, `HTML` FROM results WHERE id = ? LIMIT 1');
                $visibilityStmt->execute([$resultId]);
                $sourceRow = $visibilityStmt->fetch();
                if (!$sourceRow) {
                    throw new RuntimeException('Result not found.');
                }

                $isVisiblePublic = (int)$sourceRow['is_public'] === 1 && (int)$sourceRow['is_hidden'] === 0;
                if (!$isOwner && !$isVisiblePublic) {
                    throw new RuntimeException('Not authorized to clone this dashboard.');
                }

                $newTitle = trim((string)($_POST['clone_title'] ?? ''));
                if ($newTitle === '') {
                    throw new RuntimeException('Clone title is required.');
                }

                $newResultId = getNextResultId($pdo);
                $newResultDir = __DIR__ . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . $newResultId;
                if (!is_dir($newResultDir) && !mkdir($newResultDir, 0777, true) && !is_dir($newResultDir)) {
                    throw new RuntimeException('Unable to create destination folder for clone.');
                }

                $sourceRelativePath = (string)($sourceRow['path'] ?? '');
                $sourceAbsolutePath = $sourceRelativePath !== '' ? __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelativePath) : '';
                $sourceResultDir = ($sourceRelativePath !== '') ? dirname($sourceAbsolutePath) : '';
                if ($sourceResultDir !== '' && is_dir($sourceResultDir)) {
                    copyDirectoryRecursive($sourceResultDir, $newResultDir);
                }

                $newHtmlName = $sourceRelativePath !== '' ? basename($sourceRelativePath) : 'dashboard.html';
                $newPath = 'results/' . $newResultId . '/' . $newHtmlName;
                $newAbsoluteHtmlPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);

                $sourceHtml = (string)($sourceRow['HTML'] ?? '');
                if ($sourceHtml === '' && $sourceAbsolutePath !== '' && is_file($sourceAbsolutePath)) {
                    $sourceHtml = (string)file_get_contents($sourceAbsolutePath);
                }
                if ($sourceHtml !== '') {
                    file_put_contents($newAbsoluteHtmlPath, $sourceHtml);
                }

                $newThumbnailPath = '';
                $sourceThumbRel = trim((string)($sourceRow['thumbnail_path'] ?? ''));
                if ($sourceThumbRel !== '') {
                    $sourceThumbAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceThumbRel);
                    if (is_file($sourceThumbAbs)) {
                        $thumbBase = basename($sourceThumbAbs);
                        $destThumbAbs = $newResultDir . DIRECTORY_SEPARATOR . $thumbBase;
                        if (copy($sourceThumbAbs, $destThumbAbs)) {
                            $newThumbnailPath = 'results/' . $newResultId . '/' . $thumbBase;
                        }
                    }
                }

                $newFinalPrompt = replaceDashboardTitleInPrompt((string)($sourceRow['final_prompt'] ?? ''), $newTitle);

                $insertClone = $pdo->prepare(
                    'INSERT INTO results (id, path, id_template, id_ai_db, ai_title, ai_provider, ai_model, final_prompt, thumbnail_path, `HTML`, id_owner, is_public, is_hidden)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)'
                );
                $insertClone->execute([
                    $newResultId,
                    $newPath,
                    (int)($sourceRow['id_template'] ?? 0),
                    (int)($sourceRow['id_ai_db'] ?? 0),
                    (string)($sourceRow['ai_title'] ?? ''),
                    (string)($sourceRow['ai_provider'] ?? ''),
                    (string)($sourceRow['ai_model'] ?? ''),
                    $newFinalPrompt,
                    $newThumbnailPath,
                    $sourceHtml,
                    (int)$user['id'],
                ]);
                $pdo->prepare('UPDATE results SET n_clone = n_clone + 1 WHERE id = ?')->execute([$resultId]);

                if (($_POST['ajax'] ?? '') === '1') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Dashboard cloned successfully.']);
                    exit;
                }

                $message = 'Dashboard cloned successfully.';
            }

            if ($action === 'set_hidden') {
                $hiddenValue = (int)($_POST['hidden_value'] ?? 0) === 1 ? 1 : 0;
                $updateStmt = $pdo->prepare('UPDATE results SET is_hidden = ? WHERE id = ? AND id_owner = ?');
                $updateStmt->execute([$hiddenValue, $resultId, (int)$user['id']]);
                $message = $hiddenValue === 1 ? 'Dashboard hidden successfully.' : 'Dashboard revealed successfully.';
            }

            if ($action === 'set_public') {
                $publicValue = (int)($_POST['public_value'] ?? 0) === 1 ? 1 : 0;
                $updateStmt = $pdo->prepare('UPDATE results SET is_public = ? WHERE id = ? AND id_owner = ?');
                $updateStmt->execute([$publicValue, $resultId, (int)$user['id']]);
                $message = $publicValue === 1 ? 'Dashboard is now public.' : 'Dashboard is now private.';
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
    <?php include __DIR__ . '/topbar.php'; ?>

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
            <div class="table-wrap">
                <table class="result-hub-table">
                    <thead>
                        <tr>
                            <th style="width:78px;">Thumb</th>
                            <th>Title</th>
                            <th>Owner</th>
                            <th style="min-width:360px;">Operations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <?php $ownerLabel = (string)($result['owner_username'] ?: ('User #' . $result['id_owner'])); ?>
                            <?php $dashboardTitle = extractDashboardTitle($result); ?>
                            <?php $isOwner = (int)$result['id_owner'] === (int)$user['id']; ?>
                            <tr>
                                <td>
                                    <div class="result-thumb-64">
                                        <?php if (!empty($result['thumbnail_path']) && is_file(__DIR__ . DIRECTORY_SEPARATOR . $result['thumbnail_path'])): ?>
                                            <img src="<?php echo h($result['thumbnail_path']); ?>" alt="Thumbnail for result <?php echo h($result['id']); ?>">
                                        <?php else: ?>
                                            <span>-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo h($dashboardTitle); ?></strong>
                                    <div class="meta">#<?php echo h((int)$result['id']); ?></div>
                                    <div class="result-stats-badges" aria-label="Dashboard stats">
                                        <span class="result-stat-badge" title="Views">👁️ <?php echo h((int)($result['n_views'] ?? 0)); ?></span>
                                        <span class="result-stat-badge" title="Downloads">⬇️ <?php echo h((int)($result['n_download'] ?? 0)); ?></span>
                                        <span class="result-stat-badge" title="Clones">🧬 <?php echo h((int)($result['n_clone'] ?? 0)); ?></span>
                                    </div>
                                </td>
                                <td><?php echo h($ownerLabel); ?></td>
                                <td>
                                    <div class="result-actions">
                                        <a class="btn-ghost icon-btn" href="results.php?action=open_result&amp;result_id=<?php echo h((int)$result['id']); ?>" target="_blank" rel="noopener" title="Open dashboard" aria-label="Open dashboard">🔗</a>
                                        <a class="btn-ghost icon-btn" href="results.php?action=download_result&amp;result_id=<?php echo h((int)$result['id']); ?>" title="Download dashboard" aria-label="Download dashboard">⬇️</a>

                                        <?php if ($isOwner): ?>
                                            <button type="button" class="btn-ghost icon-btn paste-thumb-btn" data-result-id="<?php echo h($result['id']); ?>" title="Paste thumbnail" aria-label="Paste thumbnail">📋</button>
                                        <?php endif; ?>

                                        <?php if ($isOwner): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="set_public">
                                                <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                                <input type="hidden" name="public_value" value="<?php echo ((int)$result['is_public'] === 1) ? '0' : '1'; ?>">
                                                <button type="submit" class="btn-ghost icon-btn" title="<?php echo ((int)$result['is_public'] === 1) ? 'Set private' : 'Set public'; ?>" aria-label="<?php echo ((int)$result['is_public'] === 1) ? 'Set private' : 'Set public'; ?>"><?php echo ((int)$result['is_public'] === 1) ? '🔓' : '🔒'; ?></button>
                                            </form>

                                            <form method="post">
                                                <input type="hidden" name="action" value="set_hidden">
                                                <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                                <input type="hidden" name="hidden_value" value="<?php echo ((int)$result['is_hidden'] === 1) ? '0' : '1'; ?>">
                                                <button type="submit" class="btn-ghost icon-btn" title="<?php echo ((int)$result['is_hidden'] === 1) ? 'Restore dashboard' : 'Hide dashboard'; ?>" aria-label="<?php echo ((int)$result['is_hidden'] === 1) ? 'Restore dashboard' : 'Hide dashboard'; ?>"><?php echo ((int)$result['is_hidden'] === 1) ? '👁️' : '🙈'; ?></button>
                                            </form>

                                            <button type="button" class="btn-danger icon-btn delete-result-btn" data-result-id="<?php echo h($result['id']); ?>" title="Delete dashboard" aria-label="Delete dashboard">🗑️</button>
                                        <?php endif; ?>

                                        <button type="button" class="btn-ghost icon-btn clone-result-btn" data-result-id="<?php echo h($result['id']); ?>" data-title="<?php echo h($dashboardTitle); ?>" title="Clone dashboard" aria-label="Clone dashboard">🧬</button>

                                        <?php if ($isOwner): ?>
                                            <button type="button" class="btn-ghost icon-btn edit-code-btn" data-result-id="<?php echo h($result['id']); ?>" title="Edit code" aria-label="Edit code">&lt;/&gt;</button>
                                        <?php endif; ?>

                                        <?php if ($isOwner): ?>
                                            <form method="post" class="thumbnail-form" id="thumbnailForm<?php echo h($result['id']); ?>">
                                                <input type="hidden" name="action" value="save_thumbnail">
                                                <input type="hidden" name="result_id" value="<?php echo h($result['id']); ?>">
                                                <input type="hidden" name="thumbnail_data" value="" class="thumbnail-data-input">
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <form method="post" id="deleteResultForm" class="hidden">
        <input type="hidden" name="action" value="delete_result">
        <input type="hidden" name="result_id" id="deleteResultId" value="0">
    </form>

    <form method="post" id="cloneResultForm" class="hidden">
        <input type="hidden" name="action" value="clone_result">
        <input type="hidden" name="result_id" id="cloneResultId" value="0">
        <input type="hidden" name="clone_title" id="cloneResultTitle" value="">
    </form>

    <div id="deleteModal" class="code-modal hidden" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="code-modal-backdrop" data-close-delete="1"></div>
        <div class="code-modal-content">
            <div class="code-modal-header">
                <h2 id="deleteModalTitle">Delete dashboard</h2>
                <button type="button" class="secondary" id="deleteModalCancelTop">Cancel</button>
            </div>
            <div class="code-modal-body" style="padding:16px;color:#e2e8f0;">
                This action will permanently delete dashboard files and database record.
            </div>
            <div class="code-modal-actions">
                <button type="button" id="deleteModalConfirm" class="btn-danger">Delete</button>
                <button type="button" id="deleteModalCancel" class="secondary">Cancel</button>
            </div>
        </div>
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

        const cloneButtons = document.querySelectorAll('.clone-result-btn');
        const cloneResultForm = document.getElementById('cloneResultForm');
        const cloneResultId = document.getElementById('cloneResultId');
        const cloneResultTitle = document.getElementById('cloneResultTitle');

        cloneButtons.forEach((button) => {
            button.addEventListener('click', function () {
                if (!cloneResultForm || !cloneResultId || !cloneResultTitle) {
                    return;
                }

                const resultId = String(button.getAttribute('data-result-id') || '0');
                const currentTitle = String(button.getAttribute('data-title') || 'Dashboard');
                const nextTitle = window.prompt('Clone name', currentTitle + ' - Copy');
                if (!nextTitle || nextTitle.trim() === '') {
                    return;
                }

                cloneResultId.value = resultId;
                cloneResultTitle.value = nextTitle.trim();
                cloneResultForm.submit();
            });
        });

        const deleteButtons = document.querySelectorAll('.delete-result-btn');
        const deleteModal = document.getElementById('deleteModal');
        const deleteResultForm = document.getElementById('deleteResultForm');
        const deleteResultId = document.getElementById('deleteResultId');
        const deleteModalConfirm = document.getElementById('deleteModalConfirm');
        const deleteModalCancel = document.getElementById('deleteModalCancel');
        const deleteModalCancelTop = document.getElementById('deleteModalCancelTop');
        let pendingDeleteId = 0;

        function openDeleteModal(resultId) {
            pendingDeleteId = resultId;
            if (deleteModal) {
                deleteModal.classList.remove('hidden');
            }
        }

        function closeDeleteModal() {
            pendingDeleteId = 0;
            if (deleteModal) {
                deleteModal.classList.add('hidden');
            }
        }

        deleteButtons.forEach((button) => {
            button.addEventListener('click', function () {
                const resultId = Number(button.getAttribute('data-result-id') || '0');
                if (!resultId) {
                    return;
                }
                openDeleteModal(resultId);
            });
        });

        if (deleteModalConfirm) {
            deleteModalConfirm.addEventListener('click', function () {
                if (!pendingDeleteId || !deleteResultForm || !deleteResultId) {
                    return;
                }
                deleteResultId.value = String(pendingDeleteId);
                deleteResultForm.submit();
            });
        }

        if (deleteModalCancel) {
            deleteModalCancel.addEventListener('click', closeDeleteModal);
        }

        if (deleteModalCancelTop) {
            deleteModalCancelTop.addEventListener('click', closeDeleteModal);
        }

        if (deleteModal) {
            deleteModal.querySelectorAll('[data-close-delete="1"]').forEach((el) => {
                el.addEventListener('click', closeDeleteModal);
            });
        }

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
