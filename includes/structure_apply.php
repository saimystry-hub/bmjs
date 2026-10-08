<?php

function same_exam_structure(array $source, array $target): bool
{
    return array_map(static function (array $exam): array { unset($exam['max']); return $exam; }, $source) === $target;
}

function plan_apply(array $source, array $targetClassIds, int $yearId, array $options): array
{
    $options = array_merge(['replace' => false, 'fill_max' => true], $options);
    if (!in_array($source['type'] ?? '', ['preset', 'class'], true)) throw new InvalidArgumentException('Choose a valid structure source.');
    if (($source['type'] ?? '') === 'preset') {
        $q = db()->prepare('SELECT definition, name FROM structure_presets WHERE id = :id LIMIT 1');
        $q->execute([':id' => (int) ($source['id'] ?? 0)]);
        $preset = $q->fetch();
        $parsed = $preset ? parse_preset((string) $preset['definition']) : ['ok' => false, 'exams' => []];
        if (empty($parsed['ok'])) throw new InvalidArgumentException($parsed['error'] ?? 'The selected ready-made structure is invalid.');
        $sourceExams = $parsed['exams'];
        $sourceName = (string) ($preset['name'] ?? 'Preset');
    } else {
        $sourceClassId = (int) ($source['class_id'] ?? 0);
        $sourceExams = get_structure($yearId, $sourceClassId);
        $sourceName = 'Class ' . $sourceClassId;
    }
    $sourceCanonical = [];
    foreach ($sourceExams as $exam) {
        $sourceCanonical[] = [
            'code' => $exam['code'], 'name' => $exam['name'], 'term_group' => $exam['term_group'],
            'mode' => $exam['mode'], 'calc_method' => $exam['calc_method'],
            'components' => array_map(static fn($c) => is_array($c) ? $c['name'] : $c, $exam['components'] ?? []),
            'sources' => array_values($exam['source_codes'] ?? $exam['sources'] ?? []),
            'is_term_result' => (int) $exam['is_term_result'], 'has_attendance' => (int) $exam['has_attendance'],
            'max' => $exam['max'] ?? [],
        ];
    }
    if ($sourceCanonical === [] || validate_structure($sourceCanonical)['errors'] !== []) throw new InvalidArgumentException('The source structure is empty or invalid.');
    $classes = db()->query('SELECT id, name FROM classes ORDER BY sort_order, id')->fetchAll();
    $classNames = [];
    foreach ($classes as $class) {
        $classNames[(int) $class['id']] = (string) $class['name'];
    }
    $targetClassIds = array_values(array_unique(array_filter(array_map('intval', $targetClassIds))));
    $rows = [];
    foreach ($targetClassIds as $classId) {
        $existing = get_structure($yearId, $classId);
        $examsCount = count($existing);
        $hasData = false;
        foreach ($existing as $exam) {
            if ((int) $exam['marks_count'] + (int) $exam['grades_count'] + (int) $exam['attendance_count'] + (int) $exam['submissions_count'] > 0) {
                $hasData = true;
                break;
            }
        }
        $targetStatus = 'create';
        $reason = '';
        if (($source['type'] ?? '') === 'class' && $classId === (int) ($source['class_id'] ?? 0)) {
            $targetStatus = 'skip_same';
            $reason = 'Same as the source.';
        } elseif ($examsCount > 0 && $hasData) {
            $targetStatus = 'skip_has_data';
            $reason = 'It has marks, grades, attendance or submissions, so it is not changed.';
        } elseif ($examsCount > 0 && empty($options['replace'])) {
            $same = [];
            foreach ($existing as $exam) {
                $same[] = [
                    'code' => $exam['code'], 'name' => $exam['name'], 'term_group' => $exam['term_group'],
                    'mode' => $exam['mode'], 'calc_method' => $exam['calc_method'],
                    'components' => array_column($exam['components'], 'name'), 'sources' => $exam['source_codes'],
                    'is_term_result' => (int) $exam['is_term_result'], 'has_attendance' => (int) $exam['has_attendance'],
                ];
            }
            if (same_exam_structure($sourceCanonical, $same)) {
                $targetStatus = 'skip_same';
                $reason = 'It already matches the source.';
            } else {
                $targetStatus = 'skip_exists';
                $reason = 'It already has a structure. Turn on Replace to change it.';
            }
        } elseif ($examsCount > 0) {
            $targetStatus = 'replace';
            $reason = 'Will replace the current ' . $examsCount . ' exams.';
        }
        $maximumCells = 0;
        if (!empty($options['fill_max']) && in_array($targetStatus, ['create', 'replace'], true)) {
            $s = db()->prepare("SELECT COUNT(*) FROM class_subjects cs JOIN subjects sub ON sub.id = cs.subject_id WHERE cs.academic_year_id = :year_id AND cs.class_id = :class_id AND cs.is_active = 1 AND COALESCE(cs.type_override, sub.type) = 'graded'");
            $s->execute([':year_id' => $yearId, ':class_id' => $classId]);
            $subjectCount = (int) $s->fetchColumn();
            foreach ($sourceExams as $exam) {
                if (($exam['mode'] ?? '') === 'entered') {
                    $maximumCells += $subjectCount * count($exam['components'] ?? []);
                }
            }
        }
        $rows[] = [
            'class_id' => $classId, 'class_name' => $classNames[$classId] ?? 'Unknown',
            'status' => $targetStatus, 'reason' => $reason, 'exam_count' => count($sourceExams),
            'existing_exam_count' => $examsCount, 'maximum_cells' => $maximumCells,
        ];
    }
    return ['source' => $source, 'source_name' => $sourceName, 'exams' => $sourceCanonical, 'rows' => $rows, 'options' => $options, 'year_id' => $yearId];
}

