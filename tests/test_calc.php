<?php
require_once __DIR__ . '/../includes/calc.php';
require_once __DIR__ . '/../includes/structure.php';

$checks = 0;
$failures = 0;
$bands = [
    ['label' => 'A*', 'min' => 85], ['label' => 'A', 'min' => 75],
    ['label' => 'B', 'min' => 65], ['label' => 'C', 'min' => 55],
    ['label' => 'D', 'min' => 50], ['label' => 'U', 'min' => 0],
];

// Print and count one named, non-database calculation test.
function check_calc(string $name, bool $passed): void
{
    global $checks, $failures;
    $checks++;
    if (!$passed) $failures++;
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $name . PHP_EOL;
}

// Split a total between the seeded ten-mark and fifteen-mark columns.
// Split a subject total in proportion to its two marks columns.
function split_total(float $total): array
{
    $fa = round($total * 10 / 25, 2, PHP_ROUND_HALF_UP);
    return ['F.A' => ['value' => $fa, 'status' => 'entered'], 'S.A' => ['value' => round($total - $fa, 2), 'status' => 'entered']];
}

// Return one entered-exam result from column totals and marks.
// Calculate an entered exam using the shared fixture's default grade bands.
function entered(array $max, array $marks, array $gradeBands = [], string $rounding = 'nearest', string $name = 'CP1'): array
{
    global $bands;
    $exam = ['code' => $name, 'name' => $name, 'subject_name' => 'Mathematics', 'components' => array_keys($max)];
    return calc_entered_exam($exam, $marks, $max, $gradeBands ?: $bands, $rounding);
}

$totals = [25, 23.5, 21.25, 22, 25, 24.75, 23.5, 21.25];
$subjectResults = [];
$impactItems = [];
foreach ($totals as $id => $total) {
    $subjectResults[$id]['CP1'] = entered(['F.A' => 10, 'S.A' => 15], split_total($total));
    $impactItems[] = ['tmo_c' => to_cents($total), 'max_c' => 2500, 'ref' => $id];
}
$grand = calc_grand_totals([['code' => 'CP1']], $subjectResults, array_keys($totals), $bands, 'nearest')['CP1'];
check_calc('1. Eight-subject totals, percentages, grades and grand total', $grand['tmo'] === 186.25 && $grand['max'] === 200.0 && fmt_percent($grand['percent']) === '93' && $grand['grade'] === 'A*' && array_column(array_column($subjectResults, 'CP1'), 'grade') === array_fill(0, 8, 'A*') && array_map(static fn($r) => fmt_percent($r['percent']), array_column($subjectResults, 'CP1')) === ['100', '94', '85', '88', '100', '99', '94', '85']);

$higherBands = $bands;
$higherBands[0]['min'] = 90;
$impact = grade_impact($impactItems, $bands, 'nearest', $higherBands, 'nearest');
check_calc('2. Raising A* to 90 changes exactly three subject grades', $impact['changed'] === 3 && $impact['transitions'][0] === ['from' => 'A*', 'to' => 'A', 'count' => 3] && grade_for(18625, 20000, $higherBands, 'nearest') === 'A*');

$presetA = [
    'CP1' => ['code' => 'CP1', 'mode' => 'entered', 'components' => ['F.A', 'S.A']],
    'CP2' => ['code' => 'CP2', 'mode' => 'entered', 'components' => ['F.A', 'S.A']],
    'CP3' => ['code' => 'CP3', 'mode' => 'entered', 'components' => ['F.A', 'S.A']],
    'MT' => ['code' => 'MT', 'mode' => 'calculated', 'calc_method' => 'sum', 'components' => [], 'sources' => ['CP1', 'CP2', 'CP3']],
];
$presetMax = ['CP1' => ['F.A' => 10, 'S.A' => 15], 'CP2' => ['F.A' => 10, 'S.A' => 15], 'CP3' => ['F.A' => 10, 'S.A' => 15]];
$presetMarks = ['CP1' => split_total(25), 'CP2' => split_total(22), 'CP3' => split_total(25)];
$mt = calc_student_subject($presetA, $presetMax, $presetMarks, $bands, 'nearest');
$missingMt = calc_student_subject($presetA, $presetMax, ['CP1' => $presetMarks['CP1'], 'CP3' => $presetMarks['CP3']], $bands, 'nearest')['MT'];
check_calc('3. Preset A Mid Term sums sources and waits for a missing exam', $mt['MT']['tmo'] === 72.0 && $mt['MT']['max'] === 75.0 && $mt['MT']['grade'] === 'A*' && $missingMt['status'] === 'incomplete' && $missingMt['grade'] === null);

