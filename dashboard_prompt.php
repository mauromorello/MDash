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

function normalizeSection(string $title, string $content): string {
    return "[" . $title . "]\n" . trim($content) . "\n";
}

function addSectionIfNotEmpty(array &$sections, array &$seenTitles, string $title, string $content): void {
    $trimmed = trim($content);
    if ($trimmed === '' || isset($seenTitles[$title])) {
        return;
    }

    $sections[] = normalizeSection($title, $trimmed);
    $seenTitles[$title] = true;
}

function findAiProfileById(array $profiles, int $id): ?array {
    foreach ($profiles as $profile) {
        if ((int)($profile['id'] ?? 0) === $id) {
            return $profile;
        }
    }

    return null;
}

function getAiErrorHint(string $errorMessage): string {
    $needle = strtolower($errorMessage);

    if (str_contains($needle, 'endpoint non riconosciuto')) {
        return 'Hint: endpoint non riconosciuto. Usa l\'endpoint Gemini con :generateContent e verifica il model.';
    }

    if (
        str_contains($needle, 'api non valida')
        || str_contains($needle, 'api key not valid')
        || str_contains($needle, 'unauthorized')
        || str_contains($needle, 'permission denied')
    ) {
        return 'Hint: API key non valida/non autorizzata. Controlla chiave, permessi e progetto associato.';
    }

    if (
        str_contains($needle, 'token superati')
        || str_contains($needle, 'quota')
        || str_contains($needle, 'rate limit')
        || str_contains($needle, 'resource exhausted')
    ) {
        return 'Hint: token/quota superati. Riduci prompt o usa un modello con limiti maggiori.';
    }

    if (str_contains($needle, 'modello non disponibile') || str_contains($needle, 'model')) {
        return 'Hint: modello non disponibile. Verifica nome modello e disponibilita sul tuo account.';
    }

    return '';
}

function ensureResultsTable(PDO $pdo): void {
    mdashEnsureResultsAiColumns($pdo);
}

function getNextResultId(PDO $pdo): int {
    $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM results');
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['next_id'] ?? 1);
}

function ensureDirectory(string $directory): void {
    if (is_dir($directory)) {
        return;
    }

    $parent = dirname($directory);
    if ($parent !== '' && !is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        $lastError = error_get_last();
        $detail = $lastError['message'] ?? 'unknown error';
        throw new RuntimeException('Unable to create parent directory at ' . $parent . ': ' . $detail);
    }

    if (!mkdir($directory, 0777, false) && !is_dir($directory)) {
        $lastError = error_get_last();
        $detail = $lastError['message'] ?? 'unknown error';
        throw new RuntimeException('Unable to create output directory at ' . $directory . ': ' . $detail);
    }
}

function xmlEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function truncateText(string $value, int $maxLength): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $maxLength ? mb_substr($value, 0, $maxLength - 1, 'UTF-8') . 'â€¦' : $value;
    }

    return strlen($value) > $maxLength ? substr($value, 0, $maxLength - 1) . 'â€¦' : $value;
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

function getDefaultEndpointForProvider(string $provider, string $model): string {
    if ($provider === 'openrouter') {
        return 'https://openrouter.ai/api/v1/chat/completions';
    }

    $selectedModel = $model !== '' ? $model : 'gemini-flash-latest';
    return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($selectedModel) . ':generateContent';
}

function extractGeneratedText(array $decoded, string $provider): string {
    if ($provider === 'openrouter') {
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $chunks = [];
            foreach ($content as $part) {
                if (is_array($part) && (($part['type'] ?? '') === 'text') && isset($part['text'])) {
                    $chunks[] = (string)$part['text'];
                }
            }
            return trim(implode("\n", $chunks));
        }

        return trim((string)$content);
    }

    $text = '';
    if (!empty($decoded['candidates'][0]['content']['parts'])) {
        foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
            $text .= (string)($part['text'] ?? '');
        }
    }

    return trim($text);
}

