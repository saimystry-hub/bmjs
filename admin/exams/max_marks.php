<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/class_subjects.php';
require_once __DIR__ . '/../../includes/structure.php';
require_once __DIR__ . '/../../includes/structure_edit.php';
require_once __DIR__ . '/../../includes/max_marks.php';

require_login();
require_role(['admin', 'super_admin']);

$years = db()->query('SELECT id, name FROM academic_years ORDER BY name DESC')->fetchAll();
$classes = db()->query('SELECT id, name FROM classes ORDER BY sort_order, id')->fetchAll();
$activeYear = active_year();
$yearId = (int) ($_REQUEST['year_id'] ?? $_SESSION['exam_year_id'] ?? ($activeYear['id'] ?? 0));
$classId = (int) ($_REQUEST['class_id'] ?? $_SESSION['exam_class_id'] ?? ($classes[0]['id'] ?? 0));
$_SESSION['exam_year_id'] = $yearId;
$_SESSION['exam_class_id'] = $classId;
$errors = [];
$cellErrors = [];
$postedCells = [];
$notice = '';
if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $result = save_max_marks($yearId, $classId, $_POST['cells'] ?? []);
        $errors = $result['errors'];
        $cellErrors = $result['cell_errors'] ?? [];
        $postedCells = $_POST['cells'] ?? [];
        if ($result['ok']) {
            $notice = $result['changed'] . ' maximum marks saved.';
        }
    } elseif ($action === 'fill_common') {
        try {
            $count = fill_empty_max($yearId, $classId, 'common');
            $notice = $count . ' empty maximum cells filled.';
        } catch (Throwable $exception) {
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'The empty maximum cells could not be filled.';
        }
    } elseif ($action === 'copy') {
        $from = (int) ($_POST['from_class_id'] ?? 0);
        if ($from <= 0 || $from === $classId) {
            $errors[] = 'Choose another class.';
        } else {
            try {
                $result = copy_max_from_class($yearId, $from, $classId, true);
                $notice = $result['copied'] . ' maximums copied; ' . $result['skipped'] . ' cells skipped.';
            } catch (Throwable $exception) {
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Maximums could not be copied.';
            }
        }
    } elseif ($action === 'apply_column') {
        $value = validate_max_value((string) ($_POST['value'] ?? ''));
        if (!$value['ok'] || $value['value'] === null) {
            $errors[] = $value['error'] ?? 'Enter a maximum.';
        } else {
            try {
                $count = apply_column_value($yearId, $classId, (int) $_POST['exam_id'], (int) $_POST['column_id'], (float) $value['value']);
                $notice = 'Maximum applied to ' . $count . ' subjects.';
            } catch (Throwable $exception) {
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'The maximum could not be applied.';
            }
        }
    }
}
$grid = $yearId > 0 && $classId > 0 ? get_max_grid($yearId, $classId) : ['exams' => [], 'marks_subjects' => [], 'grade_only' => [], 'cells' => [], 'totals' => []];
$gaps = $yearId > 0 && $classId > 0 ? max_marks_cell_gaps($yearId, $classId) : [];
$page_title = 'Maximum marks';
$page_description = 'Set the highest marks possible for each subject.';
$tabs = ['Structure' => 'admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId, 'Maximum marks' => 'admin/exams/max_marks.php?year_id=' . $yearId . '&class_id=' . $classId, 'Open and close' => 'admin/exams/states.php?year_id=' . $yearId];
$active = 'Maximum marks';
require BASE_PATH . '/components/header.php';
require BASE_PATH . '/components/tabs.php';
?>
<section class="card"><form method="get" class="filter-grid"><label class="form-field compact">Academic year<select name="year_id"><?php foreach ($years as $year): ?><option value="<?= h((string) $year['id']) ?>" <?= (int) $year['id'] === $yearId ? 'selected' : '' ?>><?= h($year['name']) ?></option><?php endforeach; ?></select></label><label class="form-field compact">Class<select name="class_id"><?php foreach ($classes as $class): ?><option value="<?= h((string) $class['id']) ?>" <?= (int) $class['id'] === $classId ? 'selected' : '' ?>><?= h($class['name']) ?></option><?php endforeach; ?></select></label><button class="button button-secondary">Show</button></form></section>
<?php if ($notice !== ''): ?><section class="card"><div class="alert alert-success"><?= h($notice) ?></div></section><?php endif; ?>
<?php if ($errors !== []): ?><section class="card"><div class="alert alert-error"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div></section><?php endif; ?>
<?php if ($grid['exams'] === []): ?><section class="card"><h2 class="card-title">Set up the exam structure first</h2><a class="button" href="<?= h(url('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId)) ?>">Set up exams</a></section>
<?php elseif ($grid['marks_subjects'] === []): ?><section class="card"><h2 class="card-title">This class has no marks subjects yet</h2><a class="button" href="<?= h(url('admin/classes/class_subjects.php')) ?>">Choose class subjects</a></section>
<?php else: ?>
<section class="card"><p><?= count($gaps) ?> of <?= count($gaps) + array_sum(array_map(static fn($exam) => count($exam['components']) * count($grid['marks_subjects']), array_filter($grid['exams'], static fn($exam) => $exam['mode'] === 'entered'))) ?> maximum marks are empty.</p>
<div class="button-row"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fill_common"><button class="button button-secondary" type="submit">Fill empty cells with the most common value</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="copy"><label>Copy from <select name="from_class_id" required><?php foreach ($classes as $class): if ((int) $class['id'] === $classId) continue; ?><option value="<?= h((string) $class['id']) ?>"><?= h($class['name']) ?></option><?php endforeach; ?></select></label><button class="button button-secondary">Copy from another class</button></form></div>
<form method="post" class="table-wrap"><?= csrf_field() ?><input type="hidden" name="action" value="save"><table class="max-grid"><thead><tr><th rowspan="2">Subject</th><?php foreach ($grid['exams'] as $exam): ?><th colspan="<?= $exam['mode'] === 'entered' ? count($exam['components']) + 2 : 1 ?>"><?= h($exam['code']) ?><?= $exam['mode'] === 'calculated' ? ' (worked out)' : '' ?></th><?php endforeach; ?></tr><tr><?php foreach ($grid['exams'] as $exam): if ($exam['mode'] === 'entered'): foreach ($exam['components'] as $column): ?><th><?= h($column['name']) ?><button type="button" class="button button-small button-secondary" data-apply-column="<?= h((string) $exam['id']) ?>:<?= h((string) $column['id']) ?>">Apply to all subjects</button></th><?php endforeach; ?><th>Total</th><?php else: ?><th>Total</th><?php endif; endforeach; ?></tr></thead><tbody><?php foreach ($grid['marks_subjects'] as $subjectId => $subject): ?><tr><th><?= h($subject['subject_name']) ?></th><?php foreach ($grid['exams'] as $exam): if ($exam['mode'] === 'entered'): foreach ($exam['components'] as $column): $value = $grid['cells'][(int) $exam['id']][$subjectId][(int) $column['id']] ?? null; $postedValue = $postedCells[$exam['id']][$subjectId][$column['id']] ?? $value; $cellError = $cellErrors[$exam['id']][$subjectId][$column['id']] ?? ''; ?><td><input class="<?= $postedValue === null ? 'max-cell-empty' : '' ?>" name="cells[<?= h((string) $exam['id']) ?>][<?= h((string) $subjectId) ?>][<?= h((string) $column['id']) ?>]" value="<?= $postedValue === null ? '' : h((string) $postedValue) ?>" inputmode="decimal" aria-label="<?= h($subject['subject_name'] . ' ' . $exam['code'] . ' ' . $column['name']) ?>"><?php if ($cellError !== ''): ?><small class="field-error"><?= h($cellError) ?></small><?php endif; ?></td><?php endforeach; ?><td><?= isset($grid['totals'][$exam['code']][$subjectId]) ? h((string) $grid['totals'][$exam['code']][$subjectId]) : '?' ?></td><?php else: $total = $grid['totals'][$exam['code']][$subjectId] ?? null; ?><td class="calculated-cell" title="<?= $total === null ? 'Some maximum marks are still empty' : 'Worked out from the other exams' ?>"><?= $total === null ? '?' : h((string) $total) ?></td><?php endif; endforeach; ?></tr><?php endforeach; ?></tbody></table><button class="button" type="submit">Save</button></form>
<?php if ($grid['grade_only'] !== []): ?><p class="muted">These subjects have no marks, only grades: <?php $names = array_column($grid['grade_only'], 'subject_name'); echo h(implode(', ', $names)); ?>.</p><?php endif; ?></section><?php endif; ?>
<script src="<?= h(asset('js/structure_editor.js')) ?>" defer></script>
<?php require BASE_PATH . '/components/footer.php'; ?>
