<?php
require_once __DIR__ . '/includes/bootstrap.php';

$page_title = 'Installation check';
$page_description = 'Check that the local BmJS installation is ready.';
$pdo = null;
$databaseError = null;
$checks = [];
$active_year = null;
$active_year_checked = true;
$school_short_name = 'BmJS';
$theme = 'light';

try {
    $pdo = db();
} catch (RuntimeException $exception) {
    $databaseError = $exception->getMessage();
}

$checks[] = [
    'label' => 'Database connected',
    'ok' => $pdo instanceof PDO,
    'value' => $pdo instanceof PDO ? 'Connected' : 'Not connected',
    'fix' => $pdo instanceof PDO ? 'The bmjs database connection is ready.' : ($databaseError ?? 'Start MySQL and check config/config.php.'),
];

if ($pdo instanceof PDO) {
    try {
        $tableCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchColumn();
        $checks[] = [
            'label' => 'Database tables',
            'ok' => $tableCount === 30,
            'value' => $tableCount . ' (expected 30)',
            'fix' => $tableCount === 30 ? 'The expected table count is present.' : 'Import database/schema.sql in phpMyAdmin; do not re-create existing data.',
        ];
    } catch (PDOException $exception) {
        error_log('Install check could not count database tables: ' . $exception->getMessage());
        $checks[] = ['label' => 'Database tables', 'ok' => false, 'value' => 'Unavailable', 'fix' => 'Check that the database user can read information_schema.'];
    }

    foreach (['subjects' => 14, 'structure_presets' => 3, 'grade_bands' => 6, 'classes' => 5] as $table => $expected) {
        try {
            $actual = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            $checks[] = [
                'label' => $table . ' rows',
                'ok' => $actual === $expected,
                'value' => $actual . ' (expected ' . $expected . ')',
                'fix' => $actual === $expected ? 'The expected starter rows are present.' : 'Import database/seed.sql in phpMyAdmin, or confirm the starter data has not been changed.',
            ];
        } catch (PDOException $exception) {
            error_log('Install check could not count ' . $table . ': ' . $exception->getMessage());
            $checks[] = ['label' => $table . ' rows', 'ok' => false, 'value' => 'Unavailable', 'fix' => 'Import database/schema.sql and database/seed.sql in phpMyAdmin.'];
        }
    }
    try {
        $active_year = active_year();
    } catch (PDOException $exception) {
        error_log('Install check could not read the active academic year: ' . $exception->getMessage());
    }
    try {
        $school_short_name = (string) setting('school_short_name', 'BmJS');
        $theme = (string) setting('theme', 'light');
    } catch (PDOException $exception) {
        error_log('Install check could not read saved display settings: ' . $exception->getMessage());
    }
} else {
    $checks[] = ['label' => 'Database tables', 'ok' => false, 'value' => 'Unavailable (expected 30)', 'fix' => 'Start MySQL and check config/config.php.'];
    foreach (['subjects' => 14, 'structure_presets' => 3, 'grade_bands' => 6, 'classes' => 5] as $table => $expected) {
        $checks[] = ['label' => $table . ' rows', 'ok' => false, 'value' => 'Unavailable (expected ' . $expected . ')', 'fix' => 'Start MySQL and check config/config.php.'];
    }
}

$checks[] = [
    'label' => 'Active academic year',
    'ok' => $active_year !== null,
    'value' => $active_year ? $active_year['name'] : 'No active year',
    'fix' => $active_year ? 'An active academic year is configured.' : 'Mark the current academic year active in the existing database.',
];
$checks[] = [
    'label' => 'PHP version',
    'ok' => PHP_VERSION_ID >= 80000,
    'value' => PHP_VERSION,
    'fix' => PHP_VERSION_ID >= 80000 ? 'PHP 8 or newer is available.' : 'Use a XAMPP release that includes PHP 8 or newer.',
];
foreach ([
    'pdo_mysql' => 'Enable the pdo_mysql extension in the active php.ini and restart Apache.',
    'mbstring' => 'Enable the mbstring extension in the active php.ini and restart Apache.',
] as $extension => $fix) {
    $loaded = extension_loaded($extension);
    $checks[] = ['label' => $extension . ' extension', 'ok' => $loaded, 'value' => $loaded ? 'Loaded' : 'Missing', 'fix' => $loaded ? 'This PHP extension is available.' : $fix];
}
foreach ([
    'database/backups' => 'Allow the Apache/PHP user to write to database/backups.',
    'uploads' => 'Allow the Apache/PHP user to write to uploads.',
] as $relativePath => $fix) {
    $writable = is_writable(BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    $checks[] = ['label' => $relativePath . ' writable', 'ok' => $writable, 'value' => $writable ? 'Writable' : 'Not writable', 'fix' => $writable ? 'The directory is writable.' : $fix];
}

require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <h2 class="card-title">Installation checks</h2>
    <p class="muted">The logo below uses the existing PNG file.</p>
    <img class="install-logo" src="<?= h(asset('img/logo.png')) ?>" alt="Benchmark Junior School logo">
    <ul class="check-list">
        <?php foreach ($checks as $check): ?>
            <li class="check-item">
                <strong><?= h($check['label']) ?></strong>
                <span class="check-state <?= $check['ok'] ? 'check-ok' : 'check-fail' ?>"><?= $check['ok'] ? '✓' : '✗' ?> <?= h($check['value']) ?></span>
                <span class="muted"><?= h($check['fix']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="card">
    <h2 class="card-title">Design preview</h2>
    <div class="preview-actions">
        <button class="button" type="button">Primary button</button>
        <button class="button button-secondary" type="button">Secondary button</button>
        <button class="button button-danger" type="button">Danger button</button>
        <button class="button button-small" type="button">Small</button>
        <button class="button button-large" type="button">Large</button>
        <button class="button button-secondary" type="button" disabled>Disabled</button>
        <button class="button button-secondary" type="button" data-preview-toast>Show sample toast</button>
    </div>
    <p>
        <span class="badge grade-a-star">A*</span>
        <span class="badge grade-a">A</span>
        <span class="badge grade-b">B</span>
        <span class="badge grade-c">C</span>
        <span class="badge grade-d">D</span>
        <span class="badge grade-u">U</span>
        <span class="badge grade-incomplete">Incomplete</span>
    </p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student</th><th>Class</th><th>Result</th></tr></thead>
            <tbody><tr><td>Sample Student</td><td>Class 1</td><td><span class="badge grade-a">A</span></td></tr><tr><td>Another Student</td><td>Class 2</td><td><span class="badge grade-c">C</span></td></tr></tbody>
        </table>
    </div>
    <p class="help-text">Progress: 14 of 22 complete</p>
    <div class="progress" role="progressbar" aria-label="Sample task progress" aria-valuenow="64" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:64%"></div></div>
    <div class="alert alert-warning">Sample alert: Please check the missing marks before submitting.</div>
    <form class="form-grid" action="#" method="get">
        <div class="form-field">
            <label for="preview-name">Student name</label>
            <input id="preview-name" name="preview-name" value="Sample Student">
            <span class="error-text">Please enter a valid student name.</span>
            <span class="help-text">This is an example of inline help text.</span>
        </div>
    </form>
    <div class="empty-state"><strong>Empty-state preview</strong><p class="muted">Nothing is listed here yet.</p></div>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
