<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/class_subjects.php';
require_once __DIR__ . '/../../includes/structure.php';
require_once __DIR__ . '/../../includes/structure_edit.php';
require_once __DIR__ . '/../../includes/max_marks.php';
require_once __DIR__ . '/../../includes/structure_apply.php';
require_once __DIR__ . '/../../includes/bulk.php';

require_login();
require_role(['admin', 'super_admin']);

$yearRows = db()->query('SELECT id, name, is_active FROM academic_years ORDER BY name DESC')->fetchAll();
$classes = db()->query('SELECT id, name FROM classes ORDER BY sort_order, id')->fetchAll();
$activeYearRow = active_year();
$yearId = (int) ($_REQUEST['year_id'] ?? $_SESSION['exam_year_id'] ?? ($activeYearRow['id'] ?? 0));
$classId = (int) ($_REQUEST['class_id'] ?? $_SESSION['exam_class_id'] ?? ($classes[0]['id'] ?? 0));
$_SESSION['exam_year_id'] = $yearId;
$_SESSION['exam_class_id'] = $classId;
$message = '';
$errors = [];
$isPost = is_post();
if ($isPost) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_exam') {
        $id = (int) ($_POST['exam_id'] ?? 0);
        $data = [
            'code' => $_POST['code'] ?? '', 'name' => $_POST['name'] ?? '',
            'term_group' => $_POST['term_group'] ?? 'mid', 'mode' => $_POST['mode'] ?? 'entered',
            'calc_method' => $_POST['calc_method'] ?? null, 'component_ids' => $_POST['component_ids'] ?? [],
            'source_ids' => $_POST['source_ids'] ?? [], 'max_defaults' => $_POST['max_defaults'] ?? [],
            'is_term_result' => $_POST['is_term_result'] ?? 0, 'has_attendance' => $_POST['has_attendance'] ?? 0,
        ];
        $existing = $id > 0 ? get_structure($yearId, $classId) : [];
        $current = null;
        foreach ($existing as $exam) {
            if ((int) $exam['id'] === $id) {
                $current = $exam;
            }
        }
        $marksCount = (int) ($current['marks_count'] ?? 0);
        if ($current && $current['mode'] !== $data['mode'] && $marksCount > 0 && empty($_POST['confirm_impact'])) {
            $errors[] = $marksCount . ' marks have been entered for this exam. Tick the confirmation box to keep them and change how marks are obtained.';
        } else {
            $result = $current && $current['mode'] !== $data['mode']
                ? switch_exam_mode($id, (string) $data['mode'], $data)
                : save_exam($yearId, $classId, $data, $id > 0 ? $id : null);
            $errors = $result['errors'] ?? [];
            if (!empty($result['warnings'])) {
                foreach ($result['warnings'] as $warning) {
                    flash_add('warning', $warning);
                }
            }
            if ($result['ok']) {
                flash_add('success', 'The exam was saved.');
                redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
            }
        }
    } elseif ($action === 'delete_exam') {
        $result = delete_exam((int) ($_POST['exam_id'] ?? 0));
        flash_add($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
    } elseif ($action === 'move_exam') {
        if (move_exam((int) ($_POST['exam_id'] ?? 0), (string) ($_POST['direction'] ?? ''))) {
            flash_add('success', 'Exam order updated.');
        }
        redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
    } elseif ($action === 'apply_preview') {
        $source = ['type' => $_POST['source_type'] ?? 'preset'];
        $source['id'] = (int) ($_POST['preset_id'] ?? 0);
        if ($source['type'] === 'class') {
            $source['class_id'] = (int) ($_POST['source_class_id'] ?? $classId);
        }
        $options = ['replace' => !empty($_POST['replace']), 'fill_max' => !empty($_POST['fill_max'])];
        try {
            $plan = plan_apply($source, $_POST['target_class_ids'] ?? [$classId], $yearId, $options);
            $token = bulk_preview_save('structure_apply', ['plan' => $plan, 'source' => $source, 'options' => $options]);
            $_SESSION['structure_apply_token'] = $token;
            $message = 'Review the preview before applying this structure.';
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    } elseif ($action === 'apply_confirm') {
        $token = (string) ($_POST['preview_token'] ?? '');
        if (!empty($_SESSION['applied_structure_tokens'][$token])) {
            flash_add('info', 'This was already applied.');
            redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
        }
        $saved = bulk_preview_load($token, 'structure_apply');
        if (!$saved) {
            flash_add('error', 'This preview expired. Please prepare it again.');
            redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
        }
        $fresh = plan_apply($saved['source'], array_column($saved['plan']['rows'], 'class_id'), $yearId, $saved['options']);
        if (hash('sha256', serialize($fresh)) !== hash('sha256', serialize($saved['plan']))) {
            flash_add('warning', 'Something changed while you were checking. Please review again.');
            bulk_preview_clear($token);
            redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
        }
        run_apply($fresh, $saved['source'], $yearId, (int) current_user()['id']);
        bulk_preview_clear($token);
        $_SESSION['applied_structure_tokens'][$token] = true;
        flash_add('success', 'The exam structure was applied.');
        redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
    } elseif ($action === 'save_preset') {
        try {
            save_class_as_preset($yearId, $classId, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''), (int) current_user()['id']);
            flash_add('success', 'Your ready-made structure was saved.');
        } catch (Throwable $exception) {
            flash_add('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The ready-made structure could not be saved.');
        }
        redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
    } elseif ($action === 'delete_preset') {
        if (delete_user_preset((int) ($_POST['preset_id'] ?? 0), (int) current_user()['id'])) {
            flash_add('success', 'Your ready-made structure was removed.');
        } else {
            flash_add('error', 'That ready-made structure cannot be removed.');
        }
        redirect('admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId);
    }
}

$selectedClass = null;
foreach ($classes as $class) {
    if ((int) $class['id'] === $classId) {
        $selectedClass = $class;
    }
}
$exams = $selectedClass ? get_structure($yearId, $classId) : [];
$grid = $selectedClass ? get_max_grid($yearId, $classId) : ['exams' => [], 'totals' => [], 'marks_subjects' => []];
$usedExamIds = [];
if ($exams !== []) {
    $usedQuery = db()->prepare('SELECT source_assessment_id FROM assessment_sources WHERE assessment_id IN (' . implode(',', array_fill(0, count($exams), '?')) . ')');
    $usedQuery->execute(array_map(static fn($exam) => (int) $exam['id'], $exams));
    $usedExamIds = array_map('intval', array_column($usedQuery->fetchAll(), 'source_assessment_id'));
}
$presets = get_presets();
$components = db()->query('SELECT id, name FROM components ORDER BY sort_order, id')->fetchAll();
$structureMemory = array_map(static fn($exam) => structure_exam_values($exam, array_column($exam['components'], 'name'), $exam['source_codes']), $exams);
$structureCheck = validate_structure($structureMemory);
$maxGaps = $selectedClass ? max_marks_cell_gaps($yearId, $classId) : [];
$structureIssueCount = count($structureCheck['errors']) + count($structureCheck['warnings']) + count($maxGaps);
$teacherGapCount = 0;
if ($selectedClass && $yearId > 0) {
    $teacherQuery = db()->prepare("SELECT COUNT(*) FROM sections sec JOIN class_subjects cs ON cs.academic_year_id = sec.academic_year_id AND cs.class_id = sec.class_id AND cs.is_active = 1 JOIN subjects sub ON sub.id = cs.subject_id AND COALESCE(cs.type_override, sub.type) = 'graded' LEFT JOIN teacher_assignments ta ON ta.section_id = sec.id AND ta.subject_id = cs.subject_id WHERE sec.academic_year_id = :year_id AND sec.class_id = :class_id AND ta.id IS NULL");
    $teacherQuery->execute([':year_id' => $yearId, ':class_id' => $classId]);
    $teacherGapCount = (int) $teacherQuery->fetchColumn();
}
$editExam = null;
$editId = (int) ($_GET['edit'] ?? 0);
foreach ($exams as $exam) {
    if ((int) $exam['id'] === $editId) {
        $editExam = $exam;
    }
}
$showForm = isset($_GET['new']) || $editExam !== null || ($isPost && ($_POST['action'] ?? '') === 'save_exam' && $errors !== []);
if ($isPost && ($_POST['action'] ?? '') === 'save_exam' && $errors !== []) {
    $editExam = array_merge($editExam ?? [], [
        'id' => (int) ($_POST['exam_id'] ?? 0), 'code' => (string) ($_POST['code'] ?? ''),
        'name' => (string) ($_POST['name'] ?? ''), 'term_group' => (string) ($_POST['term_group'] ?? 'mid'),
        'mode' => (string) ($_POST['mode'] ?? 'entered'), 'calc_method' => (string) ($_POST['calc_method'] ?? 'sum'),
        'is_term_result' => !empty($_POST['is_term_result']), 'has_attendance' => !empty($_POST['has_attendance']),
        'components' => array_map(static fn($id) => ['id' => (int) $id], $_POST['component_ids'] ?? []),
        'sources' => array_map('intval', $_POST['source_ids'] ?? []),
    ]);
}
$page_title = 'Exam structure';
$page_description = 'Decide which exams each class has and how they are worked out.';
$tabs = ['Structure' => 'admin/exams/structure.php?year_id=' . $yearId . '&class_id=' . $classId, 'Maximum marks' => 'admin/exams/max_marks.php?year_id=' . $yearId . '&class_id=' . $classId, 'Open and close' => 'admin/exams/states.php?year_id=' . $yearId];
$active = 'Structure';
require BASE_PATH . '/components/header.php';
require BASE_PATH . '/components/tabs.php';
?>
<section class="card">
    <form method="get" class="filter-grid">
        <label class="form-field compact">Academic year<select name="year_id"><?php foreach ($yearRows as $year): ?><option value="<?= h((string) $year['id']) ?>" <?= (int) $year['id'] === $yearId ? 'selected' : '' ?>><?= h($year['name']) ?></option><?php endforeach; ?></select></label>
        <label class="form-field compact">Class<select name="class_id"><?php foreach ($classes as $class): ?><option value="<?= h((string) $class['id']) ?>" <?= (int) $class['id'] === $classId ? 'selected' : '' ?>><?= h($class['name']) ?></option><?php endforeach; ?></select></label>
        <button class="button button-secondary" type="submit">Show</button>
    </form>
    <?php if ($activeYearRow && $yearId !== (int) $activeYearRow['id']): ?><p class="alert alert-info">You are editing <?= h((string) (array_column($yearRows, 'name', 'id')[$yearId] ?? 'a year')) ?>, which is not the active year.</p><?php endif; ?>
</section>
<?php if ($isPost && $errors !== []): ?><section class="card"><div class="alert alert-error"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div></section><?php endif; ?>
<?php if (!$selectedClass || $yearId <= 0): ?>
<section class="card"><p class="empty-state">Add an academic year and a class before setting up exams.</p></section>
<?php elseif ($exams === []): ?>
<section class="card"><h2 class="card-title">Start with a ready-made structure</h2><div class="task-grid"><?php foreach ($presets as $preset): if (!(int) $preset['is_builtin']) continue; ?><form method="post" class="exam-preset-card"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preview"><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>"><input type="hidden" name="class_id" value="<?= h((string) $classId) ?>"><input type="hidden" name="source_type" value="preset"><input type="hidden" name="preset_id" value="<?= h((string) $preset['id']) ?>"><input type="hidden" name="target_class_ids[]" value="<?= h((string) $classId) ?>"><input type="hidden" name="fill_max" value="1"><h3><?= h($preset['name']) ?></h3><p><?= h($preset['description']) ?></p><button class="button" type="submit">Use this</button></form><?php endforeach; ?></div>
<details><summary>My ready-made structures</summary><?php foreach ($presets as $preset): if ((int) $preset['is_builtin']) continue; ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preview"><input type="hidden" name="source_type" value="preset"><input type="hidden" name="preset_id" value="<?= h((string) $preset['id']) ?>"><input type="hidden" name="target_class_ids[]" value="<?= h((string) $classId) ?>"><input type="hidden" name="fill_max" value="1"><span><?= h($preset['name']) ?></span><button class="button button-small" type="submit">Use this</button></form><?php endforeach; ?></details>
<a class="button button-secondary" href="?year_id=<?= h((string) $yearId) ?>&class_id=<?= h((string) $classId) ?>&new=1">Start from scratch (add exams one by one)</a></section>
<?php else: ?>
<section class="card"><h2 class="card-title"><?= h($selectedClass['name']) ?>: <?= count($exams) ?> exams</h2><p><?= count($maxGaps) ?> maximum marks still need to be filled. <?php if ($structureIssueCount === 0): ?><span class="badge badge-success">Structure looks good</span><?php else: ?><span class="badge badge-warning"><?= h((string) $structureIssueCount) ?> things to check</span><?php endif; ?></p>
<?php foreach (['mid' => 'Mid Term', 'final' => 'Final Term'] as $group => $label): ?><h3 class="term-heading"><?= h($label) ?></h3><div class="exam-list"><?php foreach ($exams as $index => $exam): if ($exam['term_group'] !== $group) continue; $totalValues = array_values($grid['totals'][$exam['code']] ?? []); $maximum = modal_value(array_filter($totalValues, static fn($v) => $v !== null)); $description = describe_exam($exam, []); if ($exam['mode'] === 'entered') { $columnDescriptions = []; foreach ($exam['components'] as $column) { $columnValues = []; foreach ($grid['marks_subjects'] as $subjectId => $_) $columnValues[] = $grid['cells'][(int) $exam['id']][$subjectId][(int) $column['id']] ?? null; $columnMaximum = modal_value(array_filter($columnValues, static fn($v) => $v !== null)); $columnDescriptions[] = $column['name'] . ($columnMaximum !== null ? ' /' . $columnMaximum : ''); } $description = 'separate exam: ' . implode(', ', $columnDescriptions) . (count($columnDescriptions) === 1 ? ' only' : ''); } $hasData = (int) $exam['marks_count'] + (int) $exam['grades_count'] + (int) $exam['attendance_count'] + (int) $exam['submissions_count'] > 0; $usedByOther = in_array((int) $exam['id'], $usedExamIds, true); ?><article class="exam-card"><div><strong><?= h($exam['code']) ?> — <?= h($exam['name']) ?></strong><p><?= h($label . ': ' . $description) ?><?= $maximum !== null ? ' (maximum ' . h((string) $maximum) . ')' : '' ?></p><span class="state-badge state-<?= h($exam['state']) ?>"><?= h(ucfirst($exam['state'])) ?></span><?php if ((int) $exam['is_term_result']): ?><span class="badge">Term result</span><?php endif; ?><?php if ((int) $exam['has_attendance']): ?><span class="badge">Counts attendance</span><?php endif; ?><?php if ($hasData): ?><span class="badge">Has data</span><?php endif; ?></div><div class="button-row"><a class="button button-small button-secondary" href="?year_id=<?= h((string) $yearId) ?>&class_id=<?= h((string) $classId) ?>&edit=<?= h((string) $exam['id']) ?>">Edit</a><?php foreach (['up' => 'Move up', 'down' => 'Move down'] as $direction => $text): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="move_exam"><input type="hidden" name="exam_id" value="<?= h((string) $exam['id']) ?>"><input type="hidden" name="direction" value="<?= h($direction) ?>"><button class="button button-small button-secondary" type="submit"><?= h($text) ?></button></form><?php endforeach; ?><?php if (!$hasData && !$usedByOther): ?><form method="post" data-confirm="Remove <?= h($exam['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_exam"><input type="hidden" name="exam_id" value="<?= h((string) $exam['id']) ?>"><button class="button button-small button-secondary" type="submit">Remove</button></form><?php else: ?><span class="muted" title="<?= $hasData ? 'This exam has data.' : 'This exam is used by another exam.' ?>">Cannot remove</span><?php endif; ?></div></article><?php endforeach; ?></div><?php endforeach; ?>
<div class="structure-preview"><h3>What teachers and reports will show</h3><p><strong>Teachers will enter:</strong> <?php $entered = []; foreach ($exams as $exam) { if ($exam['mode'] === 'entered') { $part = []; foreach ($exam['components'] as $column) { $values = []; foreach ($grid['marks_subjects'] as $subjectId => $_) { $value = $grid['cells'][(int) $exam['id']][$subjectId][(int) $column['id']] ?? null; if ($value !== null) $values[] = $value; } $part[] = $column['name'] . ' /' . (modal_value($values) ?? '?'); } $entered[] = $exam['code'] . ' (' . implode(', ', $part) . ')'; } } echo h(implode(', ', $entered) ?: 'No marks are typed directly.'); ?></p><p><strong>Report card will show:</strong> <?= h(implode(', ', array_column($exams, 'code'))) ?></p></div>
<div class="button-row"><a class="button" href="?year_id=<?= h((string) $yearId) ?>&class_id=<?= h((string) $classId) ?>&new=1">Add exam</a><a class="button button-secondary" href="#apply-preset">Apply a ready-made structure</a><a class="button button-secondary" href="#apply-form">Copy this structure to other classes</a><a class="button button-secondary" href="#save-preset">Save as my preset</a><button class="button button-secondary" type="button" data-open-structure-check>Check this structure</button></div>
<details id="structure-check"><summary>Structure check results</summary><?php if ($structureCheck['errors'] === [] && $structureCheck['warnings'] === [] && $maxGaps === [] && $teacherGapCount === 0): ?><p>Everything looks fine.</p><?php else: ?><ul><?php foreach ($structureCheck['errors'] as $issue): ?><li><?= h($issue) ?></li><?php endforeach; ?><?php foreach ($structureCheck['warnings'] as $issue): ?><li><?= h($issue) ?></li><?php endforeach; ?><?php if ($maxGaps !== []): ?><li><?= count($maxGaps) ?> maximum marks are empty. <a href="<?= h(url('admin/exams/max_marks.php?year_id=' . $yearId . '&class_id=' . $classId)) ?>">Fill them in</a>.</li><?php endif; ?><?php if ($teacherGapCount > 0): ?><li><?= h((string) $teacherGapCount) ?> marks-subject assignments have no teacher. <a href="<?= h(url('admin/teachers/assignments.php')) ?>">Review teachers</a>.</li><?php endif; ?></ul><?php endif; ?></details>
</section>
<?php endif; ?>
<?php if ($showForm): ?>
<section class="card"><h2 class="card-title"><?= !empty($editExam['id']) ? 'Edit exam' : 'Add exam' ?></h2><form method="post" class="form-grid" data-exam-editor><?= csrf_field() ?><input type="hidden" name="action" value="save_exam"><input type="hidden" name="exam_id" value="<?= h((string) ($editExam['id'] ?? 0)) ?>"><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>"><input type="hidden" name="class_id" value="<?= h((string) $classId) ?>">
<label class="form-field">Name<input name="name" required maxlength="80" value="<?= h((string) ($editExam['name'] ?? '')) ?>"></label><label class="form-field">Code<?php if ($editExam): ?><input value="<?= h($editExam['code']) ?>" readonly><input type="hidden" name="code" value="<?= h($editExam['code']) ?>"><small>The code cannot be changed. It is used in report cards.</small><?php else: ?><input name="code" required maxlength="20" pattern="[A-Za-z0-9_-]+"><?php endif; ?></label><label class="form-field">Belongs to<select name="term_group"><option value="mid" <?= ($editExam['term_group'] ?? 'mid') === 'mid' ? 'selected' : '' ?>>Mid Term</option><option value="final" <?= ($editExam['term_group'] ?? '') === 'final' ? 'selected' : '' ?>>Final Term</option></select></label>
<fieldset><legend>How are the marks obtained?</legend><label><input type="radio" name="mode" value="entered" <?= ($editExam['mode'] ?? 'entered') === 'entered' ? 'checked' : '' ?>> Teachers enter the marks</label><label><input type="radio" name="mode" value="calculated" <?= ($editExam['mode'] ?? '') === 'calculated' ? 'checked' : '' ?>> Added up from other exams</label></fieldset>
<div data-entered-panel><strong>Marks columns</strong><?php foreach ($components as $component): $selected = false; foreach ($editExam['components'] ?? [] as $oldColumn) { if ((int) $oldColumn['id'] === (int) $component['id']) $selected = true; } ?><label class="checkbox-row"><input type="checkbox" name="component_ids[]" value="<?= h((string) $component['id']) ?>" <?= $selected ? 'checked' : '' ?>> <?= h($component['name']) ?> <input name="max_defaults[<?= h((string) $component['id']) ?>]" type="text" inputmode="decimal" value="<?= h((string) ($_POST['max_defaults'][$component['id']] ?? '')) ?>" placeholder="Maximum for every subject"></label><?php endforeach; ?></div>
<div data-calculated-panel><label class="form-field">How?<select name="calc_method"><option value="sum" <?= ($editExam['calc_method'] ?? 'sum') === 'sum' ? 'selected' : '' ?>>Add them up (total)</option><option value="average" <?= ($editExam['calc_method'] ?? '') === 'average' ? 'selected' : '' ?>>Take the average</option></select></label><strong>Choose exams to work from</strong><?php foreach ($exams as $sourceExam): if ((int) $sourceExam['id'] === (int) ($editExam['id'] ?? 0)) continue; ?><label class="checkbox-row"><input type="checkbox" name="source_ids[]" value="<?= h((string) $sourceExam['id']) ?>" data-source-code="<?= h($sourceExam['code']) ?>" <?= in_array((int) $sourceExam['id'], $editExam['sources'] ?? [], true) ? 'checked' : '' ?>> <?= h($sourceExam['code'] . ' — ' . $sourceExam['name']) ?></label><?php endforeach; ?><p data-source-preview></p></div>
<details><summary>More options</summary><label><input type="checkbox" name="is_term_result" value="1" <?= !empty($editExam['is_term_result']) ? 'checked' : '' ?>> This is the term result for its term</label><label><input type="checkbox" name="has_attendance" value="1" <?= !empty($editExam['has_attendance']) ? 'checked' : '' ?>> Counts attendance</label></details>
<?php if ($editExam && (int) ($editExam['marks_count'] ?? 0) > 0): ?><div class="mode-warning" data-impact-warning data-original-mode="<?= h((string) ($exams[array_search($editExam['id'], array_column($exams, 'id'))]['mode'] ?? $editExam['mode'])) ?>" data-mark-count="<?= h((string) $editExam['marks_count']) ?>"><span data-impact-copy></span><label><input type="checkbox" name="confirm_impact" value="1"> I understand</label></div><?php endif; ?><button class="button" type="submit">Save exam</button></form></section>
<?php endif; ?>
<?php if ($exams !== []): ?>
<section class="card" id="apply-form"><h2 class="card-title">Apply this structure to other classes</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preview"><input type="hidden" name="source_type" value="class"><input type="hidden" name="source_class_id" value="<?= h((string) $classId) ?>"><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>"><?php foreach ($classes as $class): ?><label class="checkbox-row"><input type="checkbox" name="target_class_ids[]" value="<?= h((string) $class['id']) ?>"> <?= h($class['name']) ?></label><?php endforeach; ?><label><input type="checkbox" name="fill_max" value="1" checked> Also fill in the maximum marks</label><label><input type="checkbox" name="replace" value="1"> Replace a class's current structure when safe</label><button class="button button-secondary" type="submit">Preview</button></form>
<details id="apply-preset"><summary>Apply a ready-made structure to this class</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preview"><input type="hidden" name="source_type" value="preset"><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>"><input type="hidden" name="class_id" value="<?= h((string) $classId) ?>"><input type="hidden" name="target_class_ids[]" value="<?= h((string) $classId) ?>"><label class="form-field">Ready-made structure<select name="preset_id"><?php foreach ($presets as $preset): ?><option value="<?= h((string) $preset['id']) ?>"><?= h($preset['name']) ?></option><?php endforeach; ?></select></label><label><input type="checkbox" name="fill_max" value="1" checked> Also fill in the maximum marks</label><label><input type="checkbox" name="replace" value="1"> Replace this structure when safe</label><button class="button button-secondary">Preview</button></form></details>
<form method="post" id="save-preset"><?= csrf_field() ?><input type="hidden" name="action" value="save_preset"><label class="form-field">Name<input name="name" required maxlength="100"></label><label class="form-field">Short description<input name="description" maxlength="255"></label><button class="button button-secondary" type="submit">Save as my ready-made structure</button></form></section>
<section class="card"><h2 class="card-title">My ready-made structures</h2><?php $myPresets = array_filter($presets, static fn($preset) => !(int) $preset['is_builtin']); if ($myPresets === []): ?><p class="muted">You have not saved any ready-made structures yet.</p><?php else: foreach ($myPresets as $preset): ?><form method="post" class="inline-form" data-confirm="Remove the ready-made structure <?= h($preset['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_preset"><input type="hidden" name="preset_id" value="<?= h((string) $preset['id']) ?>"><span><?= h($preset['name']) ?></span><button class="button button-small button-secondary">Remove</button></form><?php endforeach; endif; ?></section>
<?php endif; ?>
<?php $savedPreview = isset($_SESSION['structure_apply_token']) ? bulk_preview_load((string) $_SESSION['structure_apply_token'], 'structure_apply') : null; if ($savedPreview): ?><section class="card"><h2 class="card-title">Review the changes</h2><p>Ready-made structure: <?= h($savedPreview['plan']['source_name']) ?></p><table><thead><tr><th>Class</th><th>Preview</th><th>Maximum marks</th></tr></thead><tbody><?php foreach ($savedPreview['plan']['rows'] as $row): ?><tr><td><?= h($row['class_name']) ?></td><td><?= h($row['reason'] ?: 'Will be created: ' . $row['exam_count'] . ' exams') ?></td><td><?= h((string) $row['maximum_cells']) ?></td></tr><?php endforeach; ?></tbody></table><?php $canApply = false; foreach ($savedPreview['plan']['rows'] as $r) { $canApply = $canApply || in_array($r['status'], ['create', 'replace'], true); } ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply_confirm"><input type="hidden" name="preview_token" value="<?= h((string) $_SESSION['structure_apply_token']) ?>"><button class="button" type="submit" <?= !$canApply ? 'disabled title="Nothing would change."' : '' ?>>Confirm</button></form></section><?php endif; ?>
<script src="<?= h(asset('js/structure_editor.js')) ?>" defer></script>
<?php require BASE_PATH . '/components/footer.php'; ?>
