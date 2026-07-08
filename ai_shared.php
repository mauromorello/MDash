<?php

function mdashEnsureAiDbTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ai_db (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            provider VARCHAR(100) NOT NULL,
            model VARCHAR(100) NOT NULL,
            api_key TEXT NOT NULL,
            web_end_point TEXT NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_owner INT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            is_hidden TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_ai_db_owner (id_owner),
            INDEX idx_ai_db_visibility (is_public, is_hidden),
            INDEX idx_ai_db_date (date_creation)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'date_creation',
        'id_owner',
        'is_public',
        'is_hidden',
    ];

    foreach ($columns as $column) {
        $exists = $pdo->query("SHOW COLUMNS FROM ai_db LIKE '" . str_replace("'", "''", $column) . "'")->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            continue;
        }

        switch ($column) {

    $idColumn = $pdo->query("SHOW COLUMNS FROM ai_db LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
    if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE ai_db MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
    }
            case 'date_creation':
                $pdo->exec("ALTER TABLE ai_db ADD COLUMN date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER web_end_point");
                break;
            case 'id_owner':
                $pdo->exec("ALTER TABLE ai_db ADD COLUMN id_owner INT NOT NULL AFTER date_creation");
                break;
            case 'is_public':
                $pdo->exec("ALTER TABLE ai_db ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER id_owner");
                break;
            case 'is_hidden':
                $pdo->exec("ALTER TABLE ai_db ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public");
                break;
        }
    }
}

function mdashEnsureDashboardAiColumn(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dashboards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_datasource INT DEFAULT NULL,
            id_makeup INT NOT NULL DEFAULT 0,
            id_ai_db INT NOT NULL DEFAULT 0,
            data_filter_prompt TEXT NOT NULL,
            data_manipulation_prompt TEXT NOT NULL,
            dashboard_prompt_1 TEXT NOT NULL,
            dashboard_prompt_2 TEXT NOT NULL,
            id_template INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $column = $pdo->query("SHOW COLUMNS FROM dashboards LIKE 'id_ai_db'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE dashboards ADD COLUMN id_ai_db INT NOT NULL DEFAULT 0 AFTER id_makeup");
    }
}

function mdashEnsureResultsAiColumns(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS results (
            id INT NOT NULL,
            path TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            id_template INT NOT NULL,
            id_ai_db INT NOT NULL DEFAULT 0,
            ai_title VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            ai_provider VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            ai_model VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            final_prompt TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            thumbnail_path TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            `HTML` LONGTEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            id_owner INT NOT NULL,
            is_public INT NOT NULL DEFAULT '0',
            is_hidden INT NOT NULL DEFAULT '0',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'id_ai_db' => "ALTER TABLE results ADD COLUMN id_ai_db INT NOT NULL DEFAULT 0 AFTER id_template",
        'ai_title' => "ALTER TABLE results ADD COLUMN ai_title VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER id_ai_db",
        'ai_provider' => "ALTER TABLE results ADD COLUMN ai_provider VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER ai_title",
        'ai_model' => "ALTER TABLE results ADD COLUMN ai_model VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER ai_provider",
    ];

    foreach ($columns as $column => $alterSql) {
        $exists = $pdo->query("SHOW COLUMNS FROM results LIKE '" . str_replace("'", "''", $column) . "'")->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            $pdo->exec($alterSql);
        }
    }

    $htmlColumn = $pdo->query("SHOW COLUMNS FROM results LIKE 'HTML'")->fetch(PDO::FETCH_ASSOC);
    if (!$htmlColumn) {
        $pdo->exec("ALTER TABLE results ADD COLUMN `HTML` LONGTEXT COLLATE utf8mb4_unicode_ci NOT NULL AFTER thumbnail_path");
    }
}

function mdashFetchAccessibleAiProfiles(PDO $pdo, int $userId, bool $includeHidden = false): array {
    mdashEnsureAiDbTable($pdo);

    $where = $includeHidden
        ? '((a.id_owner = :user_id) OR (a.is_public = 1 AND a.is_hidden = 0))'
        : '((a.id_owner = :user_id AND a.is_hidden = 0) OR (a.is_public = 1 AND a.is_hidden = 0))';

    $stmt = $pdo->prepare(
        'SELECT a.*, u.username AS owner_username,
                CASE WHEN a.id_owner = :user_id_owner THEN 1 ELSE 0 END AS is_owner
         FROM ai_db a
         LEFT JOIN users u ON u.id = a.id_owner
         WHERE ' . $where . '
         ORDER BY a.id DESC'
    );
    $stmt->execute(['user_id' => $userId, 'user_id_owner' => $userId]);

    return $stmt->fetchAll();
}

function mdashFetchAccessibleAiProfile(PDO $pdo, int $aiId, int $userId, bool $includeHidden = false): ?array {
    if ($aiId <= 0) {
        return null;
    }

    mdashEnsureAiDbTable($pdo);

    $where = $includeHidden
        ? '(a.id = ? AND (a.id_owner = ? OR a.is_public = 1))'
        : '(a.id = ? AND ((a.id_owner = ? AND a.is_hidden = 0) OR (a.is_public = 1 AND a.is_hidden = 0)))';

    $stmt = $pdo->prepare(
        'SELECT a.*, u.username AS owner_username
         FROM ai_db a
         LEFT JOIN users u ON u.id = a.id_owner
         WHERE ' . $where . '
         LIMIT 1'
    );
    $stmt->execute([$aiId, $userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}
