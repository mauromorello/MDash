<?php
$key = $_GET['key'] ?? $_POST['key'] ?? '';
$expectedKey = 'lskfdjsdkfjeijrnsdnfmndmf';
if ($key !== $expectedKey) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Config access denied</title></head><body><a href="main.php" style="display:inline-block;margin:12px 0;color:#111827;text-decoration:none;font-weight:700;">Mdash</a><h1>Accesso non autorizzato</h1><p>Parametro key mancante o errato.</p></body></html>';
    exit;
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdoStatus = 'Non testato';
$pdoError = '';
$userTableStatus = 'Non verificata';
$userTableExists = false;
$userTableCreateMessage = '';
try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdoStatus = 'OK';
} catch (Exception $e) {
    $pdoStatus = 'Errore';
    $pdoError = $e->getMessage();
}

if ($pdoStatus === 'OK') {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $userTableExists = (bool)$stmt->fetchColumn();
        $userTableStatus = $userTableExists ? 'Esiste' : 'Non esiste';
    } catch (Exception $e) {
        $userTableStatus = 'Errore verifica: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_users_table') {
    if ($pdoStatus !== 'OK') {
        $userTableCreateMessage = 'Impossibile creare la tabella perché il DB non è accessibile: ' . $pdoError;
    } else {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS users (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL UNIQUE,
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
            $colCheck->execute(['email']);
            if (!$colCheck->fetch()) {
                // Try to add with a default value, then update to unique.
                try {
                    $pdo->exec("ALTER TABLE `users` ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT 'placeholder@example.com'");
                    $pdo->exec("UPDATE `users` SET email = CONCAT('user_', id, '@example.com') WHERE email = 'placeholder@example.com'");
                    $pdo->exec("ALTER TABLE `users` ADD UNIQUE (email)");
                } catch(Exception $e) {
                    // Fallback for older MySQL versions that might not like the above
                     $pdo->exec("ALTER TABLE `users` ADD COLUMN email VARCHAR(255) NULL");
                     $pdo->exec("UPDATE `users` SET email = CONCAT('user_', id, '@example.com') WHERE email IS NULL");
                     $pdo->exec("ALTER TABLE `users` MODIFY COLUMN email VARCHAR(255) NOT NULL UNIQUE");
                }
            }
            $colCheck->execute(['last_login_ip']);
            if (!$colCheck->fetch()) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN last_login_ip VARCHAR(45) NULL");
            }
            $colCheck->execute(['last_login_agent']);
            if (!$colCheck->fetch()) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN last_login_agent TEXT NULL");
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $stmt->execute(['mimmoz']);
            if ((int)$stmt->fetchColumn() === 0) {
                $hash = password_hash('zxcasd', PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO users (username, password_hash, email, is_admin, is_enabled, created_at, updated_at) VALUES (?, ?, ?, 1, 1, NOW(), NOW())');
                $ins->execute(['mimmoz', $hash, 'mimmoz@example.com']);
                $userTableCreateMessage = 'Tabella users creata e utente admin creato.';
            } else {
                $userTableCreateMessage = 'Tabella users creata (o esistente) e utente admin già presente.';
            }
            $userTableExists = true;
            $userTableStatus = 'Esiste';
        } catch (Exception $e) {
            $userTableCreateMessage = 'Errore creazione tabella users: ' . $e->getMessage();
        }
    }
}

$apacheStatus = 'Non disponibile';
if (function_exists('apache_get_version')) {
    $apacheStatus = apache_get_version();
} elseif (!empty($_SERVER['SERVER_SOFTWARE'])) {
    $apacheStatus = $_SERVER['SERVER_SOFTWARE'];
}

