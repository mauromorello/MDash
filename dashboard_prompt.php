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

function buildAbsolutePath(string $relativePath): string {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return $scheme . '://' . $host . ($basePath !== '' ? $basePath . '/' : '/') . $relativePath;
}

function normalizeSection(string $title, string $content): string {
    return "[" . $title . "]\n" . trim($content) . "\n";
}

function getEnvironmentValue(string $name): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }

    $dotenvPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($dotenvPath)) {
        return '';
    }

    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $rawValue] = array_map('trim', explode('=', $line, 2));
        if ($key !== $name) {
            continue;
        }

        return trim($rawValue, "\"'");
    }

    return '';
}

function ensureResultsTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS results (
            id INT NOT NULL,
            path TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            id_template INT NOT NULL,
            final_prompt TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            thumbnail_path TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            id_owner INT NOT NULL,
            is_public INT NOT NULL DEFAULT '0',
            is_hidden INT NOT NULL DEFAULT '0',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function getNextResultId(PDO $pdo): int {
    $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM results');
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['next_id'] ?? 1);
}

function ensureDirectory(string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create output directory.');
    }
}

function xmlEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function truncateText(string $value, int $maxLength): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $maxLength ? mb_substr($value, 0, $maxLength - 1, 'UTF-8') . '…' : $value;
    }

    return strlen($value) > $maxLength ? substr($value, 0, $maxLength - 1) . '…' : $value;
}

function buildThumbnailSvg(array $context): string {
    $title = truncateText((string)($context['title'] ?? 'Dashboard'), 42);
    $templateTitle = truncateText((string)($context['template_title'] ?? 'No template'), 42);
    $ownerName = truncateText((string)($context['owner_name'] ?? 'Unknown owner'), 42);
    $resultId = (int)($context['result_id'] ?? 0);
    $createdAt = truncateText((string)($context['created_at'] ?? date('Y-m-d H:i:s')), 32);

    $lines = [
        $title,
        'Template: ' . $templateTitle,
        'Owner: ' . $ownerName,
        'Result #' . $resultId,
        $createdAt,
    ];

    $textY = 150;
    $textChunks = '';
    foreach ($lines as $line) {
        $textChunks .= '<text x="80" y="' . $textY . '" fill="#e2e8f0" font-size="36" font-family="Arial, Helvetica, sans-serif">' . xmlEscape($line) . '</text>';
        $textY += 62;
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675" viewBox="0 0 1200 675" role="img" aria-label="Dashboard thumbnail">'
        . '<defs>'
        . '<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0%" stop-color="#0f172a"/>'
        . '<stop offset="100%" stop-color="#2563eb"/>'
        . '</linearGradient>'
        . '<linearGradient id="card" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0%" stop-color="#ffffff" stop-opacity="0.18"/>'
        . '<stop offset="100%" stop-color="#ffffff" stop-opacity="0.06"/>'
        . '</linearGradient>'
        . '</defs>'
        . '<rect width="1200" height="675" rx="36" fill="url(#bg)"/>'
        . '<circle cx="1030" cy="130" r="110" fill="#60a5fa" fill-opacity="0.18"/>'
        . '<circle cx="980" cy="540" r="160" fill="#22c55e" fill-opacity="0.10"/>'
        . '<rect x="60" y="60" width="1080" height="555" rx="30" fill="url(#card)" stroke="#ffffff" stroke-opacity="0.12"/>'
        . '<text x="80" y="115" fill="#ffffff" font-size="34" font-weight="700" font-family="Arial, Helvetica, sans-serif">Generated Dashboard</text>'
        . $textChunks
        . '<rect x="760" y="110" width="300" height="24" rx="12" fill="#ffffff" fill-opacity="0.25"/>'
        . '<rect x="760" y="160" width="220" height="24" rx="12" fill="#ffffff" fill-opacity="0.18"/>'
        . '<rect x="760" y="210" width="260" height="24" rx="12" fill="#ffffff" fill-opacity="0.18"/>'
        . '<rect x="760" y="260" width="200" height="24" rx="12" fill="#ffffff" fill-opacity="0.18"/>'
        . '<rect x="760" y="310" width="240" height="24" rx="12" fill="#ffffff" fill-opacity="0.18"/>'
        . '<rect x="760" y="360" width="280" height="24" rx="12" fill="#ffffff" fill-opacity="0.18"/>'
        . '<rect x="760" y="410" width="180" height="24" rx="12" fill="#ffffff" fill-opacity="0.18"/>'
        . '<rect x="760" y="460" width="280" height="24" rx="12" fill="#ffffff" fill-opacity="0.18"/>'
        . '</svg>';
}

function callGeminiGenerateHtml(string $finalPrompt): string {
    /*
     Gemini API requirements:
     - Set GEMINI_API_KEY in .env or server environment.
     - Use the Gemini Generative Language REST endpoint shown in the provided curl example.
     - Send the final prompt as a single user content payload.
     - Ask for a complete HTML dashboard output only, without markdown fences.
     - Handle API errors and rate limits before saving the generated file.
    */
    $apiKey = getEnvironmentValue('GEMINI_API_KEY') ?: getEnvironmentValue('GOOGLE_API_KEY');
    if ($apiKey === '') {
        throw new RuntimeException('Missing Gemini API key in environment.');
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';
    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => $finalPrompt . "\n\nReturn only a complete HTML document. Do not use markdown fences."
                    ]
                ]
            ]
        ],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $errorMessage = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Gemini request failed: ' . $errorMessage);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $detail = $decoded['error']['message'] ?? $response;
        throw new RuntimeException('Gemini API error: ' . $detail);
    }

    $text = '';
    if (!empty($decoded['candidates'][0]['content']['parts'])) {
        foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
            $text .= (string)($part['text'] ?? '');
        }
    }

    $text = trim($text);
    if ($text === '') {
        throw new RuntimeException('Gemini returned an empty response.');
    }

    return preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $text) ?? $text;
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
$dashboard = null;
$upload = null;
$template = null;
$resultFilePath = '';
$generatedHtml = '';
$generationSteps = [];
$message = '';
$dashboardId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$promptTitle = '';
$masterPrompt = '';

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
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

