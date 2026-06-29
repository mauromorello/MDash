<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 320px; margin: 50px auto; }
        form div { margin-bottom: 10px; }
        label { display: block; margin-bottom: 4px; }
        input { width: 100%; padding: 8px; }
        button { padding: 8px 12px; }
        #message { margin-top: 12px; }
    </style>
</head>
<body>
    <h2>MDASH login</h2>
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

    <div id="message"></div>

    <script>
        function getCookieValue(name) {
            const cookies = document.cookie.split(';').map(c => c.trim());
            for (const cookie of cookies) {
                if (cookie.startsWith(name + '=')) {
                    return cookie.substring(name.length + 1);
                }
            }
            return null;
        }

        const storedUser = getCookieValue('mdash_user');
        if (storedUser) {
            try {
                const user = JSON.parse(decodeURIComponent(storedUser));
                if (user && user.id) {
                    window.location.href = 'main.php';
                }
            } catch (e) {}
        }
    </script>

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
                    // salva cookie persistente con info utente e reindirizza
                    try {
                        const user = result.data.user || {};
                        const cookieVal = encodeURIComponent(JSON.stringify(user));
                        const expires = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toUTCString();
                        document.cookie = 'mdash_user=' + cookieVal + '; path=/; expires=' + expires + ';';
                    } catch(e){}
                    window.location.href = 'main.php';
                } else {
                    message.innerHTML = '<strong>Errore:</strong> ' + result.message;
                }
            })
            .catch(() => {
                document.getElementById('message').innerHTML = 'Errore durante la richiesta.';
            });
        });
    </script>
</body>
</html>