$switched = $presetA;
$switched['MT'] = ['code' => 'MT', 'mode' => 'entered', 'components' => ['S.A'], 'sources' => ['CP1', 'CP2', 'CP3']];
$enteredMt = calc_student_subject($switched, $presetMax + ['MT' => ['S.A' => 75]], $presetMarks + ['MT' => ['S.A' => ['value' => 60, 'status' => 'entered']]], $bands, 'nearest');
$restoredMt = calc_student_subject($presetA, $presetMax + ['MT' => ['S.A' => 75]], $presetMarks + ['MT' => ['S.A' => ['value' => 60, 'status' => 'entered'], 'CP1' => $presetMarks['CP1']]], $bands, 'nearest');
$changedCp = calc_student_subject($switched, $presetMax + ['MT' => ['S.A' => 75]], ['CP1' => split_total(0), 'CP2' => split_total(0), 'CP3' => split_total(0), 'MT' => ['S.A' => ['value' => 60, 'status' => 'entered']],], $bands, 'nearest');
check_calc('4. Switching a remembered exam to entered and back ignores remembered marks', $enteredMt['MT']['tmo'] === 60.0 && $enteredMt['MT']['grade'] === 'A' && $changedCp['MT']['tmo'] === 60.0 && $restoredMt['MT']['tmo'] === 72.0);

$presetB = ['MT' => ['code' => 'MT', 'mode' => 'entered', 'components' => ['S.A']]];
$presetC = ['FT' => ['code' => 'FT', 'mode' => 'entered', 'components' => ['S.A']]];
$resultB = calc_student_subject($presetB, ['MT' => ['S.A' => 25]], ['MT' => ['S.A' => ['value' => 20, 'status' => 'entered']]], $bands, 'nearest');
$resultC = calc_student_subject($presetC, ['FT' => ['S.A' => 50]], ['FT' => ['S.A' => ['value' => 45, 'status' => 'entered']]], $bands, 'nearest');
check_calc('5. Preset B and C separate term marks grade independently', $resultB['MT']['grade'] === 'A' && $resultC['FT']['grade'] === 'A*');

$changedMax = calc_student_subject($presetA, array_replace($presetMax, ['CP1' => ['F.A' => 12, 'S.A' => 15]]), $presetMarks, $bands, 'nearest')['MT'];
check_calc('6. Changing a source maximum changes derived maximum and displayed percent', $changedMax['max'] === 77.0 && fmt_percent($changedMax['percent']) === '94');

$rounds = [
    grade_for(16900, 20000, $bands, 'nearest') === 'A*' && grade_for(16900, 20000, $bands, 'none') === 'A' && grade_for(16900, 20000, $bands, 'down') === 'A',
    grade_for(14900, 20000, $bands, 'nearest') === 'A' && grade_for(14900, 20000, $bands, 'none') === 'B' && grade_for(14900, 20000, $bands, 'down') === 'B',
    grade_for(9900, 20000, $bands, 'nearest') === 'D' && grade_for(9900, 20000, $bands, 'none') === 'U' && grade_for(9900, 20000, $bands, 'down') === 'U',
];
check_calc('7. Nearest, exact and down rounding match boundary rules', !in_array(false, $rounds, true));

