<?php
if (!isset($sp_year_id)) {
    $sp_year_id = (int) (active_year()['id'] ?? 0);
}
if (!isset($sp_field)) {
    $sp_field = 'class_ids[]';
}
if (!isset($sp_selected) || !is_array($sp_selected)) {
    $sp_selected = [];
}

$selectedClasses = array_fill_keys(array_map('strval', $sp_selected), true);
$counts = scope_class_counts((int) $sp_year_id);
$classes = db()->prepare('SELECT * FROM classes ORDER BY sort_order, id');
$classes->execute();
?>
<div class="scope-picker">
    <div class="scope-picker-actions">
        <a href="#" data-check-all="scope-picker">Select all</a>
        <a href="#" data-check-all="scope-picker-clear">Clear</a>
    </div>
    <?php foreach ($classes as $class): ?>
        <?php $classId = (int) $class['id']; ?>
        <?php $countInfo = $counts[$classId] ?? ['sections' => 0, 'students' => 0]; ?>
        <label class="scope-item">
            <input type="checkbox" name="<?= h($sp_field) ?>" value="<?= h((string) $classId) ?>" data-group="scope-picker" <?= isset($selectedClasses[(string) $classId]) ? 'checked' : '' ?>>
            <span><?= h($class['name']) ?>, <?= h((string) $countInfo['sections']) ?> sections, <?= h((string) $countInfo['students']) ?> students</span>
        </label>
    <?php endforeach; ?>
</div>
