<?php
require_once __DIR__ . '/../includes/structure.php';
require_once __DIR__ . '/../includes/max_marks.php';
require_once __DIR__ . '/../includes/structure_apply.php';

function check(string $label, bool $ok): void
{
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
}

$presetA = [
    ['code' => 'CP1', 'name' => 'Checkpoint 1', 'term_group' => 'mid', 'mode' => 'entered', 'calc_method' => null, 'components' => ['F.A', 'S.A'], 'sources' => [], 'has_attendance' => 1, 'is_term_result' => 0],
    ['code' => 'CP2', 'name' => 'Checkpoint 2', 'term_group' => 'mid', 'mode' => 'entered', 'calc_method' => null, 'components' => ['F.A', 'S.A'], 'sources' => [], 'has_attendance' => 1, 'is_term_result' => 0],
    ['code' => 'CP3', 'name' => 'Checkpoint 3', 'term_group' => 'mid', 'mode' => 'entered', 'calc_method' => null, 'components' => ['F.A', 'S.A'], 'sources' => [], 'has_attendance' => 1, 'is_term_result' => 0],
    ['code' => 'MT', 'name' => 'Mid Term', 'term_group' => 'mid', 'mode' => 'calculated', 'calc_method' => 'sum', 'components' => [], 'sources' => ['CP1', 'CP2', 'CP3'], 'has_attendance' => 0, 'is_term_result' => 1],
    ['code' => 'CP4', 'name' => 'Checkpoint 4', 'term_group' => 'final', 'mode' => 'entered', 'calc_method' => null, 'components' => ['F.A', 'S.A'], 'sources' => [], 'has_attendance' => 1, 'is_term_result' => 0],
    ['code' => 'CP5', 'name' => 'Checkpoint 5', 'term_group' => 'final', 'mode' => 'entered', 'calc_method' => null, 'components' => ['F.A', 'S.A'], 'sources' => [], 'has_attendance' => 1, 'is_term_result' => 0],
    ['code' => 'CP6', 'name' => 'Checkpoint 6', 'term_group' => 'final', 'mode' => 'entered', 'calc_method' => null, 'components' => ['F.A', 'S.A'], 'sources' => [], 'has_attendance' => 1, 'is_term_result' => 0],
    ['code' => 'FT', 'name' => 'Final Term', 'term_group' => 'final', 'mode' => 'calculated', 'calc_method' => 'sum', 'components' => [], 'sources' => ['CP4', 'CP5', 'CP6'], 'has_attendance' => 0, 'is_term_result' => 1],
];
$presetAJson = <<<'JSON'
{"version":1,"assessments":[
  {"code":"CP1","name":"Checkpoint 1","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP2","name":"Checkpoint 2","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP3","name":"Checkpoint 3","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"MT","name":"Mid Term","term_group":"mid","mode":"calculated","calc_method":"sum","components":[],"max":{},"sources":["CP1","CP2","CP3"],"has_attendance":0,"is_term_result":1},
  {"code":"CP4","name":"Checkpoint 4","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP5","name":"Checkpoint 5","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP6","name":"Checkpoint 6","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"FT","name":"Final Term","term_group":"final","mode":"calculated","calc_method":"sum","components":[],"max":{},"sources":["CP4","CP5","CP6"],"has_attendance":0,"is_term_result":1}
]}
JSON;
check('Seed Preset A JSON parses eight exams', parse_preset($presetAJson)['ok'] && count(parse_preset($presetAJson)['exams']) === 8);
check('Preset A structure has no errors', validate_structure($presetA)['errors'] === []);
$sourceShape = [['code' => 'CP1', 'name' => 'Checkpoint', 'components' => ['F.A'], 'max' => ['F.A' => 20]]];
$targetShape = [['code' => 'CP1', 'name' => 'Checkpoint', 'components' => ['F.A']]];
check('Class structures compare without preset maximums', same_exam_structure($sourceShape, $targetShape));
$duplicate = $presetA;
$duplicate[] = $presetA[0];
$duplicate[8]['code'] = 'cp1';
check('Duplicate code is case-insensitive', count(validate_structure($duplicate)['errors']) > 0);
$badCode = $presetA;
$badCode[0]['code'] = 'CP 1';
check('Exam code rejects spaces', !validate_exam_code('CP 1')['ok']);
$emptyColumns = [['code' => 'X', 'name' => 'Exam', 'term_group' => 'mid', 'mode' => 'entered', 'components' => [], 'sources' => []]];
check('Entered exam needs marks columns', count(validate_structure($emptyColumns)['errors']) > 0);
$invalidMode = $presetA;
$invalidMode[0]['mode'] = 'unknown';
check('Invalid exam mode is rejected', count(validate_structure($invalidMode)['errors']) > 0);
$noSource = [['code' => 'X', 'name' => 'Exam', 'term_group' => 'mid', 'mode' => 'calculated', 'calc_method' => null, 'components' => [], 'sources' => []]];
check('Calculated exam needs method and sources', count(validate_structure($noSource)['errors']) >= 2);
$badSource = [['code' => 'X', 'name' => 'Exam', 'term_group' => 'mid', 'mode' => 'calculated', 'calc_method' => 'sum', 'components' => [], 'sources' => ['Q']]];
check('Missing source code is rejected', count(validate_structure($badSource)['errors']) > 0);
$selfSource = [['code' => 'X', 'name' => 'Exam', 'term_group' => 'mid', 'mode' => 'calculated', 'calc_method' => 'sum', 'components' => [], 'sources' => ['X']]];
check('Self source is rejected', count(validate_structure($selfSource)['errors']) > 0);
check('find_cycle detects A to B to A', find_cycle(['A' => ['B'], 'B' => ['A']]) !== null);
check('find_cycle accepts A to B to C', find_cycle(['A' => ['B'], 'B' => ['C']]) === null);
$circle = [
    ['code' => 'MT', 'name' => 'Mid Term', 'term_group' => 'mid', 'mode' => 'calculated', 'calc_method' => 'sum', 'components' => [], 'sources' => ['FT'], 'is_term_result' => 1],
    ['code' => 'FT', 'name' => 'Final Term', 'term_group' => 'final', 'mode' => 'calculated', 'calc_method' => 'sum', 'components' => [], 'sources' => ['MT'], 'is_term_result' => 1],
];
check('Calculated exam loop is rejected', count(validate_structure($circle)['errors']) > 0);
$remembered = $presetA;
$remembered[0]['sources'] = ['missing'];
$remembered[3]['components'] = ['remembered'];
check('Unused remembered mode settings do not cause errors', validate_structure($remembered)['errors'] === []);
$missingResult = [$presetA[0]];
check('Missing term result is a warning', count(validate_structure($missingResult)['warnings']) > 0);

check('Sum description lists source codes', describe_exam(['mode' => 'calculated', 'calc_method' => 'sum', 'sources' => ['CP1', 'CP2', 'CP3']]) === 'added up from CP1 + CP2 + CP3');
check('Average description is plain language', describe_exam(['mode' => 'calculated', 'calc_method' => 'average', 'sources' => ['MT', 'FT']]) === 'average of MT + FT');
check('Entered description lists both columns', describe_exam(['mode' => 'entered', 'components' => ['F.A', 'S.A']]) === 'separate exam: F.A + S.A');
check('Entered description lists one column', describe_exam(['mode' => 'entered', 'components' => ['S.A']]) === 'separate exam: S.A only');

foreach (['10' => true, '12.5' => true, '12.555' => false, '-1' => false, 'abc' => false, '1000' => false, '' => true] as $value => $expected) {
    $result = validate_max_value($value);
    check('Maximum value ' . ($value === '' ? 'blank' : $value), $result['ok'] === $expected && ($value !== '' || $result['value'] === null));
}
check('Admin can open draft exam', state_transition_allowed('draft', 'open', 'admin')['ok']);
check('Admin can lock open exam', state_transition_allowed('open', 'locked', 'admin')['ok']);
check('Admin cannot reopen locked exam', !state_transition_allowed('locked', 'open', 'admin')['ok']);
check('Super admin can reopen locked exam', state_transition_allowed('locked', 'open', 'super_admin')['ok']);
check('Same-state change is refused', !state_transition_allowed('open', 'open', 'admin')['ok']);
check('Calculated sum maximum', derive_calculated_max('sum', [25, 25, 25]) === 75.0);
check('Calculated average maximum', derive_calculated_max('average', [75, 75]) === 75.0);
check('Calculated fractional average', derive_calculated_max('average', [25, 50]) === 37.5);
check('Calculated decimal average rounds from hundredths', derive_calculated_max('average', [0.1, 0.2]) === 0.15);
check('Unknown source maximum stays unknown', derive_calculated_max('sum', [25, null]) === null);

$computeExams = [
    ['code' => 'CP1', 'mode' => 'entered'], ['code' => 'CP2', 'mode' => 'entered'],
    ['code' => 'CP3', 'mode' => 'entered'], ['code' => 'MT', 'mode' => 'calculated', 'calc_method' => 'sum', 'sources' => ['CP1', 'CP2', 'CP3']],
];
$maxes = ['CP1' => [5 => 25], 'CP2' => [5 => 25], 'CP3' => [5 => 25]];
check('Preset A maximum totals to 75', compute_max_totals($computeExams, $maxes)['MT'][5] === 75.0);
$maxes['CP2'][5] = null;
check('Missing component maximum makes calculated total unknown', compute_max_totals($computeExams, $maxes)['MT'][5] === null);
$maxes['CP1'][5] = 27;
$maxes['CP2'][5] = 25;
check('Changing source maximum updates calculated total', compute_max_totals($computeExams, $maxes)['MT'][5] === 77.0);
$computeExams[] = ['code' => 'MTB', 'mode' => 'entered'];
$maxes['MTB'] = [5 => 25];
check('Entered term maximum stays independent', compute_max_totals($computeExams, $maxes)['MTB'][5] === 25.0);
check('Most common maximum selected', modal_value([10, 10, 12]) === 10.0);
check('Modal ties choose the larger value', modal_value([10, 12]) === 12.0);
check('Empty modal is null', modal_value([]) === null);

$presetB = <<<'JSON'
{"version":1,"assessments":[
  {"code":"CP1","name":"Checkpoint 1","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP2","name":"Checkpoint 2","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"MT","name":"Mid Term","term_group":"mid","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":25},"sources":[],"has_attendance":0,"is_term_result":1},
  {"code":"CP3","name":"Checkpoint 3","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP4","name":"Checkpoint 4","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"FT","name":"Final Term","term_group":"final","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":25},"sources":[],"has_attendance":0,"is_term_result":1}
]}
JSON;
$presetC = <<<'JSON'
{"version":1,"assessments":[
  {"code":"CP1","name":"Checkpoint 1","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP2","name":"Checkpoint 2","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"MT","name":"Mid Term","term_group":"mid","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":50},"sources":[],"has_attendance":0,"is_term_result":1},
  {"code":"CP3","name":"Checkpoint 3","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP4","name":"Checkpoint 4","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"FT","name":"Final Term","term_group":"final","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":50},"sources":[],"has_attendance":0,"is_term_result":1}
]}
JSON;
$parsedB = parse_preset($presetB);
$parsedC = parse_preset($presetC);
check('Preset B parses six exams and S.A maximum 25', $parsedB['ok'] && count($parsedB['exams']) === 6 && $parsedB['exams'][2]['max']['S.A'] === 25);
check('Preset C parses six exams and S.A maximum 50', $parsedC['ok'] && count($parsedC['exams']) === 6 && $parsedC['exams'][2]['max']['S.A'] === 50);
$presetAText = derive_preset_json($presetA, ['CP1' => ['F.A' => [10], 'S.A' => [15]], 'CP2' => ['F.A' => [10], 'S.A' => [15]], 'CP3' => ['F.A' => [10], 'S.A' => [15]], 'CP4' => ['F.A' => [10], 'S.A' => [15]], 'CP5' => ['F.A' => [10], 'S.A' => [15]], 'CP6' => ['F.A' => [10], 'S.A' => [15]]]);
$roundTrip = parse_preset($presetAText);
check('Preset JSON round-trips', $roundTrip['ok'] && count($roundTrip['exams']) === count($presetA) && $roundTrip['exams'][3]['sources'] === ['CP1', 'CP2', 'CP3']);
check('Invalid preset JSON is rejected', !parse_preset('{bad')['ok']);
check('Preset maximum outside supported range is rejected', !parse_preset('{"version":1,"assessments":[{"code":"X","name":"Exam","term_group":"mid","mode":"entered","components":["S.A"],"sources":[],"max":{"S.A":1000}}]}')['ok']);
check('Preset with a loop is rejected', !parse_preset('{"version":1,"assessments":[{"code":"MT","name":"Mid","term_group":"mid","mode":"calculated","calc_method":"sum","components":[],"sources":["FT"],"is_term_result":1},{"code":"FT","name":"Final","term_group":"final","mode":"calculated","calc_method":"sum","components":[],"sources":["MT"],"is_term_result":1}]}')['ok']);