$serverInfo = [];
$serverInfo['PHP Version'] = phpversion();
$serverInfo['SAPI'] = php_sapi_name();
$serverInfo['Document Root'] = $_SERVER['DOCUMENT_ROOT'] ?? 'N/A';
$serverInfo['Current Script Path'] = __FILE__;
$serverInfo['Current Working Directory'] = getcwd();
$serverInfo['Server Software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
$serverInfo['Server Name'] = $_SERVER['SERVER_NAME'] ?? 'N/A';
$serverInfo['Server Protocol'] = $_SERVER['SERVER_PROTOCOL'] ?? 'N/A';
$serverInfo['Request Method'] = $_SERVER['REQUEST_METHOD'] ?? 'N/A';
$serverInfo['HTTPS'] = !empty($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'off';
$serverInfo['PDO extension'] = extension_loaded('pdo') ? 'Yes' : 'No';
$serverInfo['PDO MySQL extension'] = extension_loaded('pdo_mysql') ? 'Yes' : 'No';
$serverInfo['OpenSSL extension'] = extension_loaded('openssl') ? 'Yes' : 'No';
$serverInfo['mbstring extension'] = extension_loaded('mbstring') ? 'Yes' : 'No';
$serverInfo['File Uploads'] = ini_get('file_uploads') ? 'Yes' : 'No';
$serverInfo['Max Execution Time'] = ini_get('max_execution_time');
$serverInfo['Memory Limit'] = ini_get('memory_limit');
$serverInfo['Timezone'] = date_default_timezone_get();

$tables = [];
$tableColumns = [];
if ($pdoStatus === 'OK') {
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $tableNames = $stmt->fetchAll(PDO::FETCH_NUM);
        foreach ($tableNames as $row) {
            if (empty($row[0])) {
                continue;
            }
            $table = $row[0];
            $rowsStmt = $pdo->prepare("SELECT * FROM `" . str_replace('`', '', $table) . "` LIMIT 10");
            $rowsStmt->execute();
            $tables[$table] = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
            $colsStmt->execute();
            $tableColumns[$table] = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $tables = ['__error__' => 'Impossibile leggere le tabelle: ' . $e->getMessage()];
    }
}

$deployLogPath = __DIR__ . '/deploy_log.txt';
$deployLogLines = [];
if (is_readable($deployLogPath)) {
    $allLines = file($deployLogPath, FILE_IGNORE_NEW_LINES);
    $deployLogLines = array_slice($allLines, max(0, count($allLines) - 300));
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Config helper</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .brand-home { display:inline-block; margin-bottom: 10px; color:#111827; text-decoration:none; font-weight:700; }
        .brand-home:hover { text-decoration: underline; }
        table { border-collapse: collapse; width: 100%; max-width: 1000px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f4f4f4; }
        .status-ok { color: green; font-weight: bold; }
        .status-error { color: darkred; font-weight: bold; }
        .panel { margin-bottom: 20px; }
        .panel h2 { margin-bottom: 8px; }
        .log-box { background:#111; color:#eee; padding:12px; max-height:300px; overflow:auto; white-space:pre-wrap; font-family:Menlo, Monaco, monospace; border:1px solid #333; }
    </style>
</head>
<body>
    <a href="main.php" class="brand-home">Mdash</a>
    <h1>Configurazione tecnica</h1>
    <p>Questa pagina mostra i parametri tecnici utilizzati dal sito.</p>

    <div class="panel">
        <h2>Stato DB</h2>
        <table>
            <tr><th>Attivo</th><td><?php echo $pdoStatus === 'OK' ? '<span class="status-ok">Sì</span>' : '<span class="status-error">No</span>'; ?></td></tr>
            <tr><th>Host</th><td><?php echo h($dbHost); ?></td></tr>
            <tr><th>Nome DB</th><td><?php echo h($dbName); ?></td></tr>
            <tr><th>Utente DB</th><td><?php echo h($dbUser); ?></td></tr>
            <tr><th>Password DB</th><td><code><?php echo h($dbPass); ?></code></td></tr>
            <tr><th>PDO status</th><td><?php echo h($pdoStatus); ?></td></tr>
            <?php if ($pdoError): ?><tr><th>Errore connessione</th><td><code><?php echo h($pdoError); ?></code></td></tr><?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Tabella utenti</h2>
        <table>
            <tr><th>Stato tabella <code>users</code></th><td><?php echo h($userTableStatus); ?></td></tr>
        </table>
        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="key" value="<?php echo h($key); ?>">
            <input type="hidden" name="action" value="create_users_table">
            <button type="submit">Crea tabella users e primo admin</button>
        </form>
        <?php if ($userTableCreateMessage): ?>
            <p><?php echo h($userTableCreateMessage); ?></p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Info server</h2>
        <table>
            <tr><th>Apache / server</th><td><?php echo h($apacheStatus); ?></td></tr>
            <?php foreach ($serverInfo as $label => $value): ?>
                <tr><th><?php echo h($label); ?></th><td><?php echo h($value); ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Parametri ambiente</h2>
        <table>
            <tr><th>Path root del sito</th><td><?php echo h($_SERVER['DOCUMENT_ROOT'] ?? 'N/A'); ?></td></tr>
            <tr><th>Path file corrente</th><td><?php echo h(__FILE__); ?></td></tr>
            <tr><th>Path working directory</th><td><?php echo h(getcwd()); ?></td></tr>
            <tr><th>Server software</th><td><?php echo h($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'); ?></td></tr>
            <tr><th>PHP ini file</th><td><?php echo h(php_ini_loaded_file() ?: 'N/A'); ?></td></tr>
        </table>
    </div>

    <div class="panel">
        <h2>Liste tabelle e prime 10 righe</h2>
        <?php if (isset($tables['__error__'])): ?>
            <p class="status-error"><?php echo h($tables['__error__']); ?></p>
        <?php elseif (empty($tables)): ?>
            <p>Nessuna tabella trovata o DB non accessibile.</p>
        <?php else: ?>
            <?php foreach ($tables as $tableName => $rows): ?>
                <h3><?php echo h($tableName); ?></h3>
                <?php if (!empty($tableColumns[$tableName])): ?>
                    <div style="margin-bottom:12px;">
                        <strong>Campi e tipi:</strong>
                        <table>
                            <thead>
                                <tr><th>Campo</th><th>Tipo</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableColumns[$tableName] as $col): ?>
                                    <tr>
                                        <td><?php echo h($col['Field']); ?></td>
                                        <td><?php echo h($col['Type']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>Impossibile leggere i campi della tabella.</p>
                <?php endif; ?>
                <?php if (empty($rows)): ?>
                    <p>Nessuna riga presente.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <?php foreach (array_keys($rows[0]) as $col): ?>
                                    <th><?php echo h($col); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?php echo h($value); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Log deploy</h2>
        <div class="log-box">
            <?php if (empty($deployLogLines)): ?>
                <p>Nessun file deploy_log.txt trovato o file vuoto.</p>
            <?php else: ?>
                <?php foreach ($deployLogLines as $line): ?>
                    <?php echo h($line); ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
