<?php
session_start();

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
$upload = null;
$message = '';

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
    $message = 'Errore di connessione al database: ' . $e->getMessage();
}

if ($pdo) {
    $uploadId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_upload') {
        if ($uploadId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1'
            );
            $stmt->execute([$uploadId, (int)$user['id']]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare(
                    'UPDATE uploads SET description = ?, tags = ?, long_description = ?, prompt_1 = ?, prompt_2 = ?, is_public = ? WHERE id = ? AND id_owner = ?'
                );
                $stmt->execute([
                    trim((string)($_POST['description'] ?? '')),
                    trim((string)($_POST['tags'] ?? '')),
                    trim((string)($_POST['long_description'] ?? '')),
                    trim((string)($_POST['prompt_1'] ?? '')),
                    trim((string)($_POST['prompt_2'] ?? '')),
                    (int)($_POST['is_public'] ?? 0),
                    $uploadId,
                    (int)$user['id'],
                ]);
                $message = 'Upload aggiornato correttamente.';
            } else {
                $message = 'Non sei autorizzato a modificare questo upload.';
            }
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM uploads WHERE id = ? AND id_owner = ? LIMIT 1');
    $stmt->execute([$uploadId, (int)$user['id']]);
    $upload = $stmt->fetch();

    if (!$upload) {
        $message = 'Upload non trovato o non accessibile.';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica upload</title>
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
                <h1>Modifica upload</h1>
                <div class="meta">Aggiorna i dati del file caricato.</div>
            </div>
            <a href="database_list.php">Torna all'elenco</a>
        </div>

        <?php if ($message): ?>
            <div class="message<?php echo strpos($message, 'Errore') !== false || strpos($message, 'Non sei') !== false ? ' error' : ''; ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($upload): ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="action" value="save_upload">
                    <input type="hidden" name="id" value="<?php echo h($upload['id']); ?>">

                    <div class="field">
                        <label for="filename">Nome file</label>
                        <input type="text" id="filename" value="<?php echo h($upload['filename']); ?>" disabled>
                        <div class="meta">Il nome del file non è modificabile da questa schermata.</div>
                    </div>

                    <div class="field">
                        <label for="description">Descrizione breve</label>
                        <input type="text" id="description" name="description" value="<?php echo h($upload['description'] ?? ''); ?>">
                    </div>

                    <div class="field">
                        <label for="tags">Tag</label>
                        <input type="text" id="tags" name="tags" value="<?php echo h($upload['tags'] ?? ''); ?>">
                    </div>

                    <div class="field">
                        <label for="long_description">Descrizione lunga</label>
                        <textarea id="long_description" name="long_description"><?php echo h($upload['long_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="prompt_1">Prompt 1</label>
                            <textarea id="prompt_1" name="prompt_1"><?php echo h($upload['prompt_1'] ?? ''); ?></textarea>
                        </div>
                        <div class="field">
                            <label for="prompt_2">Prompt 2</label>
                            <textarea id="prompt_2" name="prompt_2"><?php echo h($upload['prompt_2'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="field">
                        <label for="is_public">Visibilità</label>
                        <select id="is_public" name="is_public">
                            <option value="0"<?php echo ((int)$upload['is_public'] === 0) ? ' selected' : ''; ?>>Privato</option>
                            <option value="1"<?php echo ((int)$upload['is_public'] === 1) ? ' selected' : ''; ?>>Pubblico</option>
                        </select>
                    </div>

                    <button type="submit">Salva modifiche</button>
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
    </script>
</body>
</html>
