<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/ai_shared.php';

function getUserFromSessionOrCookie(): ?array {
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

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sendAiJson(bool $success, string $message, array $payload = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $payload));
    exit;
}

function utf8Length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function defaultDataDiscoveryPrompt(): string {
    return "You are a senior data analyst.\n"
        . "Analyze the full dataset and produce a robust schema and parsing strategy for future dashboards.\n"
        . "Focus especially on date formats and numeric normalization rules.\n"
        . "Return plain text only (no markdown fences).";
}

function readCsvPreviewRows(string $absolutePath, int $maxRows = 10): array {
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

function readDatasetContent(string $absolutePath): string {
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        throw new RuntimeException('Uploaded file is not readable.');
    }

    $content = file_get_contents($absolutePath);
    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Uploaded file is empty or unreadable.');
    }

    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

    return $content;
}

function buildUploadAutofillPrompt(array $uploadRecord, string $datasetContent): string {
    $fileName = (string)($uploadRecord['filename'] ?? 'data.csv');

    return "Sei un data analyst senior.\n"
        . "Analizza TUTTO il file allegato e compila i campi richiesti.\n"
        . "Rispondi in testo semplice e in formato rigidamente interpretabile, senza markdown.\n"
        . "Usa ESATTAMENTE queste chiavi, una per riga:\n"
        . "Title: <titolo breve>\n"
        . "Long title: <descrizione estesa>\n"
        . "Prompt 1: <che cosa e la tabella e a cosa serve>\n"
        . "Prompt 2: <analisi dettagliata di ogni campo con istruzioni per parsing successivi, con forte focus su parsing corretto di date e numeri>\n\n"
        . "File name: {$fileName}\n"
        . "Dataset completo:\n"
        . $datasetContent;
}

function parseAutofillResponse(string $text): array {
    $clean = trim(preg_replace('/^```(?:text|markdown)?\s*|\s*```$/i', '', $text) ?? $text);

    $pick = static function (string $pattern) use ($clean): string {
        if (preg_match($pattern, $clean, $matches)) {
            return trim((string)$matches[1]);
        }
        return '';
    };

    $title = $pick('/^\s*Title\s*:\s*(.*?)\s*(?=^\s*Long\s*title\s*:|\z)/ims');
    $longTitle = $pick('/^\s*Long\s*title\s*:\s*(.*?)\s*(?=^\s*Prompt\s*1\s*:|\z)/ims');
    $prompt1 = $pick('/^\s*Prompt\s*1\s*:\s*(.*?)\s*(?=^\s*Prompt\s*2\s*:|\z)/ims');
    $prompt2 = $pick('/^\s*Prompt\s*2\s*:\s*(.*?)\s*$/ims');

    if ($title === '' || $longTitle === '' || $prompt1 === '' || $prompt2 === '') {
        throw new RuntimeException('AI response is not in the expected structured format.');
    }

    return [
        'title' => trim($title),
        'long_title' => trim($longTitle),
        'prompt_1' => trim($prompt1),
        'prompt_2' => trim($prompt2),
        'raw_text' => $clean,
    ];
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
            'temperature' => 0.1,
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
                'temperature' => 0.1,
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
        CURLOPT_TIMEOUT => 120,
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

    return $text;
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
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN description MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN long_description MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN prompt_1 MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN data_discovery_prompt MEDIUMTEXT NOT NULL");
        $pdo->exec("ALTER TABLE uploads MODIFY COLUMN prompt_2 MEDIUMTEXT NOT NULL");

        $legacyAi1 = $pdo->query("SHOW COLUMNS FROM uploads LIKE 'AI_1'")->fetch(PDO::FETCH_ASSOC);
        if ($legacyAi1) {
            $pdo->exec("ALTER TABLE uploads DROP COLUMN AI_1");
        }
        $legacyAi2 = $pdo->query("SHOW COLUMNS FROM uploads LIKE 'AI_2'")->fetch(PDO::FETCH_ASSOC);
        if ($legacyAi2) {
            $pdo->exec("ALTER TABLE uploads DROP COLUMN AI_2");
        }
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
} catch (Throwable $e) {
    $message = 'Database connection error: ' . $e->getMessage();
}

