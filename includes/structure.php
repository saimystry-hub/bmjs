<?php
function validate_exam_code(string $code): array
{
    $value = trim($code);
    $ok = preg_match('/^[A-Za-z0-9_-]{1,20}$/', $value) === 1;
    return ['ok' => $ok, 'value' => $value, 'error' => $ok ? null : 'Use 1 to 20 letters, numbers, underscores or dashes.'];
}

function validate_max_value(string $text): array
{
    $value = trim($text);
    if ($value === '') return ['ok' => true, 'value' => null, 'error' => null];
    if (!preg_match('/^(?:\d{1,3})(?:\.\d{1,2})?$/', $value) || (float) $value > 999.99) return ['ok' => false, 'value' => null, 'error' => 'Enter a number from 0 to 999.99 with no more than 2 decimal places.'];
    return ['ok' => true, 'value' => round((float) $value, 2), 'error' => null];
}
function find_cycle(array $sourcesByCode): ?array
{
    $visited = [];
    $active = [];
    $stack = [];
    $walk = function (string $code) use (&$walk, &$visited, &$active, &$stack, $sourcesByCode): ?array {
        if (isset($active[$code])) {
            $start = array_search($code, $stack, true);
            return array_slice($stack, $start === false ? 0 : $start);
        }
        if (isset($visited[$code])) {
            return null;
        }
        $visited[$code] = true;
        $active[$code] = true;
        $stack[] = $code;
        foreach ($sourcesByCode[$code] ?? [] as $source) {
            $source = (string) $source;
            if (isset($sourcesByCode[$source])) {
                $cycle = $walk($source);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }
        array_pop($stack);
        unset($active[$code]);
        return null;
    };
    foreach (array_keys($sourcesByCode) as $code) {
        $cycle = $walk((string) $code);
        if ($cycle !== null) {
            return $cycle;
        }
    }
    return null;
}
function validate_structure(array $exams): array
{
    $errors = [];
    $warnings = [];
    $codes = [];
    foreach ($exams as $exam) {
        $code = trim((string) ($exam['code'] ?? ''));
        $name = trim((string) ($exam['name'] ?? ''));
        $key = strtolower($code);
        if (!validate_exam_code($code)['ok']) {
            $errors[] = ($code === '' ? 'An exam needs a code.' : $code . ' has an invalid code.');
        }
        if (isset($codes[$key])) {
            $errors[] = 'The code ' . $code . ' is used more than once.';
        }
        $codes[$key] = true;
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = ($code !== '' ? $code : 'An exam') . ' needs a name of 1 to 80 characters.';
        }
        if (!in_array($exam['term_group'] ?? '', ['mid', 'final'], true)) {
            $errors[] = $code . ' must belong to Mid Term or Final Term.';
        }
        if (!in_array($exam['mode'] ?? '', ['entered', 'calculated'], true)) $errors[] = $code . ' must be entered or calculated.';
        if (($exam['mode'] ?? '') === 'entered' && empty($exam['components'])) {
            $errors[] = $code . ' needs at least one marks column.';
        }
        if (($exam['mode'] ?? '') === 'calculated') {
            if (!in_array($exam['calc_method'] ?? null, ['sum', 'average'], true)) {
                $errors[] = $code . ' needs a calculation method.';
            }
            if (empty($exam['sources'])) {
                $errors[] = $code . ' needs at least one exam to work from.';
            }
        }
    }

    $canonical = [];
    foreach ($exams as $exam) {
        $canonical[strtolower((string) ($exam['code'] ?? ''))] = (string) ($exam['code'] ?? '');
    }
    $calcSources = [];
    $termResults = ['mid' => 0, 'final' => 0];
    foreach ($exams as $exam) {
        $code = (string) ($exam['code'] ?? '');
        $group = (string) ($exam['term_group'] ?? '');
        if (isset($termResults[$group]) && !empty($exam['is_term_result'])) {
            $termResults[$group]++;
        }
        if (($exam['mode'] ?? '') !== 'calculated') {
            continue;
        }
        $sourceGroups = [];
        foreach ($exam['sources'] ?? [] as $sourceCode) {
            $sourceKey = strtolower((string) $sourceCode);
            if (!isset($canonical[$sourceKey])) {
                $errors[] = $code . ' uses an exam that does not exist: ' . $sourceCode . '.';
                continue;
            }
            $sourceCode = $canonical[$sourceKey];
            if (strcasecmp($code, $sourceCode) === 0) {
                $errors[] = $code . ' cannot be worked out from itself.';
                continue;
            }
            foreach ($exams as $sourceExam) {
                if (strcasecmp((string) ($sourceExam['code'] ?? ''), $sourceCode) === 0) {
                    $sourceGroups[(string) ($sourceExam['term_group'] ?? '')] = true;
                    if (($sourceExam['mode'] ?? '') === 'calculated') {
                        $calcSources[$code][] = $sourceCode;
                    }
                    break;
                }
            }
        }
        if (count($sourceGroups) > 1) {
            $warnings[] = $code . ' uses exams from both terms.';
        }
        if (($exam['calc_method'] ?? '') === 'average' && count($exam['sources'] ?? []) === 1) {
            $warnings[] = $code . ' takes an average of only one exam.';
        }
    }
    $cycle = find_cycle($calcSources);
    if ($cycle !== null) {
        $errors[] = implode(' and ', $cycle) . ' are worked out from each other, making a circle.';
    }
    foreach ($termResults as $group => $count) {
        $label = $group === 'mid' ? 'Mid Term' : 'Final Term';
        if ($count === 0) {
            $warnings[] = $label . ' has no exam marked as the term result.';
        } elseif ($count > 1) {
            $warnings[] = $label . ' has more than one exam marked as the term result.';
        }
    }
    return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
}
function describe_exam(array $exam, array $codeById = []): string
{
    if (($exam['mode'] ?? '') === 'calculated') {
        $sources = array_map(static fn($source) => (string) ($codeById[$source] ?? $source), $exam['sources'] ?? []);
        return (($exam['calc_method'] ?? 'sum') === 'average' ? 'average of ' : 'added up from ') . implode(' + ', $sources);
    }
    $components = array_map(static fn($component) => (string) (is_array($component) ? ($component['name'] ?? '') : $component), $exam['components'] ?? []);
    return 'separate exam: ' . implode(' + ', $components) . (count($components) === 1 ? ' only' : '');
}
function state_transition_allowed(string $from, string $to, string $role): array
{
    $valid = ['draft', 'open', 'locked'];
    if (!in_array($from, $valid, true) || !in_array($to, $valid, true) || $from === $to) {
        return ['ok' => false, 'reason' => 'Choose a different valid state.'];
    }
    if (!in_array($role, ['admin', 'super_admin'], true)) return ['ok' => false, 'reason' => 'You cannot change exam states.'];
    if ($from === 'locked' && $role !== 'super_admin') {
        return ['ok' => false, 'reason' => 'Only the super admin can reopen a locked exam.'];
    }
    return ['ok' => true, 'reason' => ''];
}
function derive_calculated_max(string $method, array $sourceTotals): ?float
{
    if ($sourceTotals === [] || !in_array($method, ['sum', 'average'], true)) {
        return null;
    }
    foreach ($sourceTotals as $value) if ($value === null || !is_numeric($value)) return null;
    $sumCents = array_sum(array_map(static fn($value) => (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP), $sourceTotals));
    if ($method === 'average') $sumCents = intdiv(2 * $sumCents + count($sourceTotals), 2 * count($sourceTotals));
    return $sumCents / 100;
}
function compute_max_totals(array $exams, array $maxByExamSubject): array
{
    $byCode = [];
    $cycleGraph = [];
    $subjects = [];
    foreach ($maxByExamSubject as $values) {
        foreach (array_keys($values) as $id) {
            $subjects[(string) $id] = $id;
        }
    }
    foreach ($exams as $exam) {
        $byCode[(string) $exam['code']] = $exam;
        if (($exam['mode'] ?? '') === 'calculated') {
            $cycleGraph[(string) $exam['code']] = array_values(array_filter($exam['sources'] ?? [], static function ($source) use ($exams) {
                foreach ($exams as $item) {
                    if (($item['code'] ?? '') === $source) {
                        return ($item['mode'] ?? '') === 'calculated';
                    }
                }
                return false;
            }));
        }
    }
    $cycle = find_cycle($cycleGraph);
    $memo = [];
    $resolve = function (string $code, string $subject) use (&$resolve, &$memo, $byCode, $maxByExamSubject, $cycle): ?float {
        $key = $code . ':' . $subject;
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        if ($cycle !== null && in_array($code, $cycle, true)) {
            return $memo[$key] = null;
        }
        $exam = $byCode[$code] ?? null;
        if ($exam === null) {
            return $memo[$key] = null;
        }
        if (($exam['mode'] ?? '') !== 'calculated') {
            $value = $maxByExamSubject[$code][$subject] ?? null;
            return $memo[$key] = ($value === null ? null : (float) $value);
        }
        $totals = [];
        foreach ($exam['sources'] ?? [] as $source) {
            $totals[] = $resolve((string) $source, $subject);
        }
        return $memo[$key] = derive_calculated_max((string) ($exam['calc_method'] ?? ''), $totals);
    };
    $result = [];
    foreach ($byCode as $code => $_) {
        foreach ($subjects as $subject) {
            $result[$code][$subject] = $resolve($code, (string) $subject);
        }
    }
    return $result;
}

