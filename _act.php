<?php
session_start();

header('Content-Type: application/json');

function respond(bool $success, string $message, array $data = []): void {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

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
    respond(false, 'Impossibile connettersi al database. Verifica i parametri di configurazione.', [
        'error' => $e->getMessage(),
    ]);
}

$pdo->exec(
    "
    CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'user',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        first_login_at DATETIME NULL DEFAULT NULL,
        last_login_at DATETIME NULL DEFAULT NULL,
        last_login_ip VARCHAR(45) NULL DEFAULT NULL,
        last_login_agent TEXT NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    "
);

try {
    $columnsStmt = $pdo->query('SHOW COLUMNS FROM users');
    $columns = [];
    foreach ($columnsStmt->fetchAll() as $col) {
        $columns[$col['Field']] = true;
    }

    if (!isset($columns['is_enabled'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1');
        if (isset($columns['is_active'])) {
            $pdo->exec('UPDATE users SET is_enabled = is_active');
        }
    }

    if (!isset($columns['is_admin'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0');
    }

    if (!isset($columns['role'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
    }

    $pdo->exec("UPDATE users SET role = 'admin' WHERE is_admin = 1 AND (role IS NULL OR role = '' OR role = 'user')");
} catch (PDOException $e) {
    // Non bloccare il login in caso di schema lock temporaneo.
}

$countStmt = $pdo->query('SELECT COUNT(*) FROM users');
if ((int) $countStmt->fetchColumn() === 0) {
    $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
    $insertStmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())'
    );
    $insertStmt->execute(['admin', $defaultHash, 'admin']);
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'login');

if ($action === 'logout') {
    $_SESSION = [];
    setcookie('mdash_user', '', time() - 3600, '/');
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    respond(true, 'Logout effettuato.');
}

if ($action === 'login') {
    $username = trim((string) ($_POST['user'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        respond(false, 'Inserisci username e password.');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(false, 'Credenziali non valide.');
    }

    $isEnabled = 1;
    if (array_key_exists('is_enabled', $user)) {
        $isEnabled = (int)$user['is_enabled'];
    } elseif (array_key_exists('is_active', $user)) {
        $isEnabled = (int)$user['is_active'];
    }

    if ($isEnabled !== 1) {
        respond(false, 'L’utente è disattivato.');
    }

    $isAdmin = 0;
    if (array_key_exists('is_admin', $user)) {
        $isAdmin = (int)$user['is_admin'];
    }
    $role = (string)($user['role'] ?? ($isAdmin === 1 ? 'admin' : 'user'));

    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);

    $updateStmt = $pdo->prepare(
        'UPDATE users SET first_login_at = COALESCE(first_login_at, ?), last_login_at = ?, last_login_ip = ?, last_login_agent = ?, updated_at = ? WHERE id = ?'
    );
    $updateStmt->execute([$now, $now, $ip, $agent, $now, $user['id']]);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $role;
    $_SESSION['is_admin'] = $isAdmin;
    $_SESSION['is_enabled'] = $isEnabled;
    $_SESSION['login_time'] = $now;

    $cookiePayload = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'is_admin' => $isAdmin,
        'is_enabled' => $isEnabled,
        'login_time' => $now,
    ];
    setcookie('mdash_user', urlencode(json_encode($cookiePayload)), [
        'expires' => time() + (86400 * 7),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

    respond(true, 'Login effettuato con successo.', [
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $role,
            'is_admin' => $isAdmin,
            'is_enabled' => $isEnabled,
        ],
    ]);
}

respond(false, 'Azione non supportata.');
