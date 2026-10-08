<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post()) {
    csrf_require();
}

if (is_post() && ($_POST['action'] ?? '') === 'add_subject') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $shortName = trim((string) ($_POST['short_name'] ?? ''));
    $type = in_array($_POST['type'] ?? '', ['graded', 'grade_only'], true) ? $_POST['type'] : 'graded';
    $countsTowardTotal = !empty($_POST['counts_toward_total']) ? 1 : 0;
    $showOnReport = !empty($_POST['show_on_report']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $isActive = !empty($_POST['is_active']) ? 1 : 1;

    if ($name === '' || mb_strlen($name) > 80) {
        flash_add('error', 'Subject name is required and must be 80 characters or fewer.');
    } elseif ($shortName === '' || mb_strlen($shortName) > 20) {
        flash_add('error', 'Short name is required and must be 20 characters or fewer.');
    } else {
        $exists = db()->prepare('SELECT id FROM subjects WHERE name = :name LIMIT 1');
        $exists->execute([':name' => $name]);
        if ($exists->fetch()) {
            flash_add('error', 'That subject name already exists.');
        } else {
            $insert = db()->prepare('INSERT INTO subjects (name, short_name, type, counts_toward_total, show_on_report, sort_order, is_active) VALUES (:name, :short_name, :type, :counts, :show, :sort_order, :is_active)');
            $insert->execute([
                ':name' => $name,
                ':short_name' => $shortName,
                ':type' => $type,
                ':counts' => $type === 'grade_only' ? 0 : $countsTowardTotal,
                ':show' => $showOnReport,
                ':sort_order' => $sortOrder,
                ':is_active' => $isActive,
            ]);
            log_action('subject_created', 'subjects', (int) db()->lastInsertId(), null, ['name' => $name]);
            flash_add('success', 'The subject was added.');
            redirect('admin/classes/subjects.php');
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'update_subject') {
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $shortName = trim((string) ($_POST['short_name'] ?? ''));
    $type = in_array($_POST['type'] ?? '', ['graded', 'grade_only'], true) ? $_POST['type'] : 'graded';
    $countsTowardTotal = !empty($_POST['counts_toward_total']) ? 1 : 0;
    $showOnReport = !empty($_POST['show_on_report']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $isActive = !empty($_POST['is_active']) ? 1 : 0;

    $existing = db()->prepare('SELECT * FROM subjects WHERE id = :id LIMIT 1');
    $existing->execute([':id' => $subjectId]);
    $row = $existing->fetch();
    if (!$row) {
        flash_add('error', 'That subject could not be found.');
    } else {
        $oldType = (string) $row['type'];
        $hasData = db()->prepare('SELECT 1 FROM marks m INNER JOIN assessments a ON a.id = m.assessment_id WHERE m.subject_id = :subject_id LIMIT 1');
        $hasData->execute([':subject_id' => $subjectId]);
        $hasGradeData = db()->prepare('SELECT 1 FROM grade_entries ge INNER JOIN assessments a ON a.id = ge.assessment_id WHERE ge.subject_id = :subject_id LIMIT 1');
        $hasGradeData->execute([':subject_id' => $subjectId]);
        $hasComments = db()->prepare('SELECT 1 FROM comments WHERE subject_id = :subject_id LIMIT 1');
        $hasComments->execute([':subject_id' => $subjectId]);
        $typeChanged = $oldType !== $type;
        if ($typeChanged && (($hasData->fetch() !== false) || ($hasGradeData->fetch() !== false) || ($hasComments->fetch() !== false))) {
            flash_add('warning', 'This subject already has data. The old entries are kept but ignored when the type changes.');
        }

        if ($type === 'grade_only') {
            $countsTowardTotal = 0;
        }

        db()->prepare('UPDATE subjects SET name = :name, short_name = :short_name, type = :type, counts_toward_total = :counts, show_on_report = :show, sort_order = :sort_order, is_active = :is_active WHERE id = :id')->execute([
            ':name' => $name,
            ':short_name' => $shortName,
            ':type' => $type,
            ':counts' => $countsTowardTotal,
            ':show' => $showOnReport,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive,
            ':id' => $subjectId,
        ]);
        log_action('subject_updated', 'subjects', $subjectId, null, ['name' => $name, 'type' => $type]);
        flash_add('success', 'The subject was updated.');
        redirect('admin/classes/subjects.php');
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'toggle_subject') {
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $newState = (int) ($_POST['is_active'] ?? 0);
    $classUsage = db()->prepare('SELECT COUNT(*) FROM class_subjects WHERE subject_id = :subject_id');
    $classUsage->execute([':subject_id' => $subjectId]);
    if ($newState === 0 && (int) $classUsage->fetchColumn() > 0) {
        flash_add('warning', 'This subject is used by one or more classes. It was disabled, but it remains available for re-use later.');
    }
    db()->prepare('UPDATE subjects SET is_active = :is_active WHERE id = :id')->execute([':is_active' => $newState, ':id' => $subjectId]);
    log_action('subject_toggled', 'subjects', $subjectId, null, ['is_active' => $newState]);
    redirect('admin/classes/subjects.php');
}

$subjects = db()->query('SELECT * FROM subjects ORDER BY sort_order, name')->fetchAll();
$page_title = 'Subjects';
$page_description = 'Manage the master subject list used across classes.';
require BASE_PATH . '/components/header.php';
$tabs = ['Years' => 'admin/classes/years.php', 'Classes' => 'admin/classes/index.php', 'Sections' => 'admin/classes/sections.php', 'Subjects' => 'admin/classes/subjects.php', 'Class subjects' => 'admin/classes/class_subjects.php'];
require BASE_PATH . '/components/tabs.php';
?>
<section class="card">
    <h2 class="card-title">Subjects</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Short</th><th>Type</th><th>Counts</th><th>Report</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><?= h($subject['name']) ?></td>
                        <td><?= h($subject['short_name']) ?></td>
                        <td><?= h($subject['type'] === 'grade_only' ? 'Grade only' : 'Marks subject') ?></td>
                        <td><?= h($subject['counts_toward_total'] ? 'Yes' : 'No') ?></td>
                        <td><?= h($subject['show_on_report'] ? 'Yes' : 'No') ?></td>
                        <td><?= h((string) $subject['sort_order']) ?></td>
                        <td><?= $subject['is_active'] ? '<span class="badge grade-a">Active</span>' : '<span class="badge grade-incomplete">Disabled</span>' ?></td>
                        <td>
                            <form method="post" action="<?= h(url('admin/classes/subjects.php')) ?>" class="inline-form" data-confirm="Save subject changes?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_subject">
                                <input type="hidden" name="subject_id" value="<?= h((string) $subject['id']) ?>">
                                <input type="text" name="name" value="<?= h($subject['name']) ?>" required>
                                <input type="text" name="short_name" value="<?= h($subject['short_name']) ?>" required>
                                <select name="type">
                                    <option value="graded" <?= $subject['type'] === 'graded' ? 'selected' : '' ?>>Marks subject</option>
                                    <option value="grade_only" <?= $subject['type'] === 'grade_only' ? 'selected' : '' ?>>Grade only</option>
                                </select>
                                <label><input type="checkbox" name="counts_toward_total" <?= $subject['type'] === 'grade_only' ? 'disabled' : ($subject['counts_toward_total'] ? 'checked' : '') ?>> Counts</label>
                                <label><input type="checkbox" name="show_on_report" <?= $subject['show_on_report'] ? 'checked' : '' ?>> Report</label>
                                <input type="number" name="sort_order" value="<?= h((string) $subject['sort_order']) ?>">
                                <label><input type="checkbox" name="is_active" <?= $subject['is_active'] ? 'checked' : '' ?>> Active</label>
                                <button class="button button-small" type="submit">Save</button>
                            </form>
                            <form method="post" action="<?= h(url('admin/classes/subjects.php')) ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_subject">
                                <input type="hidden" name="subject_id" value="<?= h((string) $subject['id']) ?>">
                                <input type="hidden" name="is_active" value="<?= $subject['is_active'] ? 0 : 1 ?>">
                                <button class="button button-secondary button-small" type="submit"><?= $subject['is_active'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="card">
    <h2 class="card-title">Add subject</h2>
    <form method="post" action="<?= h(url('admin/classes/subjects.php')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_subject">
        <div class="form-field"><label for="subject-name">Name</label><input id="subject-name" name="name" type="text" required></div>
        <div class="form-field"><label for="subject-short">Short name</label><input id="subject-short" name="short_name" type="text" maxlength="20" required></div>
        <div class="form-field"><label for="subject-type">Type</label><select id="subject-type" name="type"><option value="graded">Marks subject</option><option value="grade_only">Grade only</option></select></div>
        <div class="form-field"><label><input type="checkbox" name="counts_toward_total" checked> Counts toward total</label></div>
        <div class="form-field"><label><input type="checkbox" name="show_on_report" checked> Shown on report card</label></div>
        <div class="form-field"><label for="subject-order">Order</label><input id="subject-order" name="sort_order" type="number" value="0"></div>
        <button class="button" type="submit">Add subject</button>
    </form>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
