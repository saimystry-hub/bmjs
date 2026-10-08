<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/csv.php';
require_once __DIR__ . '/../../includes/students.php';

require_login();
require_role(['admin', 'super_admin']);

$year = active_year();
$yearId = $year !== null ? (int) $year['id'] : 0;
if ($yearId === 0) {
    $latestYear = db()->query('SELECT id FROM academic_years ORDER BY id DESC LIMIT 1')->fetch();
    $yearId = $latestYear ? (int) $latestYear['id'] : 0;
}

$sessionKey = 'student_import_preview';

if (isset($_GET['action']) && $_GET['action'] === 'download_errors') {
    $plan = $_SESSION[$sessionKey] ?? null;
    if (is_array($plan) && isset($plan['rows'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="student-import-errors.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['row', 'bmjs_id', 'full_name', 'class', 'section', 'errors']);
        foreach ($plan['rows'] as $row) {
            if (($row['status'] ?? 'skip') !== 'error') {
                continue;
            }
            fputcsv($output, [
                $row['row'] ?? '',
                $row['bmjs_id'] ?? '',
                $row['full_name'] ?? '',
                $row['class_label'] ?? '',
                $row['section_label'] ?? '',
                implode('; ', $row['problems'] ?? []),
            ]);
        }
        fclose($output);
        exit;
    }
    flash_add('warning', 'There are no error rows to download.');
}

if (is_post() && ($_POST['action'] ?? '') === 'upload_csv') {
    csrf_require();
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        flash_add('error', 'Please upload a CSV file.');
    } else {
        $raw = file_get_contents($_FILES['csv_file']['tmp_name']);
        if ($raw === false) {
            flash_add('error', 'The CSV file could not be read.');
        } else {
            $decoded = csv_to_utf8($raw);
            $delimiter = csv_detect_delimiter($decoded['text']);
            $parsed = csv_parse($decoded['text'], $delimiter);
            if ($parsed['error'] !== null) {
                flash_add('error', $parsed['error']);
            } else {
                $mapping = csv_guess_mapping($parsed['headers']);
                if ($mapping['bmjs_id'] === null || $mapping['full_name'] === null || $mapping['class'] === null || $mapping['section'] === null) {
                    $_SESSION['student_import_headers'] = $parsed['headers'];
                    $_SESSION['student_import_rows'] = $parsed['rows'];
                    flash_add('info', 'Please match the CSV columns before previewing the import.');
                } else {
                    $lookups = student_import_lookups($yearId);
                    $plan = build_import_plan($parsed['rows'], $mapping, $lookups, ['create_missing' => false, 'update_existing' => true, 'allow_move' => false]);
                    $_SESSION[$sessionKey] = $plan;
                    flash_add('success', 'CSV preview is ready. Review the rows and confirm when you are ready.');
                }
            }
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'map_columns') {
    csrf_require();
    $headers = $_SESSION['student_import_headers'] ?? [];
    $rows = $_SESSION['student_import_rows'] ?? [];
    if ($headers === [] || $rows === []) {
        flash_add('error', 'The CSV preview has expired. Please upload the file again.');
    } else {
        $mapping = [];
        foreach (['bmjs_id', 'full_name', 'class', 'section', 'dob', 'status'] as $field) {
            $mapping[$field] = isset($_POST['map'][$field]) && $_POST['map'][$field] !== '' ? (int) $_POST['map'][$field] : null;
        }
        $lookups = student_import_lookups($yearId);
        $plan = build_import_plan($rows, $mapping, $lookups, ['create_missing' => false, 'update_existing' => true, 'allow_move' => false]);
        $_SESSION[$sessionKey] = $plan;
        unset($_SESSION['student_import_headers'], $_SESSION['student_import_rows']);
        flash_add('success', 'The CSV columns were mapped and the preview refreshed.');
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'confirm_import') {
    csrf_require();
    $plan = $_SESSION[$sessionKey] ?? null;
    if (!is_array($plan) || empty($plan['rows'])) {
        flash_add('error', 'There is no pending import to confirm.');
    } else {
        $options = [
            'create_missing' => !empty($_POST['create_missing']),
            'update_existing' => !empty($_POST['update_existing']),
            'allow_move' => !empty($_POST['allow_move']),
        ];
        $counts = run_import($plan, $options, $yearId, (int) current_user()['id']);
        unset($_SESSION[$sessionKey]);
        flash_add('success', 'The student import finished: ' . $counts['new'] . ' new, ' . $counts['update'] . ' updated.');
        redirect('admin/students/index.php');
    }
}

$page_title = 'Import students';
$page_description = 'Upload a CSV file and review the student import before saving anything.';
require BASE_PATH . '/components/header.php';
$preview = $_SESSION[$sessionKey] ?? null;
$needsMapping = $_SESSION['student_import_headers'] ?? null;
$headers = $needsMapping ?? []; 
?>
<section class="card">
    <div class="page-header split">
        <div>
            <h1 class="page-title">Import students</h1>
            <p class="page-description">Upload a CSV, preview the matches, and save valid rows in one transaction.</p>
        </div>
        <div class="button-row">
            <a class="button button-secondary" href="<?= h(url('docs/sample_students.csv')) ?>">Download sample CSV</a>
            <a class="button button-secondary" href="<?= h(url('admin/students/index.php')) ?>">Back to list</a>
        </div>
    </div>
</section>

<?php if ($needsMapping !== null): ?>
    <section class="card">
        <h2 class="card-title">Map CSV columns</h2>
        <form method="post" action="<?= h(url('admin/students/import.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="map_columns">
            <div class="form-grid">
                <?php foreach (['bmjs_id' => 'BMJS ID', 'full_name' => 'Full name', 'class' => 'Class', 'section' => 'Section', 'dob' => 'Date of birth', 'status' => 'Status'] as $field => $label): ?>
                    <div class="form-field">
                        <label for="map-<?= h($field) ?>"><?= h($label) ?></label>
                        <select id="map-<?= h($field) ?>" name="map[<?= h($field) ?>]">
                            <option value="">Skip</option>
                            <?php foreach ($headers as $index => $header): ?>
                                <option value="<?= h((string) $index) ?>"><?= h((string) $header) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="button" type="submit">Preview import</button>
        </form>
    </section>
<?php else: ?>
    <section class="card">
        <form method="post" action="<?= h(url('admin/students/import.php')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_csv">
            <div class="form-field">
                <label for="csv-file">CSV file</label>
                <input id="csv-file" type="file" name="csv_file" accept=".csv,text/csv" required>
            </div>
            <button class="button" type="submit">Upload and preview</button>
        </form>
    </section>
<?php endif; ?>

<?php if (is_array($preview) && !empty($preview['rows'])): ?>
    <section class="card">
        <div class="page-header split">
            <h2 class="card-title">Import preview</h2>
            <div class="button-row">
                <a class="button button-secondary" href="<?= h(url('admin/students/import.php?action=download_errors')) ?>">Download error rows</a>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>BMJS ID</th>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview['rows'] as $row): ?>
                        <tr>
                            <td><?= h((string) ($row['row'] ?? '')) ?></td>
                            <td><?= h((string) ($row['bmjs_id'] ?? '')) ?></td>
                            <td><?= h((string) ($row['full_name'] ?? '')) ?></td>
                            <td><?= h((string) ($row['resolved_class'] ?? ($row['class_label'] ?? ''))) ?></td>
                            <td><?= h((string) ($row['resolved_section'] ?? ($row['section_label'] ?? ''))) ?></td>
                            <td>
                                <?php
                                $rowStatus = (string) ($row['status'] ?? 'skip');
                                $rowClass = $rowStatus === 'error' ? 'danger' : ($rowStatus === 'new' ? 'success' : ($rowStatus === 'update' ? 'info' : 'warning'));
                                ?>
                                <span class="badge badge-<?= h($rowClass) ?>"><?= h(strtoupper($rowStatus)) ?></span>
                            </td>
                            <td>
                                <?php
                                $notes = array_merge($row['notes'] ?? [], $row['problems'] ?? []);
                                echo h(implode('; ', $notes));
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="<?= h(url('admin/students/import.php')) ?>" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_import">
            <label class="checkbox-row"><input type="checkbox" name="create_missing" value="1"> Create missing classes and sections</label>
            <label class="checkbox-row"><input type="checkbox" name="update_existing" value="1" checked> Update existing students</label>
            <label class="checkbox-row"><input type="checkbox" name="allow_move" value="1"> Allow moving students to other sections</label>
            <button class="button" type="submit">Confirm import</button>
        </form>
    </section>
<?php endif; ?>
<?php require BASE_PATH . '/components/footer.php';
