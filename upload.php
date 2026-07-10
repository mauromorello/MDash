<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/ai_shared.php';

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

function utf8Length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function sendAiJson(bool $success, string $message, string $generatedText = ''): void {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'generated_text' => $generatedText,
    ]);
    exit;
}

function readCsvSampleRows(string $absolutePath, int $maxRows = 10): array {
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        throw new RuntimeException('Uploaded file is not readable.');
    }

    $rows = [];
    $header = [];

    $file = new SplFileObject($absolutePath, 'r');
    while (!$file->eof()) {
        $row = $file->fgetcsv();
        if ($row === false || $row === [null]) {
            continue;
        }

        $normalized = array_map(static fn($v) => trim((string)$v), $row);
        if (empty($header)) {
            $header = $normalized;
            continue;
        }

        $rows[] = $normalized;
        if (count($rows) >= $maxRows) {
            break;
        }
    }

    return [
        'header' => $header,
        'rows' => $rows,
    ];
}

function defaultDataDiscoveryPrompt(): string {
    return "You are a senior data analyst.\n"
        . "Analyze the CSV sample and produce a complete and practical data scheme.\n"
        . "Return plain text only (no markdown fences).\n\n"
        . "Required output sections:\n"
        . "1) Dataset overview\n"
        . "2) Fields\n"
        . "   For each field include:\n"
        . "   - Field\n"
        . "   - Meaning\n"
        . "   - Observed type (string/number/date/boolean/mixed)\n"
        . "   - Example values (2-3)\n"
        . "   - Data quality notes (missing values, anomalies, duplicates hints)\n"
        . "3) Suggested checks\n"
        . "   Add concrete validation checks that should be run on this dataset.\n\n"
        . "Use the CSV sample below to infer structure and quality details.\n"
        . "{{CSV_SAMPLE}}";
}

function buildDataSchemePrompt(array $uploadRecord, array $sample, string $title, string $longDescription, string $tableDescription, string $dataDiscoveryPrompt): string {
    $fileName = (string)($uploadRecord['filename'] ?? 'data.csv');
    $description = trim($title);
    $tags = trim((string)($uploadRecord['tags'] ?? ''));
    $longDescription = trim($longDescription);
    $tableDescription = trim($tableDescription);
    $dataDiscoveryPrompt = trim($dataDiscoveryPrompt);
    $header = $sample['header'] ?? [];
    $rows = $sample['rows'] ?? [];

    $tableLines = [];
    if (!empty($header)) {
        $tableLines[] = implode(' | ', $header);
    }
    foreach ($rows as $row) {
        $tableLines[] = implode(' | ', $row);
    }

    $sampleText = implode("\n", $tableLines);
    $promptTemplate = $dataDiscoveryPrompt !== '' ? $dataDiscoveryPrompt : defaultDataDiscoveryPrompt();
    if (!str_contains($promptTemplate, '{{CSV_SAMPLE}}')) {
        $promptTemplate .= "\n\nCSV sample:\n{{CSV_SAMPLE}}";
    }

    $context = "File name: {$fileName}\n";
    if ($description !== '') {
        $context .= "Title: {$description}\n";
    }
    if ($longDescription !== '') {
        $context .= "Long description: {$longDescription}\n";
    }
    if ($tableDescription !== '') {
        $context .= "Table description: {$tableDescription}\n";
    }
    if ($tags !== '') {
        $context .= "Tags: {$tags}\n";
    }

    return trim($context) . "\n\n" . str_replace('{{CSV_SAMPLE}}', $sampleText, $promptTemplate);
}

