<?php
session_start();

function sendJson($success, $message, $uploadId = 0): void {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'upload_id' => $uploadId,
    ]);
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

function utf8Length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
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
$dbError = '';
$uploadId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$step = 'upload';
$message = '';
$record = null;

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
        "CREATE TABLE IF NOT EXISTS uploads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            tags VARCHAR(255) NOT NULL,
            long_description TEXT NOT NULL,
            prompt_1 TEXT NOT NULL,
            prompt_2 TEXT NOT NULL,
            id_owner INT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            AI_1 TEXT NOT NULL,
            AI_2 TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $tableExists = $pdo->query("SHOW TABLES LIKE 'uploads'")->fetchColumn();
    if ($tableExists) {
        $idColumn = $pdo->query("SHOW COLUMNS FROM uploads LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if ($idColumn && stripos((string)$idColumn['Extra'], 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE uploads MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        // Ensure long textual fields can store rich prompts without truncation errors.
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN description MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN tags TEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN long_description MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN prompt_1 MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN prompt_2 MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN AI_1 MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN AI_2 MEDIUMTEXT NOT NULL");
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_file') {
        if (!$pdo) {
            $message = 'Impossibile connettersi al database: ' . h($dbError);
        } elseif (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Seleziona un file valido da caricare.';
        } else {
            $baseName = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
            $safeBaseName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName) ?: 'file';
            $fileName = $safeBaseName . '.csv';
            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO uploads (path, filename, description, tags, long_description, prompt_1, prompt_2, id_owner, is_public, AI_1, AI_2) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
                );
                $stmt->execute([
                    '',
                    $fileName,
                    '',
                    '',
                    '',
                    '',
                    '',
                    (int)$user['id'],
                    0,
                    '',
                    '',
                ]);

                $uploadId = (int)$pdo->lastInsertId();
                $recordDir = $uploadDir . DIRECTORY_SEPARATOR . $uploadId;
                if (!is_dir($recordDir)) {
                    mkdir($recordDir, 0777, true);
                }

                $targetPath = $recordDir . DIRECTORY_SEPARATOR . $fileName;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                    $relativePath = 'uploads/' . $uploadId . '/' . $fileName;
                    $updateStmt = $pdo->prepare('UPDATE uploads SET path = ? WHERE id = ?');
                    $updateStmt->execute([$relativePath, $uploadId]);
                    $step = 'finalize';
                    $message = 'File caricato correttamente. Completa i campi richiesti.';
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        sendJson(true, $message, $uploadId);
                    }
                } else {
                    $deleteStmt = $pdo->prepare('DELETE FROM uploads WHERE id = ?');
                    $deleteStmt->execute([$uploadId]);
                    $message = 'Il file non è stato salvato. Riprova.';
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        sendJson(false, $message);
                    }
                }
            } catch (PDOException $e) {
                $message = 'Errore durante il salvataggio del file: ' . $e->getMessage();
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    sendJson(false, $message);
                }
            }
        }
    }

    if ($action === 'save_metadata' && $pdo) {
        $uploadId = (int)($_POST['upload_id'] ?? 0);
        $prompt1 = trim((string)($_POST['prompt_1'] ?? ''));
        $prompt2 = trim((string)($_POST['prompt_2'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        $longDescription = trim((string)($_POST['long_description'] ?? ''));
        $isPublic = (int)($_POST['is_public'] ?? 0);

        if (utf8Length($description) > 16000000 || utf8Length($tags) > 65000 || utf8Length($longDescription) > 16000000 || utf8Length($prompt1) > 16000000 || utf8Length($prompt2) > 16000000) {
            $message = 'Alcuni campi sono troppo lunghi. Riduci il testo e riprova.';
        }

        if ($message === '' && $uploadId > 0) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE uploads SET description = ?, tags = ?, long_description = ?, prompt_1 = ?, prompt_2 = ?, id_owner = ?, is_public = ? WHERE id = ? AND id_owner = ?'
                );
                $stmt->execute([
                    $description,
                    $tags,
                    $longDescription,
                    $prompt1,
                    $prompt2,
                    (int)$user['id'],
                    $isPublic,
                    $uploadId,
                    (int)$user['id'],
                ]);
                header('Location: main.php');
                exit;
            } catch (PDOException $e) {
                $message = 'Errore durante il salvataggio dei metadati: ' . $e->getMessage();
            }
        }

        if ($message === '') {
            $message = 'Impossibile completare il salvataggio.';
        }
    }
}

if ($uploadId > 0 && $pdo) {
    $rowStmt = $pdo->prepare('SELECT * FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1');
    $rowStmt->execute([$uploadId, (int)$user['id']]);
    $record = $rowStmt->fetch();
}

