<?php
require_once __DIR__ . '/calc_load.php';

// Read one year's grading scale, how widely it is shared, and its saved-result count.
function get_year_grading(int $yearId): array
{
    $query = db()->prepare(
        'SELECT ay.id AS year_id, ay.name AS year_name, ay.grading_scale_id, gs.name AS scale_name,
                (SELECT COUNT(*) FROM academic_years used WHERE used.grading_scale_id = ay.grading_scale_id) AS years_using,
                ((SELECT COUNT(*) FROM marks m JOIN assessments a ON a.id = m.assessment_id WHERE a.academic_year_id = ay.id) +
                 (SELECT COUNT(*) FROM grade_entries ge JOIN assessments a ON a.id = ge.assessment_id WHERE a.academic_year_id = ay.id)) AS result_count
         FROM academic_years ay JOIN grading_scales gs ON gs.id = ay.grading_scale_id WHERE ay.id = :year_id'
    );
    $query->execute([':year_id' => $yearId]);
    $year = $query->fetch();
    if (!$year) throw new InvalidArgumentException('The selected academic year could not be found.');
    $grading = year_grading($yearId);
    return $year + ['bands' => $grading['bands'], 'rounding' => $grading['rounding'], 'marks_decimals' => $grading['marks_decimals'], 'percent_decimals' => $grading['percent_decimals']];
}

// Read, trim and validate grade-band arrays submitted from the grading form.
function bands_from_post(array $post): array
{
    $labels = $post['band_label'] ?? $post['labels'] ?? [];
    $minimums = $post['band_min'] ?? $post['minimums'] ?? [];
    if (!is_array($labels) || !is_array($minimums)) return ['bands' => [], 'errors' => ['Enter the grade labels and minimum percentages.']];
    $bands = [];
    foreach (array_keys($labels) as $key) {
        if (!is_scalar($labels[$key]) || !is_scalar($minimums[$key] ?? null)) {
            return ['bands' => [], 'errors' => ['Enter a grade label and minimum percentage for every row.']];
        }
        $bands[] = ['label' => trim((string) $labels[$key]), 'min' => trim((string) $minimums[$key])];
    }
    return ['bands' => $bands, 'errors' => validate_bands($bands)];
}

// Compare current grades with proposed bands without writing to the database.
function grading_impact(int $yearId, array $newBands, string $newRounding): array
{
    $classesQuery = db()->prepare(
        'SELECT c.id AS class_id, c.name AS class_name, se.student_id, st.full_name
         FROM classes c JOIN sections sec ON sec.class_id = c.id AND sec.academic_year_id = :year
         JOIN student_enrollments se ON se.section_id = sec.id AND se.academic_year_id = sec.academic_year_id
         JOIN students st ON st.id = se.student_id
         GROUP BY c.id, c.name, se.student_id, st.full_name ORDER BY c.sort_order, c.id, st.id'
    );
    $classesQuery->execute([':year' => $yearId]);
    $classRows = $classesQuery->fetchAll();
    $byClass = [];
    foreach ($classRows as $row) $byClass[(int) $row['class_id']][] = $row;
    $oldGrading = year_grading($yearId);
    $resultTotal = 0; $resultChanged = 0; $grandChanged = 0; $affected = [];
    $transitions = []; $examples = []; $signature = [];
    foreach ($byClass as $classId => $students) {
        $studentIds = array_map(static fn(array $student): int => (int) $student['student_id'], $students);
        $data = load_calc_data($yearId, (int) $classId, $studentIds);
        $calculated = calc_all($data);
        $names = [];
        foreach ($students as $student) $names[(int) $student['student_id']] = (string) $student['full_name'];
        foreach ($calculated as $studentId => $studentResult) {
            foreach ($studentResult['subjects'] as $subjectId => $examResults) {
                if (($data['subjects'][$subjectId]['kind'] ?? '') !== 'marks') continue;
                foreach ($examResults as $code => $result) {
                    if ($result['status'] !== 'ok' || $result['tmo'] === null || $result['max'] === null) continue;
                    $oldGrade = $result['grade'];
                    $newGrade = grade_for(to_cents($result['tmo']), to_cents($result['max']), $newBands, $newRounding);
                    $resultTotal++;
                    $signature[] = [$classId, $studentId, $subjectId, $code, to_cents($result['tmo']), to_cents($result['max']), $oldGrade, $newGrade];
                    grading_record_transition($transitions, $examples, $affected, $names, $students[0]['class_name'], $studentId, $data['subjects'][$subjectId]['name'], $code, $oldGrade, $newGrade, false);
                    if ($oldGrade !== $newGrade) $resultChanged++;
                }
            }
            foreach ($studentResult['grand'] as $code => $result) {
                if ($result['status'] !== 'ok' || $result['tmo'] === null || $result['max'] === null) continue;
                $oldGrade = $result['grade'];
                $newGrade = grade_for(to_cents($result['tmo']), to_cents($result['max']), $newBands, $newRounding);
                $signature[] = [$classId, $studentId, 'grand', $code, to_cents($result['tmo']), to_cents($result['max']), $oldGrade, $newGrade];
                grading_record_transition($transitions, $examples, $affected, $names, $students[0]['class_name'], $studentId, 'Grand total', $code, $oldGrade, $newGrade, true);
                if ($oldGrade !== $newGrade) $grandChanged++;
            }
        }
    }
    uasort($transitions, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['from'], $b['from']));
    return [
        'results_total' => $resultTotal, 'results_changed' => $resultChanged, 'grand_changed' => $grandChanged,
        'students_affected' => count($affected), 'transitions' => array_slice(array_values($transitions), 0, 10),
        'examples' => array_slice($examples, 0, 5), 'signature' => md5(json_encode([$yearId, $newBands, $newRounding, $signature], JSON_THROW_ON_ERROR)),
    ];
}