function generateTextFromAiProfile(string $prompt, array $aiProfile): string {
    $apiKey = trim((string)($aiProfile['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Selected AI profile has no API key.');
    }

    $provider = strtolower(trim((string)($aiProfile['provider'] ?? 'gemini')));
    $supportedProviders = mdashSupportedAiProviders();
    if (!isset($supportedProviders[$provider])) {
        throw new RuntimeException('Unsupported provider: ' . $provider);
    }

    $model = trim((string)($aiProfile['model'] ?? ''));
    if ($model === '') {
        $model = $provider === 'openrouter' ? 'openai/gpt-4o' : 'gemini-flash-latest';
    }

    $endpoint = trim((string)($aiProfile['web_end_point'] ?? ''));
    if ($endpoint === '') {
        $endpoint = $provider === 'openrouter'
            ? 'https://openrouter.ai/api/v1/chat/completions'
            : 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    }
    if (str_contains($endpoint, '{model}')) {
        $endpoint = str_replace('{model}', rawurlencode($model), $endpoint);
    }
    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid AI endpoint URL.');
    }

    $headers = ['Content-Type: application/json'];
    $payload = [];

    if ($provider === 'openrouter') {
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.2,
        ];
        $baseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $headers[] = 'HTTP-Referer: ' . $baseUrl;
        $headers[] = 'X-Title: MDash Upload Assistant';
    } else {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];
        $headers[] = 'X-goog-api-key: ' . $apiKey;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $errorMessage = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('AI request failed: ' . $errorMessage);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $detail = trim((string)($decoded['error']['message'] ?? $response));
        throw new RuntimeException('AI API error (HTTP ' . $httpCode . '): ' . $detail);
    }

    $text = '';
    if ($provider === 'openrouter') {
        $text = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    } else {
        if (!empty($decoded['candidates'][0]['content']['parts'])) {
            foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
                $text .= (string)($part['text'] ?? '');
            }
        }
        $text = trim($text);
    }

    if ($text === '') {
        throw new RuntimeException('AI returned empty content.');
    }

    return preg_replace('/^```(?:text|markdown)?\s*|\s*```$/i', '', $text) ?? $text;
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
$aiProfiles = [];

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
            data_discovery_prompt TEXT NOT NULL,
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
        $hasDiscoveryPromptColumn = $pdo->query("SHOW COLUMNS FROM uploads LIKE 'data_discovery_prompt'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasDiscoveryPromptColumn) {
            $pdo->exec("ALTER TABLE uploads ADD COLUMN data_discovery_prompt MEDIUMTEXT NOT NULL AFTER prompt_1");
        }
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN data_discovery_prompt MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN prompt_2 MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN AI_1 MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN AI_2 MEDIUMTEXT NOT NULL");
    }

    mdashEnsureAiDbTable($pdo);

    $aiProfilesStmt = $pdo->prepare(
        'SELECT a.*
         FROM ai_db a
         WHERE ((a.id_owner = :user_id AND a.is_hidden = 0) OR (a.is_public = 1 AND a.is_hidden = 0))
         ORDER BY a.id DESC'
    );
    $aiProfilesStmt->execute(['user_id' => (int)$user['id']]);
    $aiProfiles = $aiProfilesStmt->fetchAll();
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_file') {
        if (!$pdo) {
            $message = 'Unable to connect to database: ' . h($dbError);
        } elseif (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Select a valid file to upload.';
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
                    'INSERT INTO uploads (path, filename, description, tags, long_description, prompt_1, data_discovery_prompt, prompt_2, id_owner, is_public, AI_1, AI_2) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
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
                    $message = 'File uploaded successfully. Complete the required fields.';
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        sendJson(true, $message, $uploadId);
                    }
                } else {
                    $deleteStmt = $pdo->prepare('DELETE FROM uploads WHERE id = ?');
                    $deleteStmt->execute([$uploadId]);
                    $message = 'The file was not saved. Please try again.';
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        sendJson(false, $message);
                    }
                }
            } catch (PDOException $e) {
                $message = 'Error while saving file: ' . $e->getMessage();
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    sendJson(false, $message);
                }
            }
        }
    }

    if ($action === 'save_metadata' && $pdo) {
        $uploadId = (int)($_POST['upload_id'] ?? 0);
        $prompt1 = trim((string)($_POST['prompt_1'] ?? ''));
        $dataDiscoveryPrompt = trim((string)($_POST['data_discovery_prompt'] ?? ''));
        $prompt2 = trim((string)($_POST['prompt_2'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $longDescription = trim((string)($_POST['long_description'] ?? ''));
        $isPublic = isset($_POST['is_public']) ? 1 : 0;
        $ai1 = trim((string)($_POST['AI_1'] ?? ''));
        $ai2 = trim((string)($_POST['AI_2'] ?? ''));
        $tags = '';

        if ($uploadId > 0) {
            $existingStmt = $pdo->prepare('SELECT tags FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1');
            $existingStmt->execute([$uploadId, (int)$user['id']]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                $tags = trim((string)($existing['tags'] ?? ''));
            }
        }

        if (utf8Length($description) > 16000000 || utf8Length($tags) > 65000 || utf8Length($longDescription) > 16000000 || utf8Length($prompt1) > 16000000 || utf8Length($dataDiscoveryPrompt) > 16000000 || utf8Length($prompt2) > 16000000) {
            $message = 'Some fields are too long. Reduce text and try again.';
        }

        if ($message === '' && $uploadId > 0) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE uploads SET description = ?, tags = ?, long_description = ?, prompt_1 = ?, data_discovery_prompt = ?, prompt_2 = ?, AI_1 = ?, AI_2 = ?, id_owner = ?, is_public = ? WHERE id = ? AND id_owner = ?'
                );
                $stmt->execute([
                    $description,
                    $tags,
                    $longDescription,
                    $prompt1,
                    $dataDiscoveryPrompt,
                    $prompt2,
                    $ai1,
                    $ai2,
                    (int)$user['id'],
                    $isPublic,
                    $uploadId,
                    (int)$user['id'],
                ]);
                header('Location: main.php');
                exit;
            } catch (PDOException $e) {
                $message = 'Error while saving metadata: ' . $e->getMessage();
            }
        }

        if ($message === '') {
            $message = 'Unable to complete save.';
        }
    }

    if ($action === 'generate_prompt2_ai' && $pdo) {
        $uploadId = (int)($_POST['upload_id'] ?? 0);
        $aiId = (int)($_POST['id_ai_db'] ?? 0);

        try {
            if ($uploadId <= 0) {
                throw new RuntimeException('Invalid upload ID.');
            }
            if ($aiId <= 0) {
                throw new RuntimeException('Select an AI profile first.');
            }

            $uploadStmt = $pdo->prepare('SELECT * FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1');
            $uploadStmt->execute([$uploadId, (int)$user['id']]);
            $uploadRecord = $uploadStmt->fetch();
            if (!$uploadRecord) {
                throw new RuntimeException('Upload record not found.');
            }

            $aiStmt = $pdo->prepare(
                'SELECT * FROM ai_db WHERE id = ? AND ((id_owner = ? AND is_hidden = 0) OR (is_public = 1 AND is_hidden = 0)) LIMIT 1'
            );
            $aiStmt->execute([$aiId, (int)$user['id']]);
            $aiProfile = $aiStmt->fetch();
            if (!$aiProfile) {
                throw new RuntimeException('Selected AI profile is not available.');
            }

            $relativePath = (string)($uploadRecord['path'] ?? '');
            if ($relativePath === '') {
                throw new RuntimeException('Uploaded file path is missing.');
            }
            $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            $sample = readCsvSampleRows($absolutePath, 10);
            if (empty($sample['header'])) {
                throw new RuntimeException('Unable to read CSV header from uploaded file.');
            }

            $title = trim((string)($_POST['description'] ?? (string)($uploadRecord['description'] ?? '')));
            $longDescription = trim((string)($_POST['long_description'] ?? (string)($uploadRecord['long_description'] ?? '')));
            $tableDescription = trim((string)($_POST['prompt_1'] ?? (string)($uploadRecord['prompt_1'] ?? '')));
            $dataDiscoveryPrompt = trim((string)($_POST['data_discovery_prompt'] ?? (string)($uploadRecord['data_discovery_prompt'] ?? '')));

            $prompt = buildDataSchemePrompt($uploadRecord, $sample, $title, $longDescription, $tableDescription, $dataDiscoveryPrompt);
            $generatedText = generateTextFromAiProfile($prompt, $aiProfile);
            sendAiJson(true, 'AI schema generated successfully.', $generatedText);
        } catch (Throwable $e) {
            sendAiJson(false, $e->getMessage());
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload file</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page upload-wrap">
        <div class="topbar">
            <h1>Upload file</h1>
            <a href="main.php">Back to home</a>
        </div>

        <?php if ($message): ?>
            <div class="message error"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($step === 'upload'): ?>
            <div class="box">
                <form id="uploadForm" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_file">
                    <div class="field">
                        <label for="file">Select file</label>
                        <input type="file" id="file" name="file" required>
                        <div class="hint">The file will be stored in uploads/id/filename.csv.</div>
                    </div>
                    <div id="progressBox" class="progress-box">
                        <div id="progressLabel">Upload in progress...</div>
                        <div class="progress-track"><div id="progressFill" class="progress-fill"></div></div>
                        <div id="progressText" class="progress-text">0%</div>
                    </div>
                    <button type="submit">Upload file</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($step === 'finalize' && $record): ?>
            <div class="box">
                <h2>Complete file details</h2>
                <p>Record created with ID <strong><?php echo h($record['id']); ?></strong>.</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_metadata">
                    <input type="hidden" name="upload_id" value="<?php echo h($record['id']); ?>">

                    <div class="field">
                        <label for="description">Title</label>
                        <input type="text" id="description" name="description" value="<?php echo h($record['description'] ?? ''); ?>" placeholder="Dataset title">
                    </div>

                    <div class="field">
                        <label for="long_description">Long description</label>
                        <textarea id="long_description" name="long_description" placeholder="Additional details about the file and its fields"><?php echo h($record['long_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="prompt_1">Table description</label>
                        <textarea id="prompt_1" name="prompt_1" placeholder="Describe the table business meaning and expected structure"><?php echo h($record['prompt_1'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="data_discovery_prompt">Data discovery prompt</label>
                        <textarea id="data_discovery_prompt" name="data_discovery_prompt" placeholder="Prompt used to guide AI schema analysis"><?php
                            $savedDiscoveryPrompt = trim((string)($record['data_discovery_prompt'] ?? ''));
                            echo h($savedDiscoveryPrompt !== '' ? $savedDiscoveryPrompt : defaultDataDiscoveryPrompt());
                        ?></textarea>
                    </div>

                    <div class="field">
                        <label for="id_ai_db">AI profile for analysis</label>
                        <select id="id_ai_db" name="id_ai_db">
                            <option value="">Select AI profile</option>
                            <?php foreach ($aiProfiles as $profile): ?>
                                <option value="<?php echo h((int)$profile['id']); ?>">#<?php echo h((int)$profile['id']); ?> - <?php echo h((string)$profile['title']); ?> [<?php echo h((string)$profile['provider']); ?> / <?php echo h((string)$profile['model']); ?>]</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="inline-actions" style="margin-top:8px;">
                            <button type="button" class="secondary" id="generateDataSchemeBtn">Generate data scheme with AI (first 10 rows)</button>
                        </div>
                    </div>

                    <div class="field">
                        <label for="prompt_2">Data scheme</label>
                        <textarea id="prompt_2" name="prompt_2" placeholder="Detailed schema generated by AI"><?php echo h($record['prompt_2'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="AI_1">Default AI profile 1</label>
                        <select id="AI_1" name="AI_1">
                            <option value="">None</option>
                            <?php foreach ($aiProfiles as $profile): ?>
                                <?php $profileId = (string)(int)$profile['id']; ?>
                                <option value="<?php echo h($profileId); ?>"<?php echo ((string)($record['AI_1'] ?? '') === $profileId) ? ' selected' : ''; ?>>#<?php echo h((int)$profile['id']); ?> - <?php echo h((string)$profile['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="AI_2">Default AI profile 2</label>
                        <select id="AI_2" name="AI_2">
                            <option value="">None</option>
                            <?php foreach ($aiProfiles as $profile): ?>
                                <?php $profileId = (string)(int)$profile['id']; ?>
                                <option value="<?php echo h($profileId); ?>"<?php echo ((string)($record['AI_2'] ?? '') === $profileId) ? ' selected' : ''; ?>>#<?php echo h((int)$profile['id']); ?> - <?php echo h((string)$profile['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field" style="margin-top:6px;">
                        <label style="font-weight:600; display:flex; gap:8px; align-items:center;">
                            <input type="checkbox" name="is_public" value="1"<?php echo ((int)($record['is_public'] ?? 0) === 1) ? ' checked' : ''; ?>>
                            Public dataset
                        </label>
                    </div>

                    <button type="submit">Save and return home</button>
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
                progressLabel.textContent = 'Upload in progress...';

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
                                progressLabel.textContent = result && result.message ? result.message : 'Upload completed.';
                                progressText.textContent = 'Done';
                                window.location.reload();
                            }
                        } catch (e) {
                            progressLabel.textContent = 'Invalid response from server.';
                            progressText.textContent = 'Error';
                        }
                    } else {
                        let serverMessage = 'Upload error.';
                        try {
                            const result = JSON.parse(xhr.responseText);
                            if (result && result.message) {
                                serverMessage = result.message;
                            }
                        } catch (e) {}
                        progressLabel.textContent = serverMessage;
                        progressText.textContent = 'Error';
                    }
                });
                xhr.addEventListener('error', function () {
                    progressLabel.textContent = 'Network error during upload.';
                    progressText.textContent = 'Error';
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

        const generateDataSchemeBtn = document.getElementById('generateDataSchemeBtn');
        if (generateDataSchemeBtn) {
            generateDataSchemeBtn.addEventListener('click', function () {
                const uploadIdInput = document.querySelector('input[name="upload_id"]');
                const aiSelect = document.getElementById('id_ai_db');
                const prompt2Field = document.getElementById('prompt_2');
                const descriptionField = document.getElementById('description');
                const longDescriptionField = document.getElementById('long_description');
                const prompt1Field = document.getElementById('prompt_1');
                const discoveryPromptField = document.getElementById('data_discovery_prompt');

                if (!uploadIdInput || !uploadIdInput.value) {
                    alert('Upload record not found.');
                    return;
                }
                if (!aiSelect || !aiSelect.value) {
                    alert('Select an AI profile first.');
                    return;
                }
                if (!prompt2Field) {
                    alert('Data scheme field not found.');
                    return;
                }

                generateDataSchemeBtn.disabled = true;
                const oldText = generateDataSchemeBtn.textContent;
                generateDataSchemeBtn.textContent = 'Reading with AI...';

                const payload = new URLSearchParams({
                    action: 'generate_prompt2_ai',
                    upload_id: uploadIdInput.value,
                    id_ai_db: aiSelect.value,
                    description: descriptionField ? descriptionField.value : '',
                    long_description: longDescriptionField ? longDescriptionField.value : '',
                    prompt_1: prompt1Field ? prompt1Field.value : '',
                    data_discovery_prompt: discoveryPromptField ? discoveryPromptField.value : '',
                });

                fetch('upload.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: payload.toString(),
                })
                .then(response => response.json())
                .then(result => {
                    if (!result || !result.success) {
                        throw new Error((result && result.message) ? result.message : 'AI request failed.');
                    }
                    prompt2Field.value = result.generated_text || '';
                })
                .catch(error => {
                    alert(error.message || 'AI request failed.');
                })
                .finally(() => {
                    generateDataSchemeBtn.disabled = false;
                    generateDataSchemeBtn.textContent = oldText;
                });
            });
        }
    </script>
</body>
</html>
