<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/class_subjects.php';
require_once __DIR__ . '/../includes/teachers.php';

function assertTrue($label, $condition): void
{
    echo $condition ? 'PASS: ' . $label . PHP_EOL : 'FAIL: ' . $label . PHP_EOL;
}

assertTrue('valid_year_name accepted 2026-27', valid_year_name('2026-27'));
assertTrue('valid_year_name rejected 2026-28', !valid_year_name('2026-28'));
assertTrue('valid_year_name rejected 2026', !valid_year_name('2026'));
assertTrue('valid_year_name rejected 26-27', !valid_year_name('26-27'));
assertTrue('valid_year_name rejected 2026/27', !valid_year_name('2026/27'));

$parsed = parse_section_names("Mars, Jupiter\nmars,  Venus ");
assertTrue('parse_section_names deduplicates names', $parsed['names'] === ['Mars', 'Jupiter', 'Venus']);
$longParse = parse_section_names(str_repeat('A', 41));
assertTrue('parse_section_names catches long names', count($longParse['errors']) > 0);

$cycleMap = [1 => 2, 2 => 3, 3 => null];
assertTrue('would_create_class_cycle detects loop to I', would_create_class_cycle($cycleMap, 3, 1));
assertTrue('would_create_class_cycle detects self cycle', would_create_class_cycle($cycleMap, 3, 3));
assertTrue('would_create_class_cycle accepts null next', !would_create_class_cycle($cycleMap, 3, null));
assertTrue('would_create_class_cycle permits I->III', !would_create_class_cycle([1 => 2, 2 => 3, 3 => null], 1, 3));

$diff = diff_class_subjects([1 => 1, 2 => 0, 3 => 1], [1, 2, 4]);
assertTrue('diff_class_subjects add and reenable', $diff['add'] === [4] && $diff['reenable'] === [2] && $diff['remove'] === [3] && $diff['keep'] === [1]);

$assignmentDiff = diff_assignments([1 => 2, 2 => 3], [1 => 3, 2 => 3, 4 => 7]);
assertTrue('diff_assignments tracks add and change', $assignmentDiff['add'][4] === 7 && $assignmentDiff['change'][1] === [2, 3] && $assignmentDiff['keep'] === [2]);
assertTrue('generate_temporary_password uses BmJS prefix', str_starts_with(generate_temporary_password(), 'BmJS-'));

$blockers = user_change_blockers(['id' => 9, 'role' => 'teacher', 'is_active' => 1], ['role' => 'admin', 'is_active' => 1], 9, 1, 0, 0);
assertTrue('user_change_blockers blocks self role changes', count($blockers) > 0);

$resetRule = user_password_reset_allowed(42, 42);
assertTrue('user_password_reset_allowed blocks self reset', $resetRule['allowed'] === false && $resetRule['message'] === "Use 'Change password' for your own account.");
$resetRule = user_password_reset_allowed(42, 99);
assertTrue('user_password_reset_allowed allows another user', $resetRule['allowed'] === true && $resetRule['message'] === '');
