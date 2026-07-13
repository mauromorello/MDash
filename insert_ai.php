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

$supportedProviders = mdashSupportedAiProviders();

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdo = null;
$error = '';
$formData = [
    'title' => '',
    'provider' => 'gemini',
    'model' => '',
    'api_key' => '',
    'web_end_point' => '',
    'is_public' => '0',
    'is_hidden' => '0',
];

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

    mdashEnsureAiDbTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($formData as $key => $value) {
            $formData[$key] = trim((string)($_POST[$key] ?? $value));
        }

        $formData['provider'] = strtolower($formData['provider']);

        if ($formData['title'] === '') {
            throw new RuntimeException('AI profile title is required.');
        }
        if ($formData['provider'] === '') {
            throw new RuntimeException('AI provider is required.');
        }
        if (!isset($supportedProviders[$formData['provider']])) {
            throw new RuntimeException('Unsupported AI provider selected.');
        }
        if ($formData['model'] === '') {
            throw new RuntimeException('AI model is required.');
        }
        if ($formData['api_key'] === '') {
            throw new RuntimeException('AI API key is required.');
        }
        if ($formData['web_end_point'] === '') {
            throw new RuntimeException('AI endpoint is required.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ai_db (title, provider, model, api_key, web_end_point, date_creation, id_owner, is_public, is_hidden) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)'
        );
        $stmt->execute([
            $formData['title'],
            $formData['provider'],
            $formData['model'],
            $formData['api_key'],
            $formData['web_end_point'],
            (int)$user['id'],
            (int)($formData['is_public'] === '1' ? 1 : 0),
            (int)($formData['is_hidden'] === '1' ? 1 : 0),
        ]);

        header('Location: ai_db.php?created=1');
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<?php $pageTitle = 'New AI Profile'; include __DIR__ . '/header.php'; ?>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>New AI Profile</h1>
                <div class="meta">Create a reusable AI credential profile for dashboard generation.</div>
            </div>
            <a href="ai_db.php">Back to list</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="post">
                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" maxlength="255" value="<?php echo h($formData['title']); ?>" required>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="provider">Provider</label>
                        <select id="provider" name="provider" required>
                            <?php foreach ($supportedProviders as $providerKey => $providerLabel): ?>
                                <option value="<?php echo h($providerKey); ?>"<?php echo $formData['provider'] === $providerKey ? ' selected' : ''; ?>><?php echo h($providerLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="model">Model / Agent</label>
                        <input type="text" id="model" name="model" maxlength="100" value="<?php echo h($formData['model']); ?>" required>
                        <div class="meta">Examples: <strong>gemini-flash-latest</strong> (Gemini), <strong>openai/gpt-4o</strong> (OpenRouter).</div>
                    </div>
                </div>
                <div class="field">
                    <label for="api_key">API key</label>
                    <textarea id="api_key" name="api_key" rows="3" required><?php echo h($formData['api_key']); ?></textarea>
                </div>
                <div class="field">
                    <label for="web_end_point">Web endpoint</label>
                    <textarea id="web_end_point" name="web_end_point" rows="3" required><?php echo h($formData['web_end_point']); ?></textarea>
                    <div class="meta">Gemini: .../v1beta/models/{model}:generateContent | OpenRouter: https://openrouter.ai/api/v1/chat/completions</div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="is_public">Visibility</label>
                        <select id="is_public" name="is_public">
                            <option value="0"<?php echo $formData['is_public'] === '0' ? ' selected' : ''; ?>>Private</option>
                            <option value="1"<?php echo $formData['is_public'] === '1' ? ' selected' : ''; ?>>Public</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="is_hidden">Status</label>
                        <select id="is_hidden" name="is_hidden">
                            <option value="0"<?php echo $formData['is_hidden'] === '0' ? ' selected' : ''; ?>>Visible</option>
                            <option value="1"<?php echo $formData['is_hidden'] === '1' ? ' selected' : ''; ?>>Hidden</option>
                        </select>
                    </div>
                </div>
                <button type="submit">Save AI profile</button>
            </form>
        </div>
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
    </script>
</body>
</html>


