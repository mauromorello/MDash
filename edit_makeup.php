<?php
session_start();

if (empty($_COOKIE['mdash_user'])) {
    header('Location: index.php');
    exit;
}

function getUserFromSessionOrCookie() {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['username'])) {
        return [
            'id' => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'login_time' => $_SESSION['login_time'] ?? null,
            'is_admin' => (int)($_SESSION['is_admin'] ?? 0),
        ];
    }

    if (!empty($_COOKIE['mdash_user'])) {
        $user = json_decode(urldecode($_COOKIE['mdash_user']), true);
        if (is_array($user) && !empty($user['id'])) {
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'] ?? 'user',
                'login_time' => $user['login_time'] ?? null,
                'is_admin' => (int)($user['is_admin'] ?? 0),
            ];
        }
    }

    return null;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$user = getUserFromSessionOrCookie();
if (!$user) {
    header('Location: index.php');
    exit;
}

$idMakeup = (int)($_GET['id'] ?? $_POST['id_makeup'] ?? 0);
if ($idMakeup <= 0) {
    header('Location: makeup.php');
    exit;
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mdash';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'zxca$dqwe123';

$pdo = null;
$error = '';
$row = null;

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS makeup (
            id_makeup INT NOT NULL,
            date_makeup DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_owner INT NOT NULL,
            prompt_makeup TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            is_private INT NOT NULL DEFAULT 1,
            is_hidden INT NOT NULL DEFAULT 0,
            name VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            palette TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            PRIMARY KEY (id_makeup),
            INDEX idx_makeup_owner (id_owner),
            INDEX idx_makeup_private_hidden (is_private, is_hidden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string)($_POST['name'] ?? ''));
        $promptMakeup = trim((string)($_POST['prompt_makeup'] ?? ''));
        $paletteJson = trim((string)($_POST['palette'] ?? '[]'));
        $isPrivate = (int)($_POST['is_private'] ?? 1) === 1 ? 1 : 0;
        $isHidden = (int)($_POST['is_hidden'] ?? 0) === 1 ? 1 : 0;

        $decoded = json_decode($paletteJson, true);
        if (!is_array($decoded) || count($decoded) !== 5) {
            $error = 'Palette JSON must contain exactly 5 colors.';
        } elseif ($name === '') {
            $error = 'Name is required.';
        } elseif ($promptMakeup === '') {
            $error = 'Makeup prompt is required.';
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE makeup SET name = ?, prompt_makeup = ?, is_private = ?, is_hidden = ?, palette = ? WHERE id_makeup = ? AND id_owner = ?'
            );
            $updateStmt->execute([
                $name,
                $promptMakeup,
                $isPrivate,
                $isHidden,
                json_encode(array_values($decoded), JSON_UNESCAPED_SLASHES),
                $idMakeup,
                (int)$user['id'],
            ]);

            header('Location: makeup.php?updated=1');
            exit;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM makeup WHERE id_makeup = ? AND id_owner = ? LIMIT 1');
    $stmt->execute([$idMakeup, (int)$user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        $error = 'Makeup profile not found or not editable.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<?php $pageTitle = 'Edit Makeup'; include __DIR__ . '/header.php'; ?>
<body>
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="page">
        <div class="topbar">
            <div>
                <h1>Edit Makeup</h1>
                <div class="meta">Update prompt and palette for the selected makeup profile.</div>
            </div>
            <a href="makeup.php">Back to makeup list</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($row): ?>
            <div class="card">
                <form method="post">
                    <input type="hidden" name="id_makeup" value="<?php echo h($row['id_makeup']); ?>">

                    <div class="field">
                        <label>Palette colors</label>
                        <?php $palette = json_decode((string)$row['palette'], true); ?>
                        <?php if (!is_array($palette) || count($palette) !== 5) { $palette = ['#2563EB','#0F766E','#7C3AED','#F59E0B','#DC2626']; } ?>
                        <div class="palette-picker-row" id="palettePickerRow">
                            <?php foreach ($palette as $color): ?>
                                <input type="color" class="palette-color" value="<?php echo h((string)$color); ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="palette">Palette JSON</label>
                        <textarea id="palette" name="palette" class="palette-json-area"><?php echo h((string)$row['palette']); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" maxlength="255" required value="<?php echo h((string)$row['name']); ?>">
                    </div>

                    <div class="field">
                        <label for="prompt_makeup">Makeup prompt</label>
                        <textarea id="prompt_makeup" name="prompt_makeup"><?php echo h((string)$row['prompt_makeup']); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="is_private">Visibility</label>
                            <select id="is_private" name="is_private">
                                <option value="1"<?php echo ((int)$row['is_private'] === 1) ? ' selected' : ''; ?>>Private</option>
                                <option value="0"<?php echo ((int)$row['is_private'] === 0) ? ' selected' : ''; ?>>Public</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="is_hidden">Status</label>
                            <select id="is_hidden" name="is_hidden">
                                <option value="0"<?php echo ((int)$row['is_hidden'] === 0) ? ' selected' : ''; ?>>Visible</option>
                                <option value="1"<?php echo ((int)$row['is_hidden'] === 1) ? ' selected' : ''; ?>>Hidden</option>
                            </select>
                        </div>
                    </div>

                    <div class="inline-actions">
                        <button type="submit">Save changes</button>
                        <a href="makeup.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function parsePaletteJson(rawText) {
            try {
                const values = JSON.parse(rawText);
                if (!Array.isArray(values) || values.length !== 5) {
                    return null;
                }

                const normalized = values.map(function (value) {
                    const hex = String(value || '').trim().toUpperCase();
                    return /^#[0-9A-F]{6}$/.test(hex) ? hex : null;
                });

                if (normalized.some(function (value) { return value === null; })) {
                    return null;
                }

                return normalized;
            } catch (e) {
                return null;
            }
        }

        function syncPaletteJsonFromPickers() {
            const colors = Array.from(document.querySelectorAll('.palette-color')).map(function (input) {
                return String(input.value || '').toUpperCase();
            });
            document.getElementById('palette').value = JSON.stringify(colors);
        }

        function syncPickersFromPaletteJson() {
            const textarea = document.getElementById('palette');
            const parsed = parsePaletteJson(textarea.value);
            if (!parsed) {
                return;
            }

            const pickers = document.querySelectorAll('.palette-color');
            parsed.forEach(function (hex, index) {
                if (pickers[index]) {
                    pickers[index].value = hex;
                }
            });
        }

        document.querySelectorAll('.palette-color').forEach(function (input) {
            input.addEventListener('input', syncPaletteJsonFromPickers);
        });

        const paletteTextarea = document.getElementById('palette');
        if (paletteTextarea) {
            paletteTextarea.addEventListener('input', syncPickersFromPaletteJson);
            paletteTextarea.addEventListener('change', syncPickersFromPaletteJson);
            paletteTextarea.addEventListener('blur', syncPickersFromPaletteJson);
            syncPickersFromPaletteJson();
        }

        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function () {
                fetch('_act.php', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'logout' })
                }).finally(() => {
                    document.cookie = 'mdash_user=; path=/; max-age=0';
                    window.location.href = 'index.php';
                });
            });
        }
    </script>
</body>
</html>