// Record a changed grade transition, its example, and the affected student.
function grading_record_transition(array &$transitions, array &$examples, array &$affected, array $names, string $className, int $studentId, string $subject, string $exam, ?string $old, ?string $new, bool $grand): void
{
    if ($old === $new) return;
    $key = (string) $old . "\0" . (string) $new;
    if (!isset($transitions[$key])) $transitions[$key] = ['from' => $old, 'to' => $new, 'count' => 0];
    $transitions[$key]['count']++;
    $affected[$studentId] = true;
    if (count($examples) < 5) $examples[] = ['student' => $names[$studentId] ?? '', 'class' => $className, 'subject' => $subject, 'exam' => $exam, 'from' => $old, 'to' => $new, 'grand' => $grand];
}

// Save year grading in one transaction and copy a shared scale before changing it.
function save_year_grading(int $yearId, array $newBands, string $newRounding, int $marksDecimals, int $percentDecimals, string $reason, int $userId): array
{
    $errors = validate_bands($newBands);
    if (!in_array($newRounding, ['nearest', 'none', 'down'], true)) $errors[] = 'Choose a valid rounding rule.';
    if ($marksDecimals < 0 || $marksDecimals > 2 || $percentDecimals < 0 || $percentDecimals > 2) $errors[] = 'Choose 0, 1 or 2 displayed decimal places.';
    if (mb_strlen(trim($reason)) > 200) $errors[] = 'The reason cannot be longer than 200 characters.';
    if ($errors !== []) throw new InvalidArgumentException(implode(' ', array_unique($errors)));
    $impact = grading_impact($yearId, $newBands, $newRounding);
    db()->beginTransaction();
    try {
        $query = db()->prepare('SELECT ay.name, ay.grading_scale_id, gs.name AS scale_name FROM academic_years ay JOIN grading_scales gs ON gs.id = ay.grading_scale_id WHERE ay.id = :year_id FOR UPDATE');
        $query->execute([':year_id' => $yearId]);
        $year = $query->fetch();
        if (!$year) throw new InvalidArgumentException('The selected academic year could not be found.');
        $oldGrading = year_grading($yearId);
        $scaleId = (int) $year['grading_scale_id'];
        $bandsQuery = db()->prepare('SELECT grade_label AS label, min_percent AS min FROM grade_bands WHERE scale_id = :scale_id ORDER BY sort_order');
        $bandsQuery->execute([':scale_id' => $scaleId]);
        $oldBands = $bandsQuery->fetchAll();
        $scaleCount = db()->prepare('SELECT COUNT(*) FROM academic_years WHERE grading_scale_id = :scale_id');
        $scaleCount->execute([':scale_id' => $scaleId]);
        $shared = (int) $scaleCount->fetchColumn() > 1;
        if ($shared) {
            $newScale = db()->prepare('INSERT INTO grading_scales (name) VALUES (:name)');
            $newScale->execute([':name' => 'Scale for ' . $year['name']]);
            $newScaleId = (int) db()->lastInsertId();
            $copy = db()->prepare('INSERT INTO grade_bands (scale_id, grade_label, min_percent, sort_order) SELECT :new_id, grade_label, min_percent, sort_order FROM grade_bands WHERE scale_id = :old_id');
            $copy->execute([':new_id' => $newScaleId, ':old_id' => $scaleId]);
            db()->prepare('UPDATE academic_years SET grading_scale_id = :new_id WHERE id = :year_id')->execute([':new_id' => $newScaleId, ':year_id' => $yearId]);
            $scaleId = $newScaleId;
        }
        db()->prepare('DELETE FROM grade_bands WHERE scale_id = :scale_id')->execute([':scale_id' => $scaleId]);
        $insert = db()->prepare('INSERT INTO grade_bands (scale_id, grade_label, min_percent, sort_order) VALUES (:scale_id, :label, :minimum, :sort_order)');
        foreach (sort_bands($newBands) as $index => $band) $insert->execute([':scale_id' => $scaleId, ':label' => trim((string) $band['label']), ':minimum' => (float) $band['min'], ':sort_order' => $index + 1]);
        $setting = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (['rounding_rule' => $newRounding, 'marks_decimals' => (string) $marksDecimals, 'percent_decimals' => (string) $percentDecimals] as $key => $value) $setting->execute([':key' => $key, ':value' => $value]);
        log_action('grading_saved', 'academic_years', $yearId, ['scale_id' => $year['grading_scale_id'], 'bands' => $oldBands, 'rounding' => $oldGrading['rounding'], 'marks_decimals' => $oldGrading['marks_decimals'], 'percent_decimals' => $oldGrading['percent_decimals']], ['scale_id' => $scaleId, 'bands' => $newBands, 'rounding' => $newRounding, 'marks_decimals' => $marksDecimals, 'percent_decimals' => $percentDecimals, 'reason' => trim($reason), 'impact' => ['results_changed' => $impact['results_changed'], 'grand_changed' => $impact['grand_changed'], 'students_affected' => $impact['students_affected']]]);
        db()->commit();
        return $impact;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Save grading rules failed: ' . $exception->getMessage());
        if ($exception instanceof InvalidArgumentException) throw $exception;
        throw new RuntimeException('The grade rules could not be saved. Please try again.', 0, $exception);
    }
}