$absentOne = entered(['F.A' => 10, 'S.A' => 15], ['F.A' => ['status' => 'absent'], 'S.A' => ['value' => 10, 'status' => 'entered']]);
$absentBoth = entered(['F.A' => 10, 'S.A' => 15], ['F.A' => ['status' => 'absent'], 'S.A' => ['status' => 'absent']]);
$exempt = entered(['F.A' => 10, 'S.A' => 15], ['F.A' => ['status' => 'exempt']]);
$incomplete = entered(['F.A' => 10, 'S.A' => 15], ['F.A' => ['value' => 5, 'status' => 'entered']]);
$noMax = entered(['F.A' => 0, 'S.A' => 15], ['F.A' => ['status' => 'exempt']]);
check_calc('8. Absent, exempt, incomplete, no maximum and precedence', $absentOne['tmo'] === 10.0 && $absentOne['absent'] && $absentBoth['tmo'] === 0.0 && $exempt['status'] === 'exempt' && $incomplete['status'] === 'incomplete' && $noMax['status'] === 'exempt');

$subjectsCase9 = array_fill_keys(range(1, 8), ['kind' => 'marks', 'counts' => true]);
$subjectsCase9[9] = ['kind' => 'grade', 'counts' => true];
$subjectsCase9[10] = ['kind' => 'marks', 'counts' => false];
$maxCase9 = $marksCase9 = [];
for ($subjectId = 1; $subjectId <= 8; $subjectId++) {
    $maxCase9[$subjectId]['CP1'] = ['F.A' => 10, 'S.A' => 15];
    $marksCase9[4][$subjectId]['CP1'] = split_total(25);
}
$maxCase9[10]['CP1'] = ['F.A' => 10, 'S.A' => 15];
$marksCase9[4][10]['CP1'] = split_total(25);
$case9 = ['bands' => $bands, 'rounding' => 'nearest', 'exams' => ['CP1' => ['code' => 'CP1', 'mode' => 'entered', 'components' => ['F.A', 'S.A'], 'term_group' => 'mid']], 'subjects' => $subjectsCase9, 'max' => $maxCase9, 'marks' => $marksCase9, 'grades' => [4 => [9 => ['CP1' => 'A']]], 'student_ids' => [4]];
$withGradeOnly = calc_all($case9);
$subjectsCase9[8]['counts'] = false;
$case9['subjects'] = $subjectsCase9;
$withOneUncounted = calc_all($case9);
check_calc('9. Grade-only subjects do not change eight-subject total and an uncounted subject reduces its maximum', $withGradeOnly[4]['grand']['CP1']['tmo'] === 200.0 && $withGradeOnly[4]['grand']['CP1']['max'] === 200.0 && $withGradeOnly[4]['subjects'][9]['CP1']['grade'] === 'A' && $withOneUncounted[4]['grand']['CP1']['tmo'] === 175.0 && $withOneUncounted[4]['grand']['CP1']['max'] === 175.0);

$avgExams = ['MT' => ['code' => 'MT', 'mode' => 'entered', 'components' => ['S.A']], 'FT' => ['code' => 'FT', 'mode' => 'entered', 'components' => ['S.A']], 'AVG' => ['code' => 'AVG', 'mode' => 'calculated', 'calc_method' => 'average', 'sources' => ['MT', 'FT'], 'components' => []]];
$avgResults = calc_student_subject($avgExams, ['MT' => ['S.A' => 75], 'FT' => ['S.A' => 75]], ['MT' => ['S.A' => ['value' => 75, 'status' => 'entered']], 'FT' => ['S.A' => ['value' => 60, 'status' => 'entered']]], $bands, 'nearest');
$oddAverage = calc_calculated_exam(['calc_method' => 'average', 'sources' => ['x', 'y']], ['x' => ['status' => 'ok', 'tmo' => 25, 'max' => 50, 'absent' => false], 'y' => ['status' => 'ok', 'tmo' => 50, 'max' => 50, 'absent' => false]], $bands, 'nearest');
check_calc('10. Average combines marks and maxima with hundredths rounding', $avgResults['AVG']['tmo'] === 67.5 && $avgResults['AVG']['max'] === 75.0 && $avgResults['AVG']['grade'] === 'A*' && $oddAverage['tmo'] === 37.5);