if ($pdo && $dashboardId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM dashboards WHERE id = ? LIMIT 1');
        $stmt->execute([$dashboardId]);
        $dashboard = $stmt->fetch();

        if ($dashboard && !empty($dashboard['id_datasource'])) {
            $uploadStmt = $pdo->prepare('SELECT * FROM uploads WHERE id = ? LIMIT 1');
            $uploadStmt->execute([(int)$dashboard['id_datasource']]);
            $upload = $uploadStmt->fetch();
        }

        if ($dashboard && !empty($dashboard['id_template'])) {
            $templateStmt = $pdo->prepare(
                'SELECT t.id, t.title, t.prompt, t.id_owner, t.is_public, t.is_hidden, u.username AS owner_username
                 FROM templates t
                 LEFT JOIN users u ON u.id = t.id_owner
                 WHERE t.id = ? AND (t.id_owner = ? OR t.is_public = 1) AND t.is_hidden = 0
                 LIMIT 1'
            );
            $templateStmt->execute([(int)$dashboard['id_template'], (int)$user['id']]);
            $template = $templateStmt->fetch();
        }
    } catch (PDOException $e) {
        $error = 'Error while loading data: ' . $e->getMessage();
    }
}

if (!$dashboard && $error === '') {
    $error = 'Dashboard not found.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_dashboard') {
    if (!$dashboard) {
        $error = 'Dashboard not found.';
    } else {
        try {
            $generationSteps[] = 'Preparing dashboard generation request.';
            ensureResultsTable($pdo);
            $generationSteps[] = 'Results table is available.';

            $generationSteps[] = 'Sending prompt to Gemini API.';
            $generatedHtml = callGeminiGenerateHtml((string)($_POST['master_prompt'] ?? $masterPrompt));
            $generationSteps[] = 'AI response received successfully.';
            $generationSteps[] = 'Generated HTML length: ' . strlen($generatedHtml) . ' bytes.';

            $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';

            $resultId = getNextResultId($pdo);
            $resultDir = $resultsDir . DIRECTORY_SEPARATOR . $resultId;
            ensureDirectory($resultDir);
            $generationSteps[] = 'Created output directory: results/' . $resultId . '/';

            $fileName = 'dashboard.html';
            $diskPath = $resultDir . DIRECTORY_SEPARATOR . $fileName;
            file_put_contents($diskPath, $generatedHtml);
            $generationSteps[] = 'Saved dashboard HTML to results/' . $resultId . '/dashboard.html.';

            $relativePath = 'results/' . $resultId . '/' . $fileName;
            $thumbnailPath = 'results/' . $resultId . '/thumbnail.svg';
            file_put_contents(
                $resultDir . DIRECTORY_SEPARATOR . 'thumbnail.svg',
                buildThumbnailSvg([
                    'title' => (string)($dashboard['title'] ?? 'Dashboard'),
                    'template_title' => (string)($template['title'] ?? 'No template'),
                    'owner_name' => (string)($user['username'] ?? 'Unknown owner'),
                    'result_id' => $resultId,
                    'created_at' => date('Y-m-d H:i:s'),
                ])
            );
            $generationSteps[] = 'Generated thumbnail SVG preview.';
            $idTemplate = (int)($dashboard['id_template'] ?? 0);
            $idOwner = (int)$user['id'];
            $isPublic = (int)($dashboard['is_public'] ?? 0);
            $isHidden = (int)($dashboard['is_hidden'] ?? 0);

            $insertStmt = $pdo->prepare(
                'INSERT INTO results (id, path, id_template, final_prompt, thumbnail_path, id_owner, is_public, is_hidden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([
                $resultId,
                $relativePath,
                $idTemplate,
                (string)($_POST['master_prompt'] ?? $masterPrompt),
                $thumbnailPath,
                $idOwner,
                $isPublic,
                $isHidden,
            ]);
            $generationSteps[] = 'Inserted result row with ID ' . $resultId . '.';

            $resultFilePath = $relativePath;
            $message = 'Dashboard generated successfully.';
            $generationSteps[] = 'Generation completed.';
        } catch (Throwable $e) {
            $generationSteps[] = 'Generation failed.';
            $error = 'Error while generating dashboard: ' . $e->getMessage();
        }
    }
}

