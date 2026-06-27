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
    <h2>Accedi</h2>
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
