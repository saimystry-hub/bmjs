<?php
$page_title = $page_title ?? 'BmJS Result Management System';
$page_description = $page_description ?? '';
$show_chrome = $show_chrome ?? true;
$theme = $theme ?? setting('theme', 'light');
?>
<!doctype html>
<html lang="en" data-theme="<?= h($theme === 'dark' ? 'dark' : 'light') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= h($page_description) ?>">
    <title><?= h($page_title) ?> | BmJS</title>
    <link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
</head>
<body>
<?php if ($show_chrome): ?>
<div class="app-shell">
    <?php require BASE_PATH . '/components/sidebar.php'; ?>
    <div class="app-main">
        <?php require BASE_PATH . '/components/topbar.php'; ?>
        <main class="content-area" id="main-content">
            <?php require BASE_PATH . '/components/flash.php'; ?>
<?php else: ?>
<main class="standalone-page" id="main-content">
    <?php require BASE_PATH . '/components/flash.php'; ?>
<?php endif; ?>
