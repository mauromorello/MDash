<?php
session_start();

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
    $error = 'Errore database: ' . $e->getMessage();
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
    } catch (PDOException $e) {
        $error = 'Errore durante il caricamento dei dati: ' . $e->getMessage();
    }
}

if (!$dashboard && $error === '') {
    $error = 'Dashboard non trovata.';
}

if ($dashboard) {
    $promptTitle = trim((string)($dashboard['title'] ?? 'Dashboard senza titolo'));
    $uploadAbsolutePath = $upload && !empty($upload['path']) ? buildAbsolutePath((string)$upload['path']) : 'Datasource non collegata';

    $sections = [];
    $sections[] = normalizeSection('Titolo dashboard', $promptTitle);
    $sections[] = normalizeSection(
        'Base di dati',
        "Usa questa base dati come sorgente primaria per costruire la dashboard.\n" .
        "Percorso completo del file: " . $uploadAbsolutePath . "\n" .
        "File: " . ($upload['filename'] ?? 'N/D') . "\n" .
        "Descrizione breve: " . ($upload['description'] ?? '') . "\n" .
        "Descrizione lunga: " . ($upload['long_description'] ?? '')
    );
    $sections[] = normalizeSection(
        'Descrizione dei dati e gestione',
        "Prompt dati 1:\n" . ($upload['prompt_1'] ?? '') . "\n\n" .
        "Prompt dati 2:\n" . ($upload['prompt_2'] ?? '') . "\n\n" .
        "Tag:\n" . ($upload['tags'] ?? '')
    );
    $sections[] = normalizeSection(
        'Prompt dashboard',
        "Data filter prompt:\n" . ($dashboard['data_filter_prompt'] ?? '') . "\n\n" .
        "Data manipulation prompt:\n" . ($dashboard['data_manipulation_prompt'] ?? '') . "\n\n" .
        "Dashboard prompt 1:\n" . ($dashboard['dashboard_prompt_1'] ?? '') . "\n\n" .
        "Dashboard prompt 2:\n" . ($dashboard['dashboard_prompt_2'] ?? '')
    );
    $sections[] = normalizeSection(
        'Template e makeup futuri',
        "ID template da usare in futuro: " . (string)($dashboard['id_template'] ?? 0) . "\n" .
        "ID makeup da usare in futuro: " . (string)($dashboard['id_makeup'] ?? 0) . "\n" .
        "Questa sezione per ora è solo informativa e servirà per comporre il master prompt finale."
    );

    $masterPrompt = implode("\n", $sections);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_prompt') {
    $promptTitle = trim((string)($_POST['prompt_title'] ?? $promptTitle));
    $masterPrompt = trim((string)($_POST['master_prompt'] ?? $masterPrompt));
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard prompt</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="user-ribbon">
        <div class="brand">MDash</div>
        <div class="info">Utente: <?php echo h($user['username']); ?> | Login: <?php echo h($user['login_time'] ?? date('Y-m-d H:i:s')); ?></div>
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
                <div class="meta">Genera e rifinisci il prompt che verrà poi inviato alla AI.</div>
            </div>
            <a href="dashboards.php">Torna all'elenco dashboard</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php else: ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="preview_prompt">
                    <input type="hidden" name="id" value="<?php echo h($dashboardId); ?>">

                    <div class="field">
                        <label for="prompt_title">Titolo</label>
                        <input type="text" id="prompt_title" name="prompt_title" value="<?php echo h($promptTitle); ?>">
                    </div>

                    <div class="field">
                        <label for="master_prompt">Prompt</label>
                        <textarea id="master_prompt" name="master_prompt" style="min-height: 520px;"><?php echo h($masterPrompt); ?></textarea>
                    </div>

                    <div class="inline-actions">
                        <button type="submit">Aggiorna anteprima</button>
                        <button type="button" class="secondary" id="copyPromptBtn">Copia prompt</button>
                        <a href="edit_dashboard.php?id=<?php echo h($dashboardId); ?>" class="btn-secondary">Modifica dashboard</a>
                    </div>
                </form>
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