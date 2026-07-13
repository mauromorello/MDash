<?php
$key = $_GET['key'] ?? $_POST['key'] ?? '';
$expectedKey = 'lskfdjsdkfjeijrnsdnfmndmf';
if ($key !== $expectedKey) {
    http_response_code(403);
    $pageTitle = 'Config access denied';
    include __DIR__ . '/header.php';
    ?>
<body>
<div class="config-page"><a href="main.php" class="brand-home">Mdash</a><h1>Unauthorized access</h1><p>Missing or invalid key parameter.</p></div>
</body>
</html>
<?php
    exit;
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdoStatus = 'Not tested';
$pdoError = '';
$userTableStatus = 'Not checked';
$userTableExists = false;
$userTableCreateMessage = '';
try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdoStatus = 'OK';
} catch (Exception $e) {
    $pdoStatus = 'Error';
    $pdoError = $e->getMessage();
}

if ($pdoStatus === 'OK') {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $userTableExists = (bool)$stmt->fetchColumn();
        $userTableStatus = $userTableExists ? 'Exists' : 'Missing';
    } catch (Exception $e) {
        $userTableStatus = 'Check error: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_users_table') {
    if ($pdoStatus !== 'OK') {
        $userTableCreateMessage = 'Cannot create table because DB is not reachable: ' . $pdoError;
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
                $userTableCreateMessage = 'Users table created and initial admin user created.';
            } else {
                $userTableCreateMessage = 'Users table ready (created or existing) and admin user already present.';
            }
            $userTableExists = true;
            $userTableStatus = 'Exists';
        } catch (Exception $e) {
            $userTableCreateMessage = 'Users table creation error: ' . $e->getMessage();
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
        $tables = ['__error__' => 'Unable to read tables: ' . $e->getMessage()];
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
?>
<?php $pageTitle = 'Config helper'; include __DIR__ . '/header.php'; ?>
<body>
<div class="config-page">
    <a href="main.php" class="brand-home">Mdash</a>
    <h1>Technical configuration</h1>
    <p>This page shows technical runtime parameters used by the site.</p>

    <div class="panel">
        <h2>Database status</h2>
        <table>
            <tr><th>Active</th><td><?php echo $pdoStatus === 'OK' ? '<span class="status-ok">Yes</span>' : '<span class="status-error">No</span>'; ?></td></tr>
            <tr><th>Host</th><td><?php echo h($dbHost); ?></td></tr>
            <tr><th>Database name</th><td><?php echo h($dbName); ?></td></tr>
            <tr><th>Database user</th><td><?php echo h($dbUser); ?></td></tr>
            <tr><th>Database password</th><td><code><?php echo h($dbPass); ?></code></td></tr>
            <tr><th>PDO status</th><td><?php echo h($pdoStatus); ?></td></tr>
            <?php if ($pdoError): ?><tr><th>Connection error</th><td><code><?php echo h($pdoError); ?></code></td></tr><?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Users table</h2>
        <table>
            <tr><th><code>users</code> table status</th><td><?php echo h($userTableStatus); ?></td></tr>
        </table>
        <form method="post" class="config-form-inline">
            <input type="hidden" name="key" value="<?php echo h($key); ?>">
            <input type="hidden" name="action" value="create_users_table">
            <button type="submit">Create users table and initial admin</button>
        </form>
        <?php if ($userTableCreateMessage): ?>
            <p><?php echo h($userTableCreateMessage); ?></p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Server info</h2>
        <table>
            <tr><th>Apache / server</th><td><?php echo h($apacheStatus); ?></td></tr>
            <?php foreach ($serverInfo as $label => $value): ?>
                <tr><th><?php echo h($label); ?></th><td><?php echo h($value); ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Environment parameters</h2>
        <table>
            <tr><th>Site root path</th><td><?php echo h($_SERVER['DOCUMENT_ROOT'] ?? 'N/A'); ?></td></tr>
            <tr><th>Current file path</th><td><?php echo h(__FILE__); ?></td></tr>
            <tr><th>Working directory path</th><td><?php echo h(getcwd()); ?></td></tr>
            <tr><th>Server software</th><td><?php echo h($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'); ?></td></tr>
            <tr><th>PHP ini file</th><td><?php echo h(php_ini_loaded_file() ?: 'N/A'); ?></td></tr>
        </table>
    </div>

    <div class="panel">
        <h2>Tables and first 10 rows</h2>
        <?php if (isset($tables['__error__'])): ?>
            <p class="status-error"><?php echo h($tables['__error__']); ?></p>
        <?php elseif (empty($tables)): ?>
            <p>No tables found or DB not accessible.</p>
        <?php else: ?>
            <?php foreach ($tables as $tableName => $rows): ?>
                <h3><?php echo h($tableName); ?></h3>
                <?php if (!empty($tableColumns[$tableName])): ?>
                    <div class="table-columns-box">
                        <strong>Columns and types:</strong>
                        <table>
                            <thead>
                                <tr><th>Field</th><th>Type</th></tr>
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
                    <p>Unable to read table columns.</p>
                <?php endif; ?>
                <?php if (empty($rows)): ?>
                    <p>No rows available.</p>
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
        <h2>Deploy log</h2>
        <div class="log-box">
            <?php if (empty($deployLogLines)): ?>
                <p>No deploy_log.txt found or file is empty.</p>
            <?php else: ?>
                <?php foreach ($deployLogLines as $line): ?>
                    <?php echo h($line); ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>


