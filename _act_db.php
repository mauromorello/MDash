<?php
session_start();

header('Content-Type: application/json');

function respond(bool $success, string $message, array $data = []){
    echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data]);
    exit;
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

// minimal auth: check cookie or session
$user = null;
if (!empty($_SESSION['user_id'])) {
    $user = ['id' => $_SESSION['user_id'], 'is_admin' => $_SESSION['is_admin'] ?? 0];
} elseif (!empty($_COOKIE['mdash_user'])) {
    $u = json_decode(urldecode($_COOKIE['mdash_user']), true);
    if (is_array($u) && !empty($u['id'])) {
        $user = ['id' => (int)$u['id'], 'is_admin' => (int)($u['is_admin'] ?? 0)];
    }
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// connect helper
function pdoConnect($host, $user, $pass, $db = null){
    $dsn = "mysql:host={$host}" . ($db ? ";dbname={$db}" : '') . ";charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}

try {
    $pdo = pdoConnect($dbHost, $dbUser, $dbPass, $dbName);
} catch (Exception $e) {
    // allow create_db which doesn't need existing DB
    if (($action ?? '') !== 'create_db') {
        respond(false, 'Impossibile connettersi al database: ' . $e->getMessage());
    }
}

if ($action === 'create_db') {
    // create database and users table + admin user
    $targetDb = $_POST['db_name'] ?? $dbName;
    try {
        $tmp = pdoConnect($dbHost, $dbUser, $dbPass, null);
        $tmp->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`','',$targetDb) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $pdo = pdoConnect($dbHost, $dbUser, $dbPass, $targetDb);

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                is_manager TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                first_login_at DATETIME NULL,
                last_login_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // create admin user mimmoz / zxcasd
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute(['mimmoz']);
        if ((int)$stmt->fetchColumn() === 0) {
            $hash = password_hash('zxcasd', PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, is_enabled, created_at, updated_at) VALUES (?, ?, 1, 1, NOW(), NOW())');
            $ins->execute(['mimmoz', $hash]);
        }

        respond(true, 'Database e tabelle create con successo.', ['db'=>$targetDb]);
    } catch (Exception $e) {
        respond(false, 'Errore durante creazione DB: ' . $e->getMessage());
    }
}

// other actions require authentication
if (!$user) {
    respond(false, 'Non autenticato.', ['code' => 401]);
}

if ($action === 'list_tables') {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = [];
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $r[0];
    }
    respond(true, 'Tabelle trovate.', ['tables'=>$tables]);
}

if ($action === 'get_schema') {
    $table = $_POST['table'] ?? '';
    if (!$table) respond(false, 'Nome tabella mancante.');
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "`");
    $stmt->execute();
    $cols = $stmt->fetchAll();
    respond(true, 'Schema ottenuto.', ['columns'=>$cols]);
}

if ($action === 'get_rows') {
    $table = $_POST['table'] ?? '';
    $limit = (int)($_POST['limit'] ?? 100);
    if (!$table) respond(false, 'Nome tabella mancante.');
    $stmt = $pdo->prepare("SELECT * FROM `" . str_replace('`','',$table) . "` LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    respond(true, 'Record ottenuti.', ['rows'=>$rows]);
}

if ($action === 'update_row') {
    // requires at least pk id
    $table = $_POST['table'] ?? '';
    $data = $_POST['data'] ?? null;
    if (!$table || !$data) respond(false, 'Parametri mancanti.');
    if (is_string($data)) {
        $data = json_decode($data, true);
    }
    if (!is_array($data) || empty($data['id'])) respond(false, 'ID mancante nel record.');

    $id = $data['id'];
    unset($data['id']);
    if (empty($data)) respond(false, 'Nessun campo da aggiornare.');

    $sets = [];
    $params = [];
    foreach ($data as $k=>$v) {
        $sets[] = "`" . str_replace('`','',$k) . "` = ?";
        $params[] = $v;
    }
    $params[] = $id;
    $sql = "UPDATE `" . str_replace('`','',$table) . "` SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($params);
        respond(true, 'Record aggiornato.');
    } catch (Exception $e) {
        respond(false, 'Errore aggiornamento: ' . $e->getMessage());
    }
}

respond(false, 'Azione non supportata.');
