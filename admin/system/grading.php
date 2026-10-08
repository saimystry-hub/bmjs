<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/grading.php';
require_once __DIR__ . '/../../includes/bulk.php';

require_login();
require_role(['super_admin']);

$years = db()->query('SELECT id, name, is_active FROM academic_years ORDER BY name DESC')->fetchAll();
$activeYear = active_year();
$requestedYear = $_REQUEST['year_id'] ?? $_SESSION['grading_year_id'] ?? ($activeYear['id'] ?? 0);
$yearId = is_numeric($requestedYear) ? (int) $requestedYear : (int) ($activeYear['id'] ?? 0);
$knownYear = false;
foreach ($years as $year) if ((int) $year['id'] === $yearId) $knownYear = true;
if (!$knownYear && $years !== []) $yearId = (int) ($activeYear['id'] ?? $years[0]['id']);
$_SESSION['grading_year_id'] = $yearId;
$errors = [];
$notice = '';
$preview = null;
$form = ['band_label' => [], 'band_min' => [], 'rounding' => 'nearest', 'marks_decimals' => 2, 'percent_decimals' => 0, 'reason' => ''];
try {
    $current = get_year_grading($yearId);
    $form = ['band_label' => array_column($current['bands'], 'label'), 'band_min' => array_column($current['bands'], 'min'), 'rounding' => $current['rounding'], 'marks_decimals' => $current['marks_decimals'], 'percent_decimals' => $current['percent_decimals'], 'reason' => ''];
} catch (Throwable $exception) {
    error_log('Load grading page failed: ' . $exception->getMessage());
    $errors[] = 'The grade rules could not be loaded.';
    $current = ['bands' => [], 'scale_name' => '', 'years_using' => 0, 'result_count' => 0, 'year_name' => ''];
}
if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'standard') {
        $form = ['band_label' => ['A*', 'A', 'B', 'C', 'D', 'U'], 'band_min' => ['85', '75', '65', '55', '50', '0'], 'rounding' => $form['rounding'], 'marks_decimals' => $form['marks_decimals'], 'percent_decimals' => $form['percent_decimals'], 'reason' => ''];
    } elseif ($action === 'preview') {
        $parsed = bands_from_post($_POST);
        $form = ['band_label' => $_POST['band_label'] ?? [], 'band_min' => $_POST['band_min'] ?? [], 'rounding' => (string) ($_POST['rounding'] ?? 'nearest'), 'marks_decimals' => (string) ($_POST['marks_decimals'] ?? '2'), 'percent_decimals' => (string) ($_POST['percent_decimals'] ?? '0'), 'reason' => trim((string) ($_POST['reason'] ?? ''))];
        $errors = $parsed['errors'];
        if (!in_array($form['rounding'], ['nearest', 'none', 'down'], true)) $errors[] = 'Choose a rounding rule.';
        foreach (['marks_decimals', 'percent_decimals'] as $field) if (!in_array((string) $form[$field], ['0', '1', '2'], true)) $errors[] = 'Choose 0, 1 or 2 displayed decimal places.';
        if (mb_strlen($form['reason']) > 200) $errors[] = 'The reason cannot be longer than 200 characters.';
        if ($errors === []) {
            try {
                $impact = grading_impact($yearId, $parsed['bands'], $form['rounding']);
                $previewData = ['year_id' => $yearId, 'bands' => $parsed['bands'], 'rounding' => $form['rounding'], 'marks_decimals' => (int) $form['marks_decimals'], 'percent_decimals' => (int) $form['percent_decimals'], 'reason' => $form['reason'], 'impact' => $impact, 'old_rounding' => $current['rounding'], 'old_marks_decimals' => $current['marks_decimals'], 'old_percent_decimals' => $current['percent_decimals']];
                $token = bulk_preview_save('grading_save', $previewData);
                $_SESSION['grading_preview_token'] = $token;
                $preview = $previewData;
            } catch (Throwable $exception) {
                error_log('Prepare grading preview failed: ' . $exception->getMessage());
                $errors[] = 'The grade changes could not be previewed. Please try again.';
            }
        }
    } elseif ($action === 'back') {
        $token = (string) ($_SESSION['grading_preview_token'] ?? '');
        if ($token !== '') bulk_preview_clear($token);
        unset($_SESSION['grading_preview_token']);
        $form = ['band_label' => $_POST['band_label'] ?? [], 'band_min' => $_POST['band_min'] ?? [], 'rounding' => (string) ($_POST['rounding'] ?? $current['rounding']), 'marks_decimals' => (string) ($_POST['marks_decimals'] ?? $current['marks_decimals']), 'percent_decimals' => (string) ($_POST['percent_decimals'] ?? $current['percent_decimals']), 'reason' => (string) ($_POST['reason'] ?? '')];
    } elseif ($action === 'confirm') {
        $token = (string) ($_POST['preview_token'] ?? '');
        if (!empty($_SESSION['applied_grading_tokens'][$token])) {
            $notice = 'These grade changes were already saved.';
        } else {
            $previewData = bulk_preview_load($token, 'grading_save');
            if (!$previewData || (int) $previewData['year_id'] !== $yearId) {
                $errors[] = 'This preview expired. Please prepare it again.';
            } else {
                try {
                    $fresh = grading_impact($yearId, $previewData['bands'], $previewData['rounding']);
                    if (!hash_equals((string) $previewData['impact']['signature'], (string) $fresh['signature'])) {
                        $previewData['impact'] = $fresh;
                        bulk_preview_clear($token);
                        $token = bulk_preview_save('grading_save', $previewData);
                        $_SESSION['grading_preview_token'] = $token;
                        $preview = $previewData;
                        $errors[] = 'The marks changed while you were checking. Please review again.';
                    } else {
                        $savedImpact = save_year_grading($yearId, $previewData['bands'], $previewData['rounding'], $previewData['marks_decimals'], $previewData['percent_decimals'], $previewData['reason'], (int) current_user()['id']);
                        $current = get_year_grading($yearId);
                        $form = ['band_label' => array_column($current['bands'], 'label'), 'band_min' => array_column($current['bands'], 'min'), 'rounding' => $current['rounding'], 'marks_decimals' => $current['marks_decimals'], 'percent_decimals' => $current['percent_decimals'], 'reason' => ''];
                        bulk_preview_clear($token);
                        unset($_SESSION['grading_preview_token']);
                        $_SESSION['applied_grading_tokens'][$token] = true;
                        $notice = 'Grades saved. ' . $savedImpact['results_changed'] . ' results changed grade.';
                    }
                } catch (Throwable $exception) {
                    error_log('Confirm grading update failed: ' . $exception->getMessage());
                    $errors[] = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The grade rules could not be saved. Please try again.';
                }
            }
        }
    }
}
$previewToken = (string) ($_SESSION['grading_preview_token'] ?? '');
if ($preview === null && $previewToken !== '') {
    $preview = bulk_preview_load($previewToken, 'grading_save');
    if (!$preview || (int) $preview['year_id'] !== $yearId) {
        bulk_preview_clear($previewToken);
        unset($_SESSION['grading_preview_token']);
        $preview = null;
    }
}
if ($preview !== null) {
    $form = ['band_label' => array_column($preview['bands'], 'label'), 'band_min' => array_column($preview['bands'], 'min'), 'rounding' => $preview['rounding'], 'marks_decimals' => $preview['marks_decimals'], 'percent_decimals' => $preview['percent_decimals'], 'reason' => $preview['reason']];
}
$page_title = 'Grades';
$page_description = 'Decide which percentage earns which grade.';
require BASE_PATH . '/components/header.php';
?>
<section class="card"><form method="get" class="filter-grid"><label class="form-field compact">Academic year<select name="year_id"><?php foreach ($years as $year): ?><option value="<?= h((string) $year['id']) ?>" <?= (int) $year['id'] === $yearId ? 'selected' : '' ?>><?= h($year['name']) ?></option><?php endforeach; ?></select></label><button class="button button-secondary">Show</button></form><p>Grade scale: <?= h($current['scale_name']) ?>. Used by: <?= h((string) $current['years_using']) ?> year(s). <?= h((string) $current['result_count']) ?> results have been entered in this year.</p></section>
<?php if ($notice !== ''): ?><section class="card"><div class="alert alert-success"><?= h($notice) ?></div></section><?php endif; ?>
<?php if ($errors !== []): ?><section class="card"><div class="alert alert-error" role="alert"><?php foreach (array_unique($errors) as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div></section><?php endif; ?>
<?php if ($preview !== null): ?>
<section class="card"><h2 class="card-title">Review grade changes</h2><p><?= h((string) $preview['impact']['results_changed']) ?> of <?= h((string) $preview['impact']['results_total']) ?> entered results would change grade. <?= h((string) $preview['impact']['students_affected']) ?> students are affected.</p>
<div class="table-wrap grading-transition-wrap"><table class="grading-transition-table"><thead><tr><th>From</th><th>To</th><th>Results</th></tr></thead><tbody><?php foreach ($preview['impact']['transitions'] as $row): ?><tr><td><?= h((string) $row['from']) ?></td><td><?= h((string) $row['to']) ?></td><td><?= h((string) $row['count']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if ($preview['impact']['examples'] !== []): ?><h3>Examples</h3><ul><?php foreach ($preview['impact']['examples'] as $example): ?><li><?= h($example['student'] . ' · ' . $example['class'] . ' · ' . $example['subject'] . ' · ' . $example['exam'] . ': ' . $example['from'] . ' to ' . $example['to']) ?></li><?php endforeach; ?></ul><?php endif; ?>
<ul><li>This changes the grades on the report cards of <?= h($current['year_name']) ?>.</li><?php if ($preview['rounding'] !== $preview['old_rounding'] || $preview['marks_decimals'] !== $preview['old_marks_decimals'] || $preview['percent_decimals'] !== $preview['old_percent_decimals']): ?><li>Rounding and decimals apply to every year.</li><?php endif; ?><?php if ((int) $current['years_using'] > 1): ?><li>A separate copy of the grades will be made for <?= h($current['year_name']) ?>, so the other years do not change.</li><?php endif; ?></ul>
<form method="post" class="button-row"><?= csrf_field() ?><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="preview_token" value="<?= h($previewToken) ?>"><button class="button" type="submit">Confirm and save</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>"><input type="hidden" name="action" value="back"><?php foreach ($form['band_label'] as $i => $label): ?><input type="hidden" name="band_label[]" value="<?= h((string) $label) ?>"><input type="hidden" name="band_min[]" value="<?= h((string) ($form['band_min'][$i] ?? '')) ?>"><?php endforeach; ?><input type="hidden" name="rounding" value="<?= h((string) $form['rounding']) ?>"><input type="hidden" name="marks_decimals" value="<?= h((string) $form['marks_decimals']) ?>"><input type="hidden" name="percent_decimals" value="<?= h((string) $form['percent_decimals']) ?>"><input type="hidden" name="reason" value="<?= h((string) $form['reason']) ?>"><button class="button button-secondary">Back to edit</button></form></section>
<?php else: ?>
<form method="post" class="grading-page" data-grading-page data-year="<?= h((string) $yearId) ?>" data-preview-url="<?= h(url('api/preview_grading.php')) ?>">
<?= csrf_field() ?><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>">
<section class="card"><h2 class="card-title">Grades</h2><div class="table-wrap"><table class="grading-band-table"><thead><tr><th>Grade</th><th>From %</th><th>Action</th></tr></thead><tbody data-band-rows><?php foreach ($form['band_label'] as $i => $label): ?><tr><td><input name="band_label[]" maxlength="10" value="<?= h((string) $label) ?>" aria-label="Grade label"></td><td><input name="band_min[]" inputmode="decimal" value="<?= h((string) ($form['band_min'][$i] ?? '')) ?>" aria-label="Minimum percentage"></td><td><button type="button" class="button button-small button-secondary" data-remove-band>Remove</button></td></tr><?php endforeach; ?></tbody></table></div><p class="muted">The lowest grade must start at 0.</p><div class="button-row"><button type="button" class="button button-secondary" data-add-band>Add a grade</button><button class="button button-secondary" name="action" value="standard">Use the standard grades</button></div></section>
<section class="card"><h2 class="card-title">Rounding and numbers</h2><fieldset class="grading-rounding"><?php foreach (['nearest' => 'Round to the nearest whole percent first (84.5% counts as 85%)', 'none' => 'Use the exact percent (84.99% stays below 85%)', 'down' => 'Cut off the decimals (84.99% counts as 84%)'] as $value => $label): ?><label class="checkbox-row"><input type="radio" name="rounding" value="<?= h($value) ?>" <?= $form['rounding'] === $value ? 'checked' : '' ?>> <?= h($label) ?></label><?php endforeach; ?></fieldset><div class="form-grid"><label>Decimals shown for marks<select name="marks_decimals"><?php for ($i = 0; $i <= 2; $i++): ?><option value="<?= $i ?>" <?= (string) $form['marks_decimals'] === (string) $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></label><label>Decimals shown for percentages<select name="percent_decimals"><?php for ($i = 0; $i <= 2; $i++): ?><option value="<?= $i ?>" <?= (string) $form['percent_decimals'] === (string) $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></label></div><p class="muted">These settings apply to every academic year.</p></section>
<section class="card"><h2 class="card-title">Why are you changing this?</h2><label class="form-field">Reason (optional)<input name="reason" maxlength="200" value="<?= h((string) $form['reason']) ?>"></label></section>
<section class="card"><h2 class="card-title">Try it</h2><div class="form-grid"><label>Percentage<input data-try-percent inputmode="decimal"></label><label>Marks<input data-try-marks inputmode="decimal"></label><label>Maximum<input data-try-max inputmode="decimal"></label></div><p data-try-result class="muted" aria-live="polite"></p><p data-live-impact class="muted" aria-live="polite"></p></section>
<section class="card"><button class="button button-large" type="submit" name="action" value="preview">Preview changes</button></section>
</form>
<?php require BASE_PATH . '/components/grading_results_check.php'; ?>
<?php endif; ?>
<script src="<?= h(asset('js/grading.js')) ?>" defer></script>
<?php require BASE_PATH . '/components/footer.php'; ?>
