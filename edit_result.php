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
        throw new RuntimeException('Selected AI profile has no API key configured.');
    }

    $provider = strtolower(trim((string)($aiProfile['provider'] ?? 'gemini')));
    $supportedProviders = mdashSupportedAiProviders();
    if (!isset($supportedProviders[$provider])) {
        throw new RuntimeException('Unsupported AI provider: ' . $provider);
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
        throw new RuntimeException('Invalid AI endpoint URL.');
    }

    $requestPrompt = $finalPrompt . "\n\nReturn only the final complete HTML document. Do not add markdown fences.";
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
        $headers[] = 'HTTP-Referer: ' . ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/');
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
        CURLOPT_CONNECTTIMEOUT => 30,
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
        throw new RuntimeException('AI API error (HTTP ' . $httpCode . '): ' . ($apiMessage !== '' ? $apiMessage : $response));
    }

    $text = extractGeneratedText($decoded, $provider);
    if ($text === '') {
        throw new RuntimeException('AI returned an empty response.');
    }

    return preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $text) ?? $text;
}

function buildOldBackupPath(string $diskPath): string {
    $dir = dirname($diskPath);
    $filename = pathinfo($diskPath, PATHINFO_FILENAME);
    $extension = pathinfo($diskPath, PATHINFO_EXTENSION);
    $backupName = $filename . '_old' . ($extension !== '' ? '.' . $extension : '');
    return $dir . DIRECTORY_SEPARATOR . $backupName;
}

function saveHtmlWithBackup(string $diskPath, string $htmlCode): void {
    $dirPath = dirname($diskPath);
    if (!is_dir($dirPath) && !mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
        throw new RuntimeException('Unable to create output directory for HTML file.');
    }

    if (is_file($diskPath)) {
        $backupPath = buildOldBackupPath($diskPath);
        if (is_file($backupPath) && !@unlink($backupPath)) {
            throw new RuntimeException('Unable to overwrite existing backup file: ' . basename($backupPath));
        }
        if (!@rename($diskPath, $backupPath)) {
            throw new RuntimeException('Unable to create backup copy before save.');
        }
    }

    if (file_put_contents($diskPath, $htmlCode) === false) {
        throw new RuntimeException('Unable to write HTML file to disk.');
    }
}

