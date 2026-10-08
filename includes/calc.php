<?php
// Convert a numeric mark or percentage to integer hundredths.
function to_cents(int|float|string|null $value): ?int
{
    if ($value === null || !is_numeric($value)) {
        return null;
    }
    return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
}
// Convert integer hundredths to a number suitable for display.
function from_cents(?int $value): ?float
{
    return $value === null ? null : $value / 100;
}
// Format marks with a fixed number of decimal places.
function fmt_marks(int|float|null $value, int $decimals = 2): string
{
    return $value === null ? '' : number_format($value, max(0, min(2, $decimals)), '.', '');
}
// Format a percentage with a fixed number of decimal places.
function fmt_percent(int|float|null $value, int $decimals = 0): string
{
    return $value === null ? '' : number_format($value, max(0, min(2, $decimals)), '.', '');
}
// Return a result's grade or its plain-language status.
function result_grade_text(array $result): string
{
    if (($result['status'] ?? '') === 'ok') {
        return (string) ($result['grade'] ?? '');
    }
    return match ($result['status'] ?? '') {
        'exempt' => 'Exempt',
        'no_max' => 'No maximum',
        'loop' => 'Incomplete',
        'na' => 'Not applicable',
        default => 'Incomplete',
    };
}
// Sort grade bands from the highest minimum percentage to the lowest.
function sort_bands(array $bands): array
{
    usort($bands, static fn(array $a, array $b): int => (float) $b['min'] <=> (float) $a['min']);
    return $bands;
}
// Return the exact percentage represented by marks and maximum in cents.
function percent_exact(int $tmoC, int $maxC): ?float
{
    return $maxC <= 0 ? null : ($tmoC * 100) / $maxC;
}

// Find the grade band for marks using the selected rounding rule.
function grade_for(int $tmoC, int $maxC, array $bands, string $rounding): ?string
{
    if ($maxC <= 0 || !in_array($rounding, ['nearest', 'down', 'none'], true)) {
        return null;
    }
    $whole = match ($rounding) {
        'nearest' => intdiv(2 * $tmoC * 100 + $maxC, 2 * $maxC),
        'down' => intdiv($tmoC * 100, $maxC),
        default => null,
    };
    foreach (sort_bands($bands) as $band) {
        $minimum = (int) round((float) $band['min'] * 100, 0, PHP_ROUND_HALF_UP);
        $matched = $rounding === 'none'
            ? $tmoC * 10000 >= $minimum * $maxC
            : $whole * 100 >= $minimum;
        if ($matched) {
            return (string) $band['label'];
        }
    }
    return null;
}

// Validate a teacher-entered mark and return its numeric value or an error.
function validate_mark_input(string $text, int|float|string|null $max): array
{
    $value = trim($text);
    if ($value === '') {
        return ['ok' => true, 'value' => null, 'error' => null];
    }
    if (str_contains($value, ',')) {
        return ['ok' => false, 'value' => null, 'error' => 'Use a dot, like 13.25'];
    }
    if ($max === null || !is_numeric($max)) {
        return ['ok' => false, 'value' => null, 'error' => 'Maximum marks are not set for this exam'];
    }
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        return ['ok' => false, 'value' => null, 'error' => 'Enter a number with no more than 2 decimal places'];
    }
    $cents = to_cents($value);
    if ($cents > to_cents($max)) {
        return ['ok' => false, 'value' => null, 'error' => 'The highest mark here is ' . rtrim(rtrim(number_format((float) $max, 2, '.', ''), '0'), '.')];
    }
    return ['ok' => true, 'value' => from_cents($cents), 'error' => null];
}

