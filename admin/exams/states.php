<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/structure.php';
require_once __DIR__ . '/../../includes/structure_edit.php';
require_once __DIR__ . '/../../includes/bulk.php';

require_login();
require_role(['admin', 'super_admin']);

$years = db()->query('SELECT id, name FROM academic_years ORDER BY name DESC')->fetchAll();
$activeYear = active_year();
$yearId = (int) ($_REQUEST['year_id'] ?? $_SESSION['exam_year_id'] ?? ($activeYear['id'] ?? 0));
$_SESSION['exam_year_id'] = $yearId;
$errors = [];
$notice = '';
$classes = db()->query('SELECT id, name FROM classes ORDER BY sort_order, id')->fetchAll();
$classNames = [];
foreach ($classes as $class) {
    $classNames[(int) $class['id']] = (string) $class['name'];
}
if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'change_state') {
        $result = set_exam_state((int) ($_POST['exam_id'] ?? 0), (string) ($_POST['state'] ?? ''), (string) current_user()['role']);
        if ($result['ok']) {
            $notice = $result['message'];
        } else {
            $errors[] = $result['message'];
        }
    } elseif ($action === 'bulk_preview') {
        $classValues = is_array($_POST['class_ids'] ?? null) ? $_POST['class_ids'] : [];
        $codeValues = is_array($_POST['codes'] ?? null) ? $_POST['codes'] : [];
        $classIds = array_values(array_unique(array_map('intval', array_filter($classValues, 'is_scalar'))));
        $codes = array_values(array_unique(array_map('strval', array_filter($codeValues, 'is_scalar'))));
        $target = is_string($_POST['state'] ?? null) ? $_POST['state'] : '';
        $rows = [];
        unset($_SESSION['exam_state_preview_token']);
        if ($classIds === [] || $codes === [] || !in_array($target, ['draft', 'open', 'locked'], true)) {
            $errors[] = 'Choose at least one class, one exam and a new state.';
        } else {
            foreach ($classIds as $classId) {
                $q = db()->prepare('SELECT id, code, state FROM assessments WHERE academic_year_id = :year AND class_id = :class AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ') ORDER BY sort_order, id');
                $q->execute(array_merge([$yearId, $classId], $codes));
                foreach ($q->fetchAll() as $exam) {
                    $allowed = state_transition_allowed((string) $exam['state'], $target, (string) current_user()['role']);
                    $rows[] = ['exam_id' => (int) $exam['id'], 'code' => $exam['code'], 'class_id' => $classId, 'from' => $exam['state'], 'to' => $target, 'allowed' => $allowed['ok'], 'reason' => $allowed['reason']];
                }
            }
            if ($rows === []) {
                $errors[] = 'No matching exams were found for those classes.';
            } else {
                $token = bulk_preview_save('exam_states', ['year_id' => $yearId, 'class_ids' => $classIds, 'codes' => $codes, 'rows' => $rows]);
                $_SESSION['exam_state_preview_token'] = $token;
            }
        }
    } elseif ($action === 'bulk_confirm') {
        $token = (string) ($_SESSION['exam_state_preview_token'] ?? '');
        $preview = $token !== '' ? bulk_preview_load($token, 'exam_states') : null;
        if (!is_array($preview) || empty($preview['class_ids']) || empty($preview['codes']) || empty($preview['rows']) || (int) $preview['year_id'] !== $yearId) {
            $errors[] = 'This preview expired. Please prepare it again.';
        } else {
            try {
                db()->beginTransaction();
                $current = [];
                foreach ($preview['class_ids'] as $classId) {
                    $q = db()->prepare('SELECT id, code, state FROM assessments WHERE academic_year_id = :year AND class_id = :class AND code IN (' . implode(',', array_fill(0, count($preview['codes']), '?')) . ') ORDER BY sort_order, id FOR UPDATE');
                    $q->execute(array_merge([$yearId, $classId], $preview['codes']));
                    foreach ($q->fetchAll() as $exam) $current[(int) $exam['id']] = $exam;
                }
                $previewIds = array_map(static fn($row) => (int) $row['exam_id'], $preview['rows']);
                $currentIds = array_keys($current);
                sort($currentIds);
                sort($previewIds);
                if ($currentIds !== $previewIds) {
                    throw new RuntimeException('Something changed while you were checking. Please review the changes again.');
                }
                $changes = [];
                foreach ($preview['rows'] as $row) {
                    $exam = $current[(int) $row['exam_id']] ?? null;
                    $allowed = $exam ? state_transition_allowed((string) $exam['state'], (string) $row['to'], (string) current_user()['role']) : ['ok' => false];
                    if (!$exam || $exam['state'] !== $row['from'] || $allowed['ok'] !== $row['allowed']) {
                        throw new RuntimeException('Something changed while you were checking. Please review the changes again.');
                    }
                    if ($allowed['ok']) $changes[] = ['id' => (int) $row['exam_id'], 'to' => $row['to']];
                }
                $update = db()->prepare('UPDATE assessments SET state = :state WHERE id = :id AND academic_year_id = :year');
                foreach ($changes as $change) {
                    $update->execute([':state' => $change['to'], ':id' => $change['id'], ':year' => $yearId]);
                }
                log_action('exam_states_changed', 'assessments', null, null, ['changed' => count($changes), 'skipped' => count($preview['rows']) - count($changes)]);
                db()->commit();
                bulk_preview_clear($token);
                unset($_SESSION['exam_state_preview_token']);
                $notice = count($changes) . ' exam states updated.';
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                if ($exception instanceof RuntimeException) {
                    $errors[] = $exception->getMessage();
                    bulk_preview_clear($token);
                    unset($_SESSION['exam_state_preview_token']);
                } else {
                    error_log('Bulk exam state update failed: ' . $exception->getMessage());
                    $errors[] = 'The exam states could not be updated. Please review the changes and try again.';
                }
            }
        }
    }
}
$statement = db()->prepare('SELECT a.*, c.name AS class_name FROM assessments a JOIN classes c ON c.id = a.class_id WHERE a.academic_year_id = :year ORDER BY c.sort_order, a.sort_order, a.id');
$statement->execute([':year' => $yearId]);
$examRows = $statement->fetchAll();
$codes = [];
foreach ($examRows as $exam) {
    $codes[$exam['code']] = min($codes[$exam['code']] ?? PHP_INT_MAX, (int) $exam['sort_order']);
}
asort($codes);
$lookup = [];
foreach ($examRows as $exam) {
    $lookup[(int) $exam['class_id']][(string) $exam['code']] = $exam;
}
$page_title = 'Open and close exams';
$page_description = 'Teachers can only enter marks while an exam is open.';
$tabs = ['Structure' => 'admin/exams/structure.php?year_id=' . $yearId, 'Maximum marks' => 'admin/exams/max_marks.php?year_id=' . $yearId, 'Open and close' => 'admin/exams/states.php?year_id=' . $yearId];
$active = 'Open and close';
require BASE_PATH . '/components/header.php';
require BASE_PATH . '/components/tabs.php';
?>
<section class="card"><form method="get" class="filter-grid"><label class="form-field compact">Academic year<select name="year_id"><?php foreach ($years as $year): ?><option value="<?= h((string) $year['id']) ?>" <?= (int) $year['id'] === $yearId ? 'selected' : '' ?>><?= h($year['name']) ?></option><?php endforeach; ?></select></label><button class="button button-secondary">Show</button></form></section>
<?php if ($notice !== ''): ?><section class="card"><div class="alert alert-success"><?= h($notice) ?></div></section><?php endif; ?>
<?php if ($errors !== []): ?><section class="card"><div class="alert alert-error"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div></section><?php endif; ?>
<section class="card"><h2 class="card-title">Exam states</h2><div class="table-wrap"><table><thead><tr><th>Class</th><?php foreach ($codes as $code => $_): ?><th><?= h($code) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($classes as $class): ?><tr><th><?= h($class['name']) ?></th><?php foreach ($codes as $code => $_): $exam = $lookup[(int) $class['id']][$code] ?? null; ?><td><?php if (!$exam): ?>—<?php else: ?><span class="state-badge state-<?= h($exam['state']) ?>"><?= h(ucfirst($exam['state'])) ?></span><?php if ($exam['mode'] === 'calculated'): ?><small>worked out, not typed</small><?php endif; ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="change_state"><input type="hidden" name="exam_id" value="<?= h((string) $exam['id']) ?>"><select name="state"><?php foreach (['draft' => 'Draft', 'open' => 'Open', 'locked' => 'Locked'] as $state => $label): $allowed = state_transition_allowed($exam['state'], $state, (string) current_user()['role']); ?><option value="<?= h($state) ?>" <?= $state === $exam['state'] ? 'selected' : '' ?> <?= !$allowed['ok'] && $state !== $exam['state'] ? 'disabled title="' . h($allowed['reason']) . '"' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select><button class="button button-small button-secondary">Save</button></form><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><p class="muted">Draft = not ready yet; teachers cannot enter marks. Open = teachers can enter marks. Locked = finished; only the super admin can reopen it.</p></section>
<section class="card"><h2 class="card-title">Change several at once</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_preview"><div class="form-grid"><label>Classes</label><div><?php foreach ($classes as $class): ?><label class="checkbox-row"><input type="checkbox" name="class_ids[]" value="<?= h((string) $class['id']) ?>"> <?= h($class['name']) ?></label><?php endforeach; ?></div><label>Exams</label><div><?php foreach ($codes as $code => $_): ?><label class="checkbox-row"><input type="checkbox" name="codes[]" value="<?= h($code) ?>"> <?= h($code) ?></label><?php endforeach; ?></div><label>New state<select name="state"><option value="draft">Draft</option><option value="open">Open</option><option value="locked">Locked</option></select></label></div><button class="button button-secondary">Preview changes</button></form></section>
<?php $previewToken = (string) ($_SESSION['exam_state_preview_token'] ?? ''); $preview = $previewToken !== '' ? bulk_preview_load($previewToken, 'exam_states') : null; if ($preview && (int) ($preview['year_id'] ?? 0) === $yearId): ?><section class="card"><h2 class="card-title">Review changes</h2><table><thead><tr><th>Class</th><th>Exam</th><th>Change</th></tr></thead><tbody><?php foreach ($preview['rows'] as $row): ?><tr><td><?= h($classNames[$row['class_id']] ?? (string) $row['class_id']) ?></td><td><?= h($row['code']) ?></td><td><?= $row['from'] === $row['to'] ? 'Already ' . h(ucfirst($row['from'])) : ($row['allowed'] ? 'Will change from ' . h(ucfirst($row['from'])) . ' to ' . h(ucfirst($row['to'])) : h($row['reason'])) ?></td></tr><?php endforeach; ?></tbody></table><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_confirm"><button class="button" type="submit">Confirm changes</button></form></section><?php endif; ?>
<?php require BASE_PATH . '/components/footer.php'; ?>
