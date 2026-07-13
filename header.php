<?php
$pageTitle = $pageTitle ?? 'Mdash';
$pageHtmlLang = $pageHtmlLang ?? 'en';
$pageHeadExtra = $pageHeadExtra ?? '';
$pageCssVersion = $pageCssVersion ?? (string)@filemtime(__DIR__ . '/assets/app.css');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars((string)$pageHtmlLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="stylesheet" href="assets/app.css?v=<?php echo htmlspecialchars((string)$pageCssVersion, ENT_QUOTES, 'UTF-8'); ?>">
<?php
if (is_array($pageHeadExtra)) {
    foreach ($pageHeadExtra as $headFragment) {
        echo $headFragment . "\n";
    }
} elseif ($pageHeadExtra !== '') {
    echo $pageHeadExtra . "\n";
}
?>
<link rel="apple-touch-icon" sizes="57x57" href="/mdash/icons/apple-icon-57x57.png">
<link rel="apple-touch-icon" sizes="60x60" href="/mdash/icons/apple-icon-60x60.png">
<link rel="apple-touch-icon" sizes="72x72" href="/mdash/icons/apple-icon-72x72.png">
<link rel="apple-touch-icon" sizes="76x76" href="/mdash/icons/apple-icon-76x76.png">
<link rel="apple-touch-icon" sizes="114x114" href="/mdash/icons/apple-icon-114x114.png">
<link rel="apple-touch-icon" sizes="120x120" href="/mdash/icons/apple-icon-120x120.png">
<link rel="apple-touch-icon" sizes="144x144" href="/mdash/icons/apple-icon-144x144.png">
<link rel="apple-touch-icon" sizes="152x152" href="/mdash/icons/apple-icon-152x152.png">
<link rel="apple-touch-icon" sizes="180x180" href="/mdash/icons/apple-icon-180x180.png">
<link rel="icon" type="image/png" sizes="192x192"  href="/mdash/icons/android-icon-192x192.png">
<link rel="icon" type="image/png" sizes="32x32" href="/mdash/icons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="96x96" href="/mdash/icons/favicon-96x96.png">
<link rel="icon" type="image/png" sizes="16x16" href="/mdash/icons/favicon-16x16.png">
<link rel="manifest" href="/mdash/icons/manifest.json">
<meta name="msapplication-TileColor" content="#ffffff">
<meta name="msapplication-TileImage" content="/mdash/icons/ms-icon-144x144.png">
<meta name="theme-color" content="#ffffff">
</head>