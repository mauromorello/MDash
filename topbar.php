<?php
$topbarUser = $user ?? ($me ?? null);
if (!is_array($topbarUser)) {
    $topbarUser = [];
}

$topbarUsername = htmlspecialchars((string)($topbarUser['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
$topbarLoginTime = htmlspecialchars((string)($topbarUser['login_time'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8');
$topbarIsAdmin = !empty($topbarUser['is_admin']);
?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<div class="user-ribbon" x-data="{ open: false }" @keydown.escape.window="open = false">
    <div class="topbar-left">
        <a href="main.php" class="brand brand-home">Mdash</a>
        <div class="info">User: <?php echo $topbarUsername; ?> | Login: <?php echo $topbarLoginTime; ?></div>
    </div>
    <div class="topbar-right">
        <button type="button" id="topbarMenuBtn" class="topbar-menu-btn" @click="open = !open" :aria-expanded="open.toString()" aria-controls="topbarMenu" aria-label="Open navigation menu">
            <span></span>
            <span></span>
            <span></span>
        </button>
        <div id="topbarMenu" class="topbar-dropdown" x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false">
            <a href="main.php" @click="open = false">Home</a>
            <a href="ai_db.php" @click="open = false">AI Profiles</a>
            <a href="templates.php" @click="open = false">Templates</a>
            <a href="makeup.php" @click="open = false">Markup</a>
            <a href="upload.php" @click="open = false">Upload Data</a>
            <a href="database_list.php" @click="open = false">Data Pool</a>
            <a href="dashboard_builder.php" @click="open = false">Generate Dashboard</a>
            <a href="dashboards.php" @click="open = false">Dashboard Pool</a>
            <a href="results.php" @click="open = false">Results Hub</a>
            <?php if ($topbarIsAdmin): ?>
                <a href="admin.php" @click="open = false">Admin Console</a>
            <?php endif; ?>
            <button id="logoutBtn" type="button" class="topbar-logout-btn">Logout</button>
        </div>
    </div>
</div>
