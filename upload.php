<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/ai_shared.php';

function sendJson(bool $success, string $message, int $uploadId = 0): void {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'upload_id' => $uploadId,
    ]);
    exit;
}

function sendAiJson(bool $success, string $message, array $payload = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $payload));
    exit;
}

function getUserFromSessionOrCookie(): ?array {
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

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function utf8Length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function ensureOptionsTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS options (
            option_key VARCHAR(191) NOT NULL PRIMARY KEY,
            option_value LONGTEXT NOT NULL,
            value_type VARCHAR(20) NOT NULL DEFAULT 'text',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function getUploadClauseOptionKey(int $userId): string {
    return 'upload.data_clause.accepted.user.' . $userId;
}

function hasAcceptedUploadClause(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare('SELECT option_value FROM options WHERE option_key = ? LIMIT 1');
    $stmt->execute([getUploadClauseOptionKey($userId)]);
    $row = $stmt->fetch();

    return $row !== false && trim((string)($row['option_value'] ?? '')) !== '';
}

function saveUploadClauseAcceptance(PDO $pdo, int $userId, string $username): void {
    $optionKey = getUploadClauseOptionKey($userId);
    $payload = json_encode([
        'accepted' => 1,
        'user_id' => $userId,
        'username' => $username,
        'accepted_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare(
        'INSERT INTO options (option_key, option_value, value_type) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), value_type = VALUES(value_type)'
    );
    $stmt->execute([$optionKey, (string)$payload, 'json']);
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

function readDatasetContentForAi(string $absolutePath, int $maxDataRows = 1000): string {
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        throw new RuntimeException('Uploaded file is not readable.');
    }

    $stream = fopen($absolutePath, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open uploaded file.');
    }

    $rawLines = [];
    $lineCount = 0;
    $maxLinesToSend = $maxDataRows + 1; // header + first N rows

    while (!feof($stream)) {
        $line = fgets($stream);
        if ($line === false) {
            break;
        }

        $lineCount++;
        if (count($rawLines) < $maxLinesToSend) {
            $normalized = rtrim($line, "\r\n");
            if (count($rawLines) === 0) {
                $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;
            }
            $rawLines[] = $normalized;
        }
    }
    fclose($stream);

    if (empty($rawLines)) {
        throw new RuntimeException('Uploaded file is empty or unreadable.');
    }

    $content = implode("\n", $rawLines);
    if ($lineCount > $maxLinesToSend) {
        $content .= "\n\nNOTE: Dataset truncated for AI analysis. Only header + first {$maxDataRows} data rows are included.";
    }

    return $content;
}

function buildUploadAutofillPrompt(array $uploadRecord, string $datasetContent): string {
    $fileName = (string)($uploadRecord['filename'] ?? 'data.csv');

    return "You are a senior data analyst.\n"
        . "Analyze the FULL dataset content below (not only a sample).\n"
        . "IMPORTANT: your entire answer MUST be in English only.\n"
        . "Return plain text only (no markdown, no code fences).\n"
        . "Return EXACTLY these 4 keys, one key per line and in this order:\n"
        . "Title: <short title in English>\n"
        . "Long title: <long descriptive title in English>\n"
        . "Prompt 1: <what this table is and what it is used for, in English>\n"
        . "Prompt 2: <STRUCTURED field-by-field schema and parsing rules in English>\n\n"
        . "Prompt 2 must include, for EVERY detected field/column, all of these elements:\n"
        . "- Field name\n"
        . "- Business meaning\n"
        . "- Observed data type\n"
        . "- Example values\n"
        . "- Parsing rule\n"
        . "- Date parsing format (if applicable)\n"
        . "- Numeric normalization rule (if applicable)\n"
        . "- Validation checks\n"
        . "If a rule is unknown, state 'Unknown - requires business confirmation'.\n\n"
        . "File name: {$fileName}\n"
        . "Full dataset content:\n"
        . $datasetContent;
}

function prompt2LooksStructural(string $prompt2): bool {
    $text = trim($prompt2);
    if ($text === '') {
        return false;
    }

    $fieldMarkers = preg_match_all('/(^|\n)\s*(Field|Column)\s*[:\-]/i', $text);
    $parseMarkers = preg_match_all('/parsing|normalization|validation|date format|type/i', $text);

    return ($fieldMarkers !== false && $fieldMarkers >= 2) && ($parseMarkers !== false && $parseMarkers >= 3);
}

function detectLikelyType(array $values): string {
    $nonEmpty = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $values), static fn($v) => $v !== ''));
    if (empty($nonEmpty)) {
        return 'string';
    }

    $isBool = true;
    $isNumber = true;
    $isDate = true;

    foreach ($nonEmpty as $value) {
        $lower = strtolower($value);
        if (!in_array($lower, ['0', '1', 'true', 'false', 'yes', 'no', 'y', 'n'], true)) {
            $isBool = false;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            $isNumber = false;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            $isDate = false;
        }
    }

    if ($isBool) {
        return 'boolean';
    }
    if ($isNumber) {
        return 'number';
    }
    if ($isDate) {
        return 'date';
    }

    return 'string';
}

