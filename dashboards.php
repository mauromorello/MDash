<?php
session_start();

function getUserFromSessionOrCookie() {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['username'])) {
        return [
            'id' => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'is_admin' => (int)($_SESSION['is_admin'] ?? 0),
        ];
    }

    if (!empty($_COOKIE['mdash_user'])) {
        $user = json_decode(urldecode($_COOKIE['mdash_user']), true);
        if (is_array($user) && !empty($user['id'])) {
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'] ?? 'utente',
                'is_admin' => (int)($user['is_admin'] ?? 0),
            ];
        }
    }

    return null;
}

$user = getUserFromSessionOrCookie();
if (!$user) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elenco dashboard</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div>
                <h1>Elenco dashboard</h1>
                <div class="meta">Area vuota pronta per l’elenco dei dashboard.</div>
            </div>
            <a href="main.php">Torna alla home</a>
        </div>
        <div class="card">
            <p>Qui comparirà l’elenco dei dashboard creati.</p>
            <p>Al momento non sono presenti dashboard.</p>
        </div>
    </div>
</body>
</html>
