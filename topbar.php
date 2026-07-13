<?php
$topbarUser = $user ?? ($me ?? null);
if (!is_array($topbarUser)) {
    $topbarUser = [];
}

$topbarUsername = htmlspecialchars((string)($topbarUser['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
$topbarLoginTime = htmlspecialchars((string)($topbarUser['login_time'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8');
$topbarIsAdmin = !empty($topbarUser['is_admin']);
$topbarRole = strtolower(trim((string)($topbarUser['role'] ?? ($_SESSION['role'] ?? 'user'))));
$topbarCanManage = $topbarIsAdmin || ($topbarRole !== '' && $topbarRole !== 'user');
$topbarCurrentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));

$topbarQuickMenus = [
    'config' => [
        'label' => 'Config',
        'items' => [
            ['href' => 'ai_db.php', 'label' => 'AI'],
            ['href' => 'templates.php', 'label' => 'Template'],
            ['href' => 'makeup.php', 'label' => 'Markup'],
        ],
    ],
    'data' => [
        'label' => 'Data',
        'items' => [
            ['href' => 'upload.php', 'label' => 'Upload'],
            ['href' => 'database_list.php', 'label' => 'Data Pool'],
        ],
    ],
    'dashboards' => [
        'label' => 'Dashboards',
        'items' => [
            ['href' => 'dashboard_builder.php', 'label' => 'Generate'],
            ['href' => 'dashboards.php', 'label' => 'Dash Pool'],
            ['href' => 'results.php', 'label' => 'Dashboard'],
        ],
    ],
    'favorites' => [
        'label' => 'Preferiti',
        'items' => [
            ['href' => 'templates.php?favorites=1', 'label' => 'Template'],
            ['href' => 'makeup.php?favorites=1', 'label' => 'Markup'],
            ['href' => 'database_list.php?favorites=1', 'label' => 'Data'],
            ['href' => 'dashboards.php?favorites=1', 'label' => 'Dashboard'],
            ['href' => 'results.php?favorites=1', 'label' => 'Results'],
        ],
    ],
];

if ($topbarIsAdmin) {
    $topbarQuickMenus['admin'] = [
        'label' => 'Admin',
        'items' => [
            ['href' => 'admin.php', 'label' => 'Console'],
            ['href' => 'main.php', 'label' => 'Home'],
        ],
    ];
}
?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<div class="user-ribbon" x-data="{ open: false, mainMenuOpen: '' }" @keydown.escape.window="open = false; mainMenuOpen = ''">
    <div class="topbar-left">
        <a href="main.php" class="topbar-logo-link" aria-label="Go to main page">
            <img src="assets/logo.png" alt="MDash logo" class="topbar-logo-image">
        </a>
        <div class="info">
            <span>User: <?php echo $topbarUsername; ?></span>
            <span class="topbar-role-icons" aria-label="User permissions">
                <?php if ($topbarIsAdmin): ?>
                    <span class="topbar-role-icon" title="Admin" aria-label="Admin">&#128737;</span>
                <?php endif; ?>
                <?php if ($topbarCanManage): ?>
                    <span class="topbar-role-icon" title="Can manage" aria-label="Can manage">&#9881;</span>
                <?php endif; ?>
            </span>
            <span>| Login: <?php echo $topbarLoginTime; ?></span>
        </div>
    </div>

    <div class="topbar-right">
        <nav class="topbar-main-nav" aria-label="Quick menus">
            <?php foreach ($topbarQuickMenus as $menuKey => $menuConfig): ?>
                <div class="topbar-main-nav-item">
                    <button type="button" class="topbar-main-nav-btn" @click="mainMenuOpen = mainMenuOpen === '<?php echo htmlspecialchars((string)$menuKey, ENT_QUOTES, 'UTF-8'); ?>' ? '' : '<?php echo htmlspecialchars((string)$menuKey, ENT_QUOTES, 'UTF-8'); ?>'" :aria-expanded="(mainMenuOpen === '<?php echo htmlspecialchars((string)$menuKey, ENT_QUOTES, 'UTF-8'); ?>').toString()" aria-controls="topbarMain<?php echo htmlspecialchars((string)$menuKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$menuConfig['label'], ENT_QUOTES, 'UTF-8'); ?></button>
                    <div id="topbarMain<?php echo htmlspecialchars((string)$menuKey, ENT_QUOTES, 'UTF-8'); ?>" class="topbar-main-nav-panel" x-cloak x-show="mainMenuOpen === '<?php echo htmlspecialchars((string)$menuKey, ENT_QUOTES, 'UTF-8'); ?>'" x-transition.origin.top.right @click.outside="mainMenuOpen = ''">
                        <?php foreach (($menuConfig['items'] ?? []) as $menuItem): ?>
                            <a href="<?php echo htmlspecialchars((string)($menuItem['href'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>" @click="mainMenuOpen = ''"><?php echo htmlspecialchars((string)($menuItem['label'] ?? 'Menu'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="topbar-hamburger-wrap">
            <button type="button" id="topbarMenuBtn" class="topbar-menu-btn" @click="open = !open" :aria-expanded="open.toString()" aria-controls="topbarMenu" aria-label="Open navigation menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <div id="topbarMenu" class="topbar-dropdown" x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false">
                <a href="ai_db.php" @click="open = false">Config</a>
                <?php if ($topbarIsAdmin): ?>
                    <a href="admin.php" @click="open = false">Admin Console</a>
                <?php endif; ?>
                <button id="logoutBtn" type="button" class="topbar-logout-btn">Logout</button>
            </div>
        </div>
    </div>
</div>
