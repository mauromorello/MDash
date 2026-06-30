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
$uploads = [];

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
            description TEXT NOT NULL,
            tags VARCHAR(255) NOT NULL,
            long_description TEXT NOT NULL,
            prompt_1 TEXT NOT NULL,
            prompt_2 TEXT NOT NULL,
            id_owner INT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            AI_1 TEXT NOT NULL,
            AI_2 TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt = $pdo->prepare(
        'SELECT u.*, usr.username AS owner_username FROM uploads u LEFT JOIN users usr ON usr.id = u.id_owner WHERE (u.id_owner = ? OR u.is_public = 1) ORDER BY u.id DESC'
    );
    $stmt->execute([(int)$user['id']]);
    $uploads = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elenco basi dati</title>
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
                <h1>Elenco basi dati</h1>
                <div class="meta">Mostra i tuoi file e quelli pubblici di altri utenti.</div>
            </div>
            <a href="main.php">Torna alla home</a>
        </div>

        <?php if (!empty($error)): ?>
            <p class="message error">Errore di accesso al database: <?php echo h($error); ?></p>
        <?php elseif (empty($uploads)): ?>
            <p class="empty">Non hai ancora caricato file.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>File</th>
                            <th>Proprietario</th>
                            <th>Descrizione</th>
                            <th>Tag</th>
                            <th>Visibilità</th>
                            <th>Percorso</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uploads as $row): ?>
                            <tr>
                                <td><?php echo h($row['id']); ?></td>
                                <td>
                                    <?php echo h($row['filename']); ?>
                                    <?php if (!empty($row['path']) && is_file(__DIR__ . DIRECTORY_SEPARATOR . $row['path'])): ?>
                                        <div><a href="<?php echo h($row['path']); ?>" target="_blank">Apri file</a></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$row['id_owner'] === (int)$user['id']): ?>
                                        <span class="pill">Tu</span>
                                    <?php else: ?>
                                        <?php echo h($row['owner_username'] ?: 'Utente #' . $row['id_owner']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($row['description'] ?: '-'); ?></td>
                                <td><?php echo h($row['tags'] ?: '-'); ?></td>
                                <td><span class="pill"><?php echo ((int)$row['is_public'] === 1) ? 'Pubblico' : 'Privato'; ?></span></td>
                                <td><?php echo h($row['path'] ?: '-'); ?></td>
                                <td>
                                    <?php if ((int)$row['id_owner'] === (int)$user['id']): ?>
                                        <a href="edit_upload.php?id=<?php echo h($row['id']); ?>">Modifica</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
