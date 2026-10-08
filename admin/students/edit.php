<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/csv.php';
require_once __DIR__ . '/../../includes/students.php';

require_login();
require_role(['admin', 'super_admin']);

$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$student = $studentId > 0 ? get_student($studentId) : null;
$year = active_year();
$yearId = $year !== null ? (int) $year['id'] : 0;
if ($yearId === 0) {
    $latestYear = db()->query('SELECT id FROM academic_years ORDER BY id DESC LIMIT 1')->fetch();
    $yearId = $latestYear ? (int) $latestYear['id'] : 0;
}

if (is_post()) {
    csrf_require();
    $bmjsId = trim((string) ($_POST['bmjs_id'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $dob = trim((string) ($_POST['dob'] ?? ''));
    $status = in_array((string) ($_POST['status'] ?? 'active'), ['active', 'sor', 'freeze', 'left'], true)
        ? (string) $_POST['status']
        : 'active';
    $sectionId = isset($_POST['section_id']) ? (int) $_POST['section_id'] : 0;
    $pattern = setting('student_id_pattern', '/^(?=.*\d)[A-Za-z0-9-]{3,30}$/');
    $errors = [];

    $idError = validate_bmjs_id($bmjsId, $pattern);
    if ($idError !== null) {
        $errors[] = $idError;
    }
    if ($fullName === '') {
        $errors[] = 'Student name is required.';
    }
    if ($sectionId <= 0) {
        $errors[] = 'Choose a section.';
    }
    $dobResult = parse_dob($dob);
    if ($dob !== '' && !$dobResult['ok']) {
        $errors[] = $dobResult['error'];
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            flash_add('error', $error);
        }
    } else {
        try {
            $payload = [
                'bmjs_id' => $bmjsId,
                'full_name' => clean_name($fullName),
                'dob' => $dobResult['date'] ?? null,
                'status' => $status,
                'section_id' => $sectionId,
            ];
            if ($studentId > 0) {
                update_student($studentId, $payload, $yearId);
                flash_add('success', 'Student details were updated.');
            } else {
                create_student($payload, $yearId);
                flash_add('success', 'Student added.');
            }
            redirect('admin/students/index.php');
        } catch (Throwable $exception) {
            flash_add('error', $exception->getMessage());
        }
    }
}

$classes = db()->query('SELECT c.*, s.id AS section_id, s.name AS section_name FROM classes c LEFT JOIN sections s ON s.class_id = c.id AND s.academic_year_id = :year_id ORDER BY c.sort_order, c.id, s.name')->fetchAll();
$sections = db()->query('SELECT s.*, c.name AS class_name FROM sections s LEFT JOIN classes c ON c.id = s.class_id WHERE s.academic_year_id = :year_id ORDER BY c.sort_order, s.name')->fetchAll();

$page_title = $studentId > 0 ? 'Edit student' : 'Add student';
$page_description = 'Add a new student or update an existing record in the current school year.';
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <div class="page-header split">
        <div>
            <h1 class="page-title"><?= $studentId > 0 ? 'Edit student' : 'Add student' ?></h1>
            <p class="page-description">Keep the BMJS ID and section in sync with the active year.</p>
        </div>
        <div class="button-row">
            <a href="<?= h(url('admin/students/index.php')) ?>" class="button button-secondary">Back to students</a>
        </div>
    </div>
</section>

<section class="card">
    <form method="post" action="<?= h(url('admin/students/edit.php' . ($studentId > 0 ? '?id=' . $studentId : ''))) ?>" class="form-grid">
        <?= csrf_field() ?>
        <div class="form-field">
            <label for="bmjs-id">BMJS ID</label>
            <input id="bmjs-id" name="bmjs_id" type="text" value="<?= h($student['bmjs_id'] ?? '') ?>" maxlength="40" required>
        </div>
        <div class="form-field">
            <label for="student-name">Full name</label>
            <input id="student-name" name="full_name" type="text" value="<?= h($student['full_name'] ?? '') ?>" maxlength="150" required>
        </div>
        <div class="form-field">
            <label for="student-dob">Date of birth</label>
            <input id="student-dob" name="dob" type="date" value="<?= h((string) ($student['dob'] ?? '')) ?>">
        </div>
        <div class="form-field">
            <label for="student-status">Status</label>
            <select id="student-status" name="status">
                <option value="active" <?= (($student['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                <option value="sor" <?= (($student['status'] ?? 'active') === 'sor') ? 'selected' : '' ?>>SOR</option>
                <option value="freeze" <?= (($student['status'] ?? 'active') === 'freeze') ? 'selected' : '' ?>>Freeze</option>
                <option value="left" <?= (($student['status'] ?? 'active') === 'left') ? 'selected' : '' ?>>Left</option>
            </select>
        </div>
        <div class="form-field">
            <label for="student-section">Section</label>
            <select id="student-section" name="section_id" required>
                <option value="">Choose a section</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= h((string) $section['id']) ?>" <?= ((int) ($student['section_id'] ?? 0) === (int) $section['id']) ? 'selected' : '' ?>><?= h($section['class_name'] . ' / ' . $section['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-submit full-width">
            <button class="button" type="submit"><?= $studentId > 0 ? 'Save changes' : 'Add student' ?></button>
        </div>
    </form>
</section>
<?php require BASE_PATH . '/components/footer.php';
