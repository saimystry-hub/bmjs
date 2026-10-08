<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validators.php';
require_once __DIR__ . '/../../includes/bulk.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post() && ($_POST['action'] ?? '') === 'select_year') {
    csrf_require();
    $_SESSION['section_year_id'] = (int) ($_POST['year_id'] ?? 0);
    redirect('admin/classes/sections.php?year_id=' . (int) $_SESSION['section_year_id']);
}

$selectedYearId = (int) ($_GET['year_id'] ?? ($_SESSION['section_year_id'] ?? (active_year()['id'] ?? 0)));
if ($selectedYearId <= 0) {
    $selectedYearId = (int) (active_year()['id'] ?? 0);
}
$_SESSION['section_year_id'] = $selectedYearId;

if (is_post() && ($_POST['action'] ?? '') === 'preview_sections') {
    csrf_require();
    $classIds = array_map('intval', $_POST['class_ids'] ?? []);
    $parse = parse_section_names((string) ($_POST['section_names'] ?? ''));
    if ($parse['names'] === []) {
        flash_add('error', 'Add at least one valid section name.');
    } else {
        $preview = ['year_id' => $selectedYearId, 'class_ids' => $classIds, 'names' => $parse['names'], 'errors' => $parse['errors']];
        $token = bulk_preview_save('create_sections', $preview);
        redirect('admin/classes/sections.php?year_id=' . $selectedYearId . '&token=' . rawurlencode($token));
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'confirm_sections') {
    csrf_require();
    $token = (string) ($_POST['token'] ?? '');
    $preview = bulk_preview_load($token, 'create_sections');
    if ($preview === null) {
        $page_title = 'Already applied';
        $page_description = 'This preview has already been used.';
        require BASE_PATH . '/components/header.php';
        echo '<section class="card"><h2 class="card-title">This was already applied</h2><p class="muted">That preview has already been used. Nothing was changed.</p><p><a class="button" href="' . h(url('admin/classes/sections.php')) . '">Back to sections</a></p></section>';
        require BASE_PATH . '/components/footer.php';
        exit;
    }

    $createdCount = 0;
    $created = [];
    db()->beginTransaction();
    foreach (($preview['class_ids'] ?? []) as $classId) {
        $classId = (int) $classId;
        foreach (($preview['names'] ?? []) as $name) {
            $exists = db()->prepare('SELECT id FROM sections WHERE academic_year_id = :year_id AND class_id = :class_id AND name = :name LIMIT 1');
            $exists->execute([':year_id' => $selectedYearId, ':class_id' => $classId, ':name' => $name]);
            if ($exists->fetch()) {
                continue;
            }
            db()->prepare('INSERT INTO sections (academic_year_id, class_id, name, hrt_user_id) VALUES (:year_id, :class_id, :name, NULL)')->execute([
                ':year_id' => $selectedYearId,
                ':class_id' => $classId,
                ':name' => $name,
            ]);
            $created[] = ['class_id' => $classId, 'name' => $name];
            $createdCount++;
        }
    }
    db()->commit();
    bulk_preview_clear($token);
    log_action('sections_created', 'sections', null, null, ['year_id' => $selectedYearId, 'count' => $createdCount, 'created' => $created]);
    flash_add('success', 'The missing sections were created.');
    redirect('admin/classes/sections.php?year_id=' . $selectedYearId);
}

$years = db()->query('SELECT * FROM academic_years ORDER BY name DESC')->fetchAll();
$selectedYear = null;
foreach ($years as $year) {
    if ((int) $year['id'] === $selectedYearId) {
        $selectedYear = $year;
    }
}
if ($selectedYear === null && !empty($years)) {
    $selectedYear = $years[0];
    $selectedYearId = (int) $selectedYear['id'];
}

$sections = db()->prepare('SELECT s.*, c.name AS class_name, u.full_name AS teacher_name, (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id AND st.status = "active") AS student_count FROM sections s INNER JOIN classes c ON c.id = s.class_id LEFT JOIN users u ON u.id = s.hrt_user_id WHERE s.academic_year_id = :year_id ORDER BY c.sort_order, s.name');
$sections->execute([':year_id' => $selectedYearId]);
$sectionRows = $sections->fetchAll();

$page_title = 'Sections';
$page_description = 'Create and manage class sections for the school year.';
require BASE_PATH . '/components/header.php';
$tabs = ['Years' => 'admin/classes/years.php', 'Classes' => 'admin/classes/index.php', 'Sections' => 'admin/classes/sections.php', 'Subjects' => 'admin/classes/subjects.php', 'Class subjects' => 'admin/classes/class_subjects.php'];
require BASE_PATH . '/components/tabs.php';
?>
<section class="card">
    <h2 class="card-title">Sections</h2>
    <form method="post" action="<?= h(url('admin/classes/sections.php')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="select_year">
        <div class="form-field">
            <label for="section-year">Year</label>
            <select id="section-year" name="year_id" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?= h((string) $year['id']) ?>" <?= ((int) $year['id']) === $selectedYearId ? 'selected' : '' ?>><?= h($year['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Class</th><th>Name</th><th>Class teacher</th><th>Students</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($sectionRows as $section): ?>
                    <tr>
                        <td><?= h($section['class_name']) ?></td>
                        <td><?= h($section['name']) ?></td>
                        <td><?= h($section['teacher_name'] ?? 'No HRT yet') ?></td>
                        <td><?= h((string) $section['student_count']) ?></td>
                        <td><a class="button button-small button-secondary" href="<?= h(url('admin/classes/sections.php?year_id=' . $selectedYearId)) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="card">
    <h2 class="card-title">Create sections for several classes</h2>
    <form method="post" action="<?= h(url('admin/classes/sections.php?year_id=' . $selectedYearId)) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview_sections">
        <div class="form-field">
            <label>Classes</label>
            <?php require BASE_PATH . '/components/scope_picker.php'; ?>
        </div>
        <div class="form-field">
            <label for="section-names">Section names</label>
            <textarea id="section-names" name="section_names" rows="4" placeholder="Mars, Jupiter"></textarea>
        </div>
        <button class="button" type="submit">Preview</button>
    </form>
</section>
<?php if (isset($_GET['token'])): ?>
    <?php $preview = bulk_preview_load((string) $_GET['token'], 'create_sections'); ?>
    <?php if ($preview !== null): ?>
        <section class="card">
            <h2 class="card-title">Section preview</h2>
            <form method="post" action="<?= h(url('admin/classes/sections.php?year_id=' . $selectedYearId)) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_sections">
                <input type="hidden" name="token" value="<?= h((string) $_GET['token']) ?>">
                <ul>
                    <?php foreach (($preview['class_ids'] ?? []) as $classId): ?>
                        <?php $className = db()->prepare('SELECT name FROM classes WHERE id = :id LIMIT 1'); $className->execute([':id' => $classId]); $classRow = $className->fetch(); ?>
                        <li><strong><?= h($classRow['name'] ?? 'Class') ?>:</strong>
                            <?php foreach (($preview['names'] ?? []) as $name): ?>
                                <span><?= h($name) ?></span>
                            <?php endforeach; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <button class="button" type="submit">Confirm</button>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require BASE_PATH . '/components/footer.php'; ?>