function buildPrompt2FallbackFromCsv(string $absolutePath, int $maxRows = 200): string {
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        return '';
    }

    $header = [];
    $rows = [];
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

    if (empty($header)) {
        return '';
    }

    $lines = [];
    $lines[] = 'Field schema and parsing instructions';
    $lines[] = 'Use this section as operational parsing guidance for future dashboard generations.';

    foreach ($header as $idx => $columnName) {
        $columnValues = [];
        foreach ($rows as $row) {
            $columnValues[] = (string)($row[$idx] ?? '');
        }

        $type = detectLikelyType($columnValues);
        $examples = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $columnValues), static fn($v) => $v !== '')));
        $examples = array_slice($examples, 0, 3);
        $exampleText = empty($examples) ? 'N/A' : implode(' | ', $examples);

        $parseRule = 'Trim whitespace and preserve source value.';
        $dateRule = 'N/A';
        $numericRule = 'N/A';
        if ($type === 'number') {
            $parseRule = 'Convert to numeric type after trimming and removing thousand separators.';
            $numericRule = 'Replace comma decimal separators when needed and cast to decimal.';
        } elseif ($type === 'date') {
            $parseRule = 'Parse date using a deterministic parser and store as ISO-8601 when possible.';
            $dateRule = 'Detect input format explicitly before conversion; avoid locale ambiguity.';
        } elseif ($type === 'boolean') {
            $parseRule = 'Map accepted tokens to boolean: true/false, yes/no, 1/0.';
        }

        $lines[] = '';
        $lines[] = 'Field: ' . ($columnName !== '' ? $columnName : ('column_' . ($idx + 1)));
        $lines[] = 'Business meaning: Unknown - requires business confirmation';
        $lines[] = 'Observed data type: ' . $type;
        $lines[] = 'Example values: ' . $exampleText;
        $lines[] = 'Parsing rule: ' . $parseRule;
        $lines[] = 'Date parsing format: ' . $dateRule;
        $lines[] = 'Numeric normalization rule: ' . $numericRule;
        $lines[] = 'Validation checks: Non-null checks when required, domain checks, and duplicate checks.';
    }

    return implode("\n", $lines);
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
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $clean) ?: []), static fn($v) => $v !== ''));
        if (count($lines) >= 4) {
            $title = $title !== '' ? $title : preg_replace('/^Title\s*:\s*/i', '', $lines[0]);
            $longTitle = $longTitle !== '' ? $longTitle : preg_replace('/^Long\s*title\s*:\s*/i', '', $lines[1]);
            $prompt1 = $prompt1 !== '' ? $prompt1 : preg_replace('/^Prompt\s*1\s*:\s*/i', '', $lines[2]);
            $prompt2 = $prompt2 !== '' ? $prompt2 : preg_replace('/^Prompt\s*2\s*:\s*/i', '', implode("\n", array_slice($lines, 3)));
        }
    }

    if ($title === '' || $prompt1 === '' || $prompt2 === '') {
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
$dbError = '';
$message = '';
$uploadId = (int)($_GET['id'] ?? $_POST['upload_id'] ?? 0);
$record = null;
$aiProfiles = [];
$uploadClauseAccepted = false;
$flow = (string)($_GET['flow'] ?? $_POST['flow'] ?? '');
if ($flow !== 'ai' && $flow !== 'manual') {
    $flow = '';
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS uploads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            description MEDIUMTEXT NOT NULL,
            tags TEXT NOT NULL,
            long_description MEDIUMTEXT NOT NULL,
            prompt_1 MEDIUMTEXT NOT NULL,
            data_discovery_prompt MEDIUMTEXT NOT NULL,
            prompt_2 MEDIUMTEXT NOT NULL,
            id_owner INT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $tableExists = $pdo->query("SHOW TABLES LIKE 'uploads'")->fetchColumn();
    if ($tableExists) {
        $idColumn = $pdo->query("SHOW COLUMNS FROM uploads LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE uploads MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

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
    ensureOptionsTable($pdo);
    $uploadClauseAccepted = hasAcceptedUploadClause($pdo, (int)$user['id']);

    $aiProfilesStmt = $pdo->prepare(
        'SELECT a.*
         FROM ai_db a
         WHERE ((a.id_owner = :user_id AND a.is_hidden = 0) OR (a.is_public = 1 AND a.is_hidden = 0))
         ORDER BY a.id DESC'
    );
    $aiProfilesStmt->execute(['user_id' => (int)$user['id']]);
    $aiProfiles = $aiProfilesStmt->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload_file') {
        $alreadyAccepted = hasAcceptedUploadClause($pdo, (int)$user['id']);
        $acceptUploadPolicy = $alreadyAccepted || (int)($_POST['accept_upload_policy'] ?? 0) === 1;

        if (!$acceptUploadPolicy) {
            $message = 'You must accept the data upload clause before uploading a file.';
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                sendJson(false, $message);
            }
        }

        if ($acceptUploadPolicy && !$alreadyAccepted) {
            saveUploadClauseAcceptance($pdo, (int)$user['id'], (string)($user['username'] ?? 'user'));
            $uploadClauseAccepted = true;
        } elseif ($alreadyAccepted) {
            $uploadClauseAccepted = true;
        }

        if ($message === '' && empty($_FILES['file']['name'])) {
            $message = 'Select a valid file to upload.';
        }

        if ($message === '' && isset($_FILES['file']['error']) && (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErr = (int)$_FILES['file']['error'];
            if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
                $message = 'The selected file is too large for current PHP upload limits. Increase upload_max_filesize and post_max_size.';
            } elseif ($uploadErr === UPLOAD_ERR_PARTIAL) {
                $message = 'File upload was interrupted (partial upload). Please retry.';
            } else {
                $message = 'Upload failed with error code ' . $uploadErr . '.';
            }
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                sendJson(false, $message);
            }
        }

        if ($message === '') {
            $originalName = (string)($_FILES['file']['name'] ?? '');
            $normalizedName = str_replace('\\', '/', str_replace("\0", '', $originalName));
            $fileName = basename($normalizedName);
            if ($fileName === '' || $fileName === '.' || $fileName === '..') {
                $fileName = 'file.csv';
            }

            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO uploads (path, filename, description, tags, long_description, prompt_1, data_discovery_prompt, prompt_2, id_owner, is_public) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    '',
                    $fileName,
                    '',
                    '',
                    '',
                    '',
                    defaultDataDiscoveryPrompt(),
                    '',
                    (int)$user['id'],
                    0,
                ]);

                $newUploadId = (int)$pdo->lastInsertId();
                $recordDir = $uploadDir . DIRECTORY_SEPARATOR . $newUploadId;
                if (!is_dir($recordDir)) {
                    mkdir($recordDir, 0777, true);
                }

                $targetPath = $recordDir . DIRECTORY_SEPARATOR . $fileName;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                    $relativePath = 'uploads/' . $newUploadId . '/' . $fileName;
                    $updateStmt = $pdo->prepare('UPDATE uploads SET path = ? WHERE id = ?');
                    $updateStmt->execute([$relativePath, $newUploadId]);
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        sendJson(true, 'File uploaded successfully.', $newUploadId);
                    }
                    $uploadId = $newUploadId;
                    $message = 'File uploaded successfully.';
                } else {
                    $deleteStmt = $pdo->prepare('DELETE FROM uploads WHERE id = ?');
                    $deleteStmt->execute([$newUploadId]);
                    $message = 'The file was not saved. Please try again.';
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        sendJson(false, $message);
                    }
                }
            } catch (Throwable $e) {
                $message = 'Error while saving file: ' . $e->getMessage();
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    sendJson(false, $message);
                }
            }
        } else {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                sendJson(false, $message);
            }
        }
    }

    if ($action === 'generate_upload_ai') {
        try {
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $aiId = (int)($_POST['id_ai_db'] ?? 0);

            if ($uploadId <= 0) {
                throw new RuntimeException('Invalid upload ID.');
            }
            if ($aiId <= 0) {
                throw new RuntimeException('Select an AI profile first.');
            }

            if (!isset($_SESSION['upload_ai_called'])) {
                $_SESSION['upload_ai_called'] = [];
            }
            if (!empty($_SESSION['upload_ai_called'][$uploadId])) {
                throw new RuntimeException('AI analysis for this upload has already been executed once.');
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
            $datasetContent = readDatasetContentForAi($absolutePath, 1000);

            $prompt = buildUploadAutofillPrompt($uploadRecord, $datasetContent);
            $generatedText = generateTextFromAiProfile($prompt, $aiProfile);
            $parsed = parseAutofillResponse($generatedText);
            $prompt2Final = trim((string)$parsed['prompt_2']);

            if (!prompt2LooksStructural($prompt2Final)) {
                $fallbackPrompt2 = buildPrompt2FallbackFromCsv($absolutePath, 1000);
                if ($fallbackPrompt2 !== '') {
                    $prompt2Final = trim($prompt2Final) !== ''
                        ? (trim($prompt2Final) . "\n\n" . $fallbackPrompt2)
                        : $fallbackPrompt2;
                }
            }

            if (utf8Length($parsed['title']) > 16000000 || utf8Length($parsed['long_title']) > 16000000 || utf8Length($parsed['prompt_1']) > 16000000 || utf8Length($prompt2Final) > 16000000) {
                throw new RuntimeException('AI output is too long for upload fields.');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE uploads SET description = ?, long_description = ?, prompt_1 = ?, prompt_2 = ?, data_discovery_prompt = ? WHERE id = ? AND id_owner = ?'
            );
            $updateStmt->execute([
                $parsed['title'],
                $parsed['long_title'],
                $parsed['prompt_1'],
                $prompt2Final,
                defaultDataDiscoveryPrompt(),
                $uploadId,
                (int)$user['id'],
            ]);

            $_SESSION['upload_ai_called'][$uploadId] = 1;

            sendAiJson(true, 'AI analysis completed.', [
                'fields' => [
                    'description' => $parsed['title'],
                    'long_description' => $parsed['long_title'],
                    'prompt_1' => $parsed['prompt_1'],
                    'prompt_2' => $prompt2Final,
                ],
                'generated_text' => $parsed['raw_text'],
            ]);
        } catch (Throwable $e) {
            sendAiJson(false, $e->getMessage());
        }
    }

    if ($action === 'save_metadata') {
        $uploadId = (int)($_POST['upload_id'] ?? 0);
        $description = trim((string)($_POST['description'] ?? ''));
        $longDescription = trim((string)($_POST['long_description'] ?? ''));
        $prompt1 = trim((string)($_POST['prompt_1'] ?? ''));
        $prompt2 = trim((string)($_POST['prompt_2'] ?? ''));
        $dataDiscoveryPrompt = trim((string)($_POST['data_discovery_prompt'] ?? defaultDataDiscoveryPrompt()));
        $isPublic = isset($_POST['is_public']) ? 1 : 0;

        if ($description === '' || $prompt1 === '' || $prompt2 === '') {
            $message = 'Title, Prompt 1 and Prompt 2 are required.';
        } elseif (utf8Length($description) > 16000000 || utf8Length($longDescription) > 16000000 || utf8Length($prompt1) > 16000000 || utf8Length($prompt2) > 16000000 || utf8Length($dataDiscoveryPrompt) > 16000000) {
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

            header('Location: main.php');
            exit;
        }
    }
}

