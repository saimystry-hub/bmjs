<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/students.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post()) {
    csrf_require();
    if (($_POST['action'] ?? '') === 'bulk_update') {
        $ids = $_POST['student_ids'] ?? [];
        $status = in_array((string) ($_POST['status'] ?? 'active'), ['active', 'sor', 'freeze', 'left'], true)
            ? (string) $_POST['status']
            : 'active';
        $sectionId = isset($_POST['section_id']) ? (int) $_POST['section_id'] : 0;

        foreach ($ids as $studentId) {
            $id = (int) $studentId;
            if ($id <= 0) {
                continue;
            }
            $payload = ['status' => $status];
            if ($sectionId > 0) {
                $payload['section_id'] = $sectionId;
            }
            update_student($id, $payload, (int) ($_POST['year_id'] ?? active_year()['id'] ?? 0));
        }
        flash_add('success', 'Bulk update applied to the selected students.');
        redirect('admin/students/bulk.php');
    }
}

$year = active_year();
$yearId = $year !== null ? (int) $year['id'] : 0;
if ($yearId === 0) {
    $latestYear = db()->query('SELECT id FROM academic_years ORDER BY id DESC LIMIT 1')->fetch();
    $yearId = $latestYear ? (int) $latestYear['id'] : 0;
}

$studentsResult = list_students(['year_id' => $yearId, 'status' => 'all', 'scope' => 'year'], 1, 200);
$students = $studentsResult['rows'];
$sections = db()->query('SELECT s.*, c.name AS class_name FROM sections s LEFT JOIN classes c ON c.id = s.class_id WHERE s.academic_year_id = :year_id ORDER BY c.sort_order, s.name')->fetchAll();

$page_title = 'Bulk student update';
$page_description = 'Apply a status or section change to many students quickly.';
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <h1 class="page-title">Bulk student update</h1>
    <p class="page-description">Use this for a quick status or section change across a year group.</p>
</section>

<section class="card">
    <form method="post" action="<?= h(url('admin/students/bulk.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_update">
        <input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>">
        <div class="form-grid">
            <div class="form-field">
                <label for="bulk-status">Status</label>
                <select id="bulk-status" name="status">
                    <option value="active">Active</option>
                    <option value="sor">SOR</option>
                    <option value="freeze">Freeze</option>
                    <option value="left">Left</option>
                </select>
            </div>
            <div class="form-field">
                <label for="bulk-section">Move to section</label>
                <select id="bulk-section" name="section_id">
                    <option value="0">Leave section unchanged</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= h((string) $section['id']) ?>"><?= h($section['class_name'] . ' / ' . $section['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="bulk-select-all"></th>
                        <th>Name</th>
                        <th>BMJS ID</th>
                        <th>Current section</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><input type="checkbox" name="student_ids[]" value="<?= h((string) $student['id']) ?>" class="bulk-student-check"></td>
                            <td><?= h($student['full_name']) ?></td>
                            <td><?= h($student['bmjs_id']) ?></td>
                            <td><?= h((string) ($student['section_name'] ?? '—')) ?></td>
                            <td><?= h((string) ($student['status'] ?? 'active')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button class="button" type="submit">Apply to selected students</button>
    </form>
</section>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('bulk-select-all');
        const checks = document.querySelectorAll('.bulk-student-check');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
            });
        }
    });
</script>
<?php require BASE_PATH . '/components/footer.php';
