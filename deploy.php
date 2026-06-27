<?php
// Script di Auto-Deploy per GitHub

// 1. Per sicurezza, controlliamo che la richiesta arrivi davvero (opzionale ma utile)
// Puoi aggiungere un token di sicurezza in seguito, per ora facciamo il meccanismo base.

// 2. Esegui il comando di pull sulla cartella del server
$output = shell_exec('cd /var/www/web/mdash && git pull 2>&1');

// 3. Scrivi il risultato in un file di log per controllare se funziona
file_put_contents('deploy_log.txt', date('[Y-m-d H:i:s] ') . $output, FILE_APPEND);

echo "Deploy completato con successo!";