if ($dashboard) {
    $promptTitle = trim((string)($dashboard['title'] ?? 'Dashboard without title'));

    $sections = [];
    $sections[] = normalizeSection('Dashboard title', $promptTitle);
    $sections[] = normalizeSection(
        'Dashboard prompt',
        "Data filter prompt:\n" . ($dashboard['data_filter_prompt'] ?? '') . "\n\n" .
        "Data manipulation prompt:\n" . ($dashboard['data_manipulation_prompt'] ?? '') . "\n\n" .
        "Dashboard prompt 1:\n" . ($dashboard['dashboard_prompt_1'] ?? '') . "\n\n" .
        "Dashboard prompt 2:\n" . ($dashboard['dashboard_prompt_2'] ?? '')
    );
    $sections[] = normalizeSection(
        'Template',
        "Template ID: " . (string)($dashboard['id_template'] ?? 0) . "\n" .
        "Template title: " . ($template['title'] ?? 'N/A') . "\n\n" .
        ($template && !empty($template['prompt']) ? (string)$template['prompt'] : '')
    );
    $sections[] = normalizeSection(
        'Makeup',
        ''
    );
    $sections[] = normalizeSection(
        'Error output',
        $error !== '' ? $error : 'None'
    );

    $masterPrompt = implode("\n", $sections);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_prompt') {
    $promptTitle = trim((string)($_POST['prompt_title'] ?? $promptTitle));
    $masterPrompt = trim((string)($_POST['master_prompt'] ?? $masterPrompt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Prompt</title>
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
                <h1>Dashboard prompt</h1>
                <div class="meta">Generate and refine the final prompt that will be sent to the AI.</div>
            </div>
            <a href="dashboards.php">Back to dashboard list</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="message"><?php echo h($message); ?><?php echo $resultFilePath !== '' ? ' Output saved to ' . h($resultFilePath) . '.' : ''; ?></div>
        <?php endif; ?>

        <?php if (!empty($generationSteps)): ?>
            <div class="card generation-panel">
                <h2>Generation status</h2>
                <p class="meta">The app cannot expose the model's internal reasoning, but it can show the execution steps, the returned HTML, and the saved output.</p>
                <ul class="generation-log">
                    <?php foreach ($generationSteps as $step): ?>
                        <li><?php echo h($step); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="post">
                <input type="hidden" name="action" value="preview_prompt">
                <input type="hidden" name="id" value="<?php echo h($dashboardId); ?>">

                <div class="field">
                    <label for="prompt_title">Title</label>
                    <input type="text" id="prompt_title" name="prompt_title" value="<?php echo h($promptTitle); ?>">
                </div>

                <div class="field">
                    <label for="master_prompt">Prompt</label>
                    <textarea id="master_prompt" name="master_prompt" style="min-height: 520px;"><?php echo h($masterPrompt); ?></textarea>
                </div>

                <div class="inline-actions">
                    <button type="submit">Refresh preview</button>
                    <button type="button" class="secondary" id="copyPromptBtn">Copy prompt</button>
                    <a href="edit_dashboard.php?id=<?php echo h($dashboardId); ?>" class="btn-secondary">Edit dashboard</a>
                </div>
            </form>
        </div>

        <div class="card">
            <form method="post">
                <input type="hidden" name="action" value="generate_dashboard">
                <input type="hidden" name="id" value="<?php echo h($dashboardId); ?>">
                <input type="hidden" name="master_prompt" value="<?php echo h($masterPrompt); ?>">
                <input type="hidden" name="prompt_title" value="<?php echo h($promptTitle); ?>">
                <button type="submit">Generate dashboard</button>
            </form>
        </div>

        <?php if (!empty($generatedHtml)): ?>
            <div class="card generation-panel">
                <h2>Generated output</h2>
                <div class="generated-preview-wrap">
                    <iframe class="generated-preview-frame" title="Generated dashboard preview" srcdoc="<?php echo h($generatedHtml); ?>"></iframe>
                </div>

                <div class="field">
                    <label for="generatedHtmlCode">Generated HTML source</label>
                    <textarea id="generatedHtmlCode" readonly style="min-height: 360px;"><?php echo h($generatedHtml); ?></textarea>
                </div>

                <?php if ($resultFilePath !== ''): ?>
                    <div class="inline-actions">
                        <a href="<?php echo h($resultFilePath); ?>" target="_blank" rel="noopener">Open saved file</a>
                        <a href="results.php">Go to results</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const copyPromptBtn = document.getElementById('copyPromptBtn');
        if (copyPromptBtn) {
            copyPromptBtn.addEventListener('click', function () {
                const promptField = document.getElementById('master_prompt');
                if (!promptField) {
                    return;
                }
                promptField.select();
                promptField.setSelectionRange(0, promptField.value.length);
                navigator.clipboard.writeText(promptField.value).catch(function () {});
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