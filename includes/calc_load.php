<?php
require_once __DIR__ . '/calc.php';

// Load a year's grading scale and the displayed-number settings.
function year_grading(int $yearId): array
{
    $statement = db()->prepare(
        'SELECT ay.grading_scale_id, gs.name AS scale_name, gb.grade_label, gb.min_percent
         FROM academic_years ay JOIN grading_scales gs ON gs.id = ay.grading_scale_id
         LEFT JOIN grade_bands gb ON gb.scale_id = gs.id
         WHERE ay.id = :year_id ORDER BY gb.sort_order, gb.min_percent DESC'
    );
    $statement->execute([':year_id' => $yearId]);
    $rows = $statement->fetchAll();
    if ($rows === []) throw new InvalidArgumentException('The selected academic year could not be found.');
    $bands = [];
    foreach ($rows as $row) if ($row['grade_label'] !== null) $bands[] = ['label' => (string) $row['grade_label'], 'min' => (float) $row['min_percent']];
    $settings = db()->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('rounding_rule','marks_decimals','percent_decimals')")->fetchAll();
    $values = array_column($settings, 'setting_value', 'setting_key');
    $rounding = (string) ($values['rounding_rule'] ?? 'nearest');
    if (!in_array($rounding, ['nearest', 'none', 'down'], true)) $rounding = 'nearest';
    return [
        'scale_id' => (int) $rows[0]['grading_scale_id'], 'scale_name' => (string) $rows[0]['scale_name'],
        'bands' => $bands, 'rounding' => $rounding,
        'marks_decimals' => max(0, min(2, (int) ($values['marks_decimals'] ?? 2))),
        'percent_decimals' => max(0, min(2, (int) ($values['percent_decimals'] ?? 0))),
    ];
}

// Split student identifiers into bounded lists for safe set-based queries.
function calc_id_chunks(array $ids): array
{
    return array_chunk(array_values(array_unique(array_map('intval', $ids))), 500);
}

// Load all result inputs for one class with set-based queries and a small request cache.
function load_calc_data(int $yearId, int $classId, array $studentIds): array
{
    static $cache = [];
    $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
    sort($studentIds);
    $cacheKey = md5(serialize([$yearId, $classId, $studentIds]));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $grading = year_grading($yearId);
    $subjects = [];
    $query = db()->prepare(
        'SELECT cs.subject_id, s.name, COALESCE(cs.type_override, s.type) AS effective_type,
                cs.counts_toward_total, cs.sort_order
         FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id
         WHERE cs.academic_year_id = :year AND cs.class_id = :class AND cs.is_active = 1
         ORDER BY cs.sort_order, s.sort_order, s.name'
    );
    $query->execute([':year' => $yearId, ':class' => $classId]);
    foreach ($query->fetchAll() as $row) {
        $subjects[(int) $row['subject_id']] = ['name' => (string) $row['name'], 'kind' => $row['effective_type'] === 'grade_only' ? 'grade' : 'marks', 'counts' => (bool) $row['counts_toward_total']];
    }
    $query = db()->prepare('SELECT * FROM assessments WHERE academic_year_id = :year AND class_id = :class ORDER BY sort_order, id');
    $query->execute([':year' => $yearId, ':class' => $classId]);
    $examRows = $query->fetchAll();
    $examIds = array_map(static fn(array $row): int => (int) $row['id'], $examRows);
    $exams = [];
    $codeById = [];
    foreach ($examRows as $row) {
        $code = (string) $row['code'];
        $codeById[(int) $row['id']] = $code;
        $exams[$code] = ['id' => (int) $row['id'], 'code' => $code, 'name' => (string) $row['name'], 'mode' => (string) $row['mode'], 'calc_method' => $row['calc_method'], 'components' => [], 'sources' => [], 'term_group' => (string) $row['term_group'], 'has_attendance' => (bool) $row['has_attendance'], 'is_term_result' => (bool) $row['is_term_result']];
    }
    $in = $examIds === [] ? '' : implode(',', array_fill(0, count($examIds), '?'));
    if ($examIds !== []) {
        $query = db()->prepare('SELECT ac.assessment_id, c.name FROM assessment_components ac JOIN components c ON c.id = ac.component_id WHERE ac.assessment_id IN (' . $in . ') ORDER BY c.sort_order, c.id');
        $query->execute($examIds);
        foreach ($query->fetchAll() as $row) $exams[$codeById[(int) $row['assessment_id']]]['components'][] = (string) $row['name'];
        $query = db()->prepare('SELECT src.assessment_id, source.code FROM assessment_sources src JOIN assessments source ON source.id = src.source_assessment_id WHERE src.assessment_id IN (' . $in . ') ORDER BY source.sort_order, source.id');
        $query->execute($examIds);
        foreach ($query->fetchAll() as $row) $exams[$codeById[(int) $row['assessment_id']]]['sources'][] = (string) $row['code'];
    }
    $max = [];
    if ($examIds !== []) {
        $query = db()->prepare('SELECT mm.subject_id, mm.assessment_id, c.name AS component_name, mm.max_value FROM max_marks mm JOIN assessments a ON a.id = mm.assessment_id JOIN components c ON c.id = mm.component_id WHERE a.academic_year_id = ? AND a.class_id = ? AND mm.assessment_id IN (' . $in . ')');
        $query->execute(array_merge([$yearId, $classId], $examIds));
        foreach ($query->fetchAll() as $row) {
            $code = $codeById[(int) $row['assessment_id']];
            $max[(int) $row['subject_id']][$code][(string) $row['component_name']] = (float) $row['max_value'];
        }
    }
    $marks = [];
    $grades = [];
    $attendance = [];
    if ($studentIds !== [] && $examIds !== []) {
        foreach (calc_id_chunks($studentIds) as $chunk) {
            $studentIn = implode(',', array_fill(0, count($chunk), '?'));
            $query = db()->prepare('SELECT m.student_id, m.subject_id, m.assessment_id, c.name AS component_name, m.value, m.status FROM marks m JOIN assessments a ON a.id = m.assessment_id JOIN components c ON c.id = m.component_id WHERE a.academic_year_id = ? AND a.class_id = ? AND m.assessment_id IN (' . $in . ') AND m.student_id IN (' . $studentIn . ')');
            $query->execute(array_merge([$yearId, $classId], $examIds, $chunk));
            foreach ($query->fetchAll() as $row) $marks[(int) $row['student_id']][(int) $row['subject_id']][$codeById[(int) $row['assessment_id']]][(string) $row['component_name']] = ['value' => $row['value'] === null ? null : (float) $row['value'], 'status' => (string) $row['status']];
            $query = db()->prepare('SELECT ge.student_id, ge.subject_id, ge.assessment_id, ge.grade FROM grade_entries ge JOIN assessments a ON a.id = ge.assessment_id WHERE a.academic_year_id = ? AND a.class_id = ? AND ge.assessment_id IN (' . $in . ') AND ge.student_id IN (' . $studentIn . ')');
            $query->execute(array_merge([$yearId, $classId], $examIds, $chunk));
            foreach ($query->fetchAll() as $row) $grades[(int) $row['student_id']][(int) $row['subject_id']][$codeById[(int) $row['assessment_id']]] = (string) $row['grade'];
            $query = db()->prepare('SELECT att.student_id, att.assessment_id, att.present, att.total_days FROM attendance att JOIN assessments a ON a.id = att.assessment_id WHERE a.academic_year_id = ? AND a.class_id = ? AND a.has_attendance = 1 AND att.assessment_id IN (' . $in . ') AND att.student_id IN (' . $studentIn . ')');
            $query->execute(array_merge([$yearId, $classId], $examIds, $chunk));
            foreach ($query->fetchAll() as $row) $attendance[(int) $row['student_id']][$codeById[(int) $row['assessment_id']]] = ['present' => (int) $row['present'], 'total_days' => (int) $row['total_days']];
        }
    }
    $data = $grading + ['exams' => $exams, 'subjects' => $subjects, 'max' => $max, 'marks' => $marks, 'grades' => $grades, 'attendance' => $attendance, 'student_ids' => $studentIds];
    if (count($cache) >= 5) array_shift($cache);
    return $cache[$cacheKey] = $data;
}