// Validate grade labels and minimum percentages before they are saved.
function validate_bands(array $bands): array
{
    $errors = [];
    $labels = [];
    $minimums = [];
    if (count($bands) < 2) $errors[] = 'Add at least two grades.';
    foreach ($bands as $band) {
        $label = trim((string) ($band['label'] ?? ''));
        $rawMinimum = $band['min'] ?? null;
        $minimumText = is_string($rawMinimum) ? trim($rawMinimum) : (string) $rawMinimum;
        if ($label === '') $errors[] = 'A grade label cannot be blank.';
        if (mb_strlen($label) > 10) $errors[] = 'A grade label cannot be longer than 10 characters.';
        $key = mb_strtolower($label);
        if ($key !== '' && isset($labels[$key])) $errors[] = 'Grade labels must be unique.';
        if ($key !== '') $labels[$key] = true;
        if (!is_numeric($minimumText) || !preg_match('/^\d+(?:\.\d{1,2})?$/', $minimumText) || (float) $minimumText > 100) {
            $errors[] = 'Enter a minimum percentage from 0 to 100 with no more than 2 decimals.';
            continue;
        }
        $minimumC = (int) round((float) $minimumText * 100, 0, PHP_ROUND_HALF_UP);
        if (isset($minimums[$minimumC])) $errors[] = 'Grade minimum percentages must be unique.';
        $minimums[$minimumC] = true;
    }
    if (!isset($minimums[0])) $errors[] = 'The lowest grade must start at 0 so every mark gets a grade.';
    return array_values(array_unique($errors));
}

// Compare old and new grades for marks expressed in integer hundredths.
function grade_impact(array $items, array $oldBands, string $oldRounding, array $newBands, string $newRounding): array
{
    $transitions = [];
    $examples = [];
    $changed = 0;
    foreach ($items as $item) {
        $old = grade_for((int) $item['tmo_c'], (int) $item['max_c'], $oldBands, $oldRounding);
        $new = grade_for((int) $item['tmo_c'], (int) $item['max_c'], $newBands, $newRounding);
        if ($old === $new) continue;
        $changed++;
        $key = $old . "\0" . $new;
        if (!isset($transitions[$key])) $transitions[$key] = ['from' => $old, 'to' => $new, 'count' => 0];
        $transitions[$key]['count']++;
        if (count($examples) < 5) $examples[] = ['ref' => $item['ref'] ?? null, 'from' => $old, 'to' => $new];
    }
    $transitions = array_values($transitions);
    usort($transitions, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['from'], (string) $b['from']));
    return ['total' => count($items), 'changed' => $changed, 'transitions' => $transitions, 'examples' => $examples];
}

// Return one consistently shaped result for every calculation outcome.
function calc_result(string $status, ?float $tmo = null, ?float $max = null, ?float $percent = null, ?string $grade = null, bool $absent = false, ?string $reason = null): array
{
    return ['status' => $status, 'tmo' => $tmo, 'max' => $max, 'percent' => $percent, 'grade' => $grade, 'absent' => $absent, 'reason' => $reason];
}

// Calculate one entered exam from its marks and maximums, using exempt/no-maximum/missing precedence.
function calc_entered_exam(array $exam, array $marksByColumn, array $maxByColumn, array $bands, string $rounding): array
{
    $columns = array_values($exam['components'] ?? []);
    foreach ($columns as $column) if (($marksByColumn[$column]['status'] ?? '') === 'exempt') return calc_result('exempt');
    $missingMax = [];
    foreach ($columns as $column) if (to_cents($maxByColumn[$column] ?? null) === null || to_cents($maxByColumn[$column]) <= 0) $missingMax[] = $column;
    if ($missingMax !== []) return calc_result('no_max', null, null, null, null, false, 'Maximum marks are not set for ' . ($exam['subject_name'] ?? 'this subject') . ' in ' . ($exam['name'] ?? $exam['code'] ?? 'this exam'));
    $missing = [];
    $tmoC = 0;
    $maxC = 0;
    $absent = false;
    foreach ($columns as $column) {
        $maxC += to_cents($maxByColumn[$column]);
        $mark = $marksByColumn[$column] ?? null;
        if (!is_array($mark) || (($mark['status'] ?? '') !== 'absent' && (($mark['value'] ?? null) === null || ($mark['status'] ?? '') !== 'entered'))) {
            $missing[] = $column;
            continue;
        }
        if (($mark['status'] ?? '') === 'absent') {
            $absent = true;
            continue;
        }
        $tmoC += to_cents($mark['value'] ?? null) ?? 0;
    }
    if ($missing !== []) return calc_result('incomplete', null, null, null, null, $absent, 'Missing marks for ' . implode(', ', $missing));
    return calc_result('ok', from_cents($tmoC), from_cents($maxC), percent_exact($tmoC, $maxC), grade_for($tmoC, $maxC, $bands, $rounding), $absent);
}