if ($record) {
    $step = 'finalize';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload file</title>
    <link rel="stylesheet" href="assets/app.css">
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f5f7fb; color: #222; }
        .wrap { max-width: 900px; margin: 32px auto; padding: 24px; background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.08); }
        h1 { margin-top: 0; }
        .box { border: 1px solid #dfe5ef; border-radius: 10px; padding: 16px; margin-bottom: 20px; background: #fafcff; }
        .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        label { font-weight: 600; }
        input[type="text"], input[type="file"], textarea, select { padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; }
        textarea { min-height: 100px; resize: vertical; }
        button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; cursor: pointer; font-size: 1rem; }
        button.secondary { background: #64748b; }
        .message { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; background: #eff6ff; color: #1d4ed8; }
        .message.error { background: #fef2f2; color: #b91c1c; }
        .hint { color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        a { color: #2563eb; text-decoration: none; }
        @media (max-width: 700px) { .row { grid-template-columns: 1fr; } }
    </style>
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

    <div class="wrap">
        <div class="topbar">
            <h1>Carica un file</h1>
            <a href="main.php">Torna alla home</a>
        </div>

        <?php if ($message): ?>
            <div class="message error"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($step === 'upload'): ?>
            <div class="box">
                <form id="uploadForm" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_file">
                    <div class="field">
                        <label for="file">Seleziona file</label>
                        <input type="file" id="file" name="file" required>
                        <div class="hint">Il file verrà salvato nella cartella uploads/id/filename.csv.</div>
                    </div>
                    <div id="progressBox" class="progress-box">
                        <div id="progressLabel">Upload in corso...</div>
                        <div class="progress-track"><div id="progressFill" class="progress-fill"></div></div>
                        <div id="progressText" class="progress-text">0%</div>
                    </div>
                    <button type="submit">Carica file</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($step === 'finalize' && $record): ?>
            <div class="box">
                <h2>Completa la descrizione del file</h2>
                <p>Record creato con ID <strong><?php echo h($record['id']); ?></strong>.</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_metadata">
                    <input type="hidden" name="upload_id" value="<?php echo h($record['id']); ?>">

                    <div class="field">
                        <label for="description">Descrizione breve</label>
                        <input type="text" id="description" name="description" value="<?php echo h($record['description'] ?? ''); ?>" placeholder="Descrizione sintetica del contenuto">
                    </div>

                    <div class="field">
                        <label for="tags">Tag</label>
                        <input type="text" id="tags" name="tags" value="<?php echo h($record['tags'] ?? ''); ?>" placeholder="es. clienti, ordini, report">
                    </div>

                    <div class="field">
                        <label for="long_description">Descrizione lunga</label>
                        <textarea id="long_description" name="long_description" placeholder="Dettagli aggiuntivi sul file e sui suoi campi"><?php echo h($record['long_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="field">
                            <label for="prompt_1">Prompt 1</label>
                            <textarea id="prompt_1" name="prompt_1" placeholder="Descrivi in modo discorsivo cosa contiene la tabella"><?php echo h($record['prompt_1'] ?? ''); ?></textarea>
                        </div>
                        <div class="field">
                            <label for="prompt_2">Prompt 2</label>
                            <textarea id="prompt_2" name="prompt_2" placeholder="Descrivi dettagliatamente tutti i campi presenti"><?php echo h($record['prompt_2'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="field">
                        <label for="is_public">Visibilità</label>
                        <select id="is_public" name="is_public">
                            <option value="0"<?php echo ((int)($record['is_public'] ?? 0) === 0) ? ' selected' : ''; ?>>Privato</option>
                            <option value="1"<?php echo ((int)($record['is_public'] ?? 0) === 1) ? ' selected' : ''; ?>>Pubblico</option>
                        </select>
                    </div>

                    <button type="submit">Salva e torna alla home</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const form = document.getElementById('uploadForm');
        const progressBox = document.getElementById('progressBox');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        const progressLabel = document.getElementById('progressLabel');

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                const fileInput = document.getElementById('file');
                if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                    return;
                }

                progressBox.style.display = 'block';
                progressFill.style.width = '0%';
                progressText.textContent = '0%';
                progressLabel.textContent = 'Upload in corso...';

                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        progressFill.style.width = percent + '%';
                        progressText.textContent = percent + '%';
                    }
                });
                xhr.addEventListener('load', function () {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            const result = JSON.parse(xhr.responseText);
                            if (result && result.success && result.upload_id) {
                                window.location.href = 'upload.php?id=' + result.upload_id;
                            } else {
                                progressLabel.textContent = result && result.message ? result.message : 'Upload completato.';
                                progressText.textContent = 'Fine';
                                window.location.reload();
                            }
                        } catch (e) {
                            progressLabel.textContent = 'Risposta non valida dal server.';
                            progressText.textContent = 'Errore';
                        }
                    } else {
                        let serverMessage = 'Errore durante l\'upload.';
                        try {
                            const result = JSON.parse(xhr.responseText);
                            if (result && result.message) {
                                serverMessage = result.message;
                            }
                        } catch (e) {}
                        progressLabel.textContent = serverMessage;
                        progressText.textContent = 'Errore';
                    }
                });
                xhr.addEventListener('error', function () {
                    progressLabel.textContent = 'Errore di rete durante l\'upload.';
                    progressText.textContent = 'Errore';
                });

                const formData = new FormData(form);
                xhr.open('POST', window.location.href);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.send(formData);
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