function getDefaultFixPromptTemplate(): string {
    return "I am pasting below the current dashboard HTML code.\n"
        . "Modify it strictly according to the task I provide.\n\n"
        . "Rules:\n"
        . "1) Apply only the requested changes.\n"
        . "2) Do not modify anything else (structure, IDs, class names, JS behavior, assets, formatting style) unless explicitly required by the task.\n"
        . "3) Return only the final complete HTML code.\n"
        . "4) Do not include explanations, comments, markdown fences, or extra text.\n\n"
        . "Task:\n"
        . "- Replace this line with your instructions.\n\n"
        . "Current code:\n"
        . "{{CURRENT_HTML}}";
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$error = '';
$message = '';
$result = null;
$aiProfiles = [];
$defaultFixPrompt = getDefaultFixPromptTemplate();
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
    $aiProfiles = mdashFetchAccessibleAiProfiles($pdo, (int)$user['id']);

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

    if (!$error && $result && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['save_result', 'ai_fix_result'], true)) {
        $action = (string)($_POST['action'] ?? '');
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
        $aiFixPrompt = (string)($_POST['ai_fix_prompt'] ?? $defaultFixPrompt);
        $selectedAiFixId = (int)($_POST['ai_fix_id_ai_db'] ?? 0);

        if ($path === '') {
            throw new RuntimeException('Path cannot be empty.');
        }
        if (trim($htmlCode) === '') {
            throw new RuntimeException('HTML code cannot be empty.');
        }

        if ($action === 'ai_fix_result') {
            if ($selectedAiFixId <= 0) {
                throw new RuntimeException('Select an AI profile to fix the HTML code.');
            }

            $aiProfile = mdashFetchAccessibleAiProfile($pdo, $selectedAiFixId, (int)$user['id']);
            if (!$aiProfile) {
                throw new RuntimeException('Selected AI profile not found or not accessible.');
            }

            $promptForAi = str_contains($aiFixPrompt, '{{CURRENT_HTML}}')
                ? str_replace('{{CURRENT_HTML}}', $htmlCode, $aiFixPrompt)
                : ($aiFixPrompt . "\n\nCurrent code:\n" . $htmlCode);

            $htmlCode = callConfiguredAiGenerateHtml($promptForAi, $aiProfile);
            if (trim($htmlCode) === '') {
                throw new RuntimeException('AI returned empty HTML code.');
            }

            $idAiDb = (int)($aiProfile['id'] ?? 0);
            $aiTitle = trim((string)($aiProfile['title'] ?? ''));
            $aiProvider = trim((string)($aiProfile['provider'] ?? ''));
            $aiModel = trim((string)($aiProfile['model'] ?? ''));
        }

        $diskPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        saveHtmlWithBackup($diskPath, $htmlCode);

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

        $redirectParams = ['updated=1'];
        if ($action === 'ai_fix_result') {
            $redirectParams[] = 'ai_fixed=1';
        }
        header('Location: edit_result.php?id=' . (int)$resultId . '&' . implode('&', $redirectParams));
        exit;
    }

    if (!$error && !empty($_GET['updated'])) {
        $message = 'Result updated successfully.';
        if (!empty($_GET['ai_fixed'])) {
            $message = 'AI fix applied and saved successfully. Previous file renamed with _old suffix.';
        }
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
<?php
$pageTitle = 'Edit Result';
$pageHeadExtra = [
    '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">',
    '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css">',
    '<style>.readonly-field-vis{background:#f3f4f6!important;color:#4b5563!important;border:1px dashed #9ca3af!important;cursor:not-allowed}.readonly-label{display:flex;align-items:center;gap:.4rem}.readonly-badge{font-size:.72rem;line-height:1;padding:.18rem .42rem;border-radius:999px;background:#e5e7eb;color:#374151;text-transform:uppercase;letter-spacing:.03em}.CodeMirror{height:560px;border:1px solid #374151;border-radius:10px;font-size:13px}.ai-fix-panel{margin-top:14px;padding:14px;border:1px solid #d1d5db;border-radius:10px;background:#f8fafc}.ai-wait-overlay{position:fixed;inset:0;background:rgba(15,23,42,.7);display:flex;align-items:center;justify-content:center;z-index:9999;padding:20px}.ai-wait-overlay.hidden{display:none}.ai-wait-card{width:min(560px,100%);background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;padding:20px;box-shadow:0 20px 40px rgba(2,6,23,.25)}.ai-wait-title{margin:0 0 8px;font-size:1.1rem;font-weight:700;color:#0f172a}.ai-wait-meta{margin:0 0 14px;color:#334155}.ai-wait-timer{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:1.25rem;font-weight:700;color:#111827;background:#f3f4f6;border:1px solid #d1d5db;border-radius:10px;padding:8px 12px;display:inline-block}</style>',
    '<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>',
    '<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>',
    '<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>',
    '<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>',
    '<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>',
];
include __DIR__ . '/header.php';
?>
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
                    <input type="hidden" name="id" value="<?php echo h((int)$result['id']); ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label class="readonly-label">ID <span class="readonly-badge">read only</span></label>
                            <input type="text" value="<?php echo h((int)$result['id']); ?>" readonly class="readonly-field-vis">
                        </div>
                        <div class="field">
                            <label class="readonly-label">Owner ID <span class="readonly-badge">read only</span></label>
                            <input type="text" value="<?php echo h((int)$result['id_owner']); ?>" readonly class="readonly-field-vis">
                        </div>
                    </div>

                    <div class="field">
                        <label for="path" class="readonly-label">Path <span class="readonly-badge">read only</span></label>
                        <input type="text" id="path" name="path" value="<?php echo h($result['path'] ?? ''); ?>" required readonly class="readonly-field-vis">
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="id_template" class="readonly-label">Template ID <span class="readonly-badge">read only</span></label>
                            <input type="text" id="id_template" name="id_template" value="<?php echo h((int)($result['id_template'] ?? 0)); ?>" readonly class="readonly-field-vis">
                        </div>
                        <div class="field">
                            <label for="id_ai_db" class="readonly-label">AI DB ID <span class="readonly-badge">read only</span></label>
                            <input type="text" id="id_ai_db" name="id_ai_db" value="<?php echo h((int)($result['id_ai_db'] ?? 0)); ?>" readonly class="readonly-field-vis">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="ai_title" class="readonly-label">AI Title <span class="readonly-badge">read only</span></label>
                            <input type="text" id="ai_title" name="ai_title" value="<?php echo h($result['ai_title'] ?? ''); ?>" readonly class="readonly-field-vis">
                        </div>
                        <div class="field">
                            <label for="ai_provider" class="readonly-label">AI Provider <span class="readonly-badge">read only</span></label>
                            <input type="text" id="ai_provider" name="ai_provider" value="<?php echo h($result['ai_provider'] ?? ''); ?>" readonly class="readonly-field-vis">
                        </div>
                    </div>

                    <div class="field">
                        <label for="ai_model" class="readonly-label">AI Model <span class="readonly-badge">read only</span></label>
                        <input type="text" id="ai_model" name="ai_model" value="<?php echo h($result['ai_model'] ?? ''); ?>" readonly class="readonly-field-vis">
                    </div>

                    <div class="field">
                        <label for="thumbnail_path" class="readonly-label">Thumbnail Path <span class="readonly-badge">read only</span></label>
                        <input type="text" id="thumbnail_path" name="thumbnail_path" value="<?php echo h($result['thumbnail_path'] ?? ''); ?>" readonly class="readonly-field-vis">
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
                            <label for="n_views" class="readonly-label">Views <span class="readonly-badge">read only</span></label>
                            <input type="text" id="n_views" name="n_views" value="<?php echo h((int)($result['n_views'] ?? 0)); ?>" readonly class="readonly-field-vis">
                        </div>
                        <div class="field">
                            <label for="n_download" class="readonly-label">Downloads <span class="readonly-badge">read only</span></label>
                            <input type="text" id="n_download" name="n_download" value="<?php echo h((int)($result['n_download'] ?? 0)); ?>" readonly class="readonly-field-vis">
                        </div>
                    </div>

                    <div class="field">
                        <label for="n_clone" class="readonly-label">Clones <span class="readonly-badge">read only</span></label>
                        <input type="text" id="n_clone" name="n_clone" value="<?php echo h((int)($result['n_clone'] ?? 0)); ?>" readonly class="readonly-field-vis">
                    </div>

                    <div class="field">
                        <label for="html_code">HTML Markup</label>
                        <textarea id="html_code" name="html_code" class="generated-html-area" required><?php echo h($result['HTML'] ?? ''); ?></textarea>
                    </div>

                    <div class="ai-fix-panel">
                        <div class="field">
                            <label for="ai_fix_prompt">Prompt for AI code correction</label>
                            <textarea id="ai_fix_prompt" name="ai_fix_prompt" class="master-prompt-area"><?php echo h((string)($_POST['ai_fix_prompt'] ?? $defaultFixPrompt)); ?></textarea>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="ai_fix_id_ai_db">AI profile</label>
                                <select id="ai_fix_id_ai_db" name="ai_fix_id_ai_db">
                                    <option value="0">Select AI profile</option>
                                    <?php foreach ($aiProfiles as $profile): ?>
                                        <?php
                                            $profileId = (int)($profile['id'] ?? 0);
                                            $selectedProfileId = (int)($_POST['ai_fix_id_ai_db'] ?? ($result['id_ai_db'] ?? 0));
                                            $selectedAttr = $selectedProfileId === $profileId ? ' selected' : '';
                                            $profileLabel = trim((string)($profile['title'] ?? ''));
                                            $providerLabel = trim((string)($profile['provider'] ?? ''));
                                            $modelLabel = trim((string)($profile['model'] ?? ''));
                                        ?>
                                        <option value="<?php echo h($profileId); ?>"<?php echo $selectedAttr; ?>>
                                            <?php echo h($profileLabel !== '' ? $profileLabel : ('AI #' . $profileId)); ?>
                                            <?php echo h(' [' . $providerLabel . ' | ' . $modelLabel . ']'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($aiProfiles)): ?>
                                    <div class="meta">No AI profile available. Create one in ai_db.php to enable AI code correction.</div>
                                <?php endif; ?>
                            </div>
                            <div class="field">
                                <label>Apply AI fix</label>
                                <button type="submit" name="action" value="ai_fix_result"<?php echo empty($aiProfiles) ? ' disabled' : ''; ?>>Apply AI fix, save file, create _old backup</button>
                            </div>
                        </div>
                    </div>

                    <div class="inline-actions">
                        <button type="submit" name="action" value="save_result">Save result</button>
                        <a href="results.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div id="aiWaitOverlay" class="ai-wait-overlay hidden" role="status" aria-live="polite" aria-label="AI fix in progress">
        <div class="ai-wait-card">
            <h2 class="ai-wait-title">Applying AI fix</h2>
            <p class="ai-wait-meta">Please wait while the AI is generating the updated code.</p>
            <p class="ai-wait-meta">Elapsed time: <span id="aiWaitTimer" class="ai-wait-timer">0s</span></p>
            <p class="ai-wait-meta">This request may take more or less time depending on the AI model being used.</p>
        </div>
    </div>

    <script>
        const htmlCodeTextarea = document.getElementById('html_code');
        if (htmlCodeTextarea && window.CodeMirror) {
            const codeEditor = CodeMirror.fromTextArea(htmlCodeTextarea, {
                mode: 'htmlmixed',
                theme: 'material-darker',
                lineNumbers: true,
                lineWrapping: true,
                tabSize: 2,
                indentUnit: 2,
                matchBrackets: true,
                autoCloseTags: true,
            });

            const editForm = htmlCodeTextarea.closest('form');
            if (editForm) {
                editForm.addEventListener('submit', function () {
                    htmlCodeTextarea.value = codeEditor.getValue();
                });
            }
        }

        const aiWaitOverlay = document.getElementById('aiWaitOverlay');
        const aiWaitTimer = document.getElementById('aiWaitTimer');
        const aiFixButton = document.querySelector('button[name="action"][value="ai_fix_result"]');
        let aiWaitInterval = null;

        function formatElapsed(seconds) {
            if (seconds < 60) {
                return seconds + 's';
            }
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return mins + 'm ' + secs + 's';
        }

        if (aiFixButton && aiWaitOverlay && aiWaitTimer) {
            aiFixButton.addEventListener('click', function () {
                const form = aiFixButton.closest('form');
                if (!form) {
                    return;
                }

                aiWaitOverlay.classList.remove('hidden');
                let elapsed = 0;
                aiWaitTimer.textContent = formatElapsed(elapsed);

                if (aiWaitInterval) {
                    clearInterval(aiWaitInterval);
                }
                aiWaitInterval = setInterval(function () {
                    elapsed += 1;
                    aiWaitTimer.textContent = formatElapsed(elapsed);
                }, 1000);

                // Keep overlay visible until server response/redirect.
                form.addEventListener('submit', function () {
                    if (aiFixButton.disabled) {
                        return;
                    }
                    aiFixButton.disabled = true;
                }, { once: true });
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
