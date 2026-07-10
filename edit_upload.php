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

function sendAiJson(bool $success, string $message, string $generatedText = ''): void {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'generated_text' => $generatedText,
    ]);
    exit;
}

function utf8Length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function readCsvSampleRows(string $absolutePath, int $maxRows = 10): array {
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        throw new RuntimeException('Uploaded file is not readable.');
    }

    $rawLines = [];
    $stream = fopen($absolutePath, 'rb');
    if ($stream !== false) {
        while (!feof($stream) && count($rawLines) < ($maxRows + 1)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");
            if (count($rawLines) === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }
            if ($line === '') {
                continue;
            }

            $rawLines[] = $line;
        }
        fclose($stream);
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
        'raw_lines' => $rawLines,
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
    $rawLines = $sample['raw_lines'] ?? [];
    $sampleText = implode("\n", $rawLines);
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
$upload = null;
$message = '';
$aiProfiles = [];
$uploadId = (int)($_GET['id'] ?? $_POST['upload_id'] ?? $_POST['id'] ?? 0);

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

    $tableExists = $pdo->query("SHOW TABLES LIKE 'uploads'")->fetchColumn();
    if ($tableExists) {
        $hasDiscoveryPromptColumn = $pdo->query("SHOW COLUMNS FROM uploads LIKE 'data_discovery_prompt'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasDiscoveryPromptColumn) {
            $pdo->exec("ALTER TABLE uploads ADD COLUMN data_discovery_prompt MEDIUMTEXT NOT NULL AFTER prompt_1");
        }
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN data_discovery_prompt MEDIUMTEXT NOT NULL");
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
    $message = 'Database connection error: ' . $e->getMessage();
}

if ($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_prompt2_ai') {
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
            if (empty($sample['raw_lines'])) {
                throw new RuntimeException('Unable to read sample rows from uploaded file.');
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_upload') {
        if ($uploadId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, tags FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1'
            );
            $stmt->execute([$uploadId, (int)$user['id']]);
            $existing = $stmt->fetch();
            if ($existing) {
                $description = trim((string)($_POST['description'] ?? ''));
                $longDescription = trim((string)($_POST['long_description'] ?? ''));
                $prompt1 = trim((string)($_POST['prompt_1'] ?? ''));
                $dataDiscoveryPrompt = trim((string)($_POST['data_discovery_prompt'] ?? ''));
                $prompt2 = trim((string)($_POST['prompt_2'] ?? ''));
                $ai1 = trim((string)($_POST['AI_1'] ?? ''));
                $ai2 = trim((string)($_POST['AI_2'] ?? ''));
                $isPublic = isset($_POST['is_public']) ? 1 : 0;
                $tags = trim((string)($existing['tags'] ?? ''));

                if (utf8Length($description) > 16000000 || utf8Length($longDescription) > 16000000 || utf8Length($prompt1) > 16000000 || utf8Length($dataDiscoveryPrompt) > 16000000 || utf8Length($prompt2) > 16000000) {
                    $message = 'Some fields are too long. Reduce text and try again.';
                } else {
                $stmt = $pdo->prepare(
                        'UPDATE uploads SET description = ?, tags = ?, long_description = ?, prompt_1 = ?, data_discovery_prompt = ?, prompt_2 = ?, AI_1 = ?, AI_2 = ?, is_public = ? WHERE id = ? AND id_owner = ?'
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
                        $isPublic,
                    $uploadId,
                    (int)$user['id'],
                ]);
                $message = 'Upload updated successfully.';
                }
            } else {
                $message = 'You are not allowed to edit this upload.';
            }
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1');
    $stmt->execute([$uploadId, (int)$user['id']]);
    $upload = $stmt->fetch();

    if (!$upload) {
        $message = 'Upload not found or not accessible.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit upload</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Edit upload</h1>
                <div class="meta">Update metadata for the uploaded file.</div>
            </div>
            <a href="database_list.php">Back to list</a>
        </div>

        <?php if ($message): ?>
            <div class="message<?php echo strpos($message, 'error') !== false || strpos($message, 'not allowed') !== false ? ' error' : ''; ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($upload): ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="save_upload">
                    <input type="hidden" name="upload_id" value="<?php echo h($upload['id']); ?>">

                    <div class="field">
                        <label for="filename">File name</label>
                        <input type="text" id="filename" value="<?php echo h($upload['filename']); ?>" disabled>
                        <div class="meta">File name cannot be edited from this screen.</div>
                    </div>

                    <div class="field">
                        <label for="description">Title</label>
                        <input type="text" id="description" name="description" value="<?php echo h($upload['description'] ?? ''); ?>">
                    </div>

                    <div class="field">
                        <label for="long_description">Long description</label>
                        <textarea id="long_description" name="long_description"><?php echo h($upload['long_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="prompt_1">Table description</label>
                        <textarea id="prompt_1" name="prompt_1"><?php echo h($upload['prompt_1'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="data_discovery_prompt">Data discovery prompt</label>
                        <textarea id="data_discovery_prompt" name="data_discovery_prompt"><?php
                            $savedDiscoveryPrompt = trim((string)($upload['data_discovery_prompt'] ?? ''));
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
                        <textarea id="prompt_2" name="prompt_2"><?php echo h($upload['prompt_2'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="AI_1">Default AI profile 1</label>
                        <select id="AI_1" name="AI_1">
                            <option value="">None</option>
                            <?php foreach ($aiProfiles as $profile): ?>
                                <?php $profileId = (string)(int)$profile['id']; ?>
                                <option value="<?php echo h($profileId); ?>"<?php echo ((string)($upload['AI_1'] ?? '') === $profileId) ? ' selected' : ''; ?>>#<?php echo h((int)$profile['id']); ?> - <?php echo h((string)$profile['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="AI_2">Default AI profile 2</label>
                        <select id="AI_2" name="AI_2">
                            <option value="">None</option>
                            <?php foreach ($aiProfiles as $profile): ?>
                                <?php $profileId = (string)(int)$profile['id']; ?>
                                <option value="<?php echo h($profileId); ?>"<?php echo ((string)($upload['AI_2'] ?? '') === $profileId) ? ' selected' : ''; ?>>#<?php echo h((int)$profile['id']); ?> - <?php echo h((string)$profile['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label style="font-weight:600; display:flex; gap:8px; align-items:center;">
                            <input type="checkbox" name="is_public" value="1"<?php echo ((int)$upload['is_public'] === 1) ? ' checked' : ''; ?>>
                            Public dataset
                        </label>
                    </div>

                    <button type="submit">Save changes</button>
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

                fetch('edit_upload.php', {
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
