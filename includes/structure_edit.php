<?php
function get_structure(int $yearId, int $classId): array
{
    $q = db()->prepare('SELECT * FROM assessments WHERE academic_year_id = :year_id AND class_id = :class_id ORDER BY sort_order, id');
    $q->execute([':year_id' => $yearId, ':class_id' => $classId]);
    $exams = $q->fetchAll();
    if ($exams === []) return [];
    $ids = array_map(static fn($row) => (int) $row['id'], $exams);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $byId = [];
    $q = db()->prepare('SELECT ac.assessment_id, ac.component_id, c.name FROM assessment_components ac JOIN components c ON c.id = ac.component_id WHERE ac.assessment_id IN (' . $in . ') ORDER BY c.sort_order, c.id');
    $q->execute($ids);
    foreach ($q->fetchAll() as $row) $byId[(int) $row['assessment_id']]['components'][] = ['id' => (int) $row['component_id'], 'name' => $row['name']];
    $q = db()->prepare('SELECT s.assessment_id, s.source_assessment_id, a.code FROM assessment_sources s JOIN assessments a ON a.id = s.source_assessment_id WHERE s.assessment_id IN (' . $in . ') ORDER BY a.sort_order, a.id');
    $q->execute($ids);
    foreach ($q->fetchAll() as $row) {
        $byId[(int) $row['assessment_id']]['sources'][] = (int) $row['source_assessment_id'];
        $byId[(int) $row['assessment_id']]['source_codes'][] = (string) $row['code'];
    }
    foreach (['marks_count' => 'marks', 'grades_count' => 'grade_entries', 'attendance_count' => 'attendance', 'submissions_count' => 'submissions'] as $key => $table) {
        $q = db()->prepare('SELECT assessment_id, COUNT(*) AS total FROM ' . $table . ' WHERE assessment_id IN (' . $in . ') GROUP BY assessment_id');
        $q->execute($ids);
        $counts = [];
        foreach ($q->fetchAll() as $row) $counts[(int) $row['assessment_id']] = (int) $row['total'];
        foreach ($exams as &$exam) $exam[$key] = $counts[(int) $exam['id']] ?? 0;
        unset($exam);
    }
    foreach ($exams as &$exam) {
        $id = (int) $exam['id'];
        $exam['components'] = $byId[$id]['components'] ?? [];
        $exam['sources'] = $byId[$id]['sources'] ?? [];
        $exam['source_codes'] = $byId[$id]['source_codes'] ?? [];
    }
    unset($exam);
    return $exams;
}
function exam_has_data(int $examId): bool
{
    $q = db()->prepare('SELECT EXISTS(SELECT 1 FROM marks WHERE assessment_id = :m) OR EXISTS(SELECT 1 FROM grade_entries WHERE assessment_id = :g) OR EXISTS(SELECT 1 FROM attendance WHERE assessment_id = :a) OR EXISTS(SELECT 1 FROM submissions WHERE assessment_id = :s)');
    $q->execute([':m' => $examId, ':g' => $examId, ':a' => $examId, ':s' => $examId]);
    return (bool) $q->fetchColumn();
}
function structure_exam_values(array $row, array $components = [], array $sources = []): array
{
    return [
        'code' => (string) $row['code'], 'name' => (string) $row['name'],
        'term_group' => (string) $row['term_group'], 'mode' => (string) $row['mode'],
        'calc_method' => $row['calc_method'] ?? null, 'components' => $components,
        'sources' => $sources, 'is_term_result' => (int) $row['is_term_result'],
        'has_attendance' => (int) $row['has_attendance'],
    ];
}
function structure_validate_class(int $yearId, int $classId, ?int $replaceId, array $replacement): array
{
    $exams = get_structure($yearId, $classId);
    $memory = [];
    foreach ($exams as $exam) {
        if ((int) $exam['id'] === $replaceId) {
            $memory[] = $replacement;
            continue;
        }
        $memory[] = structure_exam_values($exam, array_column($exam['components'], 'name'), $exam['source_codes']);
    }
    if ($replaceId === null) {
        $memory[] = $replacement;
    }
    return validate_structure($memory);
}
function save_exam(int $yearId, int $classId, array $data, ?int $examId = null): array
{
    $old = null;
    if ($examId !== null) {
        $query = db()->prepare('SELECT * FROM assessments WHERE id = :id AND academic_year_id = :year_id AND class_id = :class_id LIMIT 1');
        $query->execute([':id' => $examId, ':year_id' => $yearId, ':class_id' => $classId]);
        $old = $query->fetch();
        if (!$old) return ['ok' => false, 'errors' => ['The exam could not be found.'], 'warnings' => []];
    }
    $code = $old ? (string) $old['code'] : trim((string) ($data['code'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $group = (string) ($data['term_group'] ?? 'mid');
    $mode = (string) ($data['mode'] ?? 'entered');
    $method = in_array($data['calc_method'] ?? '', ['sum', 'average'], true) ? $data['calc_method'] : null;
    $componentIds = array_values(array_unique(array_filter(array_map('intval', $data['component_ids'] ?? []))));
    $sourceIds = array_values(array_unique(array_filter(array_map('intval', $data['source_ids'] ?? []))));
    $componentNames = [];
    if ($componentIds !== []) {
        $q = db()->prepare('SELECT id, name FROM components WHERE id IN (' . implode(',', array_fill(0, count($componentIds), '?')) . ')');
        $q->execute($componentIds);
        foreach ($q->fetchAll() as $row) {
            $componentNames[(int) $row['id']] = (string) $row['name'];
        }
    }
    $sourceCodes = [];
    if ($sourceIds !== []) {
        $q = db()->prepare('SELECT id, code FROM assessments WHERE id IN (' . implode(',', array_fill(0, count($sourceIds), '?')) . ') AND class_id = ? AND academic_year_id = ?');
        $q->execute(array_merge($sourceIds, [$classId, $yearId]));
        foreach ($q->fetchAll() as $row) {
            $sourceCodes[] = (string) $row['code'];
        }
        if (count($sourceCodes) !== count($sourceIds)) return ['ok' => false, 'errors' => ['Choose exams from this class only.'], 'warnings' => []];
    }
    if (!in_array($mode, ['entered', 'calculated'], true)) return ['ok' => false, 'errors' => ['Choose how the marks are obtained.'], 'warnings' => []];
    if (count($componentNames) !== count($componentIds)) return ['ok' => false, 'errors' => ['One of the selected marks columns is not available.'], 'warnings' => []];
    foreach ($componentIds as $componentId) {
        $default = trim((string) (($data['max_defaults'] ?? [])[$componentId] ?? ''));
        if ($default !== '' && !validate_max_value($default)['ok']) return ['ok' => false, 'errors' => ['Enter a valid maximum for each selected marks column.'], 'warnings' => []];
    }
    $candidate = [
        'code' => $code, 'name' => $name, 'term_group' => $group, 'mode' => $mode,
        'calc_method' => $method, 'components' => array_values($componentNames),
        'sources' => $sourceCodes, 'is_term_result' => !empty($data['is_term_result']) ? 1 : 0,
        'has_attendance' => !empty($data['has_attendance']) ? 1 : 0,
    ];
    $validation = structure_validate_class($yearId, $classId, $examId, $candidate);
    if ($validation['errors'] !== []) return ['ok' => false, 'errors' => $validation['errors'], 'warnings' => $validation['warnings']];
    $warnings = $validation['warnings'];
    if ($old && $old['term_group'] !== $group && exam_has_data($examId)) {
        $warnings[] = 'The term group changed, but existing results were kept.';
    }
    $addedComponents = $componentIds;
    if ($old && $old['mode'] === 'entered') {
        $oldColumnsQuery = db()->prepare('SELECT ac.component_id, c.name FROM assessment_components ac JOIN components c ON c.id = ac.component_id WHERE ac.assessment_id = :id');
        $oldColumnsQuery->execute([':id' => $examId]);
        $oldColumns = $oldColumnsQuery->fetchAll();
        $oldIds = array_map(static fn($row) => (int) $row['component_id'], $oldColumns);
        $addedComponents = array_values(array_diff($componentIds, $oldIds));
        $removedIds = array_values(array_diff($oldIds, $componentIds));
        if ($removedIds !== []) {
            $marksQuery = db()->prepare('SELECT c.name, COUNT(m.id) AS total FROM assessment_components ac JOIN components c ON c.id = ac.component_id LEFT JOIN marks m ON m.assessment_id = ac.assessment_id AND m.component_id = ac.component_id WHERE ac.assessment_id = :id AND ac.component_id IN (' . implode(',', array_fill(0, count($removedIds), '?')) . ') GROUP BY c.name');
            $marksQuery->execute(array_merge([$examId], $removedIds));
            foreach ($marksQuery->fetchAll() as $row) {
                if ((int) $row['total'] > 0) {
                    return ['ok' => false, 'errors' => [$row['name'] . ' already has marks, so it cannot be removed.'], 'warnings' => $warnings];
                }
            }
        }
    }
    try {
        db()->beginTransaction();
        if ($old) {
            db()->prepare('UPDATE assessments SET name = :name, term_group = :term_group, mode = :mode, calc_method = :method, has_attendance = :attendance, is_term_result = :result WHERE id = :id')
                ->execute([':name' => $name, ':term_group' => $group, ':mode' => $mode, ':method' => $method ?? $old['calc_method'], ':attendance' => $candidate['has_attendance'], ':result' => $candidate['is_term_result'], ':id' => $examId]);
            $savedId = $examId;
        } else {
            $last = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM assessments WHERE academic_year_id = :year_id AND class_id = :class_id');
            $last->execute([':year_id' => $yearId, ':class_id' => $classId]);
            db()->prepare('INSERT INTO assessments (academic_year_id, class_id, code, name, term_group, sort_order, mode, calc_method, state, has_attendance, is_term_result) VALUES (:year_id, :class_id, :code, :name, :term_group, :sort_order, :mode, :method, "draft", :attendance, :result)')
                ->execute([':year_id' => $yearId, ':class_id' => $classId, ':code' => $code, ':name' => $name, ':term_group' => $group, ':sort_order' => (int) $last->fetchColumn(), ':mode' => $mode, ':method' => $method, ':attendance' => $candidate['has_attendance'], ':result' => $candidate['is_term_result']]);
            $savedId = (int) db()->lastInsertId();
        }
        if ($mode === 'entered' && $componentIds !== []) {
            $delete = db()->prepare('DELETE FROM assessment_components WHERE assessment_id = :id AND component_id NOT IN (' . implode(',', array_fill(0, count($componentIds), '?')) . ')');
            $delete->execute(array_merge([$savedId], $componentIds));
            $insert = db()->prepare('INSERT IGNORE INTO assessment_components (assessment_id, component_id) VALUES (:assessment_id, :component_id)');
            foreach ($componentIds as $componentId) {
                $insert->execute([':assessment_id' => $savedId, ':component_id' => $componentId]);
            }
            if (!$old || $old['mode'] !== 'entered' || $addedComponents !== []) {
                $default = $data['max_defaults'] ?? [];
                $subjects = db()->prepare("SELECT cs.subject_id FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id WHERE cs.academic_year_id = :year_id AND cs.class_id = :class_id AND cs.is_active = 1 AND COALESCE(cs.type_override, s.type) = 'graded'");
                $subjects->execute([':year_id' => $yearId, ':class_id' => $classId]);
                $insertMax = db()->prepare('INSERT IGNORE INTO max_marks (class_id, subject_id, assessment_id, component_id, max_value) VALUES (:class_id, :subject_id, :assessment_id, :component_id, :max_value)');
                foreach ($subjects->fetchAll() as $subject) {
                    foreach (($old && $old['mode'] === 'entered' ? $addedComponents : $componentIds) as $componentId) {
                        $number = validate_max_value((string) ($default[$componentId] ?? ''));
                        if ($number['ok'] && $number['value'] !== null) {
                            $insertMax->execute([':class_id' => $classId, ':subject_id' => (int) $subject['subject_id'], ':assessment_id' => $savedId, ':component_id' => $componentId, ':max_value' => $number['value']]);
                        }

                    }
                }
            }
        }
        if ($mode === 'calculated') {
            db()->prepare('DELETE FROM assessment_sources WHERE assessment_id = :id')->execute([':id' => $savedId]);
            $insertSource = db()->prepare('INSERT INTO assessment_sources (assessment_id, source_assessment_id) VALUES (:id, :source_id)');
            foreach ($sourceIds as $sourceId) {
                $insertSource->execute([':id' => $savedId, ':source_id' => $sourceId]);
            }
        }
        if ($old && $old['mode'] !== $mode) {
            $markCount = db()->prepare('SELECT COUNT(*) FROM marks WHERE assessment_id = :id');
            $markCount->execute([':id' => $savedId]);
            log_action('exam_mode_switched', 'assessments', $savedId, ['mode' => $old['mode']], ['mode' => $mode, 'marks_count' => (int) $markCount->fetchColumn()]);
        } else {
            log_action('exam_saved', 'assessments', $savedId, $old ?: null, $candidate);
        }
        db()->commit();
        return ['ok' => true, 'id' => $savedId, 'errors' => [], 'warnings' => $warnings];
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Save exam failed: ' . $exception->getMessage());
        return ['ok' => false, 'errors' => ['The exam could not be saved. Check its code and try again.'], 'warnings' => []];
    }
}

function switch_exam_mode(int $examId, string $newMode, array $data): array
{
    $query = db()->prepare('SELECT * FROM assessments WHERE id = :id LIMIT 1');
    $query->execute([':id' => $examId]);
    $exam = $query->fetch();
    if (!$exam) {
        return ['ok' => false, 'errors' => ['The exam could not be found.'], 'warnings' => [], 'impact' => 0];
    }
    if ($newMode === (string) $exam['mode']) {
        return ['ok' => false, 'errors' => ['Choose a different way to obtain the marks.'], 'warnings' => [], 'impact' => 0];
    }
    $count = db()->prepare('SELECT COUNT(*) FROM marks WHERE assessment_id = :id');
    $count->execute([':id' => $examId]);
    $impact = (int) $count->fetchColumn();
    $result = save_exam((int) $exam['academic_year_id'], (int) $exam['class_id'], array_merge($data, ['mode' => $newMode]), $examId);
    $result['impact'] = $impact;
    if ($result['ok'] && $impact > 0) {
        $result['warnings'][] = $impact . ($newMode === 'calculated'
            ? ' marks remain saved and are ignored while this exam is worked out.'
            : ' marks remain saved and count again while teachers enter marks.');
    }
    return $result;
}

function delete_exam(int $examId): array
{
    $query = db()->prepare('SELECT * FROM assessments WHERE id = :id LIMIT 1');
    $query->execute([':id' => $examId]);
    $exam = $query->fetch();
    if (!$exam) {
        return ['ok' => false, 'message' => 'The exam could not be found.'];
    }
    if (exam_has_data($examId)) {
        return ['ok' => false, 'message' => 'This exam has results or submissions and cannot be removed.'];
    }
    $used = db()->prepare('SELECT a.name FROM assessment_sources s JOIN assessments a ON a.id = s.assessment_id WHERE s.source_assessment_id = :id');
    $used->execute([':id' => $examId]);
    $names = array_column($used->fetchAll(), 'name');
    if ($names !== []) {
        return ['ok' => false, 'message' => implode(', ', $names) . ' is worked out from this exam. Change it first.'];
    }
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM assessments WHERE id = :id')->execute([':id' => $examId]);
        log_action('exam_deleted', 'assessments', $examId, $exam, null);
        db()->commit();
        return ['ok' => true, 'message' => 'The exam was removed.'];
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function move_exam(int $examId, string $direction): bool
{
    $q = db()->prepare('SELECT * FROM assessments WHERE id = :id LIMIT 1');
    $q->execute([':id' => $examId]);
    $exam = $q->fetch();
    if (!$exam || !in_array($direction, ['up', 'down'], true)) {
        return false;
    }
    $operator = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $neighborQuery = db()->prepare('SELECT id, sort_order FROM assessments WHERE academic_year_id = :year AND class_id = :class AND sort_order ' . $operator . ' :sort ORDER BY sort_order ' . $order . ', id ' . $order . ' LIMIT 1');
    $neighborQuery->execute([':year' => $exam['academic_year_id'], ':class' => $exam['class_id'], ':sort' => $exam['sort_order']]);
    $neighbor = $neighborQuery->fetch();
    if (!$neighbor) {
        return false;
    }
    db()->beginTransaction();
    db()->prepare('UPDATE assessments SET sort_order = -1 WHERE id = :id')->execute([':id' => $examId]);
    db()->prepare('UPDATE assessments SET sort_order = :sort WHERE id = :id')->execute([':sort' => $exam['sort_order'], ':id' => $neighbor['id']]);
    db()->prepare('UPDATE assessments SET sort_order = :sort WHERE id = :id')->execute([':sort' => $neighbor['sort_order'], ':id' => $examId]);
    $all = db()->prepare('SELECT id FROM assessments WHERE academic_year_id = :year AND class_id = :class ORDER BY sort_order, id');
    $all->execute([':year' => $exam['academic_year_id'], ':class' => $exam['class_id']]);
    $update = db()->prepare('UPDATE assessments SET sort_order = :sort WHERE id = :id');
    foreach ($all->fetchAll() as $index => $row) {
        $update->execute([':sort' => $index + 1, ':id' => $row['id']]);
    }
    log_action('exam_moved', 'assessments', $examId, ['sort_order' => $exam['sort_order']], ['direction' => $direction]);
    db()->commit();
    return true;
}

function set_exam_state(int $examId, string $newState, string $role): array
{
    db()->beginTransaction();
    try {
        $q = db()->prepare('SELECT * FROM assessments WHERE id = :id LIMIT 1 FOR UPDATE');
        $q->execute([':id' => $examId]);
        $exam = $q->fetch();
        if (!$exam) { db()->rollBack(); return ['ok' => false, 'message' => 'The exam could not be found.']; }
        $allowed = state_transition_allowed((string) $exam['state'], $newState, $role);
        if (!$allowed['ok']) { db()->rollBack(); return ['ok' => false, 'message' => $allowed['reason']]; }
        db()->prepare('UPDATE assessments SET state = :state WHERE id = :id')->execute([':state' => $newState, ':id' => $examId]);
        log_action('exam_state_changed', 'assessments', $examId, ['state' => $exam['state']], ['state' => $newState]);
        db()->commit();
        return ['ok' => true, 'message' => 'The exam state was updated.'];
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Exam state update failed: ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'The exam state could not be updated. Please try again.'];
    }
}