function callConfiguredAiGenerateHtml(string $finalPrompt, array $aiProfile = []): string {
    $apiKey = trim((string)($aiProfile['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('API non valida: il profilo AI selezionato non contiene una chiave API proprietaria.');
    }

    $provider = strtolower(trim((string)($aiProfile['provider'] ?? 'gemini')));
    $supportedProviders = mdashSupportedAiProviders();
    if (!isset($supportedProviders[$provider])) {
        throw new RuntimeException('Provider non supportato: ' . $provider);
    }

    $model = trim((string)($aiProfile['model'] ?? ''));
    if ($model === '') {
        $model = $provider === 'openrouter' ? 'openai/gpt-4o' : 'gemini-flash-latest';
    }

    $endpoint = trim((string)($aiProfile['web_end_point'] ?? ''));

    if ($endpoint === '') {
        $endpoint = getDefaultEndpointForProvider($provider, $model);
    }

    if (str_contains($endpoint, '{model}')) {
        $endpoint = str_replace('{model}', rawurlencode($model), $endpoint);
    }

    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Endpoint non riconosciuto: URL non valida.');
    }

    if ($provider === 'gemini' && !str_contains(strtolower($endpoint), ':generatecontent')) {
        throw new RuntimeException('Endpoint non riconosciuto: per Gemini serve un endpoint :generateContent.');
    }

    if ($provider === 'openrouter' && !str_contains(strtolower($endpoint), '/chat/completions')) {
        throw new RuntimeException('Endpoint non riconosciuto: per OpenRouter serve un endpoint /chat/completions.');
    }

    $requestPrompt = $finalPrompt . "\n\nReturn only a complete HTML document. Do not use markdown fences.";

    $payload = [];
    $headers = ['Content-Type: application/json'];

    if ($provider === 'openrouter') {
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $requestPrompt,
                ],
            ],
        ];

        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $headers[] = 'HTTP-Referer: ' . buildAbsolutePath('');
        $headers[] = 'X-Title: MDash';
    } else {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $requestPrompt,
                        ],
                    ],
                ],
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
        $apiMessage = trim((string)($decoded['error']['message'] ?? ''));
        $apiStatus = strtoupper(trim((string)($decoded['error']['status'] ?? '')));
        $normalized = strtolower($apiMessage . ' ' . $apiStatus);

        if (
            $httpCode === 401
            || $httpCode === 403
            || str_contains($normalized, 'api key not valid')
            || str_contains($normalized, 'permission denied')
            || str_contains($normalized, 'unauth')
        ) {
            throw new RuntimeException('API non valida o non autorizzata.');
        }

        if (
            $httpCode === 404
            || str_contains($normalized, 'method not found')
            || str_contains($normalized, 'not found')
        ) {
            throw new RuntimeException('Endpoint non riconosciuto o modello non disponibile.');
        }

        if (
            $httpCode === 429
            || str_contains($normalized, 'quota')
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'resource exhausted')
            || (str_contains($normalized, 'token') && str_contains($normalized, 'exceed'))
        ) {
            throw new RuntimeException('Token superati o quota/rate limit raggiunti.');
        }

        $detail = $apiMessage !== '' ? $apiMessage : $response;
        throw new RuntimeException(strtoupper($provider) . ' API error (HTTP ' . $httpCode . '): ' . $detail);
    }

    $text = extractGeneratedText($decoded, $provider);
    if ($text === '') {
        throw new RuntimeException(strtoupper($provider) . ' returned an empty response.');
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
$makeup = null;
    $aiProfile = null;
    $aiProfiles = [];
$resultFilePath = '';
$generatedHtml = '';
$generationSteps = [];
$message = '';
$dashboardId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$selectedAiId = (int)($_POST['id_ai_db'] ?? 0);
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

        if ($dashboard && !empty($dashboard['id_makeup'])) {
            $makeupStmt = $pdo->prepare(
                'SELECT m.id_makeup, m.name, m.prompt_makeup, m.palette, m.id_owner, m.is_private, m.is_hidden, u.username AS owner_username
                 FROM makeup m
                 LEFT JOIN users u ON u.id = m.id_owner
                 WHERE m.id_makeup = ? AND (m.id_owner = ? OR m.is_private = 0) AND m.is_hidden = 0
                 LIMIT 1'
            );
            $makeupStmt->execute([(int)$dashboard['id_makeup'], (int)$user['id']]);
            $makeup = $makeupStmt->fetch();
        }

        $aiProfiles = mdashFetchAccessibleAiProfiles($pdo, (int)$user['id'], false);

        if ($selectedAiId <= 0) {
            $selectedAiId = (int)($dashboard['id_ai_db'] ?? 0);
        }

        if ($selectedAiId > 0) {
            $aiProfile = findAiProfileById($aiProfiles, $selectedAiId);
        }

        if (!$aiProfile && !empty($aiProfiles)) {
            $aiProfile = $aiProfiles[0];
            $selectedAiId = (int)($aiProfile['id'] ?? 0);
        }
    } catch (Throwable $e) {
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
            $selectedAiId = (int)($_POST['id_ai_db'] ?? $selectedAiId);

            $generationSteps[] = 'Preparing dashboard generation request.';
            ensureResultsTable($pdo);
            $generationSteps[] = 'Results table is available.';

            $generationSteps[] = 'Sending prompt to selected AI provider.';

            if ($selectedAiId > 0) {
                $aiProfile = findAiProfileById($aiProfiles, $selectedAiId);
            }

            if (!$aiProfile && !empty($aiProfiles)) {
                $aiProfile = $aiProfiles[0];
                $selectedAiId = (int)($aiProfile['id'] ?? 0);
            }

            if (!$aiProfile) {
                throw new RuntimeException('No active AI profile available. Create one in AI Library or reveal an existing one.');
            }

            if ($aiProfile) {
                $generationSteps[] = 'Using AI profile: ' . (string)($aiProfile['title'] ?? ('#' . (string)($aiProfile['id'] ?? 0)));
                $generationSteps[] = 'Provider/model: ' . (string)($aiProfile['provider'] ?? 'gemini') . ' / ' . (string)($aiProfile['model'] ?? 'gemini-flash-latest');
                $generationSteps[] = 'Endpoint: ' . (string)($aiProfile['web_end_point'] ?? '[default Gemini endpoint]');
            } else {
                $generationSteps[] = 'No AI profile selected. Falling back to environment credentials if available.';
            }

            $generatedHtml = callConfiguredAiGenerateHtml((string)($_POST['master_prompt'] ?? $masterPrompt), $aiProfile ?? []);
            $generationSteps[] = 'AI response received successfully.';
            $generationSteps[] = 'Generated HTML length: ' . strlen($generatedHtml) . ' bytes.';

            $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';
            ensureDirectory($resultsDir);
            $generationSteps[] = 'Verified results root directory.';

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
            $idAiDb = (int)(($aiProfile['id'] ?? 0) ?: 0);
            $idOwner = (int)$user['id'];
            $isPublic = (int)($dashboard['is_public'] ?? 0);
            $isHidden = (int)($dashboard['is_hidden'] ?? 0);
            $resultTags = trim((string)($upload['tags'] ?? ''));

            $insertStmt = $pdo->prepare(
                'INSERT INTO results (id, path, id_template, id_ai_db, ai_title, ai_provider, ai_model, final_prompt, thumbnail_path, `HTML`, id_owner, is_public, is_hidden, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([
                $resultId,
                $relativePath,
                $idTemplate,
                $idAiDb,
                (string)($aiProfile['title'] ?? ''),
                (string)($aiProfile['provider'] ?? ''),
                (string)($aiProfile['model'] ?? ''),
                (string)($_POST['master_prompt'] ?? $masterPrompt),
                $thumbnailPath,
                $generatedHtml,
                $idOwner,
                $isPublic,
                $isHidden,
                $resultTags,
            ]);
            $generationSteps[] = 'Inserted result row with ID ' . $resultId . '.';

            $resultFilePath = $relativePath;
            $message = 'Dashboard generated successfully.';
            $generationSteps[] = 'Generation completed.';
        } catch (Throwable $e) {
            $generationSteps[] = 'Generation failed.';
            $hint = getAiErrorHint($e->getMessage());
            if ($hint !== '') {
                $generationSteps[] = $hint;
            }
            $error = 'Error while generating dashboard: ' . $e->getMessage();
        }
    }
}

