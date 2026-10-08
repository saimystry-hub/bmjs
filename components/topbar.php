<?php
$activeYearRow = isset($active_year_checked) ? $active_year : active_year();
$topbarTitle = $page_title ?? 'Home';
$currentUser = current_user();
$userName = $currentUser['full_name'] ?? 'User';
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<header class="topbar">
    <div class="topbar-heading">
        <h1><?= h($topbarTitle) ?></h1>
        <p><?= $activeYearRow ? h($activeYearRow['name']) : 'No active year' ?></p>
    </div>
    <div class="topbar-actions">
        <button class="button button-secondary button-small theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark theme">
            <span aria-hidden="true">◐</span> Theme
        </button>
        <div class="user-placeholder" aria-label="Signed in user">
            <span class="user-avatar" aria-hidden="true"><?= h($userInitial) ?></span>
            <span><?= h($userName) ?></span>
        </div>
    </div>
</header>
