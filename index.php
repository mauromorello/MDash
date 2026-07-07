<?php
session_start();

if ((!empty($_SESSION['user_id']) && !empty($_SESSION['username'])) || !empty($_COOKIE['mdash_user'])) {
    header('Location: main.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <a href="main.php" class="brand-home">Mdash</a>
    <div class="login-wrap">
        <h2>Sign in</h2>
        <form id="loginForm">
            <div>
                <label for="user">User:</label>
                <input type="text" id="user" name="user" required>
            </div>
            <div>
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <br>
            <button type="submit">Login</button>
        </form>

        <div id="message" class="login-message"></div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'login');

            fetch('_act.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                const message = document.getElementById('message');
                if (result.success) {
                    message.innerHTML = '<strong>Login riuscito!</strong> ' + result.message;
                    if (result.data && result.data.user) {
                        const user = result.data.user;
                        const payload = {
                            id: user.id,
                            username: user.username,
                            is_admin: user.is_admin || 0,
                            is_enabled: user.is_enabled || 1,
                            login_time: new Date().toISOString().slice(0, 19).replace('T', ' ')
                        };
                        document.cookie = 'mdash_user=' + encodeURIComponent(JSON.stringify(payload)) + '; path=/; max-age=' + (60 * 60 * 24 * 7) + '; SameSite=Lax';
                    }
                    window.location.href = 'main.php';
                } else {
                    message.innerHTML = '<strong>Error:</strong> ' + result.message;
                }
            })
            .catch(() => {
                document.getElementById('message').innerHTML = 'Request failed.';
            });
        });
    </script>
</body>
</html>
