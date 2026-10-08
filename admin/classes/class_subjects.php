<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/class_subjects.php';
require_once __DIR__ . '/../../includes/bulk.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post()) {
    csrf_require();
}

$selectedYearId = (int) ($_GET['year_id'] ?? $_SESSION['class_subjects_year_id'] ?? (active_year()['id'] ?? 0));
if ($selectedYearId <= 0) {
    $selectedYearId = (int) (active_year()['id'] ?? 0);
}
$_SESSION['class_subjects_year_id'] = $selectedYearId;

$years = db()->query('SELECT * FROM academic_years ORDER BY name DESC')->fetchAll();
$classes = db()->query('SELECT * FROM classes ORDER BY sort_order, id')->fetchAll();
$subjects = db()->query('SELECT * FROM subjects WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
$page_title = 'Class subjects';
$page_description = 'Manage which subjects each class uses in the selected year.';
require BASE_PATH . '/components/header.php';
$tabs = ['Years' => 'admin/classes/years.php', 'Classes' => 'admin/classes/index.php', 'Sections' => 'admin/classes/sections.php', 'Subjects' => 'admin/classes/subjects.php', 'Class subjects' => 'admin/classes/class_subjects.php'];
require BASE_PATH . '/components/tabs.php';
?>
<section class="card">
    <h2 class="card-title">Class subjects</h2>
    <form method="get" action="<?= h(url('admin/classes/class_subjects.php')) ?>" class="form-grid">
        <div class="form-field">
            <label for="year-select">Year</label>
            <select id="year-select" name="year_id" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?= h((string) $year['id']) ?>" <?= ((int) $year['id']) === $selectedYearId ? 'selected' : '' ?>><?= h($year['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="tabs">
        <a class="tab is-active" href="#overview">Overview</a>
        <a class="tab" href="#one-class">One class</a>
        <a class="tab" href="#bulk-tools">Bulk tools</a>
    </div>
</section>
<section class="card" id="overview">
    <h3>Overview</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Subject</th><?php foreach ($classes as $class): ?><th><?= h($class['name']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><?= h($subject['name']) ?></td>
                        <?php foreach ($classes as $class): ?>
                            <?php $row = db()->prepare('SELECT * FROM class_subjects WHERE academic_year_id = :year_id AND class_id = :class_id AND subject_id = :subject_id LIMIT 1'); $row->execute([':year_id' => $selectedYearId, ':class_id' => $class['id'], ':subject_id' => $subject['id']]); $item = $row->fetch(); $isChecked = $item && ((int) $item['is_active'] === 1); $type = $item ? effective_type($item) : $subject['type']; $mark = $type === 'grade_only' ? 'G' : ''; if ($item && (int) $item['is_active'] === 0) { $mark = '!'; } ?>
                            <td><label><input type="checkbox" data-row="<?= h((string) $subject['id']) ?>" data-col="<?= h((string) $class['id']) ?>" <?= $isChecked ? 'checked' : '' ?>> <?= h($mark) ?></label></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="preview-actions">
        <a href="#" class="button button-secondary" data-toggle-row="all">Select all</a>
        <a href="#" class="button button-secondary" data-toggle-col="all">Select all columns</a>
    </div>
</section>
<section class="card" id="one-class">
    <h3>One class</h3>
    <form method="post" action="<?= h(url('admin/classes/class_subjects.php')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_one_class">
        <div class="form-field">
            <label for="one-class-select">Class</label>
            <select id="one-class-select" name="class_id">
                <?php foreach ($classes as $class): ?><option value="<?= h((string) $class['id']) ?>"><?= h($class['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button class="button" type="submit">Save</button>
    </form>
</section>
<section class="card" id="bulk-tools">
    <h3>Bulk tools</h3>
    <form method="post" action="<?= h(url('admin/classes/class_subjects.php')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_add">
        <?php require BASE_PATH . '/components/scope_picker.php'; ?>
        <?php foreach ($subjects as $subject): ?>
            <label><input type="checkbox" name="subject_ids[]" value="<?= h((string) $subject['id']) ?>"> <?= h($subject['name']) ?></label>
        <?php endforeach; ?>
        <button class="button" type="submit">Preview</button>
    </form>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