$attendanceExams = [['code' => 'CP1', 'term_group' => 'mid', 'has_attendance' => 1], ['code' => 'CP2', 'term_group' => 'mid', 'has_attendance' => 1], ['code' => 'CP3', 'term_group' => 'mid', 'has_attendance' => 1], ['code' => 'X', 'term_group' => 'mid', 'has_attendance' => 0]];
$attendance = term_attendance($attendanceExams, ['CP1' => ['present' => 23, 'total_days' => 25], 'CP2' => ['present' => 24, 'total_days' => 25], 'CP3' => ['present' => 25, 'total_days' => 25]], 'mid');
check_calc('11. Term attendance totals, missing rows and no-attendance terms', $attendance['present'] === 72 && $attendance['total'] === 75 && $attendance['absent'] === 3 && term_attendance($attendanceExams, ['CP1' => ['present' => 23, 'total_days' => 25], 'CP3' => ['present' => 25, 'total_days' => 25]], 'mid')['status'] === 'incomplete' && term_attendance([], [], 'final')['status'] === 'na');

$inputs = [['11', 10, false], ['10', 10, true], ['13.25', 20, true], ['13.255', 20, false], ['-1', 20, false], ['abc', 20, false], ['1e1', 20, false], ['10,5', 20, false], [' 5 ', 10, true], ['.5', 10, false], ['', 10, true], ['5', null, false]];
$inputOk = true;
foreach ($inputs as [$text, $maximum, $expected]) $inputOk = $inputOk && validate_mark_input($text, $maximum)['ok'] === $expected;
$inputOk = $inputOk && str_contains(validate_mark_input('10,5', 20)['error'], 'dot') && validate_mark_input('', 10)['value'] === null;
check_calc('12. Mark input validates bounds, decimals, commas, blanks and missing maximums', $inputOk);

$floatSafe = to_cents('13.25') + to_cents('10.10') === 2335;
$tenTenths = array_sum(array_fill(0, 10, to_cents('0.10')));
check_calc('13. Integer hundredths avoid floating-point addition errors', $floatSafe && $tenTenths === 100);

$invalidBands = [
    [['label' => 'A', 'min' => 100]],
    [['label' => 'A', 'min' => 100], ['label' => 'U', 'min' => 1]],
    [['label' => 'a', 'min' => 80], ['label' => 'A', 'min' => 0]],
    [['label' => 'A', 'min' => 80], ['label' => 'U', 'min' => 80]],
    [['label' => 'ABCDEFGHIJK', 'min' => 100], ['label' => 'U', 'min' => 0]],
    [['label' => 'A', 'min' => 101], ['label' => 'U', 'min' => 0]],
    [['label' => 'A', 'min' => -5], ['label' => 'U', 'min' => 0]],
    [['label' => 'A', 'min' => 'abc'], ['label' => 'U', 'min' => 0]],
    [['label' => '', 'min' => 100], ['label' => 'U', 'min' => 0]],
];
$bandValidation = validate_bands($bands) === [];
foreach ($invalidBands as $case) $bandValidation = $bandValidation && validate_bands($case) !== [];
check_calc('14. Grade-band validation accepts defaults and rejects all invalid cases', $bandValidation);

$shuffled = [$bands[5], $bands[2], $bands[0], $bands[4], $bands[1], $bands[3]];
check_calc('15. Grade results do not depend on input band order', grade_for(8450, 10000, $bands, 'nearest') === grade_for(8450, 10000, $shuffled, 'nearest'));

$loopExams = ['MT' => ['code' => 'MT', 'mode' => 'calculated', 'calc_method' => 'sum', 'sources' => ['FT']], 'FT' => ['code' => 'FT', 'mode' => 'calculated', 'calc_method' => 'sum', 'sources' => ['MT']]];
$loop = calc_student_subject($loopExams, [], [], $bands, 'nearest');
check_calc('16. A calculated-exam loop returns without endless recursion', $loop['MT']['status'] === 'loop' && $loop['FT']['status'] === 'loop');

