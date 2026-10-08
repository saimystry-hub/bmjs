<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/teachers.php';
require_once __DIR__ . '/../../components/empty_state.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post()) {
    csrf_require();
}

if (is_post() && ($_POST['action'] ?? '') === 'save_assignments') {
    $yearId = (int) ($_POST['year_id'] ?? 0);
    $classId = (int) ($_POST['class_id'] ?? 0);
    $sectionsStatement = db()->prepare('SELECT * FROM sections WHERE academic_year_id = :year_id AND class_id = :class_id ORDER BY name');
    $sectionsStatement->execute([':year_id' => $yearId, ':class_id' => $classId]);
    $sections = $sectionsStatement->fetchAll();
    $subjectStatement = db()->prepare('SELECT cs.subject_id, s.name, s.short_name FROM class_subjects cs INNER JOIN subjects s ON s.id = cs.subject_id WHERE cs.academic_year_id = :year_id AND cs.class_id = :class_id AND cs.is_active = 1 ORDER BY s.sort_order, s.name');
    $subjectStatement->execute([':year_id' => $yearId, ':class_id' => $classId]);
    $subjects = $subjectStatement->fetchAll();
    $teachers = get_teachers();
    $teacherIds = array_map(static fn ($teacher): int => (int) $teacher['id'], $teachers);
    $subjectIds = array_map(static fn ($subject): int => (int) $subject['subject_id'], $subjects);

    $updated = 0;
    foreach ($sections as $section) {
        $desired = [];
        foreach ($subjects as $subject) {
            $subjectId = (int) $subject['subject_id'];
            $teacherId = $_POST['assignments'][$section['id']][$subjectId] ?? null;
            $desired[$subjectId] = $teacherId === '' || $teacherId === null ? null : (int) $teacherId;
        }
        $result = save_section_assignments((int) $section['id'], $desired, $subjectIds, $teacherIds);
        if (($result['add'] ?? 0) + ($result['change'] ?? 0) + ($result['clear'] ?? 0) > 0) {
            $updated++;
        }
    }

    flash_add('success', 'Updated ' . $updated . ' section assignment set(s).');
    redirect('admin/teachers/assignments.php?year_id=' . $yearId . '&class_id=' . $classId);
}

$selectedYearId = (int) ($_GET['year_id'] ?? (active_year()['id'] ?? 0));
$selectedClassId = (int) ($_GET['class_id'] ?? 0);
$years = db()->query('SELECT * FROM academic_years ORDER BY name DESC')->fetchAll();
$classes = db()->query('SELECT * FROM classes ORDER BY sort_order, id')->fetchAll();
if ($selectedYearId <= 0 && count($years) > 0) {
    $selectedYearId = (int) $years[0]['id'];
}
if ($selectedClassId <= 0 && count($classes) > 0) {
    $selectedClassId = (int) $classes[0]['id'];
}

$teachers = get_teachers();
$sections = [];
$subjects = [];
if ($selectedYearId > 0 && $selectedClassId > 0) {
    $sectionStatement = db()->prepare('SELECT * FROM sections WHERE academic_year_id = :year_id AND class_id = :class_id ORDER BY name');
    $sectionStatement->execute([':year_id' => $selectedYearId, ':class_id' => $selectedClassId]);
    $sections = $sectionStatement->fetchAll();

    $subjectStatement = db()->prepare('SELECT cs.subject_id, s.name, s.short_name FROM class_subjects cs INNER JOIN subjects s ON s.id = cs.subject_id WHERE cs.academic_year_id = :year_id AND cs.class_id = :class_id AND cs.is_active = 1 ORDER BY s.sort_order, s.name');
    $subjectStatement->execute([':year_id' => $selectedYearId, ':class_id' => $selectedClassId]);
    $subjects = $subjectStatement->fetchAll();
}

$page_title = 'Teacher assignments';
$page_description = 'Assign teachers to the subjects taught in each section.';
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <h2 class="card-title">Teacher assignments</h2>
    <form method="get" action="<?= h(url('admin/teachers/assignments.php')) ?>" class="form-grid">
        <div class="form-field">
            <label for="year_id">Academic year</label>
            <select id="year_id" name="year_id" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?= h((string) $year['id']) ?>" <?= ((int) $year['id']) === $selectedYearId ? 'selected' : '' ?>><?= h($year['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="class_id">Class</label>
            <select id="class_id" name="class_id" onchange="this.form.submit()">
                <?php foreach ($classes as $class): ?>
                    <option value="<?= h((string) $class['id']) ?>" <?= ((int) $class['id']) === $selectedClassId ? 'selected' : '' ?>><?= h($class['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</section>
<?php if ($selectedYearId <= 0 || $selectedClassId <= 0 || $sections === [] || $subjects === []): ?>
    <?php render_empty_state('No assignments to manage yet', 'Choose a year and class, then add class-subject coverage and section records before assigning teachers.'); ?>
<?php else: ?>
    <section class="card">
        <form method="post" action="<?= h(url('admin/teachers/assignments.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_assignments">
            <input type="hidden" name="year_id" value="<?= h((string) $selectedYearId) ?>">
            <input type="hidden" name="class_id" value="<?= h((string) $selectedClassId) ?>">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Section</th>
                            <?php foreach ($subjects as $subject): ?>
                                <th><?= h($subject['name']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections as $section): ?>
                            <tr>
                                <td><?= h($section['name']) ?></td>
                                <?php foreach ($subjects as $subject): ?>
                                    <?php
                                        $subjectId = (int) $subject['subject_id'];
                                        $assignmentRow = db()->prepare('SELECT user_id FROM teacher_assignments WHERE section_id = :section_id AND subject_id = :subject_id LIMIT 1');
                                        $assignmentRow->execute([':section_id' => (int) $section['id'], ':subject_id' => $subjectId]);
                                        $assignedTeacherId = $assignmentRow->fetchColumn();
                                        $assignedTeacherId = $assignedTeacherId === false ? null : (int) $assignedTeacherId;
                                    ?>
                                    <td>
                                        <select name="assignments[<?= h((string) $section['id']) ?>][<?= h((string) $subjectId) ?>]">
                                            <option value="">Unassigned</option>
                                            <?php foreach ($teachers as $teacher): ?>
                                                <option value="<?= h((string) $teacher['id']) ?>" <?= ($assignedTeacherId !== null && (int) $teacher['id'] === $assignedTeacherId) ? 'selected' : '' ?>><?= h($teacher['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">Save assignments</button>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require BASE_PATH . '/components/footer.php'; ?>