if ($uploadId > 0 && $pdo) {
    $rowStmt = $pdo->prepare('SELECT * FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1');
    $rowStmt->execute([$uploadId, (int)$user['id']]);
    $record = $rowStmt->fetch();
}

$preview = ['header' => [], 'rows' => []];
if ($record) {
    $relativePath = (string)($record['path'] ?? '');
    if ($relativePath !== '') {
        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        try {
            $preview = readCsvPreviewRows($absolutePath, 10);
        } catch (Throwable $e) {
            $message = $message !== '' ? $message : $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload file</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page upload-wrap">
        <div class="topbar">
            <h1>Upload file</h1>
            <a href="main.php">Back to home</a>
        </div>

        <?php if ($dbError): ?>
            <div class="message error"><?php echo h($dbError); ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="message"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if (!$record): ?>
            <div class="card">
                <form id="uploadForm" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_file">
                    <?php if ($uploadClauseAccepted): ?>
                        <input type="hidden" name="accept_upload_policy" value="1" id="acceptUploadPolicyHidden">
                    <?php endif; ?>
                    <div class="field">
                        <label for="file">Select file</label>
                        <input type="file" id="file" name="file" required>
                        <div class="hint">The file will be stored in uploads/id/original_filename.ext.</div>
                    </div>
                    <div id="progressBox" class="progress-box">
                        <div id="progressLabel">Upload in progress...</div>
                        <div class="progress-track"><div id="progressFill" class="progress-fill"></div></div>
                        <div id="progressText" class="progress-text">0%</div>
                    </div>
                    <button type="submit" id="uploadSubmitBtn" class="btn btn-primary upload-submit-btn<?php echo $uploadClauseAccepted ? '' : ' is-hidden'; ?>"<?php echo $uploadClauseAccepted ? '' : ' disabled'; ?>>Upload file</button>

                    <div class="upload-policy-warning">
                        Warning: Uploaded data may be transmitted to a private server for AI processing. Review your internal company policies before uploading sensitive or regulated data.
                    </div>
                    <label class="upload-policy-check" for="accept_upload_policy">
                        <input type="checkbox" id="accept_upload_policy" name="accept_upload_policy" value="1"<?php echo $uploadClauseAccepted ? ' checked disabled' : ''; ?>>
                        I acknowledge and accept this data upload clause.
                    </label>
                </form>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Choose insert mode</h2>
                <p>Upload ID <strong><?php echo h($record['id']); ?></strong>. Decide how to populate fields.</p>
                <div class="choice-grid">
                    <a class="btn btn-secondary" href="upload.php?id=<?php echo h($record['id']); ?>&flow=ai">1) Use AI and auto-fill</a>
                    <a class="btn btn-primary" href="upload.php?id=<?php echo h($record['id']); ?>&flow=manual">2) Manual compile</a>
                </div>
            </div>

            <?php if ($flow !== ''): ?>
                <?php if ($flow === 'ai'): ?>
                    <div class="card">
                        <h3>AI analysis (one shot)</h3>
                        <p>The AI will be called one time and will auto-fill Title, Long title, Prompt 1 and Prompt 2.</p>
                        <div class="field">
                            <label for="id_ai_db">AI profile</label>
                            <select id="id_ai_db" name="id_ai_db">
                                <option value="">Select AI profile</option>
                                <?php foreach ($aiProfiles as $profile): ?>
                                    <option value="<?php echo h((int)$profile['id']); ?>">#<?php echo h((int)$profile['id']); ?> - <?php echo h((string)$profile['title']); ?> [<?php echo h((string)$profile['provider']); ?> / <?php echo h((string)$profile['model']); ?>]</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inline-actions">
                            <button type="button" class="secondary" id="analyzeUploadAiBtn" data-upload-id="<?php echo h($record['id']); ?>">Analyze with AI</button>
                        </div>
                    </div>
                <?php endif; ?>

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
                    <h3>Compile upload data</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="save_metadata">
                        <input type="hidden" name="upload_id" value="<?php echo h($record['id']); ?>">
                        <input type="hidden" name="flow" value="<?php echo h($flow); ?>">

                        <div class="field">
                            <label for="description">Title</label>
                            <input type="text" id="description" name="description" value="<?php echo h($record['description'] ?? ''); ?>" required>
                        </div>

                        <div class="field">
                            <label for="long_description">Long title</label>
                            <textarea id="long_description" name="long_description"><?php echo h($record['long_description'] ?? ''); ?></textarea>
                        </div>

                        <div class="field">
                            <label for="prompt_1">Prompt 1: table meaning and usage</label>
                            <textarea id="prompt_1" name="prompt_1" required><?php echo h($record['prompt_1'] ?? ''); ?></textarea>
                        </div>

                        <div class="field">
                            <label for="prompt_2">Prompt 2: detailed field analysis and parsing rules</label>
                            <textarea id="prompt_2" name="prompt_2" required><?php echo h($record['prompt_2'] ?? ''); ?></textarea>
                        </div>

                        <div class="field">
                            <label for="data_discovery_prompt">Data discovery prompt (editable)</label>
                            <textarea id="data_discovery_prompt" name="data_discovery_prompt"><?php
                                $savedDiscoveryPrompt = trim((string)($record['data_discovery_prompt'] ?? ''));
                                echo h($savedDiscoveryPrompt !== '' ? $savedDiscoveryPrompt : defaultDataDiscoveryPrompt());
                            ?></textarea>
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
        <?php endif; ?>
    </div>

    <script>
        const form = document.getElementById('uploadForm');
        const progressBox = document.getElementById('progressBox');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        const progressLabel = document.getElementById('progressLabel');
        const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
        const acceptUploadPolicyCheckbox = document.getElementById('accept_upload_policy');

        function ensureUploadPolicyHiddenInput() {
            if (!form) {
                return;
            }

            let hidden = document.getElementById('acceptUploadPolicyHidden');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'accept_upload_policy';
                hidden.value = '1';
                hidden.id = 'acceptUploadPolicyHidden';
                form.appendChild(hidden);
            }
        }

        function lockAcceptanceAndEnableUpload() {
            if (acceptUploadPolicyCheckbox) {
                acceptUploadPolicyCheckbox.checked = true;
                acceptUploadPolicyCheckbox.disabled = true;
            }
            ensureUploadPolicyHiddenInput();
            if (uploadSubmitBtn) {
                uploadSubmitBtn.classList.remove('is-hidden');
                uploadSubmitBtn.disabled = false;
            }
        }

        if (acceptUploadPolicyCheckbox && acceptUploadPolicyCheckbox.checked) {
            lockAcceptanceAndEnableUpload();
        }

        if (acceptUploadPolicyCheckbox && !acceptUploadPolicyCheckbox.disabled) {
            acceptUploadPolicyCheckbox.addEventListener('change', function () {
                if (acceptUploadPolicyCheckbox.checked) {
                    lockAcceptanceAndEnableUpload();
                }
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                if (!acceptUploadPolicyCheckbox || !acceptUploadPolicyCheckbox.checked) {
                    event.preventDefault();
                    alert('You must accept the data upload clause before uploading files.');
                    return;
                }

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
                            }
                        } catch (e) {
                            progressLabel.textContent = 'Invalid response from server.';
                            progressText.textContent = 'Error';
                        }
                    } else {
                        let serverMessage = 'Upload error.';
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.message) {
                                serverMessage = parsed.message;
                            }
                        } catch (e) {
                        }
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

        const analyzeUploadAiBtn = document.getElementById('analyzeUploadAiBtn');
        if (analyzeUploadAiBtn) {
            analyzeUploadAiBtn.addEventListener('click', function () {
                const aiSelect = document.getElementById('id_ai_db');
                const uploadId = analyzeUploadAiBtn.getAttribute('data-upload-id');
                if (!uploadId) {
                    alert('Upload not found.');
                    return;
                }
                if (!aiSelect || !aiSelect.value) {
                    alert('Select an AI profile first.');
                    return;
                }

                analyzeUploadAiBtn.disabled = true;
                const oldText = analyzeUploadAiBtn.textContent;
                analyzeUploadAiBtn.textContent = 'Analyzing full dataset...';

                const payload = new URLSearchParams({
                    action: 'generate_upload_ai',
                    upload_id: uploadId,
                    id_ai_db: aiSelect.value
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

                    analyzeUploadAiBtn.textContent = 'AI already executed';
                    alert('AI analysis completed. You can now review and edit all fields manually before saving.');
                })
                .catch(error => {
                    alert(error.message || 'AI request failed.');
                    analyzeUploadAiBtn.disabled = false;
                    analyzeUploadAiBtn.textContent = oldText;
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

