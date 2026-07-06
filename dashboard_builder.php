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
            'is_admin' => (int)($_SESSION['is_admin'] ?? 0),
        ];
    }

    if (!empty($_COOKIE['mdash_user'])) {
        $user = json_decode(urldecode($_COOKIE['mdash_user']), true);
        if (is_array($user) && !empty($user['id'])) {
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'] ?? 'utente',
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

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdo = null;
$error = '';
$message = '';
$uploads = [];
$formData = [
    'title' => '',
    'id_datasource' => '',
    'id_makeup' => '0',
    'data_filter_prompt' => '',
    'data_manipulation_prompt' => '',
    'dashboard_prompt_1' => '',
    'dashboard_prompt_2' => '',
    'id_template' => '0',
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dashboards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_datasource INT DEFAULT NULL,
            id_makeup INT NOT NULL DEFAULT 0,
            data_filter_prompt TEXT NOT NULL,
            data_manipulation_prompt TEXT NOT NULL,
            dashboard_prompt_1 TEXT NOT NULL,
            dashboard_prompt_2 TEXT NOT NULL,
            id_template INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $tableExists = $pdo->query("SHOW TABLES LIKE 'dashboards'")->fetchColumn();
    if ($tableExists) {
        $idColumn = $pdo->query("SHOW COLUMNS FROM dashboards LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if ($idColumn && stripos((string)$idColumn['Extra'], 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE dashboards MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }
    }

    $uploadsStmt = $pdo->query("SELECT id, filename, description FROM uploads ORDER BY id DESC");
    $uploads = $uploadsStmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_dashboard') {
    foreach ($formData as $key => $value) {
        $formData[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if (!$pdo) {
        $error = 'Errore database: ' . $error;
    } elseif ($formData['title'] === '') {
        $error = 'Il titolo della dashboard è obbligatorio.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO dashboards (title, id_datasource, id_makeup, data_filter_prompt, data_manipulation_prompt, dashboard_prompt_1, dashboard_prompt_2, id_template) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $formData['title'],
                $formData['id_datasource'] !== '' ? (int)$formData['id_datasource'] : null,
                (int)($formData['id_makeup'] !== '' ? $formData['id_makeup'] : 0),
                $formData['data_filter_prompt'],
                $formData['data_manipulation_prompt'],
                $formData['dashboard_prompt_1'],
                $formData['dashboard_prompt_2'],
                (int)($formData['id_template'] !== '' ? $formData['id_template'] : 0),
            ]);
            header('Location: dashboards.php?created=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Errore durante il salvataggio della dashboard: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard builder</title>
    <link rel="stylesheet" href="assets/app.css">
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

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Dashboard builder</h1>
                <div class="meta">Crea una nuova dashboard partendo da una base dati caricata.</div>
            </div>
            <a href="dashboards.php">Vai all'elenco dashboard</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php elseif (!empty($_GET['updated'])): ?>
            <div class="message">Dashboard aggiornata correttamente.</div>
        <?php endif; ?>

        <div class="card">
            <form method="post">
                <input type="hidden" name="action" value="create_dashboard">

                <div class="field">
                    <label for="title">Titolo dashboard</label>
                    <input type="text" id="title" name="title" value="<?php echo h($formData['title']); ?>" required>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="id_datasource">Base dati</label>
                        <select id="id_datasource" name="id_datasource">
                            <option value="">Nessuna</option>
                            <?php foreach ($uploads as $upload): ?>
                                <option value="<?php echo h($upload['id']); ?>"<?php echo ((string)$formData['id_datasource'] === (string)$upload['id']) ? ' selected' : ''; ?>>
                                    #<?php echo h($upload['id']); ?> - <?php echo h($upload['filename']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="id_makeup">ID makeup</label>
                        <input type="text" id="id_makeup" name="id_makeup" value="<?php echo h($formData['id_makeup']); ?>">
                    </div>
                </div>

                <div class="field">
                    <label for="data_filter_prompt">Data filter prompt</label>
                    <textarea id="data_filter_prompt" name="data_filter_prompt"><?php echo h($formData['data_filter_prompt']); ?></textarea>
                </div>

                <div class="field">
                    <label for="data_manipulation_prompt">Data manipulation prompt</label>
                    <textarea id="data_manipulation_prompt" name="data_manipulation_prompt"><?php echo h($formData['data_manipulation_prompt']); ?></textarea>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="dashboard_prompt_1">Dashboard prompt 1</label>
                        <textarea id="dashboard_prompt_1" name="dashboard_prompt_1"><?php echo h($formData['dashboard_prompt_1']); ?></textarea>
                    </div>
                    <div class="field">
                        <label for="dashboard_prompt_2">Dashboard prompt 2</label>
                        <textarea id="dashboard_prompt_2" name="dashboard_prompt_2"><?php echo h($formData['dashboard_prompt_2']); ?></textarea>
                    </div>
                </div>

                <div class="field">
                    <label for="id_template">ID template</label>
                    <input type="text" id="id_template" name="id_template" value="<?php echo h($formData['id_template']); ?>">
                </div>

                <div class="inline-actions">
                    <button type="submit">Salva dashboard</button>
                    <a href="dashboards.php" class="btn-secondary">Annulla</a>
                </div>
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
