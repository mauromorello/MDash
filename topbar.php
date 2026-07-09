<?php
$topbarUser = $user ?? ($me ?? null);
if (!is_array($topbarUser)) {
    $topbarUser = [];
}

$topbarUsername = htmlspecialchars((string)($topbarUser['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
$topbarLoginTime = htmlspecialchars((string)($topbarUser['login_time'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8');
$topbarIsAdmin = !empty($topbarUser['is_admin']);
?>
<div class="user-ribbon">
    <div class="topbar-left">
        <a href="main.php" class="brand brand-home">Mdash</a>
        <div class="info">User: <?php echo $topbarUsername; ?> | Login: <?php echo $topbarLoginTime; ?></div>
    </div>
    <div class="topbar-right">
        <button type="button" id="topbarMenuBtn" class="topbar-menu-btn" aria-expanded="false" aria-controls="topbarMenu" aria-label="Open navigation menu">
            <span></span>
            <span></span>
            <span></span>
        </button>
        <div id="topbarMenu" class="topbar-dropdown" hidden>
            <a href="main.php">Home</a>
            <a href="ai_db.php">AI Profiles</a>
            <a href="templates.php">Templates</a>
            <a href="makeup.php">Markup</a>
            <a href="upload.php">Upload Data</a>
            <a href="database_list.php">Data Pool</a>
            <a href="dashboard_builder.php">Generate Dashboard</a>
            <a href="dashboards.php">Dashboard Pool</a>
            <a href="results.php">Results Hub</a>
            <?php if ($topbarIsAdmin): ?>
                <a href="admin.php">Admin Console</a>
            <?php endif; ?>
            <button id="logoutBtn" type="button" class="topbar-logout-btn">Logout</button>
        </div>
    </div>
</div>
<script>
(function () {
    const menuBtn = document.getElementById('topbarMenuBtn');
    const menu = document.getElementById('topbarMenu');
    if (!menuBtn || !menu) {
        return;
    }

    function closeMenu() {
        menu.hidden = true;
        menuBtn.setAttribute('aria-expanded', 'false');
    }

    menuBtn.addEventListener('click', function (event) {
        event.stopPropagation();
        const opening = menu.hidden;
        menu.hidden = !menu.hidden;
        menuBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (menu.hidden) {
            return;
        }
        if (!menu.contains(event.target) && event.target !== menuBtn) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
})();
</script>
