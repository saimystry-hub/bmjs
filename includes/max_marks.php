<?php

function get_max_grid(int $yearId, int $classId): array
{
    $exams = get_structure($yearId, $classId);
    $subjectRows = get_class_subjects($yearId, $classId);
    $marksSubjects = [];
    $gradeOnly = [];
    foreach ($subjectRows as $subject) {
        if (effective_type($subject) === 'graded') {
            $marksSubjects[(int) $subject['subject_id']] = $subject;
        } else {
            $gradeOnly[] = $subject;
        }
    }
    $cellQuery = db()->prepare('SELECT mm.assessment_id, mm.subject_id, mm.component_id, mm.max_value FROM max_marks mm JOIN assessments a ON a.id = mm.assessment_id WHERE a.academic_year_id = :year_id AND a.class_id = :class_id');
    $cellQuery->execute([':year_id' => $yearId, ':class_id' => $classId]);
    $cells = [];
    foreach ($cellQuery->fetchAll() as $cell) {
        $cells[(int) $cell['assessment_id']][(int) $cell['subject_id']][(int) $cell['component_id']] = (float) $cell['max_value'];
    }
    $maxByExamSubject = [];
    foreach ($exams as $exam) {
        if ($exam['mode'] !== 'entered') {
            continue;
        }
        foreach ($marksSubjects as $subjectId => $_) {
            $values = [];
            foreach ($exam['components'] as $column) {
                $values[] = $cells[(int) $exam['id']][$subjectId][(int) $column['id']] ?? null;
            }
            $maxByExamSubject[$exam['code']][$subjectId] = in_array(null, $values, true) ? null : array_sum($values);
        }
    }
    $pureExams = [];
    foreach ($exams as $exam) {
        $pureExams[] = [
            'code' => $exam['code'], 'mode' => $exam['mode'],
            'calc_method' => $exam['calc_method'], 'sources' => $exam['source_codes'],
        ];
    }
    return [
        'exams' => $exams, 'marks_subjects' => $marksSubjects, 'grade_only' => $gradeOnly,
        'cells' => $cells, 'totals' => compute_max_totals($pureExams, $maxByExamSubject),
    ];
}

