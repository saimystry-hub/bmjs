<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/students.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post()) {
    csrf_require();
}

if (is_post() && ($_POST['action'] ?? '') === 'bulk_status') {
    $ids = $_POST['student_ids'] ?? [];
    $status = in_array((string) ($_POST['status'] ?? 'active'), ['active', 'sor', 'freeze', 'left'], true)
        ? (string) $_POST['status']
        : 'active';

    foreach ($ids as $studentId) {
        $id = (int) $studentId;
        if ($id <= 0) {
            continue;
        }
        $student = get_student($id);
        if ($student === null) {
            continue;
        }
        db()->prepare('UPDATE students SET status = :status WHERE id = :id')->execute([
            ':status' => $status,
            ':id' => $id,
        ]);
    }
    flash_add('success', 'The selected students were updated.');
    redirect('admin/students/index.php');
}

$year = active_year();
$yearId = $year !== null ? (int) $year['id'] : 0;
if ($yearId === 0) {
    $latestYear = db()->query('SELECT id FROM academic_years ORDER BY id DESC LIMIT 1')->fetch();
    $yearId = $latestYear ? (int) $latestYear['id'] : 0;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'year_id' => $yearId,
    'q' => (string) ($_GET['q'] ?? ''),
    'class_id' => isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0,
    'section_id' => isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0,
    'status' => (string) ($_GET['status'] ?? 'all'),
    'scope' => (string) ($_GET['scope'] ?? 'year'),
];
$studentsResult = list_students($filters, $page, 25);
$students = $studentsResult['rows'];
$total = $studentsResult['total'];
$classes = db()->query('SELECT * FROM classes ORDER BY sort_order, id')->fetchAll();
$sections = db()->query('SELECT s.*, c.name AS class_name FROM sections s LEFT JOIN classes c ON c.id = s.class_id WHERE s.academic_year_id = :year_id ORDER BY c.sort_order, s.name')->fetchAll();

$page_title = 'Students';
$page_description = 'Manage student records and import new students from CSV.';
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <div class="page-header split">
        <div>
            <h1 class="page-title">Students</h1>
            <p class="page-description">Search students in the active year, review status, and add or import records.</p>
        </div>
        <div class="button-row">
            <a class="button button-secondary" href="<?= h(url('admin/students/import.php')) ?>">Import CSV</a>
            <a class="button" href="<?= h(url('admin/students/edit.php')) ?>">Add student</a>
        </div>
    </div>
</section>

<section class="card">
    <form method="get" action="<?= h(url('admin/students/index.php')) ?>" class="filter-grid">
        <div class="form-field compact">
            <label for="student-search">Search</label>
            <input id="student-search" type="text" name="q" value="<?= h($filters['q']) ?>" placeholder="Name or BMJS ID">
        </div>
        <div class="form-field compact">
            <label for="student-class">Class</label>
            <select id="student-class" name="class_id">
                <option value="0">All classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= h((string) $class['id']) ?>" <?= ($filters['class_id'] === (int) $class['id']) ? 'selected' : '' ?>><?= h($class['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field compact">
            <label for="student-section">Section</label>
            <select id="student-section" name="section_id">
                <option value="0">All sections</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= h((string) $section['id']) ?>" <?= ($filters['section_id'] === (int) $section['id']) ? 'selected' : '' ?>><?= h($section['class_name'] . ' / ' . $section['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field compact">
            <label for="student-status">Status</label>
            <select id="student-status" name="status">
                <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="sor" <?= $filters['status'] === 'sor' ? 'selected' : '' ?>>SOR</option>
                <option value="freeze" <?= $filters['status'] === 'freeze' ? 'selected' : '' ?>>Freeze</option>
                <option value="left" <?= $filters['status'] === 'left' ? 'selected' : '' ?>>Left</option>
            </select>
        </div>
        <div class="form-field compact">
            <label for="student-scope">Scope</label>
            <select id="student-scope" name="scope">
                <option value="year" <?= $filters['scope'] === 'year' ? 'selected' : '' ?>>This year</option>
                <option value="all" <?= $filters['scope'] === 'all' ? 'selected' : '' ?>>All records</option>
            </select>
        </div>
        <div class="form-submit compact">
            <button class="button button-secondary" type="submit">Apply filters</button>
        </div>
    </form>
</section>

<section class="card">
    <?php if ($total > 0): ?>
        <form method="post" action="<?= h(url('admin/students/index.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_status">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-students" aria-label="Select all students"></th>
                            <th>Name</th>
                            <th>BMJS ID</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($students === []): ?>
                            <tr><td colspan="7"><div class="empty-state"><p>No students match the current filters.</p><a class="button" href="<?= h(url('admin/students/edit.php')) ?>">Add a student</a></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><input type="checkbox" name="student_ids[]" value="<?= h((string) $student['id']) ?>" class="student-select"></td>
                                    <td><?= h($student['full_name']) ?></td>
                                    <td><?= h($student['bmjs_id']) ?></td>
                                    <td><?= h($student['class_name'] ?? '—') ?></td>
                                    <td><?= h($student['section_name'] ?? '—') ?></td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        switch ($student['status'] ?? 'active') {
                                            case 'sor': $statusClass = 'warning'; break;
                                            case 'freeze': $statusClass = 'info'; break;
                                            case 'left': $statusClass = 'danger'; break;
                                            default: $statusClass = 'success'; break;
                                        }
                                        ?>
                                        <span class="badge badge-<?= h($statusClass) ?>"><?= h(strtoupper((string) ($student['status'] ?? 'active'))) ?></span>
                                    </td>
                                    <td><a href="<?= h(url('admin/students/edit.php?id=' . (int) $student['id'])) ?>">Edit</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-actions">
                <label for="bulk-status">Change selected to</label>
                <select id="bulk-status" name="status">
                    <option value="active">Active</option>
                    <option value="sor">SOR</option>
                    <option value="freeze">Freeze</option>
                    <option value="left">Left</option>
                </select>
                <button class="button button-secondary" type="submit">Apply</button>
            </div>
        </form>
    <?php else: ?>
        <div class="empty-state">
            <p>No students match the current filters.</p>
            <a class="button" href="<?= h(url('admin/students/edit.php')) ?>">Add a student</a>
        </div>
    <?php endif; ?>
</section>

<?php if ($total > 25): ?>
    <section class="card">
        <?php $pageCount = (int) ceil($total / 25); ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= h(url('admin/students/index.php?page=' . ($page - 1) . '&q=' . rawurlencode($filters['q']) . '&class_id=' . $filters['class_id'] . '&section_id=' . $filters['section_id'] . '&status=' . rawurlencode($filters['status']) . '&scope=' . rawurlencode($filters['scope']))) ?>">Previous</a>
            <?php endif; ?>
            <span>Page <?= h((string) $page) ?> of <?= h((string) $pageCount) ?></span>
            <?php if ($page < $pageCount): ?>
                <a href="<?= h(url('admin/students/index.php?page=' . ($page + 1) . '&q=' . rawurlencode($filters['q']) . '&class_id=' . $filters['class_id'] . '&section_id=' . $filters['section_id'] . '&status=' . rawurlencode($filters['status']) . '&scope=' . rawurlencode($filters['scope']))) ?>">Next</a>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('select-all-students');
        const studentChecks = document.querySelectorAll('.student-select');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                studentChecks.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }
    });
</script>
<?php require BASE_PATH . '/components/footer.php';
