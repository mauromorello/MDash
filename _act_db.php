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

function ensureTemplatesTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            prompt MEDIUMTEXT NOT NULL,
            `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_owner INT NOT NULL,
            INDEX idx_templates_owner (id_owner),
            INDEX idx_templates_date (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $tableExists = $pdo->query("SHOW TABLES LIKE 'templates'")->fetchColumn();
    if ($tableExists) {
        $idColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if ($idColumn && stripos((string)$idColumn['Extra'], 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE templates MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }
    }
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
                last_login_at DATETIME NULL,
                last_login_ip VARCHAR(45) NULL,
                last_login_agent TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $colCheck = $pdo->prepare("SHOW COLUMNS FROM `users` LIKE ?");
        $colCheck->execute(['last_login_ip']);
        if (!$colCheck->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN last_login_ip VARCHAR(45) NULL");
        }
        $colCheck->execute(['last_login_agent']);
        if (!$colCheck->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN last_login_agent TEXT NULL");
        }

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

$adminOnlyActions = ['list_users','create_user','update_user','delete_user'];
if (in_array($action, $adminOnlyActions, true) && empty($user['is_admin'])) {
    respond(false, 'Non autorizzato.', ['code' => 403]);
}

if ($action === 'list_tables') {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = [];
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $r[0];
    }
    respond(true, 'Tabelle trovate.', ['tables'=>$tables]);
}

if ($action === 'list_templates') {
    try {
        ensureTemplatesTable($pdo);
        if (!empty($user['is_admin'])) {
            $stmt = $pdo->query('SELECT id, title, prompt, `date`, id_owner FROM templates ORDER BY id DESC');
            $rows = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare('SELECT id, title, prompt, `date`, id_owner FROM templates WHERE id_owner = ? ORDER BY id DESC');
            $stmt->execute([(int)$user['id']]);
            $rows = $stmt->fetchAll();
        }
        respond(true, 'Template trovati.', ['templates' => $rows]);
    } catch (Exception $e) {
        respond(false, 'Errore lettura template: ' . $e->getMessage());
    }
}

if ($action === 'get_template') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        respond(false, 'ID template non valido.');
    }

    try {
        ensureTemplatesTable($pdo);
        if (!empty($user['is_admin'])) {
            $stmt = $pdo->prepare('SELECT id, title, prompt, `date`, id_owner FROM templates WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare('SELECT id, title, prompt, `date`, id_owner FROM templates WHERE id = ? AND id_owner = ? LIMIT 1');
            $stmt->execute([$id, (int)$user['id']]);
        }
        $row = $stmt->fetch();
        if (!$row) {
            respond(false, 'Template non trovato o non accessibile.');
        }
        respond(true, 'Template trovato.', ['template' => $row]);
    } catch (Exception $e) {
        respond(false, 'Errore lettura template: ' . $e->getMessage());
    }
}

if ($action === 'create_template') {
    $title = trim((string)($_POST['title'] ?? ''));
    $prompt = trim((string)($_POST['prompt'] ?? ''));

    if ($title === '') {
        respond(false, 'Il titolo del template è obbligatorio.');
    }

    try {
        ensureTemplatesTable($pdo);
        $stmt = $pdo->prepare('INSERT INTO templates (title, prompt, `date`, id_owner) VALUES (?, ?, NOW(), ?)');
        $stmt->execute([$title, $prompt, (int)$user['id']]);
        respond(true, 'Template creato.', ['id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        respond(false, 'Errore creazione template: ' . $e->getMessage());
    }
}

if ($action === 'update_template') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $prompt = trim((string)($_POST['prompt'] ?? ''));

    if ($id <= 0) {
        respond(false, 'ID template non valido.');
    }
    if ($title === '') {
        respond(false, 'Il titolo del template è obbligatorio.');
    }

    try {
        ensureTemplatesTable($pdo);
        if (!empty($user['is_admin'])) {
            $stmt = $pdo->prepare('UPDATE templates SET title = ?, prompt = ?, `date` = NOW() WHERE id = ?');
            $stmt->execute([$title, $prompt, $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE templates SET title = ?, prompt = ?, `date` = NOW() WHERE id = ? AND id_owner = ?');
            $stmt->execute([$title, $prompt, $id, (int)$user['id']]);
        }
        if ($stmt->rowCount() <= 0) {
            respond(false, 'Template non trovato o non modificabile.');
        }
        respond(true, 'Template aggiornato.');
    } catch (Exception $e) {
        respond(false, 'Errore aggiornamento template: ' . $e->getMessage());
    }
}

if ($action === 'delete_template') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond(false, 'ID template non valido.');
    }

    try {
        ensureTemplatesTable($pdo);
        if (!empty($user['is_admin'])) {
            $stmt = $pdo->prepare('DELETE FROM templates WHERE id = ?');
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM templates WHERE id = ? AND id_owner = ?');
            $stmt->execute([$id, (int)$user['id']]);
        }
        if ($stmt->rowCount() <= 0) {
            respond(false, 'Template non trovato o non eliminabile.');
        }
        respond(true, 'Template eliminato.');
    } catch (Exception $e) {
        respond(false, 'Errore eliminazione template: ' . $e->getMessage());
    }
}

if ($action === 'list_users') {
    $stmt = $pdo->query('SELECT id, username, is_admin, is_enabled, is_manager, created_at, updated_at FROM users ORDER BY id ASC');
    $users = $stmt->fetchAll();
    respond(true, 'Utenti trovati.', ['users' => $users]);
}

if ($action === 'create_user') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $isAdmin = (int)($_POST['is_admin'] ?? 0);
    $isEnabled = (int)($_POST['is_enabled'] ?? 1);
    $isManager = (int)($_POST['is_manager'] ?? 0);

    if ($username === '' || $password === '') {
        respond(false, 'Username e password sono obbligatori.');
    }

    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, is_enabled, is_manager, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$username, $hash, $isAdmin, $isEnabled, $isManager]);
        respond(true, 'Utente creato.', ['id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        respond(false, 'Errore creazione utente: ' . $e->getMessage());
    }
}

if ($action === 'update_user') {
    $id = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $isAdmin = isset($_POST['is_admin']) ? (int)$_POST['is_admin'] : null;
    $isEnabled = isset($_POST['is_enabled']) ? (int)$_POST['is_enabled'] : null;
    $isManager = isset($_POST['is_manager']) ? (int)$_POST['is_manager'] : null;

    if ($id <= 0) {
        respond(false, 'ID utente non valido.');
    }
    $fields = [];
    $params = [];
    if ($username !== '') {
        $fields[] = 'username = ?';
        $params[] = $username;
    }
    if ($password !== '') {
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
    }
    if ($isAdmin !== null) {
        $fields[] = 'is_admin = ?';
        $params[] = $isAdmin;
    }
    if ($isEnabled !== null) {
        $fields[] = 'is_enabled = ?';
        $params[] = $isEnabled;
    }
    if ($isManager !== null) {
        $fields[] = 'is_manager = ?';
        $params[] = $isManager;
    }

    if (empty($fields)) {
        respond(false, 'Nessun campo da aggiornare.');
    }
    $params[] = $id;
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond(true, 'Utente aggiornato.');
    } catch (Exception $e) {
        respond(false, 'Errore aggiornamento utente: ' . $e->getMessage());
    }
}

if ($action === 'delete_user') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond(false, 'ID utente non valido.');
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
        respond(true, 'Utente eliminato.');
    } catch (Exception $e) {
        respond(false, 'Errore eliminazione utente: ' . $e->getMessage());
    }
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