// Combine calculated source results and leave exempt sources out of both totals.
function calc_calculated_exam(array $exam, array $sourceResults, array $bands, string $rounding): array
{
    $active = [];
    foreach ($exam['sources'] ?? [] as $code) {
        $result = $sourceResults[$code] ?? calc_result('incomplete', null, null, null, null, false, 'Missing source');
        if ($result['status'] === 'exempt') continue;
        if ($result['status'] === 'loop') return calc_result('loop', null, null, null, null, false, 'Exams are added up from each other');
        if ($result['status'] !== 'ok') return calc_result('incomplete', null, null, null, null, false, 'Waiting for ' . $code);
        $active[] = $result;
    }
    if ($active === []) return calc_result('exempt');
    $tmoC = array_sum(array_map(static fn(array $result): int => to_cents($result['tmo']) ?? 0, $active));
    $maxC = array_sum(array_map(static fn(array $result): int => to_cents($result['max']) ?? 0, $active));
    if (($exam['calc_method'] ?? '') === 'average') {
        $count = count($active);
        $tmoC = intdiv(2 * $tmoC + $count, 2 * $count);
        $maxC = intdiv(2 * $maxC + $count, 2 * $count);
    }
    $absent = count(array_filter($active, static fn(array $result): bool => !empty($result['absent']))) > 0;
    return calc_result('ok', from_cents($tmoC), from_cents($maxC), percent_exact($tmoC, $maxC), grade_for($tmoC, $maxC, $bands, $rounding), $absent);
}

// Calculate every exam for one marks subject and guard against source loops.
function calc_student_subject(array $exams, array $maxForSubject, array $marksForSubject, array $bands, string $rounding): array
{
    $byCode = [];
    foreach ($exams as $key => $exam) $byCode[$exam['code'] ?? $key] = $exam + ['code' => $exam['code'] ?? $key];
    $memo = [];
    $stack = [];
    $resolve = function (string $code) use (&$resolve, &$memo, &$stack, $byCode, $maxForSubject, $marksForSubject, $bands, $rounding): array {
        if (isset($memo[$code])) return $memo[$code];
        if (in_array($code, $stack, true)) return calc_result('loop', null, null, null, null, false, 'Exams are added up from each other');
        if (!isset($byCode[$code])) return calc_result('incomplete', null, null, null, null, false, 'Waiting for ' . $code);
        $exam = $byCode[$code];
        if (($exam['mode'] ?? '') !== 'calculated') {
            return $memo[$code] = calc_entered_exam($exam, $marksForSubject[$code] ?? [], $maxForSubject[$code] ?? [], $bands, $rounding);
        }
        $stack[] = $code;
        $sources = [];
        foreach ($exam['sources'] ?? [] as $source) {
            $sources[$source] = $resolve((string) $source);
        }
        array_pop($stack);
        return $memo[$code] = calc_calculated_exam($exam, $sources, $bands, $rounding);
    };
    foreach (array_keys($byCode) as $code) $resolve((string) $code);
    return $memo;
}

