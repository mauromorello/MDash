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
$dbPass = getenv('DB_PASS') ?: '';

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

$countStmt = $pdo->query('SELECT COUNT(*) FROM users');
if ((int) $countStmt->fetchColumn() === 0) {
    $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
    $insertStmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())'
    );
    $insertStmt->execute(['admin', $defaultHash, 'admin']);
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'login');

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

    if ((int) $user['is_active'] !== 1) {
        respond(false, 'L’utente è disattivato.');
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);

    $updateStmt = $pdo->prepare(
        'UPDATE users SET first_login_at = COALESCE(first_login_at, ?), last_login_at = ?, last_login_ip = ?, last_login_agent = ?, updated_at = ? WHERE id = ?'
    );
    $updateStmt->execute([$now, $now, $ip, $agent, $now, $user['id']]);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    respond(true, 'Login effettuato con successo.', [
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ],
    ]);
}

respond(false, 'Azione non supportata.');