if ($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze_with_ai') {
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
            $datasetContent = readDatasetContent($absolutePath);
            $prompt = buildUploadAutofillPrompt($uploadRecord, $datasetContent);
            $generatedText = generateTextFromAiProfile($prompt, $aiProfile);
            $parsed = parseAutofillResponse($generatedText);

            sendAiJson(true, 'AI analysis completed.', [
                'fields' => [
                    'description' => $parsed['title'],
                    'long_description' => $parsed['long_title'],
                    'prompt_1' => $parsed['prompt_1'],
                    'prompt_2' => $parsed['prompt_2'],
                ],
                'generated_text' => $parsed['raw_text'],
            ]);
        } catch (Throwable $e) {
            sendAiJson(false, $e->getMessage());
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_upload') {
        if ($uploadId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1'
            );
            $stmt->execute([$uploadId, (int)$user['id']]);
            $existing = $stmt->fetch();
            if ($existing) {
                $description = trim((string)($_POST['description'] ?? ''));
                $longDescription = trim((string)($_POST['long_description'] ?? ''));
                $prompt1 = trim((string)($_POST['prompt_1'] ?? ''));
                $dataDiscoveryPrompt = trim((string)($_POST['data_discovery_prompt'] ?? ''));
                $prompt2 = trim((string)($_POST['prompt_2'] ?? ''));
                $isPublic = isset($_POST['is_public']) ? 1 : 0;

                if ($description === '' || $prompt1 === '' || $prompt2 === '') {
                    $message = 'Title, Prompt 1 and Prompt 2 are required.';
                } elseif (utf8Length($description) > 16000000 || utf8Length($longDescription) > 16000000 || utf8Length($prompt1) > 16000000 || utf8Length($dataDiscoveryPrompt) > 16000000 || utf8Length($prompt2) > 16000000) {
                    $message = 'Some fields are too long. Reduce text and try again.';
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE uploads SET description = ?, long_description = ?, prompt_1 = ?, data_discovery_prompt = ?, prompt_2 = ?, is_public = ? WHERE id = ? AND id_owner = ?'
                    );
                    $stmt->execute([
                        $description,
                        $longDescription,
                        $prompt1,
                        $dataDiscoveryPrompt,
                        $prompt2,
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

$preview = ['header' => [], 'rows' => []];
if ($upload) {
    $relativePath = (string)($upload['path'] ?? '');
    if ($relativePath !== '') {
        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        try {
            $preview = readCsvPreviewRows($absolutePath, 10);
        } catch (Throwable $e) {
            if ($message === '') {
                $message = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit upload</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<link rel="stylesheet" href="assets/app.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/app.css'); ?>">
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
            <div class="message<?php echo (stripos($message, 'error') !== false || stripos($message, 'not allowed') !== false) ? ' error' : ''; ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($upload): ?>
            <div class="card">
                <div class="field">
                    <label for="filename">File name</label>
                    <input type="text" id="filename" value="<?php echo h($upload['filename']); ?>" disabled>
                    <div class="meta">File name cannot be edited from this screen.</div>
                </div>
            </div>

            <div class="card">
                <h3>Data preview (first 10 rows)</h3>
                <?php if (!empty($preview['header'])): ?>
                    <div class="table-wrap preview-table-wrap">
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <?php foreach ($preview['header'] as $col): ?>
                                        <th><?php echo h($col); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview['rows'] as $row): ?>
                                    <tr>
                                        <?php foreach ($preview['header'] as $index => $col): ?>
                                            <td><?php echo h($row[$index] ?? ''); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">No rows available for preview.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="field">
                    <label for="id_ai_db">AI profile for analysis</label>
                    <select id="id_ai_db" name="id_ai_db">
                        <option value="">Select AI profile</option>
                        <?php foreach ($aiProfiles as $profile): ?>
                            <option value="<?php echo h((int)$profile['id']); ?>">#<?php echo h((int)$profile['id']); ?> - <?php echo h((string)$profile['title']); ?> [<?php echo h((string)$profile['provider']); ?> / <?php echo h((string)$profile['model']); ?>]</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="inline-actions" style="margin-top:8px;">
                        <button type="button" class="secondary" id="analyzeEditAiBtn" data-upload-id="<?php echo h($upload['id']); ?>">Analizza con AI</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="save_upload">
                    <input type="hidden" name="upload_id" value="<?php echo h($upload['id']); ?>">

                    <div class="field">
                        <label for="description">Title</label>
                        <input type="text" id="description" name="description" value="<?php echo h($upload['description'] ?? ''); ?>" required>
                    </div>

                    <div class="field">
                        <label for="long_description">Long title</label>
                        <textarea id="long_description" name="long_description"><?php echo h($upload['long_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="prompt_1">Prompt 1: table meaning and usage</label>
                        <textarea id="prompt_1" name="prompt_1" required><?php echo h($upload['prompt_1'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="prompt_2">Prompt 2: detailed field analysis and parsing rules</label>
                        <textarea id="prompt_2" name="prompt_2" required><?php echo h($upload['prompt_2'] ?? ''); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="data_discovery_prompt">Data discovery prompt</label>
                        <textarea id="data_discovery_prompt" name="data_discovery_prompt"><?php
                            $savedDiscoveryPrompt = trim((string)($upload['data_discovery_prompt'] ?? ''));
                            echo h($savedDiscoveryPrompt !== '' ? $savedDiscoveryPrompt : defaultDataDiscoveryPrompt());
                        ?></textarea>
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
        const analyzeEditAiBtn = document.getElementById('analyzeEditAiBtn');
        if (analyzeEditAiBtn) {
            analyzeEditAiBtn.addEventListener('click', function () {
                const aiSelect = document.getElementById('id_ai_db');
                const uploadId = analyzeEditAiBtn.getAttribute('data-upload-id');
                if (!uploadId) {
                    alert('Upload not found.');
                    return;
                }
                if (!aiSelect || !aiSelect.value) {
                    alert('Select an AI profile first.');
                    return;
                }

                analyzeEditAiBtn.disabled = true;
                const oldText = analyzeEditAiBtn.textContent;
                analyzeEditAiBtn.textContent = 'Analisi in corso...';

                const payload = new URLSearchParams({
                    action: 'analyze_with_ai',
                    upload_id: uploadId,
                    id_ai_db: aiSelect.value
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

                    if (result.fields) {
                        const titleInput = document.getElementById('description');
                        const longTitleInput = document.getElementById('long_description');
                        const prompt1Input = document.getElementById('prompt_1');
                        const prompt2Input = document.getElementById('prompt_2');

                        if (titleInput) titleInput.value = result.fields.description || '';
                        if (longTitleInput) longTitleInput.value = result.fields.long_description || '';
                        if (prompt1Input) prompt1Input.value = result.fields.prompt_1 || '';
                        if (prompt2Input) prompt2Input.value = result.fields.prompt_2 || '';
                    }

                    alert('AI analysis completed. You can manually edit all fields before saving.');
                })
                .catch(error => {
                    alert(error.message || 'AI request failed.');
                })
                .finally(() => {
                    analyzeEditAiBtn.disabled = false;
                    analyzeEditAiBtn.textContent = oldText;
                });
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