// Add counted marks-subject results into a grand total for every exam.
function calc_grand_totals(array $exams, array $resultsBySubject, array $countedSubjectIds, array $bands, string $rounding): array
{
    $results = [];
    foreach ($exams as $key => $exam) {
        $code = (string) ($exam['code'] ?? $key);
        $tmoC = 0; $maxC = 0; $seen = false; $absent = false; $waiting = false;
        foreach ($countedSubjectIds as $subjectId) {
            $result = $resultsBySubject[$subjectId][$code] ?? null;
            if ($result === null || $result['status'] === 'exempt') continue;
            $seen = true;
            if ($result['status'] !== 'ok') { $waiting = true; continue; }
            $tmoC += to_cents($result['tmo']) ?? 0;
            $maxC += to_cents($result['max']) ?? 0;
            $absent = $absent || !empty($result['absent']);
        }
        if (!$seen) $results[$code] = calc_result('na');
        elseif ($waiting) $results[$code] = calc_result('incomplete', null, null, null, null, false, 'Some marks are incomplete');
        else $results[$code] = calc_result('ok', from_cents($tmoC), from_cents($maxC), percent_exact($tmoC, $maxC), grade_for($tmoC, $maxC, $bands, $rounding), $absent);
    }
    return $results;
}

// Add attendance rows for one term and report whether any are missing.
function term_attendance(array $exams, array $attendanceByCode, string $termGroup): array
{
    $relevant = array_filter($exams, static fn(array $exam): bool => ($exam['term_group'] ?? '') === $termGroup && !empty($exam['has_attendance']));
    if ($relevant === []) return ['status' => 'na', 'present' => null, 'total' => null, 'absent' => null, 'reason' => null];
    $present = 0; $total = 0; $missing = [];
    foreach ($relevant as $exam) {
        $code = (string) $exam['code'];
        if (!isset($attendanceByCode[$code])) { $missing[] = $code; continue; }
        $present += (int) $attendanceByCode[$code]['present'];
        $total += (int) $attendanceByCode[$code]['total_days'];
    }
    return ['status' => $missing === [] ? 'ok' : 'incomplete', 'present' => $present, 'total' => $total, 'absent' => $total - $present, 'reason' => $missing === [] ? null : 'Waiting for attendance for ' . implode(', ', $missing)];
}

// Calculate every student's subject results, grand totals and term attendance.
function calc_all(array $data): array
{
    $output = [];
    $students = array_unique(array_merge(array_map('strval', $data['student_ids'] ?? []), array_map('strval', array_keys($data['marks'] ?? [])), array_map('strval', array_keys($data['grades'] ?? [])), array_map('strval', array_keys($data['attendance'] ?? []))));
    $exams = $data['exams'] ?? [];
    $bands = $data['bands'] ?? [];
    $rounding = (string) ($data['rounding'] ?? 'nearest');
    foreach ($students as $studentId) {
        $bySubject = [];
        foreach ($data['subjects'] ?? [] as $subjectId => $subject) {
            if (($subject['kind'] ?? 'marks') === 'grade') {
                foreach ($exams as $code => $_exam) {
                    $grade = $data['grades'][$studentId][$subjectId][$code] ?? null;
                    $bySubject[$subjectId][$code] = $grade === null ? calc_result('incomplete') : calc_result('ok', null, null, null, (string) $grade);
                }
            } else {
                $bySubject[$subjectId] = calc_student_subject($exams, $data['max'][$subjectId] ?? [], $data['marks'][$studentId][$subjectId] ?? [], $bands, $rounding);
            }
        }
        $counted = [];
        foreach ($data['subjects'] ?? [] as $subjectId => $subject) if (($subject['kind'] ?? 'marks') === 'marks' && !empty($subject['counts'])) $counted[] = $subjectId;
        $attendance = [];
        foreach (['mid', 'final'] as $term) $attendance[$term] = term_attendance($exams, $data['attendance'][$studentId] ?? [], $term);
        $output[$studentId] = ['subjects' => $bySubject, 'grand' => calc_grand_totals($exams, $bySubject, $counted, $bands, $rounding), 'attendance' => $attendance];
    }
    return $output;
}