function modal_value(array $values): int|float|null
{
    if ($values === []) {
        return null;
    }
    $counts = [];
    foreach ($values as $value) {
        if ($value === null || !is_numeric($value)) {
            continue;
        }
        $key = number_format((float) $value, 2, '.', '');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    if ($counts === []) {
        return null;
    }
    uksort($counts, static function ($a, $b) use ($counts) {
        return ($counts[$b] <=> $counts[$a]) ?: ((float) $b <=> (float) $a);
    });
    return (float) array_key_first($counts);
}

function parse_preset(string $json): array
{
    try { $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return ['ok' => false, 'exams' => [], 'error' => 'Preset data is not valid JSON.']; }
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || !isset($data['assessments']) || !is_array($data['assessments'])) {
        return ['ok' => false, 'exams' => [], 'error' => 'Preset data is not valid.'];
    }
    $exams = [];
    foreach ($data['assessments'] as $exam) {
        if (!is_array($exam)) return ['ok' => false, 'exams' => [], 'error' => 'Preset contains an invalid exam.'];
        if (!is_array($exam['components'] ?? []) || !is_array($exam['sources'] ?? [])) return ['ok' => false, 'exams' => [], 'error' => 'Preset contains invalid marks columns or source exams.'];
        $exam['components'] = array_values($exam['components'] ?? []);
        $exam['sources'] = array_values($exam['sources'] ?? []);
        if (($exam['mode'] ?? '') === 'entered') {
            if (!is_array($exam['max'] ?? [])) return ['ok' => false, 'exams' => [], 'error' => 'Preset maximum marks must be listed by marks column.'];
            foreach ($exam['max'] ?? [] as $column => $maximum) {
                if (!in_array((string) $column, $exam['components'], true) || is_bool($maximum) || (!is_scalar($maximum) && $maximum !== null)) return ['ok' => false, 'exams' => [], 'error' => 'Preset has an invalid maximum for a marks column.'];
                if (!validate_max_value((string) ($maximum ?? ''))['ok']) return ['ok' => false, 'exams' => [], 'error' => 'Preset maximum marks must be from 0 to 999.99.'];
            }
        }
        $exams[] = $exam;
    }
    $validation = validate_structure($exams);
    return ['ok' => $validation['errors'] === [], 'exams' => $exams, 'error' => implode(' ', $validation['errors']) ?: null];
}

function derive_preset_json(array $exams, array $maxValuesByExamColumn): string
{
    $rows = [];
    foreach ($exams as $exam) {
        $columns = array_values($exam['components'] ?? []);
        $max = [];
        if (($exam['mode'] ?? '') === 'entered') {
            foreach ($columns as $column) {
                $max[$column] = modal_value($maxValuesByExamColumn[$exam['code']][$column] ?? []);
            }
        }
        $rows[] = [
            'code' => $exam['code'], 'name' => $exam['name'], 'term_group' => $exam['term_group'],
            'mode' => $exam['mode'], 'calc_method' => $exam['mode'] === 'calculated' ? ($exam['calc_method'] ?? null) : null,
            'components' => $exam['mode'] === 'entered' ? $columns : [], 'max' => $max,
            'sources' => $exam['mode'] === 'calculated' ? array_values($exam['sources'] ?? []) : [],
            'has_attendance' => (int) ($exam['has_attendance'] ?? 0), 'is_term_result' => (int) ($exam['is_term_result'] ?? 0),
        ];
    }
    return (string) json_encode(['version' => 1, 'assessments' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
