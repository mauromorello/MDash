<?php
session_start();
require_once __DIR__ . '/ai_shared.php';

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

function sanitizeIdentifier(string $name): string {
    return str_replace('`', '', $name);
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function getPrimaryKeys(PDO $pdo, string $table): array {
    $safeTable = sanitizeIdentifier($table);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}`");
    $columns = $stmt->fetchAll();
    $primaryKeys = [];
    foreach ($columns as $column) {
        if (($column['Key'] ?? '') === 'PRI') {
            $primaryKeys[] = (string)$column['Field'];
        }
    }
    return $primaryKeys;
}

function ensureTemplatesTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            prompt MEDIUMTEXT NOT NULL,
            `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_owner INT NOT NULL,
            is_hidden TINYINT(1) NOT NULL DEFAULT 0,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_templates_owner (id_owner),
            INDEX idx_templates_date (`date`),
            INDEX idx_templates_hidden_public (is_hidden, is_public)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $tableExists = $pdo->query("SHOW TABLES LIKE 'templates'")->fetchColumn();
    if ($tableExists) {
        $idColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if ($idColumn && stripos((string)$idColumn['Extra'], 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE templates MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        $hiddenColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'is_hidden'")->fetch(PDO::FETCH_ASSOC);
        if (!$hiddenColumn) {
            $pdo->exec("ALTER TABLE templates ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
        }

        $publicColumn = $pdo->query("SHOW COLUMNS FROM templates LIKE 'is_public'")->fetch(PDO::FETCH_ASSOC);
        if (!$publicColumn) {
            $pdo->exec("ALTER TABLE templates ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0");
        }
    }
}

function canFavoriteEntity(PDO $pdo, int $userId, string $favoriteType, int $favoriteId): bool {
    if ($userId <= 0 || $favoriteId <= 0) {
        return false;
    }

    $type = mdashNormalizeFavoriteType($favoriteType);
    if ($type === null) {
        return false;
    }

    switch ($type) {
        case 'template':
            ensureTemplatesTable($pdo);
            $stmt = $pdo->prepare(
                'SELECT 1 FROM templates
                 WHERE id = ? AND (id_owner = ? OR (is_public = 1 AND is_hidden = 0))
                 LIMIT 1'
            );
            $stmt->execute([$favoriteId, $userId]);
            return (bool)$stmt->fetchColumn();

        case 'makeup':
            $stmt = $pdo->prepare(
                'SELECT 1 FROM makeup
                 WHERE id_makeup = ? AND (id_owner = ? OR (is_private = 0 AND is_hidden = 0))
                 LIMIT 1'
            );
            $stmt->execute([$favoriteId, $userId]);
            return (bool)$stmt->fetchColumn();

        case 'data':
            $stmt = $pdo->prepare(
                'SELECT 1 FROM uploads
                 WHERE id = ? AND (id_owner = ? OR is_public = 1)
                 LIMIT 1'
            );
            $stmt->execute([$favoriteId, $userId]);
            return (bool)$stmt->fetchColumn();

        case 'dashboard':
            $stmt = $pdo->prepare(
                'SELECT 1 FROM dashboards
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->execute([$favoriteId]);
            return (bool)$stmt->fetchColumn();

        case 'result':
            $stmt = $pdo->prepare(
                'SELECT 1 FROM results
                 WHERE id = ? AND ((id_owner = ? AND is_hidden = 0) OR (is_public = 1 AND is_hidden = 0))
                 LIMIT 1'
            );
            $stmt->execute([$favoriteId, $userId]);
            return (bool)$stmt->fetchColumn();
    }

    return false;
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

$adminOnlyActions = [
    'list_users',
    'create_user',
    'update_user',
    'delete_user',
    'list_tables',
    'get_schema',
    'get_rows',
    'get_rows_paginated',
    'update_row',
    'update_row_dynamic',
    'create_row_dynamic',
    'delete_row_dynamic',
];
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
        mdashEnsureFavoritesTable($pdo);
        $favoritesOnly = (int)($_POST['favorites_only'] ?? $_GET['favorites_only'] ?? 0) === 1 ? 1 : 0;
        $stmt = $pdo->prepare(
            'SELECT t.id, t.title, t.prompt, t.`date`, t.id_owner, t.is_hidden, t.is_public, u.username AS owner_username,
                    CASE WHEN t.id_owner = :user_owner THEN 1 ELSE 0 END AS is_owner,
                    CASE WHEN f.favorite_id IS NULL THEN 0 ELSE 1 END AS is_favorite
             FROM templates t
             LEFT JOIN users u ON u.id = t.id_owner
             LEFT JOIN user_favorites f ON f.favorite_type = "template" AND f.favorite_id = t.id AND f.id_owner = :favorite_owner
             WHERE (t.id_owner = :user_scope OR (t.is_public = 1 AND t.is_hidden = 0))
               AND (:favorites_only = 0 OR f.favorite_id IS NOT NULL)
             ORDER BY t.id DESC'
        );
        $stmt->execute([
            'user_owner' => (int)$user['id'],
            'favorite_owner' => (int)$user['id'],
            'user_scope' => (int)$user['id'],
            'favorites_only' => $favoritesOnly,
        ]);
        $rows = $stmt->fetchAll();
        respond(true, 'Template trovati.', ['templates' => $rows]);
    } catch (Exception $e) {
        respond(false, 'Errore lettura template: ' . $e->getMessage());
    }
}

if ($action === 'toggle_favorite') {
    $favoriteType = (string)($_POST['favorite_type'] ?? '');
    $favoriteId = (int)($_POST['favorite_id'] ?? 0);
    $forcedState = $_POST['is_favorite'] ?? null;

    if ($favoriteId <= 0) {
        respond(false, 'ID preferito non valido.');
    }

    $normalizedType = mdashNormalizeFavoriteType($favoriteType);
    if ($normalizedType === null) {
        respond(false, 'Tipo preferito non supportato.');
    }

    try {
        mdashEnsureFavoritesTable($pdo);
        if (!canFavoriteEntity($pdo, (int)$user['id'], $normalizedType, $favoriteId)) {
            respond(false, 'Elemento non disponibile per questo utente.');
        }

        if ($forcedState === null || $forcedState === '') {
            $isFavorite = mdashToggleFavorite($pdo, (int)$user['id'], $normalizedType, $favoriteId);
        } else {
            $targetState = (int)$forcedState === 1;
            $isFavorite = mdashSetFavorite($pdo, (int)$user['id'], $normalizedType, $favoriteId, $targetState);
        }

        respond(true, 'Preferito aggiornato.', ['is_favorite' => $isFavorite ? 1 : 0]);
    } catch (Exception $e) {
        respond(false, 'Errore aggiornamento preferito: ' . $e->getMessage());
    }
}

if ($action === 'get_template') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        respond(false, 'ID template non valido.');
    }

    try {
        ensureTemplatesTable($pdo);
        $stmt = $pdo->prepare('SELECT id, title, prompt, `date`, id_owner, is_hidden, is_public FROM templates WHERE id = ? AND id_owner = ? LIMIT 1');
        $stmt->execute([$id, (int)$user['id']]);
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
    $isHidden = (int)($_POST['is_hidden'] ?? 0) === 1 ? 1 : 0;
    $isPublic = (int)($_POST['is_public'] ?? 0) === 1 ? 1 : 0;

    if ($title === '') {
        respond(false, 'Il titolo del template è obbligatorio.');
    }

    try {
        ensureTemplatesTable($pdo);
        $stmt = $pdo->prepare('INSERT INTO templates (title, prompt, `date`, id_owner, is_hidden, is_public) VALUES (?, ?, NOW(), ?, ?, ?)');
        $stmt->execute([$title, $prompt, (int)$user['id'], $isHidden, $isPublic]);
        respond(true, 'Template creato.', ['id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        respond(false, 'Errore creazione template: ' . $e->getMessage());
    }
}

if ($action === 'update_template') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $prompt = trim((string)($_POST['prompt'] ?? ''));
    $isHidden = (int)($_POST['is_hidden'] ?? 0) === 1 ? 1 : 0;
    $isPublic = (int)($_POST['is_public'] ?? 0) === 1 ? 1 : 0;

    if ($id <= 0) {
        respond(false, 'ID template non valido.');
    }
    if ($title === '') {
        respond(false, 'Il titolo del template è obbligatorio.');
    }

    try {
        ensureTemplatesTable($pdo);
        $stmt = $pdo->prepare('UPDATE templates SET title = ?, prompt = ?, is_hidden = ?, is_public = ?, `date` = NOW() WHERE id = ? AND id_owner = ?');
        $stmt->execute([$title, $prompt, $isHidden, $isPublic, $id, (int)$user['id']]);
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
        $stmt = $pdo->prepare('DELETE FROM templates WHERE id = ? AND id_owner = ?');
        $stmt->execute([$id, (int)$user['id']]);
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
    if (!tableExists($pdo, $table)) {
        respond(false, 'Tabella non trovata.');
    }
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . sanitizeIdentifier($table) . "`");
    $stmt->execute();
    $cols = $stmt->fetchAll();
    respond(true, 'Schema ottenuto.', ['columns'=>$cols]);
}

if ($action === 'get_rows') {
    $table = $_POST['table'] ?? '';
    $limit = (int)($_POST['limit'] ?? 100);
    if (!$table) respond(false, 'Nome tabella mancante.');
    $safeTable = sanitizeIdentifier($table);
    $stmt = $pdo->prepare("SELECT * FROM `{$safeTable}` LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    respond(true, 'Record ottenuti.', ['rows'=>$rows]);
}

if ($action === 'get_rows_paginated') {
    $table = trim((string)($_POST['table'] ?? ''));
    $page = max(1, (int)($_POST['page'] ?? 1));
    $pageSize = max(1, min(500, (int)($_POST['page_size'] ?? 25)));

    if ($table === '') {
        respond(false, 'Nome tabella mancante.');
    }
    if (!tableExists($pdo, $table)) {
        respond(false, 'Tabella non trovata.');
    }

    $safeTable = sanitizeIdentifier($table);
    $offset = ($page - 1) * $pageSize;

    $countStmt = $pdo->query("SELECT COUNT(*) AS total_rows FROM `{$safeTable}`");
    $totalRows = (int)($countStmt->fetch()['total_rows'] ?? 0);

    $rowsStmt = $pdo->prepare("SELECT * FROM `{$safeTable}` LIMIT :limit OFFSET :offset");
    $rowsStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $rowsStmt->execute();
    $rows = $rowsStmt->fetchAll();

    $lastPage = max(1, (int)ceil($totalRows / $pageSize));
    respond(true, 'Record paginati ottenuti.', [
        'rows' => $rows,
        'page' => $page,
        'page_size' => $pageSize,
        'total_rows' => $totalRows,
        'last_page' => $lastPage,
    ]);
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
        $sets[] = "`" . sanitizeIdentifier((string)$k . "") . "` = ?";
        $params[] = $v;
    }
    $params[] = $id;
    $sql = "UPDATE `" . sanitizeIdentifier($table) . "` SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($params);
        respond(true, 'Record aggiornato.');
    } catch (Exception $e) {
        respond(false, 'Errore aggiornamento: ' . $e->getMessage());
    }
}

if ($action === 'update_row_dynamic') {
    $table = trim((string)($_POST['table'] ?? ''));
    $pk = $_POST['pk'] ?? null;
    $changes = $_POST['changes'] ?? null;

    if ($table === '' || $pk === null || $changes === null) {
        respond(false, 'Parametri mancanti.');
    }

    if (is_string($pk)) {
        $pk = json_decode($pk, true);
    }
    if (is_string($changes)) {
        $changes = json_decode($changes, true);
    }

    if (!is_array($pk) || empty($pk)) {
        respond(false, 'Primary key non valida.');
    }
    if (!is_array($changes) || empty($changes)) {
        respond(false, 'Nessun campo da aggiornare.');
    }
    if (!tableExists($pdo, $table)) {
        respond(false, 'Tabella non trovata.');
    }

    $primaryKeys = getPrimaryKeys($pdo, $table);
    if (empty($primaryKeys)) {
        respond(false, 'La tabella non ha una primary key.');
    }
    foreach ($primaryKeys as $keyField) {
        if (!array_key_exists($keyField, $pk)) {
            respond(false, 'Primary key incompleta. Campo mancante: ' . $keyField);
        }
    }

    foreach ($primaryKeys as $keyField) {
        if (array_key_exists($keyField, $changes)) {
            unset($changes[$keyField]);
        }
    }
    if (empty($changes)) {
        respond(true, 'Nessuna modifica applicabile.');
    }

    $safeTable = sanitizeIdentifier($table);
    $setParts = [];
    $params = [];
    foreach ($changes as $field => $value) {
        $setParts[] = "`" . sanitizeIdentifier((string)$field) . "` = ?";
        $params[] = $value;
    }

    $whereParts = [];
    foreach ($primaryKeys as $keyField) {
        $whereParts[] = "`" . sanitizeIdentifier($keyField) . "` = ?";
        $params[] = $pk[$keyField];
    }

    $sql = "UPDATE `{$safeTable}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond(true, 'Record aggiornato.');
    } catch (Exception $e) {
        respond(false, 'Errore aggiornamento dinamico: ' . $e->getMessage());
    }
}

if ($action === 'create_row_dynamic') {
    $table = trim((string)($_POST['table'] ?? ''));
    $row = $_POST['row'] ?? null;

    if ($table === '' || $row === null) {
        respond(false, 'Parametri mancanti.');
    }

    if (is_string($row)) {
        $row = json_decode($row, true);
    }

    if (!is_array($row) || empty($row)) {
        respond(false, 'Dati riga non validi.');
    }
    if (!tableExists($pdo, $table)) {
        respond(false, 'Tabella non trovata.');
    }

    $safeTable = sanitizeIdentifier($table);
    $schemaStmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}`");
    $schemaRows = $schemaStmt->fetchAll();
    $allowedColumns = [];
    foreach ($schemaRows as $schemaCol) {
        $field = (string)($schemaCol['Field'] ?? '');
        $extra = strtolower((string)($schemaCol['Extra'] ?? ''));
        if ($field === '' || str_contains($extra, 'auto_increment')) {
            continue;
        }
        $allowedColumns[$field] = [
            'null' => (string)($schemaCol['Null'] ?? 'NO'),
        ];
    }

    $insertCols = [];
    $insertVals = [];
    foreach ($row as $field => $value) {
        $fieldName = (string)$field;
        if (!array_key_exists($fieldName, $allowedColumns)) {
            continue;
        }

        $normalizedValue = $value;
        if ($normalizedValue === '' && strtoupper((string)$allowedColumns[$fieldName]['null']) === 'YES') {
            $normalizedValue = null;
        }

        $insertCols[] = "`" . sanitizeIdentifier($fieldName) . "`";
        $insertVals[] = $normalizedValue;
    }

    if (empty($insertCols)) {
        respond(false, 'Nessun campo valido da inserire.');
    }

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $sql = "INSERT INTO `{$safeTable}` (" . implode(', ', $insertCols) . ") VALUES ({$placeholders})";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($insertVals);
        respond(true, 'Record creato.', ['id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        respond(false, 'Errore creazione record: ' . $e->getMessage());
    }
}

if ($action === 'delete_row_dynamic') {
    $table = trim((string)($_POST['table'] ?? ''));
    $pk = $_POST['pk'] ?? null;

    if ($table === '' || $pk === null) {
        respond(false, 'Parametri mancanti.');
    }

    if (is_string($pk)) {
        $pk = json_decode($pk, true);
    }

    if (!is_array($pk) || empty($pk)) {
        respond(false, 'Primary key non valida.');
    }
    if (!tableExists($pdo, $table)) {
        respond(false, 'Tabella non trovata.');
    }

    $primaryKeys = getPrimaryKeys($pdo, $table);
    if (empty($primaryKeys)) {
        respond(false, 'La tabella non ha una primary key.');
    }
    foreach ($primaryKeys as $keyField) {
        if (!array_key_exists($keyField, $pk)) {
            respond(false, 'Primary key incompleta. Campo mancante: ' . $keyField);
        }
    }

    $safeTable = sanitizeIdentifier($table);
    $whereParts = [];
    $params = [];
    foreach ($primaryKeys as $keyField) {
        $whereParts[] = "`" . sanitizeIdentifier($keyField) . "` = ?";
        $params[] = $pk[$keyField];
    }

    $sql = "DELETE FROM `{$safeTable}` WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() < 1) {
            respond(false, 'Nessun record eliminato.');
        }
        respond(true, 'Record eliminato.');
    } catch (Exception $e) {
        respond(false, 'Errore eliminazione record: ' . $e->getMessage());
    }
}

respond(false, 'Azione non supportata.');
