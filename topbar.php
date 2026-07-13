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
$topbarIsMainPage = $topbarCurrentPage === 'main.php';
?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<div class="user-ribbon" x-data="{ open: false, mainMenuOpen: '' }" @keydown.escape.window="open = false; mainMenuOpen = ''">
    <div class="topbar-left">
        <a href="main.php" class="brand brand-home">Mdash</a>
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
        <?php if ($topbarIsMainPage): ?>
            <nav class="topbar-main-nav" aria-label="Main quick menus">
                <div class="topbar-main-nav-item">
                    <button type="button" class="topbar-main-nav-btn" @click="mainMenuOpen = mainMenuOpen === 'config' ? '' : 'config'" :aria-expanded="(mainMenuOpen === 'config').toString()" aria-controls="topbarMainConfig">Config</button>
                    <div id="topbarMainConfig" class="topbar-main-nav-panel" x-cloak x-show="mainMenuOpen === 'config'" x-transition.origin.top.right @click.outside="mainMenuOpen = ''">
                        <a href="ai_db.php" @click="mainMenuOpen = ''">AI</a>
                        <a href="templates.php" @click="mainMenuOpen = ''">Template</a>
                        <a href="makeup.php" @click="mainMenuOpen = ''">Markup</a>
                    </div>
                </div>
                <div class="topbar-main-nav-item">
                    <button type="button" class="topbar-main-nav-btn" @click="mainMenuOpen = mainMenuOpen === 'data' ? '' : 'data'" :aria-expanded="(mainMenuOpen === 'data').toString()" aria-controls="topbarMainData">Data</button>
                    <div id="topbarMainData" class="topbar-main-nav-panel" x-cloak x-show="mainMenuOpen === 'data'" x-transition.origin.top.right @click.outside="mainMenuOpen = ''">
                        <a href="upload.php" @click="mainMenuOpen = ''">Upload</a>
                        <a href="database_list.php" @click="mainMenuOpen = ''">Data Pool</a>
                    </div>
                </div>
                <div class="topbar-main-nav-item">
                    <button type="button" class="topbar-main-nav-btn" @click="mainMenuOpen = mainMenuOpen === 'dashboards' ? '' : 'dashboards'" :aria-expanded="(mainMenuOpen === 'dashboards').toString()" aria-controls="topbarMainDashboards">Dashboards</button>
                    <div id="topbarMainDashboards" class="topbar-main-nav-panel" x-cloak x-show="mainMenuOpen === 'dashboards'" x-transition.origin.top.right @click.outside="mainMenuOpen = ''">
                        <a href="dashboard_builder.php" @click="mainMenuOpen = ''">Generate</a>
                        <a href="dashboards.php" @click="mainMenuOpen = ''">Dash Pool</a>
                        <a href="results.php" @click="mainMenuOpen = ''">Dashboard</a>
                    </div>
                </div>
                <div class="topbar-main-nav-item">
                    <button type="button" class="topbar-main-nav-btn" @click="mainMenuOpen = mainMenuOpen === 'favorites' ? '' : 'favorites'" :aria-expanded="(mainMenuOpen === 'favorites').toString()" aria-controls="topbarMainFavorites">Preferiti</button>
                    <div id="topbarMainFavorites" class="topbar-main-nav-panel" x-cloak x-show="mainMenuOpen === 'favorites'" x-transition.origin.top.right @click.outside="mainMenuOpen = ''">
                        <a href="templates.php?favorites=1" @click="mainMenuOpen = ''">Template</a>
                        <a href="makeup.php?favorites=1" @click="mainMenuOpen = ''">Markup</a>
                        <a href="database_list.php?favorites=1" @click="mainMenuOpen = ''">Data</a>
                        <a href="dashboards.php?favorites=1" @click="mainMenuOpen = ''">Dashboard</a>
                        <a href="results.php?favorites=1" @click="mainMenuOpen = ''">Results</a>
                    </div>
                </div>
            </nav>
        <?php endif; ?>

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