function save_max_marks(int $yearId, int $classId, array $cells, string $auditAction = 'max_marks_saved', array $auditDetails = []): array
{
    $grid = get_max_grid($yearId, $classId);
    $allowed = [];
    foreach ($grid['exams'] as $exam) {
        if ($exam['mode'] !== 'entered') {
            continue;
        }
        foreach ($exam['components'] as $column) {
            foreach ($grid['marks_subjects'] as $subjectId => $_subject) {
                $allowed[(int) $exam['id']][$subjectId][(int) $column['id']] = true;
            }
        }
    }
    $errors = [];
    $cellErrors = [];
    $changes = [];
    foreach ($cells as $examId => $subjects) {
        foreach ((array) $subjects as $subjectId => $columns) {
            foreach ((array) $columns as $columnId => $text) {
                $e = (int) $examId;
                $s = (int) $subjectId;
                $c = (int) $columnId;
                if (empty($allowed[$e][$s][$c])) {
                    $errors[] = 'A maximum was provided for a subject, exam or column that is not editable.';
                    continue;
                }
                if (!is_scalar($text)) {
                    $message = $grid['marks_subjects'][$s]['subject_name'] . ': enter a maximum as a number.';
                    $errors[] = $message;
                    $cellErrors[$e][$s][$c] = $message;
                    continue;
                }
                $value = validate_max_value((string) $text);
                if (!$value['ok']) {
                    $message = $grid['marks_subjects'][$s]['subject_name'] . ': ' . $value['error'];
                    $errors[] = $message;
                    $cellErrors[$e][$s][$c] = $message;
                    continue;
                }
                $oldValue = $grid['cells'][$e][$s][$c] ?? null;
                if ($value['value'] === null) {
                    if ($oldValue !== null) {
                        $changes[] = ['exam' => $e, 'subject' => $s, 'column' => $c, 'value' => null];
                    }
                    continue;
                }
                if ($oldValue !== null && (float) $oldValue === (float) $value['value']) {
                    continue;
                }
                $changes[] = ['exam' => $e, 'subject' => $s, 'column' => $c, 'value' => $value['value']];
            }
        }
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'cell_errors' => $cellErrors, 'changed' => 0];
    }
    if ($changes === []) {
        return ['ok' => true, 'errors' => [], 'changed' => 0];
    }
    $examIds = array_values(array_unique(array_column($changes, 'exam')));
    $marks = implode(',', array_fill(0, count($examIds), '?'));
    $highest = db()->prepare('SELECT assessment_id, subject_id, component_id, MAX(value) AS highest FROM marks WHERE assessment_id IN (' . $marks . ') AND value IS NOT NULL GROUP BY assessment_id, subject_id, component_id');
    $highest->execute($examIds);
    $highestLookup = [];
    foreach ($highest->fetchAll() as $row) {
        $highestLookup[(int) $row['assessment_id']][(int) $row['subject_id']][(int) $row['component_id']] = (float) $row['highest'];
    }
    foreach ($changes as $change) {
        $maximumEntered = $highestLookup[$change['exam']][$change['subject']][$change['column']] ?? null;
        if ($maximumEntered !== null && ($change['value'] === null || $maximumEntered > $change['value'])) {
            $subjectName = $grid['marks_subjects'][$change['subject']]['subject_name'];
            $message = $subjectName . ': marks already entered are higher than ' . ($change['value'] ?? 'the available maximum') . '.';
            $errors[] = $message;
            $cellErrors[$change['exam']][$change['subject']][$change['column']] = $message;
        }
    }
    if ($errors !== []) return ['ok' => false, 'errors' => $errors, 'cell_errors' => $cellErrors, 'changed' => 0];
    db()->beginTransaction();
    try {
        $upsert = db()->prepare('INSERT INTO max_marks (class_id, subject_id, assessment_id, component_id, max_value) VALUES (:class_id, :subject_id, :assessment_id, :component_id, :value) ON DUPLICATE KEY UPDATE max_value = VALUES(max_value)');
        $delete = db()->prepare('DELETE FROM max_marks WHERE class_id = :class_id AND subject_id = :subject_id AND assessment_id = :assessment_id AND component_id = :component_id');
        foreach ($changes as $change) {
            $params = [':class_id' => $classId, ':subject_id' => $change['subject'], ':assessment_id' => $change['exam'], ':component_id' => $change['column']];
            if ($change['value'] === null) {
                $delete->execute($params);
            } else {
                $upsert->execute($params + [':value' => $change['value']]);
            }
        }
        log_action($auditAction, 'max_marks', null, null, ['count' => count($changes), 'class_id' => $classId] + $auditDetails);
        db()->commit();
        return ['ok' => true, 'errors' => [], 'changed' => count($changes)];
    } catch (Throwable $exception) {
        db()->rollBack();
        error_log('Maximum marks save failed: ' . $exception->getMessage());
        return ['ok' => false, 'errors' => ['The maximum marks could not be saved.'], 'changed' => 0];
    }
}

function fill_empty_max(int $yearId, int $classId, string $mode, ?float $value = null): int
{
    $grid = get_max_grid($yearId, $classId);
    $cells = [];
    foreach ($grid['exams'] as $exam) {
        if ($exam['mode'] !== 'entered') {
            continue;
        }
        foreach ($exam['components'] as $column) {
            $values = [];
            foreach ($grid['marks_subjects'] as $subjectId => $_) {
                $old = $grid['cells'][(int) $exam['id']][$subjectId][(int) $column['id']] ?? null;
                if ($old !== null) {
                    $values[] = $old;
                }
            }
            $fill = $mode === 'common' ? modal_value($values) : $value;
            if ($fill === null) {
                continue;
            }
            foreach ($grid['marks_subjects'] as $subjectId => $_) {
                $old = $grid['cells'][(int) $exam['id']][$subjectId][(int) $column['id']] ?? null;
                if ($old === null) {
                    $cells[(int) $exam['id']][$subjectId][(int) $column['id']] = (string) $fill;
                }
            }
        }
    }
    $result = save_max_marks($yearId, $classId, $cells, 'max_marks_filled');
    if (!$result['ok']) throw new RuntimeException(implode(' ', $result['errors']));
    return $result['changed'];
}