function run_apply(array $plan, array $source, int $yearId, int $userId): array
{
    $sourceExams = $plan['exams'] ?? [];
    $sourceGrid = null;
    if (($source['type'] ?? '') === 'class') {
        $sourceGrid = get_max_grid($yearId, (int) $source['class_id']);
    }
    $created = 0;
    $replaced = 0;
    db()->beginTransaction();
    try {
        foreach ($plan['rows'] ?? [] as $target) {
            if (!in_array($target['status'] ?? '', ['create', 'replace'], true)) {
                continue;
            }
            $classId = (int) $target['class_id'];
            $lock = db()->prepare('SELECT id FROM assessments WHERE academic_year_id = :year_id AND class_id = :class_id ORDER BY id FOR UPDATE');
            $lock->execute([':year_id' => $yearId, ':class_id' => $classId]);
            $currentIds = array_map('intval', array_column($lock->fetchAll(), 'id'));
            if (count($currentIds) !== (int) ($target['existing_exam_count'] ?? -1)) {
                throw new RuntimeException('The class structure changed after the preview.');
            }
            foreach ($currentIds as $currentId) {
                if (exam_has_data($currentId)) {
                    throw new RuntimeException('A class now has marks or results, so its structure was not changed.');
                }
            }
            if ($target['status'] === 'replace') {
                $delete = db()->prepare('DELETE FROM assessments WHERE academic_year_id = :year_id AND class_id = :class_id');
                $delete->execute([':year_id' => $yearId, ':class_id' => $classId]);
                $replaced++;
            }
            $idByCode = [];
            foreach ($sourceExams as $order => $exam) {
                db()->prepare('INSERT INTO assessments (academic_year_id, class_id, code, name, term_group, sort_order, mode, calc_method, state, has_attendance, is_term_result) VALUES (:year_id, :class_id, :code, :name, :term_group, :sort_order, :mode, :method, "draft", :attendance, :result)')
                    ->execute([':year_id' => $yearId, ':class_id' => $classId, ':code' => $exam['code'], ':name' => $exam['name'], ':term_group' => $exam['term_group'], ':sort_order' => $order + 1, ':mode' => $exam['mode'], ':method' => $exam['calc_method'], ':attendance' => $exam['has_attendance'], ':result' => $exam['is_term_result']]);
                $idByCode[(string) $exam['code']] = (int) db()->lastInsertId();
            }
            $subjectQuery = db()->prepare("SELECT cs.subject_id FROM class_subjects cs JOIN subjects sub ON sub.id = cs.subject_id WHERE cs.academic_year_id = :year_id AND cs.class_id = :class_id AND cs.is_active = 1 AND COALESCE(cs.type_override, sub.type) = 'graded'");
            $subjectQuery->execute([':year_id' => $yearId, ':class_id' => $classId]);
            $subjectIds = array_map('intval', array_column($subjectQuery->fetchAll(), 'subject_id'));
            foreach ($sourceExams as $exam) {
                $examId = $idByCode[(string) $exam['code']];
                $columnIds = [];
                foreach ($exam['components'] as $column) {
                    $q = db()->prepare('SELECT id FROM components WHERE name = :name LIMIT 1');
                    $q->execute([':name' => $column]);
                    $columnId = (int) $q->fetchColumn();
                    if ($columnId <= 0) {
                        throw new RuntimeException('A marks column in the structure is no longer available.');
                    }
                    $columnIds[$column] = $columnId;
                    db()->prepare('INSERT INTO assessment_components (assessment_id, component_id) VALUES (:exam_id, :component_id)')
                        ->execute([':exam_id' => $examId, ':component_id' => $columnId]);
                }
                foreach ($exam['sources'] as $code) {
                    if (!isset($idByCode[$code])) {
                        throw new RuntimeException('A source exam is missing from the structure.');
                    }
                    db()->prepare('INSERT INTO assessment_sources (assessment_id, source_assessment_id) VALUES (:exam_id, :source_id)')
                        ->execute([':exam_id' => $examId, ':source_id' => $idByCode[$code]]);
                }
                if (!empty($plan['options']['fill_max']) && $exam['mode'] === 'entered') {
                    foreach ($subjectIds as $subjectId) {
                        foreach ($columnIds as $columnName => $columnId) {
                            $max = null;
                            if (($source['type'] ?? '') === 'preset') {
                                $original = null;
                                foreach (($plan['exams'] ?? []) as $sourceExam) {
                                    if ($sourceExam['code'] === $exam['code']) {
                                        $original = $sourceExam;
                                        break;
                                    }
                                }
                                $max = $original['max'][$columnName] ?? null;
                            } elseif ($sourceGrid !== null) {
                                $fromExam = null;
                                foreach ($sourceGrid['exams'] as $sourceExam) {
                                    if ($sourceExam['code'] === $exam['code']) {
                                        $fromExam = $sourceExam;
                                        break;
                                    }
                                }
                                if ($fromExam) {
                                    $fromColumnId = null;
                                    foreach ($fromExam['components'] as $fromColumn) {
                                        if ($fromColumn['name'] === $columnName) {
                                            $fromColumnId = (int) $fromColumn['id'];
                                        }
                                    }
                                    if ($fromColumnId !== null) {
                                        if (isset($sourceGrid['marks_subjects'][$subjectId])) {
                                            $max = $sourceGrid['cells'][(int) $fromExam['id']][$subjectId][$fromColumnId] ?? null;
                                        }
                                        if (!isset($sourceGrid['marks_subjects'][$subjectId])) {
                                            $values = [];
                                            foreach ($sourceGrid['marks_subjects'] as $sid => $_) {
                                                $cell = $sourceGrid['cells'][(int) $fromExam['id']][$sid][$fromColumnId] ?? null;
                                                if ($cell !== null) {
                                                    $values[] = $cell;
                                                }
                                            }
                                            $max = modal_value($values);
                                        }
                                    }
                                }
                            }
                            if ($max !== null) {
                                db()->prepare('INSERT IGNORE INTO max_marks (class_id, subject_id, assessment_id, component_id, max_value) VALUES (:class_id, :subject_id, :exam_id, :component_id, :value)')
                                    ->execute([':class_id' => $classId, ':subject_id' => $subjectId, ':exam_id' => $examId, ':component_id' => $columnId, ':value' => $max]);
                            }
                        }
                    }
                }
            }
            $created++;
        }
        log_action('exam_structure_applied', 'assessments', null, null, ['source' => $source, 'year_id' => $yearId, 'created_classes' => $created, 'replaced_classes' => $replaced]);
        db()->commit();
        return ['created' => $created, 'replaced' => $replaced];
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Apply exam structure failed: ' . $exception->getMessage());
        throw new RuntimeException('The structure was not applied. Please review it and try again.', 0, $exception);
    }
}

