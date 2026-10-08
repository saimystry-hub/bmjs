<?php
$schoolShortName = $school_short_name ?? setting('school_short_name', 'BmJS');
$currentUser = current_user();
$role = $currentUser['role'] ?? 'teacher';
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');

$items = [];
if ($role === 'super_admin' || $role === 'admin') {
    $items = [
        ['label' => 'Home', 'url' => 'dashboard.php', 'icon' => 'home'],
        ['label' => 'Students', 'url' => 'admin/students/index.php', 'icon' => 'students'],
        ['label' => 'Classes and Subjects', 'url' => 'admin/classes/index.php', 'icon' => 'classes'],
        ['label' => 'Exams and Marks', 'url' => 'admin/exams/structure.php', 'icon' => 'exams'],
        ['label' => 'Teachers', 'url' => 'admin/teachers/assignments.php', 'icon' => 'teachers'],
        ['label' => 'Reports', 'url' => 'reports/generate.php', 'icon' => 'reports'],
        ['label' => 'Year-end', 'url' => 'admin/year_end/promotion.php', 'icon' => 'year'],
    ];
} else {
    $items = [
        ['label' => 'Home', 'url' => 'dashboard.php', 'icon' => 'home'],
        ['label' => 'My classes', 'url' => 'teacher/my_classes.php', 'icon' => 'classes'],
    ];
}

$systemItems = [
    ['label' => 'Users', 'url' => 'admin/system/users.php', 'icon' => 'users'],
    ['label' => 'Grading', 'url' => 'admin/system/grading.php', 'icon' => 'grades'],
    ['label' => 'Settings', 'url' => 'admin/system/settings.php', 'icon' => 'settings'],
    ['label' => 'Report templates', 'url' => 'admin/system/report_templates.php', 'icon' => 'templates'],
    ['label' => 'Backups', 'url' => 'admin/system/backup.php', 'icon' => 'backup'],
    ['label' => 'Audit log', 'url' => 'admin/system/audit_log.php', 'icon' => 'audit'],
];
?>
<aside class="sidebar" aria-label="Main navigation">
    <a class="school-brand" href="<?= h(url('index.php')) ?>">
        <img src="<?= h(asset('img/logo.png')) ?>" alt="Benchmark Junior School logo">
        <span><?= h($schoolShortName) ?></span>
    </a>
    <nav class="sidebar-nav">
        <?php foreach ($items as $item): ?>
            <?php $itemCurrent = basename($item['url']) === $currentPage; ?>
            <a class="nav-link <?= $itemCurrent ? 'is-active' : '' ?>" href="<?= h(url($item['url'])) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <?php if ($item['icon'] === 'home'): ?>
                        <path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>
                    <?php elseif ($item['icon'] === 'students'): ?>
                        <circle cx="9" cy="8" r="3"/><path d="M3 19v-1a6 6 0 0 1 12 0v1M17 7a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v1"/>
                    <?php elseif ($item['icon'] === 'classes'): ?>
                        <path d="M4 5h7v14H4zm9 0h7v14h-7zM7 9h1M7 13h1M16 9h1M16 13h1"/>
                    <?php elseif ($item['icon'] === 'exams'): ?>
                        <path d="M6 2h9l5 5v15H6zM14 2v6h6M9 13h8M9 17h8"/>
                    <?php elseif ($item['icon'] === 'teachers'): ?>
                        <path d="M9 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm-6 9v-1a4 4 0 0 1 8 0v1M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm5 9v-1a4 4 0 0 0-3-3.87"/>
                    <?php elseif ($item['icon'] === 'reports'): ?>
                        <path d="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"/>
                    <?php elseif ($item['icon'] === 'year'): ?>
                        <path d="M4 6h16v12H4zM8 3v3M16 3v3M4 10h16"/>
                    <?php elseif ($item['icon'] === 'users'): ?>
                        <circle cx="8" cy="8" r="3"/><path d="M2 19v-1a6 6 0 0 1 12 0v1M18 8a3 3 0 0 1 0 6M20 19v-1a4 4 0 0 0-4-4"/>
                    <?php elseif ($item['icon'] === 'grades'): ?>
                        <path d="M4 18h16M6 14l3-3 2 2 5-7 3 3"/>
                    <?php elseif ($item['icon'] === 'settings'): ?>
                        <path d="M12 3v2M12 19v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M3 12h2M19 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41M12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10z"/>
                    <?php elseif ($item['icon'] === 'templates'): ?>
                        <path d="M4 5h16v14H4zM8 9h8M8 13h8M8 17h5"/>
                    <?php elseif ($item['icon'] === 'backup'): ?>
                        <path d="M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M12 8v5l3 2"/>
                    <?php else: ?>
                        <path d="M7 5h10v14H7zM10 9h4M10 13h4"/>
                    <?php endif; ?>
                </svg>
                <span><?= h($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($role === 'super_admin'): ?>
            <div class="nav-group-label">System</div>
            <?php foreach ($systemItems as $item): ?>
                <?php $itemCurrent = basename($item['url']) === $currentPage; ?>
                <a class="nav-link <?= $itemCurrent ? 'is-active' : '' ?>" href="<?= h(url($item['url'])) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 3v2M12 19v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M3 12h2M19 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41M12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10z"/>
                    </svg>
                    <span><?= h($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    <div class="sidebar-user">
        <div class="user-card-mini">
            <span class="user-avatar" aria-hidden="true"><?= h(strtoupper(substr(($currentUser['full_name'] ?? 'U'), 0, 1))) ?></span>
            <div>
                <strong><?= h($currentUser['full_name'] ?? 'User') ?></strong>
                <small><?= h(ucfirst(str_replace('_', ' ', $role))) ?></small>
            </div>
        </div>
        <a href="<?= h(url('change_password.php')) ?>">Change password</a>
        <form method="post" action="<?= h(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button class="button button-secondary button-small" type="submit">Log out</button>
        </form>
    </div>
</aside>