function apply_column_value(int $yearId, int $classId, int $examId, int $columnId, float $value): int
{
    $grid = get_max_grid($yearId, $classId);
    $valid = false;
    foreach ($grid['exams'] as $exam) {
        if ((int) $exam['id'] === $examId && $exam['mode'] === 'entered') {
            foreach ($exam['components'] as $column) {
                $valid = $valid || (int) $column['id'] === $columnId;
            }
        }
    }
    if (!$valid || $value < 0 || $value > 999.99) {
        throw new InvalidArgumentException('Choose a valid exam column and maximum.');
    }
    $ids = array_keys($grid['marks_subjects']);
    if ($ids === []) {
        return 0;
    }
    $cells = [];
    foreach ($ids as $subjectId) $cells[$examId][$subjectId][$columnId] = (string) $value;
    $result = save_max_marks($yearId, $classId, $cells, 'max_marks_column_applied');
    if (!$result['ok']) throw new RuntimeException(implode(' ', $result['errors']));
    return $result['changed'];
}

function max_marks_cell_gaps(int $yearId, int $classId): array
{
    $grid = get_max_grid($yearId, $classId);
    $gaps = [];
    foreach ($grid['exams'] as $exam) {
        if ($exam['mode'] !== 'entered') {
            continue;
        }
        foreach ($grid['marks_subjects'] as $subjectId => $subject) {
            foreach ($exam['components'] as $column) {
                if (!isset($grid['cells'][(int) $exam['id']][$subjectId][(int) $column['id']])) {
                    $gaps[] = ['exam' => $exam['name'], 'subject' => $subject['subject_name'], 'column' => $column['name']];
                }
            }
        }
    }
    return $gaps;
}

function copy_max_from_class(int $yearId, int $fromClassId, int $toClassId, bool $onlyEmpty = true): array
{
    $source = get_max_grid($yearId, $fromClassId);
    $target = get_max_grid($yearId, $toClassId);
    $sourceSubjects = [];
    foreach ($source['marks_subjects'] as $id => $subject) {
        $sourceSubjects[(string) $subject['subject_name']] = $id;
    }
    $targetSubjects = [];
    foreach ($target['marks_subjects'] as $id => $subject) {
        $targetSubjects[(string) $subject['subject_name']] = $id;
    }
    $sourceExams = [];
    foreach ($source['exams'] as $exam) {
        $sourceExams[$exam['code']] = $exam;
    }
    $cells = [];
    $skipped = 0;
    foreach ($target['exams'] as $exam) {
        $from = $sourceExams[$exam['code']] ?? null;
        if (!$from || $exam['mode'] !== 'entered' || $from['mode'] !== 'entered') {
            continue;
        }
        $fromColumns = [];
        foreach ($from['components'] as $column) {
            $fromColumns[$column['name']] = $column['id'];
        }
        foreach ($exam['components'] as $column) {
            if (!isset($fromColumns[$column['name']])) {
                continue;
            }
            foreach ($targetSubjects as $name => $subjectId) {
                if (!isset($sourceSubjects[$name])) {
                    $skipped++;
                    continue;
                }
                $value = $source['cells'][(int) $from['id']][$sourceSubjects[$name]][(int) $fromColumns[$column['name']]] ?? null;
                if ($value === null || ($onlyEmpty && isset($target['cells'][(int) $exam['id']][$subjectId][(int) $column['id']]))) {
                    $skipped++;
                    continue;
                }
                $cells[(int) $exam['id']][$subjectId][(int) $column['id']] = (string) $value;
            }
        }
    }
    $result = save_max_marks($yearId, $toClassId, $cells, 'max_marks_copied', ['source_class_id' => $fromClassId, 'target_class_id' => $toClassId]);
    if (!$result['ok']) throw new RuntimeException(implode(' ', $result['errors']));
    return ['copied' => $result['changed'], 'skipped' => $skipped];
}
