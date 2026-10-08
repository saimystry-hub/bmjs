<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.' . PHP_EOL);
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/db.php';

// Stop with one clear message when the demo setup is incomplete.
function seed_stop(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// Find the active year, Class I, its first four enrolled students and three checkpoint exams.
function seed_context(): array
{
    $yearId = (int) db()->query('SELECT id FROM academic_years WHERE is_active = 1 LIMIT 1')->fetchColumn();
    if ($yearId <= 0) seed_stop('No active academic year was found.');
    $classQuery = db()->prepare('SELECT id FROM classes WHERE name = :name LIMIT 1');
    $classQuery->execute([':name' => 'I']);
    $classId = (int) $classQuery->fetchColumn();
    if ($classId <= 0) seed_stop('Class I was not found.');
    $students = db()->prepare('SELECT st.id, st.full_name FROM student_enrollments se JOIN students st ON st.id = se.student_id JOIN sections sec ON sec.id = se.section_id WHERE se.academic_year_id = :year AND sec.class_id = :class ORDER BY st.id LIMIT 4');
    $students->execute([':year' => $yearId, ':class' => $classId]);
    $studentRows = $students->fetchAll();
    if (count($studentRows) < 2) seed_stop('Class I needs at least two students enrolled in the active year.');
    $exams = db()->prepare("SELECT id, code FROM assessments WHERE academic_year_id = :year AND class_id = :class AND code IN (:cp1, :cp2, :cp3) AND mode = 'entered'");
    $exams->execute([':year' => $yearId, ':class' => $classId, ':cp1' => 'CP1', ':cp2' => 'CP2', ':cp3' => 'CP3']);
    $examRows = $exams->fetchAll();
    $examIds = [];
    foreach ($examRows as $exam) $examIds[(string) $exam['code']] = (int) $exam['id'];
    if (count($examIds) !== 3) seed_stop('Class I needs entered exams CP1, CP2 and CP3 from Preset A.');
    return ['year_id' => $yearId, 'class_id' => $classId, 'students' => $studentRows, 'exam_ids' => $examIds];
}

// Write a count-only audit record as part of the current transaction.
function seed_audit(string $action, array $counts): void
{
    $query = db()->prepare('INSERT INTO audit_log (user_id, action, table_name, record_id, old_value, new_value, ip) VALUES (NULL, :action, :table_name, NULL, NULL, :new_value, NULL)');
    $query->execute([':action' => $action, ':table_name' => 'marks', ':new_value' => json_encode($counts, JSON_THROW_ON_ERROR)]);
}

// Remove only unowned rows for this tool's first four students and CP1-CP3.
function seed_clear(array $context): void
{
    $studentIds = array_map(static fn(array $row): int => (int) $row['id'], $context['students']);
    $examIds = array_values($context['exam_ids']);
    db()->beginTransaction();
    try {
        $studentIn = implode(',', array_fill(0, count($studentIds), '?'));
        $examIn = implode(',', array_fill(0, count($examIds), '?'));
        $marks = db()->prepare('DELETE FROM marks WHERE entered_by IS NULL AND student_id IN (' . $studentIn . ') AND assessment_id IN (' . $examIn . ')');
        $marks->execute(array_merge($studentIds, $examIds));
        $marksCount = $marks->rowCount();
        $attendance = db()->prepare('DELETE FROM attendance WHERE updated_by IS NULL AND student_id IN (' . $studentIn . ') AND assessment_id IN (' . $examIn . ')');
        $attendance->execute(array_merge($studentIds, $examIds));
        $attendanceCount = $attendance->rowCount();
        seed_audit('test_marks_cleared', ['marks' => $marksCount, 'attendance' => $attendanceCount]);
        db()->commit();
        echo 'Removed ' . $marksCount . ' test mark rows and ' . $attendanceCount . ' attendance rows.' . PHP_EOL;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Clear test marks failed: ' . $exception->getMessage());
        seed_stop('The test marks could not be cleared.');
    }
}

// Validate the preset columns, eight marks subjects, and every required maximum.
function seed_load_maximums(array $context): array
{
    $examIds = $context['exam_ids'];
    $ids = array_values($examIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $columnsQuery = db()->prepare('SELECT ac.assessment_id, c.name FROM assessment_components ac JOIN components c ON c.id = ac.component_id WHERE ac.assessment_id IN (' . $in . ') ORDER BY c.sort_order, c.id');
    $columnsQuery->execute($ids);
    $columns = [];
    foreach ($columnsQuery->fetchAll() as $row) $columns[(int) $row['assessment_id']][] = (string) $row['name'];
    foreach ($examIds as $code => $id) {
        $names = $columns[$id] ?? [];
        if (count($names) !== 2 || array_diff(['F.A', 'S.A'], $names) !== []) seed_stop($code . ' must have F.A and S.A marks columns.');
    }
    $subjectsQuery = db()->prepare("SELECT cs.subject_id, s.name FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id WHERE cs.academic_year_id = :year AND cs.class_id = :class AND cs.is_active = 1 AND s.is_active = 1 AND COALESCE(cs.type_override, s.type) = 'graded' ORDER BY COALESCE(cs.sort_order, s.sort_order), s.sort_order, s.id");
    $subjectsQuery->execute([':year' => $context['year_id'], ':class' => $context['class_id']]);
    $subjects = $subjectsQuery->fetchAll();
    $expectedNames = ['English Literature', 'English Language', 'Urdu Adab', 'Urdu B', 'Mathematics', 'Science', 'Social Studies', 'Islamiat'];
    if (array_column($subjects, 'name') !== $expectedNames) seed_stop('Class I must have the eight marks subjects in the seeded order: ' . implode(', ', $expectedNames) . '.');
    $query = db()->prepare('SELECT subject_id, assessment_id, c.name AS component_name, max_value FROM max_marks mm JOIN components c ON c.id = mm.component_id WHERE mm.class_id = ? AND mm.assessment_id IN (' . $in . ')');
    $query->execute(array_merge([$context['class_id']], $ids));
    $maximumRows = $query->fetchAll();
    $bySubjectExam = [];
    $codeById = array_flip($examIds);
    foreach ($maximumRows as $row) $bySubjectExam[(int) $row['subject_id']][$codeById[(int) $row['assessment_id']]][(string) $row['component_name']] = (float) $row['max_value'];
    foreach ($subjects as $subject) {
        foreach ($examIds as $code => $id) {
            if (!isset($bySubjectExam[(int) $subject['subject_id']][$code]['F.A'], $bySubjectExam[(int) $subject['subject_id']][$code]['S.A']) || $bySubjectExam[(int) $subject['subject_id']][$code]['F.A'] + $bySubjectExam[(int) $subject['subject_id']][$code]['S.A'] <= 0) {
                seed_stop($subject['name'] . ' needs F.A and S.A maximum marks for ' . $code . '.');
            }
        }
    }
    return ['subjects' => $subjects, 'maximums' => $bySubjectExam];
}

// Insert the example marks and attendance in one transaction, preserving cents.
function seed_write(array $context, array $setup): void
{
    $students = $context['students'];
    $subjects = $setup['subjects'];
    $mathId = (int) $subjects[4]['subject_id'];
    $englishId = (int) $subjects[0]['subject_id'];
    $scienceId = (int) $subjects[5]['subject_id'];
    $targets = [
        0 => ['CP1' => [25, 23.5, 21.25, 22, 25, 24.75, 23.5, 21.25], 'CP2' => [4 => 22], 'CP3' => [4 => 25]],
        1 => ['CP1' => [4 => 25], 'CP3' => [4 => 25]],
    ];
    $markRows = [];
    $attendanceRows = [];
    foreach ($targets as $studentIndex => $studentTargets) {
        if (!isset($students[$studentIndex])) continue;
        $studentId = (int) $students[$studentIndex]['id'];
        foreach ($studentTargets as $code => $subjectTotals) {
            foreach ($subjectTotals as $subjectIndex => $total) {
                $subjectId = (int) $subjects[$subjectIndex]['subject_id'];
                $maxFA = $setup['maximums'][$subjectId][$code]['F.A'];
                $maxSA = $setup['maximums'][$subjectId][$code]['S.A'];
                if ($total > $maxFA + $maxSA) {
                    fwrite(STDERR, 'Skipped ' . $students[$studentIndex]['full_name'] . ' ' . $subjects[$subjectIndex]['name'] . ' ' . $code . ': mark is above the maximum.' . PHP_EOL);
                    continue;
                }
                $fa = round($total * $maxFA / ($maxFA + $maxSA), 2, PHP_ROUND_HALF_UP);
                $sa = round($total - $fa, 2, PHP_ROUND_HALF_UP);
                $markRows[] = [$studentId, $subjectId, $context['exam_ids'][$code], 'F.A', $fa, 'entered'];
                $markRows[] = [$studentId, $subjectId, $context['exam_ids'][$code], 'S.A', $sa, 'entered'];
            }
        }
    }
    if (isset($students[0])) foreach (['CP1' => [23, 25], 'CP2' => [24, 25], 'CP3' => [25, 25]] as $code => $days) $attendanceRows[] = [(int) $students[0]['id'], $context['exam_ids'][$code], $days[0], $days[1]];
    if (isset($students[1])) foreach (['CP1' => [23, 25], 'CP3' => [25, 25]] as $code => $days) $attendanceRows[] = [(int) $students[1]['id'], $context['exam_ids'][$code], $days[0], $days[1]];
    if (isset($students[2])) foreach (['F.A', 'S.A'] as $column) $markRows[] = [(int) $students[2]['id'], $englishId, $context['exam_ids']['CP1'], $column, null, 'absent'];
    if (isset($students[3])) foreach (['F.A', 'S.A'] as $column) $markRows[] = [(int) $students[3]['id'], $scienceId, $context['exam_ids']['CP1'], $column, null, 'exempt'];
    db()->beginTransaction();
    try {
        $components = db()->query('SELECT id, name FROM components')->fetchAll();
        $componentIds = array_column($components, 'id', 'name');
        $markInsert = db()->prepare('INSERT INTO marks (student_id, subject_id, assessment_id, component_id, value, status, entered_by) VALUES (?, ?, ?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE value = VALUES(value), status = VALUES(status), entered_by = NULL');
        foreach ($markRows as [$studentId, $subjectId, $examId, $column, $value, $status]) {
            if (empty($componentIds[$column])) throw new RuntimeException('Marks column ' . $column . ' is missing.');
            $markInsert->execute([$studentId, $subjectId, $examId, (int) $componentIds[$column], $value, $status]);
        }
        $attendanceInsert = db()->prepare('INSERT INTO attendance (student_id, assessment_id, present, total_days, updated_by) VALUES (?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE present = VALUES(present), total_days = VALUES(total_days), updated_by = NULL');
        foreach ($attendanceRows as $row) $attendanceInsert->execute($row);
        seed_audit('test_marks_seeded', ['marks' => count($markRows), 'attendance' => count($attendanceRows)]);
        db()->commit();
        echo 'Created or updated ' . count($markRows) . ' mark rows and ' . count($attendanceRows) . ' attendance rows for ' . count($students) . ' Class I students.' . PHP_EOL;
        foreach ($students as $student) echo '- ' . $student['full_name'] . PHP_EOL;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Seed test marks failed: ' . $exception->getMessage());
        seed_stop('The sample marks could not be saved.');
    }
}

$arguments = array_slice($argv, 1);
if (array_diff($arguments, ['--clear', '--force']) !== []) seed_stop('Use no option, --force, or --clear.');
try {
    if (in_array('--clear', $arguments, true)) {
        $context = seed_context();
        seed_clear($context);
        exit(0);
    }
    $existing = (int) db()->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    if ($existing > 0 && !in_array('--force', $arguments, true)) seed_stop('Marks already exist. Use --force only if you want to add or replace the sample rows.');
    $context = seed_context();
    $setup = seed_load_maximums($context);
    seed_write($context, $setup);
} catch (Throwable $exception) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Seed test marks failed: ' . $exception->getMessage());
    seed_stop('The sample marks could not be prepared. Check the active year, Class I and exam setup.');
}