function save_class_as_preset(int $yearId, int $classId, string $name, string $description, int $userId): int
{
    $name = trim($name);
    $description = trim($description);
    if ($name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 255) {
        throw new InvalidArgumentException('Enter a preset name and a description of up to 255 characters.');
    }
    $duplicate = db()->prepare('SELECT 1 FROM structure_presets WHERE name = :name LIMIT 1');
    $duplicate->execute([':name' => $name]);
    if ($duplicate->fetchColumn()) throw new InvalidArgumentException('A ready-made structure with that name already exists.');
    $grid = get_max_grid($yearId, $classId);
    $exams = [];
    $maxValues = [];
    foreach ($grid['exams'] as $exam) {
        $exams[] = ['code' => $exam['code'], 'name' => $exam['name'], 'term_group' => $exam['term_group'], 'mode' => $exam['mode'], 'calc_method' => $exam['calc_method'], 'components' => array_column($exam['components'], 'name'), 'sources' => $exam['source_codes'], 'is_term_result' => $exam['is_term_result'], 'has_attendance' => $exam['has_attendance']];
        foreach ($exam['components'] as $column) {
            foreach ($grid['marks_subjects'] as $subjectId => $_) {
                $value = $grid['cells'][(int) $exam['id']][$subjectId][(int) $column['id']] ?? null;
                if ($value !== null) {
                    $maxValues[$exam['code']][$column['name']][] = $value;
                }
            }
        }
    }
    if ($exams === [] || validate_structure($exams)['errors'] !== []) {
        throw new InvalidArgumentException('Fix the exam structure before saving it as a ready-made structure.');
    }
    $json = derive_preset_json($exams, $maxValues);
    db()->beginTransaction();
    try {
        $query = db()->prepare('INSERT INTO structure_presets (name, description, definition, is_builtin, created_by) VALUES (:name, :description, :definition, 0, :user_id)');
        $query->execute([':name' => $name, ':description' => $description, ':definition' => $json, ':user_id' => $userId]);
        $id = (int) db()->lastInsertId();
        log_action('structure_preset_saved', 'structure_presets', $id, null, ['name' => $name]);
        db()->commit();
        return $id;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Save exam structure preset failed: ' . $exception->getMessage());
        throw new RuntimeException('The ready-made structure could not be saved.', 0, $exception);
    }
}