// Calculate results for a selected list of students with a small result cache.
function calc_for_students(int $yearId, int $classId, array $studentIds): array
{
    static $cache = [];
    $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
    sort($studentIds);
    $key = md5(serialize([$yearId, $classId, $studentIds]));
    if (isset($cache[$key])) return $cache[$key];
    $results = calc_all(load_calc_data($yearId, $classId, $studentIds));
    if (count($cache) >= 5) array_shift($cache);
    return $cache[$key] = $results;
}

// Calculate results for students enrolled in a section for the selected year.
function calc_for_section(int $yearId, int $sectionId, bool $onlyActive = true): array
{
    static $cache = [];
    $key = $yearId . ':' . $sectionId . ':' . (int) $onlyActive;
    if (isset($cache[$key])) return $cache[$key];
    $sql = 'SELECT sec.class_id, se.student_id FROM sections sec JOIN student_enrollments se ON se.section_id = sec.id AND se.academic_year_id = sec.academic_year_id JOIN students st ON st.id = se.student_id WHERE sec.id = :section AND sec.academic_year_id = :year';
    if ($onlyActive) $sql .= " AND st.status = 'active'";
    $sql .= ' ORDER BY st.id';
    $query = db()->prepare($sql);
    $query->execute([':section' => $sectionId, ':year' => $yearId]);
    $rows = $query->fetchAll();
    if ($rows === []) {
        $check = db()->prepare('SELECT class_id FROM sections WHERE id = :section AND academic_year_id = :year');
        $check->execute([':section' => $sectionId, ':year' => $yearId]);
        if ($check->fetchColumn() === false) throw new InvalidArgumentException('The selected section is not in this academic year.');
        return $cache[$key] = [];
    }
    $studentIds = array_map('intval', array_column($rows, 'student_id'));
    $results = calc_for_students($yearId, (int) $rows[0]['class_id'], $studentIds);
    if (count($cache) >= 5) array_shift($cache);
    return $cache[$key] = $results;
}