$exemptSources = calc_student_subject($presetA, $presetMax, ['CP1' => $presetMarks['CP1'], 'CP2' => ['F.A' => ['status' => 'exempt'], 'S.A' => ['status' => 'exempt']], 'CP3' => $presetMarks['CP3']], $bands, 'nearest')['MT'];
check_calc('17. An exempt source is excluded from calculated marks and maximums', $exemptSources['status'] === 'ok' && $exemptSources['tmo'] === 50.0 && $exemptSources['max'] === 50.0);

$noMaxSource = calc_student_subject($presetA, array_replace($presetMax, ['CP2' => ['F.A' => 0, 'S.A' => 15]]), $presetMarks, $bands, 'nearest')['MT'];
check_calc('18. A calculated exam waits when one source has no maximum', $noMaxSource['status'] === 'incomplete' && str_contains($noMaxSource['reason'], 'CP2'));

$transitionBands = $bands;
$transitionBands[0]['min'] = 90;
$transitionBands[1]['min'] = 80;
$mixedItems = [['tmo_c' => 8500, 'max_c' => 10000, 'ref' => 'one'], ['tmo_c' => 8900, 'max_c' => 10000, 'ref' => 'two'], ['tmo_c' => 8400, 'max_c' => 10000, 'ref' => 'three'], ['tmo_c' => 7500, 'max_c' => 10000, 'ref' => 'four'], ['tmo_c' => 7900, 'max_c' => 10000, 'ref' => 'five']];
$mixedImpact = grade_impact($mixedItems, $bands, 'nearest', $transitionBands, 'nearest');
$sameImpact = grade_impact($mixedItems, $bands, 'nearest', $bands, 'nearest');
check_calc('19. Grade-impact transitions are sorted and identical rules have no changes', $mixedImpact['transitions'][0]['count'] >= $mixedImpact['transitions'][1]['count'] && $sameImpact['changed'] === 0);

$phase6ExamsA = [['code' => 'CP1', 'mode' => 'entered', 'components' => ['F.A', 'S.A']], ['code' => 'CP2', 'mode' => 'entered', 'components' => ['F.A', 'S.A']], ['code' => 'CP3', 'mode' => 'entered', 'components' => ['F.A', 'S.A']], ['code' => 'MT', 'mode' => 'calculated', 'calc_method' => 'sum', 'sources' => ['CP1', 'CP2', 'CP3']]];
$phase6MaxA = ['CP1' => [5 => 25], 'CP2' => [5 => 25], 'CP3' => [5 => 25]];
$calcA = calc_student_subject($phase6ExamsA, ['CP1' => ['F.A' => 10, 'S.A' => 15], 'CP2' => ['F.A' => 10, 'S.A' => 15], 'CP3' => ['F.A' => 10, 'S.A' => 15]], ['CP1' => split_total(25), 'CP2' => split_total(25), 'CP3' => split_total(25)], $bands, 'nearest');
$expectedA = compute_max_totals($phase6ExamsA, $phase6MaxA);
$phase6ExamsB = [['code' => 'CP1', 'mode' => 'entered', 'components' => ['F.A', 'S.A']], ['code' => 'MT', 'mode' => 'entered', 'components' => ['S.A']]];
$calcB = calc_student_subject($phase6ExamsB, ['CP1' => ['F.A' => 10, 'S.A' => 15], 'MT' => ['S.A' => 25]], ['CP1' => split_total(25), 'MT' => ['S.A' => ['value' => 20, 'status' => 'entered']]], $bands, 'nearest');
$expectedB = compute_max_totals($phase6ExamsB, ['CP1' => [5 => 25], 'MT' => [5 => 25]]);
check_calc('20. Engine maximums agree with Phase 6 Preset A and B structures', $calcA['MT']['max'] === $expectedA['MT'][5] && $calcB['MT']['max'] === $expectedB['MT'][5]);

echo $failures === 0 ? 'PASS: ' . $checks . ' calculation checks' . PHP_EOL : 'FAIL: ' . $failures . ' of ' . $checks . ' calculation checks' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
