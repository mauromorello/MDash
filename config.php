<?php
$key = $_GET['key'] ?? '';
$expectedKey = 'lskfdjsdkfjeijrnsdnfmndmf';
if ($key !== $expectedKey) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Config access denied</title></head><body><h1>Accesso non autorizzato</h1><p>Parametro key mancante o errato.</p></body></html>';
    exit;
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdoStatus = 'Non testato';
$pdoError = '';
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
        table { border-collapse: collapse; width: 100%; max-width: 1000px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f4f4f4; }
        .status-ok { color: green; font-weight: bold; }
        .status-error { color: darkred; font-weight: bold; }
        .panel { margin-bottom: 20px; }
        .panel h2 { margin-bottom: 8px; }
    </style>
</head>
<body>
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
</body>
</html>
