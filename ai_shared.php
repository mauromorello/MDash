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
            last_test_status VARCHAR(20) NOT NULL DEFAULT '',
            last_test_message TEXT NOT NULL,
            last_test_at DATETIME NULL DEFAULT NULL,
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
        'last_test_status',
        'last_test_message',
        'last_test_at',
    ];

    $idColumn = $pdo->query("SHOW COLUMNS FROM ai_db LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
    if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE ai_db MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
    }

    foreach ($columns as $column) {
        $exists = $pdo->query("SHOW COLUMNS FROM ai_db LIKE '" . str_replace("'", "''", $column) . "'")->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            continue;
        }

        switch ($column) {
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
            case 'last_test_status':
                $pdo->exec("ALTER TABLE ai_db ADD COLUMN last_test_status VARCHAR(20) NOT NULL DEFAULT '' AFTER is_hidden");
                break;
            case 'last_test_message':
                $pdo->exec("ALTER TABLE ai_db ADD COLUMN last_test_message TEXT NOT NULL AFTER last_test_status");
                break;
            case 'last_test_at':
                $pdo->exec("ALTER TABLE ai_db ADD COLUMN last_test_at DATETIME NULL DEFAULT NULL AFTER last_test_message");
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
            id_owner INT NOT NULL DEFAULT 0,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            is_hidden TINYINT(1) NOT NULL DEFAULT 0,
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

    $ownerColumn = $pdo->query("SHOW COLUMNS FROM dashboards LIKE 'id_owner'")->fetch(PDO::FETCH_ASSOC);
    if (!$ownerColumn) {
        $pdo->exec("ALTER TABLE dashboards ADD COLUMN id_owner INT NOT NULL DEFAULT 0 AFTER date_creation");
    }

    $publicColumn = $pdo->query("SHOW COLUMNS FROM dashboards LIKE 'is_public'")->fetch(PDO::FETCH_ASSOC);
    if (!$publicColumn) {
        $pdo->exec("ALTER TABLE dashboards ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER id_owner");
    }

    $hiddenColumn = $pdo->query("SHOW COLUMNS FROM dashboards LIKE 'is_hidden'")->fetch(PDO::FETCH_ASSOC);
    if (!$hiddenColumn) {
        $pdo->exec("ALTER TABLE dashboards ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public");
    }

    $uploadsTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'uploads'")->fetchColumn();
    if ($uploadsTableExists) {
        mdashEnsureDashboardDatasourceMapTable($pdo);

        $pdo->exec(
            "UPDATE dashboards d
             INNER JOIN (
                 SELECT dd.id_dashboard, MIN(dd.id_datasource) AS first_datasource_id
                 FROM dashboard_datasources dd
                 GROUP BY dd.id_dashboard
             ) map ON map.id_dashboard = d.id
             INNER JOIN uploads u ON u.id = map.first_datasource_id
             SET d.id_owner = u.id_owner
             WHERE d.id_owner = 0"
        );

        $pdo->exec(
            "UPDATE dashboards d
             INNER JOIN uploads u ON u.id = d.id_datasource
             SET d.id_owner = u.id_owner
             WHERE d.id_owner = 0 AND d.id_datasource IS NOT NULL"
        );
    }
}

function mdashEnsureDashboardDatasourceMapTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dashboard_datasources (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_dashboard INT UNSIGNED NOT NULL,
            id_datasource INT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dashboard_datasource (id_dashboard, id_datasource),
            INDEX idx_dashboard_datasource_dashboard (id_dashboard),
            INDEX idx_dashboard_datasource_upload (id_datasource),
            INDEX idx_dashboard_datasource_sort (id_dashboard, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function mdashFetchDashboardDatasourceIds(PDO $pdo, int $dashboardId): array {
    if ($dashboardId <= 0) {
        return [];
    }

    mdashEnsureDashboardDatasourceMapTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT id_datasource
         FROM dashboard_datasources
         WHERE id_dashboard = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$dashboardId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_map('intval', $rows ?: []));
}

function mdashReplaceDashboardDatasources(PDO $pdo, int $dashboardId, array $datasourceIds): void {
    mdashEnsureDashboardDatasourceMapTable($pdo);

    $cleanIds = [];
    foreach ($datasourceIds as $rawId) {
        $id = (int)$rawId;
        if ($id > 0 && !in_array($id, $cleanIds, true)) {
            $cleanIds[] = $id;
        }
    }

    $pdo->prepare('DELETE FROM dashboard_datasources WHERE id_dashboard = ?')->execute([$dashboardId]);

    if (empty($cleanIds)) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO dashboard_datasources (id_dashboard, id_datasource, sort_order) VALUES (?, ?, ?)'
    );
    foreach ($cleanIds as $index => $idDatasource) {
        $insert->execute([$dashboardId, $idDatasource, $index + 1]);
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
            n_views INT NOT NULL DEFAULT 0,
            n_download INT NOT NULL DEFAULT 0,
            n_clone INT NOT NULL DEFAULT 0,
            tags TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
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
        'n_views' => "ALTER TABLE results ADD COLUMN n_views INT NOT NULL DEFAULT 0 AFTER is_hidden",
        'n_download' => "ALTER TABLE results ADD COLUMN n_download INT NOT NULL DEFAULT 0 AFTER n_views",
        'n_clone' => "ALTER TABLE results ADD COLUMN n_clone INT NOT NULL DEFAULT 0 AFTER n_download",
        'tags' => "ALTER TABLE results ADD COLUMN tags TEXT COLLATE utf8mb4_unicode_ci NOT NULL AFTER n_clone",
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

function mdashSupportedAiProviders(): array {
    return [
        'gemini' => 'Gemini',
        'openrouter' => 'OpenRouter',
    ];
}

function mdashProviderLabel(string $provider): string {
    $providers = mdashSupportedAiProviders();
    $key = strtolower(trim($provider));

    return $providers[$key] ?? $provider;
}

function mdashFavoriteEntityTypes(): array {
    return [
        'template' => ['table' => 'templates', 'id_column' => 'id'],
        'makeup' => ['table' => 'makeup', 'id_column' => 'id_makeup'],
        'data' => ['table' => 'uploads', 'id_column' => 'id'],
        'dashboard' => ['table' => 'dashboards', 'id_column' => 'id'],
        'result' => ['table' => 'results', 'id_column' => 'id'],
    ];
}

function mdashNormalizeFavoriteType(string $favoriteType): ?string {
    $normalized = strtolower(trim($favoriteType));
    if ($normalized === 'markup') {
        $normalized = 'makeup';
    }
    if ($normalized === 'datasource' || $normalized === 'upload') {
        $normalized = 'data';
    }
    if ($normalized === 'results') {
        $normalized = 'result';
    }
    if ($normalized === 'templates') {
        $normalized = 'template';
    }
    if ($normalized === 'dashboards') {
        $normalized = 'dashboard';
    }

    $supported = mdashFavoriteEntityTypes();
    return isset($supported[$normalized]) ? $normalized : null;
}

function mdashEnsureFavoritesTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_favorites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_owner INT NOT NULL,
            favorite_type VARCHAR(32) NOT NULL,
            favorite_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_favorite (id_owner, favorite_type, favorite_id),
            INDEX idx_user_favorite_owner (id_owner),
            INDEX idx_user_favorite_type_id (favorite_type, favorite_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function mdashSetFavorite(PDO $pdo, int $userId, string $favoriteType, int $favoriteId, bool $isFavorite): bool {
    mdashEnsureFavoritesTable($pdo);

    $normalizedType = mdashNormalizeFavoriteType($favoriteType);
    if ($normalizedType === null || $userId <= 0 || $favoriteId <= 0) {
        return false;
    }

    if ($isFavorite) {
        $stmt = $pdo->prepare(
            'INSERT INTO user_favorites (id_owner, favorite_type, favorite_id, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)'
        );
        $stmt->execute([$userId, $normalizedType, $favoriteId]);
        return true;
    }

    $stmt = $pdo->prepare(
        'DELETE FROM user_favorites
         WHERE id_owner = ? AND favorite_type = ? AND favorite_id = ?'
    );
    $stmt->execute([$userId, $normalizedType, $favoriteId]);
    return false;
}

function mdashToggleFavorite(PDO $pdo, int $userId, string $favoriteType, int $favoriteId): bool {
    mdashEnsureFavoritesTable($pdo);

    $normalizedType = mdashNormalizeFavoriteType($favoriteType);
    if ($normalizedType === null || $userId <= 0 || $favoriteId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_favorites
         WHERE id_owner = ? AND favorite_type = ? AND favorite_id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId, $normalizedType, $favoriteId]);
    $exists = (bool)$stmt->fetchColumn();

    return mdashSetFavorite($pdo, $userId, $normalizedType, $favoriteId, !$exists);
}

function mdashFetchFavoriteMap(PDO $pdo, int $userId, string $favoriteType, array $entityIds): array {
    mdashEnsureFavoritesTable($pdo);

    $normalizedType = mdashNormalizeFavoriteType($favoriteType);
    if ($normalizedType === null || $userId <= 0 || empty($entityIds)) {
        return [];
    }

    $cleanIds = [];
    foreach ($entityIds as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) {
            $cleanIds[] = $id;
        }
    }
    $cleanIds = array_values(array_unique($cleanIds));
    if (empty($cleanIds)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($cleanIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT favorite_id
         FROM user_favorites
         WHERE id_owner = ? AND favorite_type = ? AND favorite_id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$userId, $normalizedType], $cleanIds));

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)($row['favorite_id'] ?? 0)] = true;
    }

    return $map;
}
