<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validators.php';
require_once __DIR__ . '/../../includes/audit.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post()) {
    csrf_require();
}

$page_title = 'Academic years';
$page_description = 'Only one year is active. Teachers and most screens work in the active year.';
require BASE_PATH . '/components/header.php';

$years = db()->query('SELECT y.*, gs.name AS scale_name, (SELECT COUNT(*) FROM sections s WHERE s.academic_year_id = y.id) AS section_count FROM academic_years y LEFT JOIN grading_scales gs ON gs.id = y.grading_scale_id ORDER BY y.name DESC')->fetchAll();
$activeYear = active_year();

if (is_post() && ($_POST['action'] ?? '') === 'add_year') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $sourceYearId = (int) ($_POST['copy_from_year'] ?? 0);
    $errors = [];
    if (!valid_year_name($name)) {
        $errors[] = 'Enter a year in the format 2026-27.';
    }
    if (db()->prepare('SELECT id FROM academic_years WHERE name = :name LIMIT 1')->execute([':name' => $name])) {
        $found = db()->prepare('SELECT id FROM academic_years WHERE name = :name LIMIT 1');
        $found->execute([':name' => $name]);
        if ($found->fetch()) {
            $errors[] = 'That academic year already exists.';
        }
    }
    if ($sourceYearId <= 0) {
        $sourceYearId = $activeYear['id'] ?? 0;
    }
    if (!$errors) {
        try {
            db()->beginTransaction();
            $sourceScaleId = null;
            if ($sourceYearId > 0) {
                $sourceScaleRow = db()->prepare('SELECT grading_scale_id FROM academic_years WHERE id = :id LIMIT 1');
                $sourceScaleRow->execute([':id' => $sourceYearId]);
                $sourceScaleId = (int) ($sourceScaleRow->fetchColumn() ?: 0);
            }
            if ($sourceScaleId <= 0) {
                $sourceScaleId = (int) ($activeYear['grading_scale_id'] ?? 0);
            }
            $scaleInsert = db()->prepare('INSERT INTO grading_scales (name, created_at) VALUES (:name, NOW())');
            $scaleInsert->execute([':name' => 'Scale for ' . $name]);
            $newScaleId = (int) db()->lastInsertId();
            $bandRows = db()->prepare('SELECT grade_label, min_percent, sort_order FROM grade_bands WHERE scale_id = :scale_id ORDER BY sort_order, id');
            $bandRows->execute([':scale_id' => $sourceScaleId]);
            $bandInsert = db()->prepare('INSERT INTO grade_bands (scale_id, grade_label, min_percent, sort_order) VALUES (:scale_id, :grade_label, :min_percent, :sort_order)');
            foreach ($bandRows->fetchAll() as $band) {
                $bandInsert->execute([
                    ':scale_id' => $newScaleId,
                    ':grade_label' => $band['grade_label'],
                    ':min_percent' => $band['min_percent'],
                    ':sort_order' => $band['sort_order'],
                ]);
            }
            $yearInsert = db()->prepare('INSERT INTO academic_years (name, is_active, grading_scale_id, created_at) VALUES (:name, 0, :grading_scale_id, NOW())');
            $yearInsert->execute([':name' => $name, ':grading_scale_id' => $newScaleId]);
            $yearId = (int) db()->lastInsertId();
            db()->commit();
            log_action('academic_year_created', 'academic_years', $yearId, null, ['name' => $name, 'source_year_id' => $sourceYearId]);
            flash_add('success', 'Academic year added.');
            redirect('admin/classes/years.php');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Add academic year failed: ' . $exception->getMessage());
            $errors[] = 'That academic year could not be created.';
        }
    }
    if (!empty($errors)) {
        foreach ($errors as $error) {
            flash_add('error', $error);
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'rename_year') {
    $id = (int) ($_POST['year_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($id <= 0 || !valid_year_name($name)) {
        flash_add('error', 'Enter a year in the format 2026-27.');
    } else {
        $exists = db()->prepare('SELECT id FROM academic_years WHERE name = :name AND id <> :id LIMIT 1');
        $exists->execute([':name' => $name, ':id' => $id]);
        if ($exists->fetch()) {
            flash_add('error', 'That academic year already exists.');
        } else {
            try {
                db()->prepare('UPDATE academic_years SET name = :name WHERE id = :id')->execute([':name' => $name, ':id' => $id]);
                log_action('academic_year_renamed', 'academic_years', $id, null, ['name' => $name]);
                flash_add('success', 'Academic year renamed.');
                redirect('admin/classes/years.php');
            } catch (Throwable $exception) {
                error_log('Rename academic year failed: ' . $exception->getMessage());
                flash_add('error', 'That academic year could not be renamed.');
            }
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'set_active_year') {
    $id = (int) ($_POST['year_id'] ?? 0);
    if (!has_role(['super_admin'])) {
        flash_add('error', 'Only the super administrator can change the active year.');
    } else {
        try {
            db()->beginTransaction();
            db()->prepare('UPDATE academic_years SET is_active = 0 WHERE is_active = 1')->execute();
            db()->prepare('UPDATE academic_years SET is_active = 1 WHERE id = :id')->execute([':id' => $id]);
            db()->commit();
            log_action('academic_year_activated', 'academic_years', $id, null, ['year_id' => $id]);
            flash_add('success', 'The active academic year was updated.');
            redirect('admin/classes/years.php');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Set active year failed: ' . $exception->getMessage());
            flash_add('error', 'The active year could not be changed.');
        }
    }
}

$tabs = ['Years' => 'admin/classes/years.php', 'Classes' => 'admin/classes/index.php', 'Sections' => 'admin/classes/sections.php', 'Subjects' => 'admin/classes/subjects.php', 'Class subjects' => 'admin/classes/class_subjects.php'];
require BASE_PATH . '/components/tabs.php';
?>
<section class="card">
    <h2 class="card-title">Academic years</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Active</th><th>Grading scale</th><th>Sections</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($years as $year): ?>
                    <tr>
                        <td><?= h($year['name']) ?></td>
                        <td><?= (int) $year['is_active'] === 1 ? '<span class="badge grade-a">Active</span>' : '<span class="badge grade-incomplete">Inactive</span>' ?></td>
                        <td><?= h($year['scale_name'] ?? 'Unknown') ?></td>
                        <td><?= h((string) ($year['section_count'] ?? 0)) ?></td>
                        <td>
                            <form method="post" action="<?= h(url('admin/classes/years.php')) ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rename_year">
                                <input type="hidden" name="year_id" value="<?= h((string) $year['id']) ?>">
                                <input type="text" name="name" value="<?= h($year['name']) ?>" required>
                                <button class="button button-small" type="submit">Rename</button>
                            </form>
                            <?php if (has_role(['super_admin'])): ?>
                                <form method="post" action="<?= h(url('admin/classes/years.php')) ?>" style="display:inline;" data-confirm="Set this year as active?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_active_year">
                                    <input type="hidden" name="year_id" value="<?= h((string) $year['id']) ?>">
                                    <button class="button button-secondary button-small" type="submit">Set as active</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="card">
    <h2 class="card-title">Add academic year</h2>
    <form method="post" action="<?= h(url('admin/classes/years.php')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_year">
        <div class="form-field">
            <label for="year-name">Year name</label>
            <input id="year-name" name="name" type="text" required>
        </div>
        <div class="form-field">
            <label for="copy-from-year">Copy the grading scale from</label>
            <select id="copy-from-year" name="copy_from_year">
                <?php foreach ($years as $year): ?>
                    <option value="<?= h((string) $year['id']) ?>" <?= ($activeYear && (int) $year['id'] === (int) $activeYear['id']) ? 'selected' : '' ?>><?= h($year['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="button" type="submit">Add academic year</button>
    </form>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
