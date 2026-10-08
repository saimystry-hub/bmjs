<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/teachers.php';
require_once __DIR__ . '/includes/structure.php';
require_once __DIR__ . '/includes/structure_apply.php';

require_login();
$user = current_user();
$year = active_year();
$classCount = (int) db()->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$subjectCount = (int) db()->query('SELECT COUNT(*) FROM subjects WHERE is_active = 1')->fetchColumn();
$activeYearId = (int) ($year['id'] ?? 0);
$structureStatus = $activeYearId > 0 && in_array(($user['role'] ?? ''), ['admin', 'super_admin'], true)
    ? structure_status_by_class($activeYearId)
    : [];
$classesWithSubjects = 0;
$classesWithStructure = 0;
$classesWithExams = 0;
$classesWithCompleteMaximums = 0;
foreach ($structureStatus as $classStatus) {
    if ($classStatus['subject_count'] > 0) {
        $classesWithSubjects++;
        if ($classStatus['exam_count'] > 0) {
            $classesWithStructure++;
        }
    }
    if ($classStatus['exam_count'] > 0) {
        $classesWithExams++;
        if ($classStatus['filled_cells'] >= $classStatus['required_cells']) {
            $classesWithCompleteMaximums++;
        }
    }
}
$sectionCount = 0;
if ($activeYearId > 0) {
    $sectionStatement = db()->prepare('SELECT COUNT(*) FROM sections WHERE academic_year_id = :id');
    $sectionStatement->execute([':id' => $activeYearId]);
    $sectionCount = (int) $sectionStatement->fetchColumn();
}
$classSubjectCount = 0;
if ($activeYearId > 0) {
    $classSubjectStatement = db()->prepare('SELECT COUNT(*) FROM class_subjects WHERE academic_year_id = :id AND is_active = 1');
    $classSubjectStatement->execute([':id' => $activeYearId]);
    $classSubjectCount = (int) $classSubjectStatement->fetchColumn();
}
$teacherAssignments = [];
if (($user['role'] ?? '') === 'teacher') {
    $teacherAssignments = teacher_assignments_for_user((int) ($user['id'] ?? 0), $activeYearId);
}
$page_title = 'Dashboard';
$page_description = 'Overview for ' . ($user['full_name'] ?? 'the user');
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <h2 class="card-title">Welcome back</h2>
    <p><?= h($user['full_name'] ?? '') ?> · <?= h($user['role'] ?? '') ?></p>
    <p class="muted">Active academic year: <?= $year ? h($year['name']) : 'No active year yet' ?></p>
</section>
<?php if (in_array(($user['role'] ?? ''), ['super_admin', 'admin'], true)): ?>
    <section class="card">
        <h2 class="card-title">What do you want to do?</h2>
        <div class="task-grid">
            <a class="task-card" href="<?= h(url('admin/students/index.php')) ?>">Add or import students</a>
            <a class="task-card" href="<?= h(url('admin/classes/index.php')) ?>">Set up a class</a>
            <a class="task-card" href="<?= h(url('admin/exams/structure.php')) ?>">Check marks progress</a>
            <a class="task-card" href="<?= h(url('reports/generate.php')) ?>">Print report cards</a>
            <a class="task-card" href="<?= h(url('admin/teachers/assignments.php')) ?>">Manage teachers</a>
            <?php if (($user['role'] ?? '') === 'super_admin'): ?>
                <a class="task-card" href="<?= h(url('admin/system/users.php')) ?>">Manage users</a>
            <?php endif; ?>
            <a class="task-card" href="<?= h(url('admin/year_end/rollover.php')) ?>">Start a new year</a>
            <a class="task-card" href="<?= h(url('admin/year_end/promotion.php')) ?>">Promote students</a>
        </div>
    </section>
    <section class="card">
        <h2 class="card-title">Setup checklist</h2>
        <ul class="checklist">
            <li><a href="<?= h(url('admin/classes/years.php')) ?>"><?= $year ? '<span class="dot dot-success"></span>' : '<span class="dot dot-muted"></span>' ?> Academic year</a></li>
            <li><a href="<?= h(url('admin/classes/index.php')) ?>"><?= $classCount > 0 ? '<span class="dot dot-success"></span>' : '<span class="dot dot-muted"></span>' ?> Classes</a></li>
            <li><a href="<?= h(url('admin/classes/sections.php')) ?>"><?= $sectionCount > 0 ? '<span class="dot dot-success"></span>' : '<span class="dot dot-muted"></span>' ?> Sections</a></li>
            <li><a href="<?= h(url('admin/classes/subjects.php')) ?>"><?= $subjectCount > 0 ? '<span class="dot dot-success"></span>' : '<span class="dot dot-muted"></span>' ?> Subjects</a></li>
            <li><a href="<?= h(url('admin/classes/class_subjects.php')) ?>"><?= $classSubjectCount > 0 ? '<span class="dot dot-success"></span>' : '<span class="dot dot-muted"></span>' ?> Class subjects</a></li>
            <li><a href="<?= h(url('admin/exams/structure.php')) ?>"><?= $classesWithStructure >= $classesWithSubjects ? '<span class="dot dot-success"></span>' : '<span class="dot dot-muted"></span>' ?> Exam structure (<?= h((string) $classesWithStructure) ?> of <?= h((string) $classesWithSubjects) ?> classes)</a></li>
            <li><a href="<?= h(url('admin/exams/max_marks.php')) ?>"><?= $classesWithCompleteMaximums >= $classesWithExams ? '<span class="dot dot-success"></span>' : '<span class="dot dot-muted"></span>' ?> Maximum marks (<?= h((string) $classesWithCompleteMaximums) ?> of <?= h((string) $classesWithExams) ?> classes complete)</a></li>
            <li><a href="<?= h(url('admin/teachers/assignments.php')) ?>">Teachers</a></li>
            <li><span class="dot dot-muted"></span> Students</li>
        </ul>
    </section>
<?php else: ?>
    <section class="card">
        <h2 class="card-title">Your assigned classes</h2>
        <?php if ($teacherAssignments): ?>
            <table>
                <thead><tr><th>Class</th><th>Section</th><th>Subject</th></tr></thead>
                <tbody>
                    <?php foreach ($teacherAssignments as $assignment): ?>
                        <tr>
                            <td><?= h((string) ($assignment['class_name'] ?? '')) ?></td>
                            <td><?= h((string) ($assignment['section_name'] ?? '')) ?></td>
                            <td><?= h((string) ($assignment['subject_name'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">No subject assignments have been published for your account yet.</p>
        <?php endif; ?>
        <p><a class="button" href="<?= h(url('teacher/my_classes.php')) ?>">Open my classes</a></p>
    </section>
<?php endif; ?>
<?php require BASE_PATH . '/components/footer.php'; ?>