function delete_user_preset(int $presetId, int $userId): bool
{
    db()->beginTransaction();
    try {
        $query = db()->prepare('DELETE FROM structure_presets WHERE id = :id AND is_builtin = 0 AND created_by = :user_id');
        $query->execute([':id' => $presetId, ':user_id' => $userId]);
        if ($query->rowCount() !== 1) { db()->rollBack(); return false; }
        log_action('structure_preset_deleted', 'structure_presets', $presetId, null, null);
        db()->commit();
        return true;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Delete exam structure preset failed: ' . $exception->getMessage());
        throw new RuntimeException('The ready-made structure could not be removed.', 0, $exception);
    }
}

function get_presets(): array
{
    $rows = db()->query('SELECT * FROM structure_presets ORDER BY is_builtin DESC, name')->fetchAll();
    foreach ($rows as &$row) {
        $row['parsed'] = parse_preset((string) $row['definition']);
        $row['description'] = (string) ($row['description'] ?? '');
    }
    unset($row);
    return $rows;
}

function structure_status_by_class(int $yearId): array
{
    $classes = db()->query('SELECT id, name FROM classes ORDER BY sort_order, id')->fetchAll();
    $q = db()->prepare("SELECT cs.class_id, COUNT(*) subject_count, SUM(CASE WHEN COALESCE(cs.type_override, s.type) = 'graded' THEN 1 ELSE 0 END) marks_subject_count FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id WHERE cs.academic_year_id = :year_id AND cs.is_active = 1 GROUP BY cs.class_id");
    $q->execute([':year_id' => $yearId]);
    $subjects = [];
    $marksSubjects = [];
    foreach ($q->fetchAll() as $row) {
        $id = (int) $row['class_id'];
        $subjects[$id] = (int) $row['subject_count'];
        $marksSubjects[$id] = (int) $row['marks_subject_count'];
    }
    $q = db()->prepare('SELECT a.id, a.class_id, a.mode, COUNT(ac.component_id) component_count FROM assessments a LEFT JOIN assessment_components ac ON ac.assessment_id = a.id WHERE a.academic_year_id = :year_id GROUP BY a.id, a.class_id, a.mode');
    $q->execute([':year_id' => $yearId]);
    $examRows = $q->fetchAll();
    $q = db()->prepare("SELECT a.class_id, COUNT(DISTINCT mm.id) total FROM max_marks mm JOIN assessments a ON a.id = mm.assessment_id JOIN assessment_components ac ON ac.assessment_id = mm.assessment_id AND ac.component_id = mm.component_id JOIN class_subjects cs ON cs.academic_year_id = a.academic_year_id AND cs.class_id = a.class_id AND cs.subject_id = mm.subject_id AND cs.is_active = 1 JOIN subjects s ON s.id = cs.subject_id AND COALESCE(cs.type_override, s.type) = 'graded' WHERE a.academic_year_id = :year_id AND a.mode = 'entered' GROUP BY a.class_id");
    $q->execute([':year_id' => $yearId]);
    $filled = array_column($q->fetchAll(), 'total', 'class_id');
    $result = [];
    foreach ($classes as $class) {
        $id = (int) $class['id'];
        $result[$id] = ['class_id' => $id, 'class_name' => $class['name'], 'subject_count' => $subjects[$id] ?? 0, 'exam_count' => 0, 'required_cells' => 0, 'filled_cells' => (int) ($filled[$id] ?? 0)];
    }
    foreach ($examRows as $exam) {
        $id = (int) $exam['class_id'];
        $result[$id]['exam_count']++;
        if ($exam['mode'] === 'entered') $result[$id]['required_cells'] += (int) $exam['component_count'] * ($marksSubjects[$id] ?? 0);
    }
    return $result;
}