if ($dashboard) {
    $promptTitle = trim((string)($dashboard['title'] ?? 'Dashboard without title'));
    $dataSourceId = (int)($dashboard['id_datasource'] ?? 0);
    $dataSourceFilename = (string)($upload['filename'] ?? 'N/A');
    $dataSourceRelativePath = (string)($upload['path'] ?? '');
    $dataSourceAbsoluteUrl = $dataSourceRelativePath !== '' ? buildAbsolutePath($dataSourceRelativePath) : '';
    $dataSourceDescription = (string)($upload['description'] ?? '');
    $dataSourceTags = (string)($upload['tags'] ?? '');
    $dataSourceLongDescription = trim((string)($upload['long_description'] ?? ''));
    $dataSourcePrompt1 = trim((string)($upload['prompt_1'] ?? ''));
    $dataSourcePrompt2 = trim((string)($upload['prompt_2'] ?? ''));

    $sections = [];
    $seenTitles = [];

    addSectionIfNotEmpty($sections, $seenTitles, 'Dashboard title', $promptTitle);

    $dataSourceLines = [];
    if ($dataSourceId > 0) {
        $dataSourceLines[] = 'Data source ID: ' . $dataSourceId;
    }
    if ($dataSourceFilename !== '' && $dataSourceFilename !== 'N/A') {
        $dataSourceLines[] = 'File name: ' . $dataSourceFilename;
    }
    if ($dataSourceRelativePath !== '') {
        $dataSourceLines[] = 'Relative file path: ' . $dataSourceRelativePath;
    }
    if ($dataSourceAbsoluteUrl !== '') {
        $dataSourceLines[] = 'Absolute file URL: ' . $dataSourceAbsoluteUrl;
    }
    if ($dataSourceDescription !== '') {
        $dataSourceLines[] = 'Description: ' . $dataSourceDescription;
    }
    if ($dataSourceLongDescription !== '') {
        $dataSourceLines[] = "Long description:\n" . $dataSourceLongDescription;
    }
    if ($dataSourceTags !== '') {
        $dataSourceLines[] = 'Tags: ' . $dataSourceTags;
    }
    if ($dataSourcePrompt1 !== '') {
        $dataSourceLines[] = "Interpretation prompt 1:\n" . $dataSourcePrompt1;
    }
    if ($dataSourcePrompt2 !== '') {
        $dataSourceLines[] = "Interpretation prompt 2:\n" . $dataSourcePrompt2;
    }
    addSectionIfNotEmpty($sections, $seenTitles, 'Data source', implode("\n", $dataSourceLines));

    $dashboardPromptLines = [];
    $dataFilterPrompt = trim((string)($dashboard['data_filter_prompt'] ?? ''));
    if ($dataFilterPrompt !== '') {
        $dashboardPromptLines[] = "Data filter prompt:\n" . $dataFilterPrompt;
    }
    $dataManipulationPrompt = trim((string)($dashboard['data_manipulation_prompt'] ?? ''));
    if ($dataManipulationPrompt !== '') {
        $dashboardPromptLines[] = "Data manipulation prompt:\n" . $dataManipulationPrompt;
    }
    $dashboardPrompt1 = trim((string)($dashboard['dashboard_prompt_1'] ?? ''));
    if ($dashboardPrompt1 !== '') {
        $dashboardPromptLines[] = "Dashboard prompt 1:\n" . $dashboardPrompt1;
    }
    $dashboardPrompt2 = trim((string)($dashboard['dashboard_prompt_2'] ?? ''));
    if ($dashboardPrompt2 !== '') {
        $dashboardPromptLines[] = "Dashboard prompt 2:\n" . $dashboardPrompt2;
    }
    addSectionIfNotEmpty($sections, $seenTitles, 'Dashboard prompt', implode("\n\n", $dashboardPromptLines));

    $templateLines = [];
    if (!empty($dashboard['id_template'])) {
        $templateLines[] = 'Template ID: ' . (string)$dashboard['id_template'];
    }
    if (!empty($template['title'])) {
        $templateLines[] = 'Template title: ' . (string)$template['title'];
    }
    $templatePrompt = trim((string)($template['prompt'] ?? ''));
    if ($templatePrompt !== '') {
        $templateLines[] = $templatePrompt;
    }
    addSectionIfNotEmpty($sections, $seenTitles, 'Template', implode("\n\n", $templateLines));

    $makeupLines = [];
    if (!empty($dashboard['id_makeup'])) {
        $makeupLines[] = 'Makeup ID: ' . (string)$dashboard['id_makeup'];
    }
    if (!empty($makeup['name'])) {
        $makeupLines[] = 'Makeup name: ' . (string)$makeup['name'];
    }
    $makeupPrompt = trim((string)($makeup['prompt_makeup'] ?? ''));
    if ($makeupPrompt !== '') {
        $makeupLines[] = "Makeup prompt:\n" . $makeupPrompt;
    }
    $makeupPalette = trim((string)($makeup['palette'] ?? ''));
    if ($makeupPalette !== '') {
        $makeupLines[] = "Palette JSON:\n" . $makeupPalette;
    }
    addSectionIfNotEmpty($sections, $seenTitles, 'Makeup', implode("\n\n", $makeupLines));

    if ($error !== '') {
        addSectionIfNotEmpty($sections, $seenTitles, 'Error output', $error);
    }

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
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<link rel="stylesheet" href="assets/app.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/app.css'); ?>">
    <style>
        .generation-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(2, 6, 23, 0.92);
            backdrop-filter: blur(3px);
        }

        .generation-overlay.active {
            display: flex;
        }

        .generation-overlay canvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }

        .generation-overlay-content {
            position: relative;
            z-index: 2;
            color: #e2e8f0;
            max-width: 760px;
            padding: 24px;
        }

        .generation-overlay-title {
            margin: 0;
            font-size: clamp(1.6rem, 3vw, 2.4rem);
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #93c5fd;
        }

        .generation-overlay-subtitle {
            margin: 14px 0 0;
            font-size: clamp(1rem, 2vw, 1.25rem);
            color: #dbeafe;
            min-height: 1.8em;
        }

        .generation-overlay-log-panel {
            margin-top: 14px;
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 12px;
            padding: 14px;
            min-width: min(90vw, 560px);
        }

        .generation-overlay-log {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
        }

        .generation-overlay-log li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #cbd5e1;
            font-size: 0.95rem;
        }

        .generation-overlay-log li::before {
            content: 'â—‹';
            color: #64748b;
            font-weight: 700;
        }

        .generation-overlay-log li.active {
            color: #dbeafe;
        }

        .generation-overlay-log li.active::before {
            content: 'â—';
            color: #38bdf8;
        }

        .generation-overlay-log li.done::before {
            content: 'âœ“';
            color: #22c55e;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

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
                    <textarea id="master_prompt" name="master_prompt" class="master-prompt-area"><?php echo h($masterPrompt); ?></textarea>
                </div>

                <div class="inline-actions">
                    <button type="submit">Refresh preview</button>
                    <button type="button" class="secondary" id="copyPromptBtn">Copy prompt</button>
                    <a href="edit_dashboard.php?id=<?php echo h($dashboardId); ?>" class="btn-secondary">Edit dashboard</a>
                </div>
            </form>
        </div>

        <div class="card">
            <form method="post" id="generateDashboardForm">
                <input type="hidden" name="action" value="generate_dashboard">
                <input type="hidden" name="id" value="<?php echo h($dashboardId); ?>">
                <input type="hidden" name="master_prompt" value="<?php echo h($masterPrompt); ?>">
                <input type="hidden" name="prompt_title" value="<?php echo h($promptTitle); ?>">

                <div class="field">
                    <label for="id_ai_db">AI profile for this generation</label>
                    <select id="id_ai_db" name="id_ai_db" required>
                        <?php if (empty($aiProfiles)): ?>
                            <option value="">No active AI profile available</option>
                        <?php else: ?>
                            <?php foreach ($aiProfiles as $profile): ?>
                                <?php $profileId = (int)($profile['id'] ?? 0); ?>
                                <option value="<?php echo h($profileId); ?>"<?php echo $profileId === (int)$selectedAiId ? ' selected' : ''; ?>>
                                    #<?php echo h($profileId); ?> - <?php echo h((string)($profile['title'] ?? 'AI profile')); ?> [<?php echo h((string)($profile['provider'] ?? 'gemini')); ?> / <?php echo h((string)($profile['model'] ?? 'gemini-flash-latest')); ?>]
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="meta">Only active and accessible AI profiles are listed.</div>
                </div>

                <button type="submit" id="generateDashboardBtn"<?php echo empty($aiProfiles) ? ' disabled' : ''; ?>>Generate dashboard</button>
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
                    <textarea id="generatedHtmlCode" readonly class="generated-html-area"><?php echo h($generatedHtml); ?></textarea>
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

    <div id="generationOverlay" class="generation-overlay" aria-hidden="true">
        <canvas id="generationCanvas"></canvas>
        <div class="generation-overlay-content">
            <h2 class="generation-overlay-title">Dashboard generation in progress</h2>
            <p class="generation-overlay-subtitle">Running generation pipeline, please wait...</p>
            <div class="generation-overlay-log-panel">
                <ul id="generationOverlayLog" class="generation-overlay-log">
                    <li>Preparing dashboard inputs</li>
                    <li>Validating selected AI profile</li>
                    <li>Sending request to AI provider</li>
                    <li>Waiting for AI response</li>
                    <li>Finalizing generated output</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        const overlay = document.getElementById('generationOverlay');
        const canvas = document.getElementById('generationCanvas');
        const logList = document.getElementById('generationOverlayLog');
        const generateForm = document.getElementById('generateDashboardForm');
        const generateBtn = document.getElementById('generateDashboardBtn');

        let logTimer = null;
        let animationHandle = null;
        let submitting = false;

        function startLogProgress() {
            if (!logList) {
                return;
            }

            const items = Array.from(logList.querySelectorAll('li'));
            if (items.length === 0) {
                return;
            }

            items.forEach(function (item) {
                item.classList.remove('active');
                item.classList.remove('done');
            });

            let current = 0;
            items[0].classList.add('active');

            logTimer = window.setInterval(function () {
                if (current < items.length) {
                    items[current].classList.remove('active');
                    items[current].classList.add('done');
                }

                current += 1;
                if (current >= items.length) {
                    current = items.length - 1;
                    items[current].classList.add('active');
                    return;
                }

                items[current].classList.add('active');
            }, 1200);
        }

        function stopLogProgress() {
            if (logTimer) {
                window.clearInterval(logTimer);
                logTimer = null;
            }
        }

        function startCanvasAnimation() {
            if (!canvas) {
                return;
            }

            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }

            const points = [];
            const pointCount = 90;

            function resizeCanvas() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
            }

            function seedPoints() {
                points.length = 0;
                for (let i = 0; i < pointCount; i += 1) {
                    points.push({
                        x: Math.random() * canvas.width,
                        y: Math.random() * canvas.height,
                        vx: (Math.random() - 0.5) * 0.75,
                        vy: (Math.random() - 0.5) * 0.75,
                        r: Math.random() * 2 + 0.8
                    });
                }
            }

            function drawScene(ts) {
                ctx.fillStyle = 'rgba(2, 6, 23, 0.35)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                const cx = canvas.width * 0.5;
                const cy = canvas.height * 0.5;
                const ringRadius = Math.min(canvas.width, canvas.height) * 0.2;

                ctx.save();
                ctx.translate(cx, cy);
                ctx.rotate(ts * 0.00025);
                for (let i = 0; i < 4; i += 1) {
                    ctx.strokeStyle = 'rgba(56, 189, 248, 0.22)';
                    ctx.lineWidth = 1.2;
                    ctx.beginPath();
                    ctx.arc(0, 0, ringRadius + i * 24, 0, Math.PI * 1.6);
                    ctx.stroke();
                    ctx.rotate(Math.PI / 2);
                }
                ctx.restore();

                for (let i = 0; i < points.length; i += 1) {
                    const p = points[i];
                    p.x += p.vx;
                    p.y += p.vy;

                    if (p.x < 0 || p.x > canvas.width) {
                        p.vx *= -1;
                    }
                    if (p.y < 0 || p.y > canvas.height) {
                        p.vy *= -1;
                    }

                    ctx.fillStyle = 'rgba(125, 211, 252, 0.95)';
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fill();

                    for (let j = i + 1; j < points.length; j += 1) {
                        const q = points[j];
                        const dx = p.x - q.x;
                        const dy = p.y - q.y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 120) {
                            ctx.strokeStyle = 'rgba(56, 189, 248,' + (0.22 - dist / 800) + ')';
                            ctx.lineWidth = 1;
                            ctx.beginPath();
                            ctx.moveTo(p.x, p.y);
                            ctx.lineTo(q.x, q.y);
                            ctx.stroke();
                        }
                    }
                }

                animationHandle = window.requestAnimationFrame(drawScene);
            }

            resizeCanvas();
            seedPoints();
            window.addEventListener('resize', function () {
                resizeCanvas();
                seedPoints();
            }, { once: true });
            animationHandle = window.requestAnimationFrame(drawScene);
        }

        function stopCanvasAnimation() {
            if (animationHandle) {
                window.cancelAnimationFrame(animationHandle);
                animationHandle = null;
            }
        }

        function showGenerationOverlay() {
            if (!overlay) {
                return;
            }

            overlay.classList.add('active');
            overlay.setAttribute('aria-hidden', 'false');
            startLogProgress();
            startCanvasAnimation();
        }

        function hideGenerationOverlay() {
            if (!overlay) {
                return;
            }

            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            stopLogProgress();
            stopCanvasAnimation();
        }

        if (generateForm) {
            generateForm.addEventListener('submit', function (event) {
                if (submitting) {
                    event.preventDefault();
                    return;
                }

                submitting = true;
                event.preventDefault();
                if (generateBtn) {
                    generateBtn.disabled = true;
                }
                showGenerationOverlay();

                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(function () {
                        generateForm.submit();
                    });
                });
            });
        }

        window.addEventListener('pageshow', function () {
            submitting = false;
            hideGenerationOverlay();
        });

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

