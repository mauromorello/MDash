<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

    if ((int) ($user['is_enabled'] ?? $user['is_active'] ?? 1) !== 1) {
        respond(false, 'L’utente è disattivato.');
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);

    $columns = [];
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM `users`");
        while ($col = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $col['Field'];
        }
    } catch (Exception $e) {
        $columns = [];
    }

    $updatePieces = [
        'first_login_at = COALESCE(first_login_at, ?)',
        'last_login_at = ?',
        'updated_at = ?',
    ];
    $params = [$now, $now, $now];

    if (in_array('last_login_ip', $columns, true)) {
        $updatePieces[] = 'last_login_ip = ?';
        $params[] = $ip;
    }

    if (in_array('last_login_agent', $columns, true)) {
        $updatePieces[] = 'last_login_agent = ?';
        $params[] = $agent;
    }

    $params[] = $user['id'];
    $updateStmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $updatePieces) . ' WHERE id = ?');
    $updateStmt->execute($params);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = (int) ($user['is_admin'] ?? 0);
    $_SESSION['login_time'] = $now;

    // return user info and is_admin flag so client can set cookie and redirect
    respond(true, 'Login effettuato con successo.', [
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'is_admin' => (int) ($user['is_admin'] ?? 0),
            'login_time' => $now,
        ],
    ]);
}

if ($action === 'logout') {
    session_destroy();
    setcookie('mdash_user', '', time() - 3600, '/');
    respond(true, 'Logout effettuato.');
}

respond(false, 'Azione non supportata.');
