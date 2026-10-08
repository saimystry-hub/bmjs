<?php

function student_status_counts(int $yearId): array
{
    $counts = ['active' => 0, 'sor' => 0, 'freeze' => 0, 'left' => 0, 'all' => 0];
    $statement = db()->prepare(
        'SELECT s.status, COUNT(*) AS total
         FROM student_enrollments e
         INNER JOIN students s ON s.id = e.student_id
         WHERE e.academic_year_id = :year_id
         GROUP BY s.status'
    );
    $statement->execute([':year_id' => $yearId]);
    foreach ($statement->fetchAll() as $row) {
        $status = (string) ($row['status'] ?? 'active');
        if (isset($counts[$status])) {
            $counts[$status] = (int) ($row['total'] ?? 0);
        }
    }
    $counts['all'] = $counts['active'] + $counts['sor'] + $counts['freeze'] + $counts['left'];
    return $counts;
}

function list_students(array $filters, int $page, int $perPage): array
{
    $yearId = isset($filters['year_id']) ? (int) $filters['year_id'] : ((active_year() !== null) ? (int) active_year()['id'] : 0);
    $search = trim((string) ($filters['q'] ?? ''));
    $classId = isset($filters['class_id']) ? (int) $filters['class_id'] : 0;
    $sectionId = isset($filters['section_id']) ? (int) $filters['section_id'] : 0;
    $status = in_array((string) ($filters['status'] ?? 'active'), ['active', 'sor', 'freeze', 'left', 'all'], true)
        ? (string) $filters['status']
        : 'active';
    $scope = in_array((string) ($filters['scope'] ?? 'year'), ['year', 'not_enrolled', 'all'], true)
        ? (string) $filters['scope']
        : 'year';

    $params = [];
    $where = ['1 = 1'];
    $joins = 'LEFT JOIN sections sec ON sec.id = s.section_id LEFT JOIN classes c ON c.id = sec.class_id';

    if ($yearId > 0) {
        $joins .= ' LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.academic_year_id = :year_id';
        $params[':year_id'] = $yearId;
    }

    if ($search !== '') {
        $where[] = '(LOWER(s.full_name) LIKE LOWER(:search) OR LOWER(s.bmjs_id) LIKE LOWER(:search))';
        $params[':search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    }

    if ($status !== 'all') {
        $where[] = 's.status = :status';
        $params[':status'] = $status;
    }

    if ($classId > 0) {
        $where[] = 'c.id = :class_id';
        $params[':class_id'] = $classId;
    }

    if ($sectionId > 0) {
        $where[] = 'sec.id = :section_id';
        $params[':section_id'] = $sectionId;
    }

    if ($scope === 'year' && $yearId > 0) {
        $where[] = 'e.student_id IS NOT NULL';
    }

    if ($scope === 'not_enrolled' && $yearId > 0) {
        $where[] = '(e.student_id IS NULL)';
    }

    $countQuery = 'SELECT COUNT(*) FROM students s ' . $joins . ' WHERE ' . implode(' AND ', $where);
    $countStatement = db()->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStatement->bindValue($key, $value);
    }
    $countStatement->execute();
    $total = (int) $countStatement->fetchColumn();

    $page = max(1, (int) $page);
    $perPage = max(1, (int) $perPage);
    $offset = ($page - 1) * $perPage;

    $listQuery = 'SELECT s.*, sec.name AS section_name, c.name AS class_name, c.sort_order
                  FROM students s ' . $joins . ' WHERE ' . implode(' AND ', $where) . '
                  ORDER BY c.sort_order, sec.name, s.full_name
                  LIMIT :limit OFFSET :offset';

    $statement = db()->prepare($listQuery);
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    return ['rows' => $statement->fetchAll(), 'total' => $total];
}

function get_student(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function get_student_enrollments(int $studentId): array
{
    $statement = db()->prepare(
        'SELECT e.academic_year_id, y.name AS year_name, sec.name AS section_name, c.name AS class_name
         FROM student_enrollments e
         LEFT JOIN academic_years y ON y.id = e.academic_year_id
         LEFT JOIN sections sec ON sec.id = e.section_id
         LEFT JOIN classes c ON c.id = sec.class_id
         WHERE e.student_id = :student_id
         ORDER BY e.academic_year_id DESC'
    );
    $statement->execute([':student_id' => $studentId]);
    return $statement->fetchAll();
}

function student_has_data(int $studentId, int $yearId): bool
{
    $statement = db()->prepare(
        'SELECT CASE WHEN EXISTS (
            SELECT 1 FROM marks m INNER JOIN assessments a ON a.id = m.assessment_id WHERE m.student_id = :student_id AND a.academic_year_id = :year_id
        ) OR EXISTS (
            SELECT 1 FROM grade_entries ge INNER JOIN assessments a ON a.id = ge.assessment_id WHERE ge.student_id = :student_id AND a.academic_year_id = :year_id
        ) OR EXISTS (
            SELECT 1 FROM comments c WHERE c.student_id = :student_id AND c.academic_year_id = :year_id
        ) OR EXISTS (
            SELECT 1 FROM attendance at INNER JOIN assessments a ON a.id = at.assessment_id WHERE at.student_id = :student_id AND a.academic_year_id = :year_id
        ) THEN 1 ELSE 0 END AS has_data'
    );
    $statement->execute([':student_id' => $studentId, ':year_id' => $yearId]);
    return (int) $statement->fetchColumn() === 1;
}

function create_student(array $data, int $yearId): int
{
    $bmjsId = trim((string) ($data['bmjs_id'] ?? ''));
    $fullName = trim((string) ($data['full_name'] ?? ''));
    $status = in_array((string) ($data['status'] ?? 'active'), ['active', 'sor', 'freeze', 'left'], true)
        ? (string) ($data['status'] ?? 'active')
        : 'active';
    $sectionId = isset($data['section_id']) ? (int) $data['section_id'] : 0;
    $dob = empty($data['dob']) ? null : (string) $data['dob'];

    if ($bmjsId === '' || $fullName === '' || $sectionId <= 0) {
        throw new InvalidArgumentException('A student needs a BMJS ID, name and section.');
    }

    $existing = db()->prepare('SELECT id FROM students WHERE bmjs_id = :bmjs_id LIMIT 1');
    $existing->execute([':bmjs_id' => $bmjsId]);
    if ($existing->fetch()) {
        throw new RuntimeException('Another student is already using that BMJS ID.');
    }

    db()->beginTransaction();
    try {
        $statement = db()->prepare('INSERT INTO students (bmjs_id, full_name, dob, section_id, status) VALUES (:bmjs_id, :full_name, :dob, :section_id, :status)');
        $statement->execute([
            ':bmjs_id' => $bmjsId,
            ':full_name' => $fullName,
            ':dob' => $dob,
            ':section_id' => $sectionId,
            ':status' => $status,
        ]);
        $studentId = (int) db()->lastInsertId();
        db()->prepare('INSERT INTO student_enrollments (student_id, academic_year_id, section_id) VALUES (:student_id, :year_id, :section_id)')->execute([
            ':student_id' => $studentId,
            ':year_id' => $yearId,
            ':section_id' => $sectionId,
        ]);
        log_action('student_created', 'students', $studentId, null, ['name' => $fullName, 'section_id' => $sectionId]);
        db()->commit();
        return $studentId;
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function update_student(int $id, array $data, int $yearId): void
{
    $old = get_student($id);
    if ($old === null) {
        throw new RuntimeException('The student could not be found.');
    }

    $newBmjsId = trim((string) ($data['bmjs_id'] ?? $old['bmjs_id']));
    $newFullName = trim((string) ($data['full_name'] ?? $old['full_name']));
    $newStatus = in_array((string) ($data['status'] ?? $old['status']), ['active', 'sor', 'freeze', 'left'], true)
        ? (string) ($data['status'] ?? $old['status'])
        : (string) ($old['status'] ?? 'active');
    $newSectionId = isset($data['section_id']) ? (int) $data['section_id'] : (int) ($old['section_id'] ?? 0);
    $newDob = empty($data['dob']) ? null : (string) $data['dob'];

    if ($newSectionId > 0 && (int) ($old['section_id'] ?? 0) !== $newSectionId) {
        $oldSection = (int) ($old['section_id'] ?? 0);
        $oldClassStatement = db()->prepare('SELECT class_id FROM sections WHERE id = :id LIMIT 1');
        $oldClassStatement->execute([':id' => $oldSection]);
        $oldClassId = $oldSection > 0 ? (int) $oldClassStatement->fetchColumn() : 0;
        $newClassStatement = db()->prepare('SELECT class_id FROM sections WHERE id = :id LIMIT 1');
        $newClassStatement->execute([':id' => $newSectionId]);
        $newClassId = (int) $newClassStatement->fetchColumn();
        if ($oldClassId !== $newClassId && student_has_data($id, $yearId)) {
            throw new RuntimeException('This student has marks or grades in this year, so they cannot move to another class.');
        }
    }

    db()->beginTransaction();
    try {
        $statement = db()->prepare('UPDATE students SET bmjs_id = :bmjs_id, full_name = :full_name, dob = :dob, section_id = :section_id, status = :status WHERE id = :id');
        $statement->execute([
            ':bmjs_id' => $newBmjsId,
            ':full_name' => $newFullName,
            ':dob' => $newDob,
            ':section_id' => $newSectionId,
            ':status' => $newStatus,
            ':id' => $id,
        ]);

        $upsert = db()->prepare(
            'INSERT INTO student_enrollments (student_id, academic_year_id, section_id)
             VALUES (:student_id, :year_id, :section_id)
             ON DUPLICATE KEY UPDATE section_id = VALUES(section_id)'
        );
        $upsert->execute([
            ':student_id' => $id,
            ':year_id' => $yearId,
            ':section_id' => $newSectionId,
        ]);

        log_action('student_updated', 'students', $id, [
            'bmjs_id' => $old['bmjs_id'],
            'full_name' => $old['full_name'],
            'status' => $old['status'],
            'section_id' => $old['section_id'],
        ], [
            'bmjs_id' => $newBmjsId,
            'full_name' => $newFullName,
            'status' => $newStatus,
            'section_id' => $newSectionId,
        ]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function student_import_lookups(int $yearId): array
{
    $classes = [];
    foreach (db()->query('SELECT id, name FROM classes ORDER BY sort_order, id')->fetchAll() as $row) {
        $classes[(int) $row['id']] = (string) $row['name'];
    }

    $sections = [];
    $sectionStatement = db()->prepare('SELECT class_id, id, LOWER(name) AS section_name FROM sections WHERE academic_year_id = :year_id');
    $sectionStatement->execute([':year_id' => $yearId]);
    foreach ($sectionStatement->fetchAll() as $row) {
        $sections[(int) $row['class_id']][strtolower((string) $row['section_name'])] = (int) $row['id'];
    }

    $existing = [];
    $statement = db()->prepare(
        'SELECT s.id, LOWER(s.bmjs_id) AS bmjs_key, s.full_name, s.dob, s.status, s.section_id,
                sec.class_id,
                CASE WHEN EXISTS (SELECT 1 FROM marks m INNER JOIN assessments a ON a.id = m.assessment_id WHERE m.student_id = s.id AND a.academic_year_id = :year_id)
                     OR EXISTS (SELECT 1 FROM grade_entries ge INNER JOIN assessments a ON a.id = ge.assessment_id WHERE ge.student_id = s.id AND a.academic_year_id = :year_id)
                     OR EXISTS (SELECT 1 FROM comments c WHERE c.student_id = s.id AND c.academic_year_id = :year_id)
                     OR EXISTS (SELECT 1 FROM attendance at INNER JOIN assessments a ON a.id = at.assessment_id WHERE at.student_id = s.id AND a.academic_year_id = :year_id)
                     THEN 1 ELSE 0 END AS has_data,
                e.section_id AS current_year_section_id
         FROM students s
         LEFT JOIN sections sec ON sec.id = s.section_id
         LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.academic_year_id = :year_id'
    );
    $statement->execute([':year_id' => $yearId]);
    foreach ($statement->fetchAll() as $row) {
        $bmjsKey = strtolower((string) ($row['bmjs_key'] ?? ''));
        if ($bmjsKey === '') {
            continue;
        }
        $existing[$bmjsKey] = [
            'id' => (int) $row['id'],
            'full_name' => (string) ($row['full_name'] ?? ''),
            'dob' => $row['dob'] ?? null,
            'status' => (string) ($row['status'] ?? 'active'),
            'class_id' => isset($row['class_id']) && $row['class_id'] !== null ? (int) $row['class_id'] : null,
            'section_id' => isset($row['current_year_section_id']) && $row['current_year_section_id'] !== null
                ? (int) $row['current_year_section_id']
                : ((isset($row['section_id']) && $row['section_id'] !== null) ? (int) $row['section_id'] : null),
            'has_data' => (int) ($row['has_data'] ?? 0),
        ];
    }

    return [
        'classes' => $classes,
        'sections' => $sections,
        'existing' => $existing,
        'id_pattern' => setting('student_id_pattern', '/^(?=.*\d)[A-Za-z0-9-]{3,30}$/'),
    ];
}

function run_import(array $plan, array $options, int $yearId, int $userId): array
{
    $counts = ['new' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'warning' => 0];
    $options = array_merge(['create_missing' => false, 'update_existing' => false, 'allow_move' => false], $options);

    db()->beginTransaction();
    try {
        foreach ($plan['rows'] ?? [] as $row) {
            $status = $row['status'] ?? 'skip';
            if (!in_array($status, ['new', 'update'], true)) {
                continue;
            }

            $sectionId = $row['section_id'] ?? null;
            if ($sectionId === null || $sectionId === 'to be created') {
                continue;
            }

            if ($status === 'new') {
                create_student([
                    'bmjs_id' => (string) ($row['bmjs_id'] ?? ''),
                    'full_name' => (string) ($row['full_name'] ?? ''),
                    'dob' => (string) ($row['dob'] ?? ''),
                    'status' => (string) ($row['status_value'] ?? 'active'),
                    'section_id' => (int) $sectionId,
                ], $yearId);
                $counts['new']++;
            } else {
                $studentId = (int) ($row['student_id'] ?? 0);
                if ($studentId > 0) {
                    update_student($studentId, [
                        'bmjs_id' => (string) ($row['bmjs_id'] ?? ''),
                        'full_name' => (string) ($row['full_name'] ?? ''),
                        'dob' => (string) ($row['dob'] ?? ''),
                        'status' => (string) ($row['status_value'] ?? 'active'),
                        'section_id' => (int) $sectionId,
                    ], $yearId);
                    $counts['update']++;
                }
            }
        }

        log_action('students_imported', 'students', null, null, ['counts' => $counts, 'filename' => $options['filename'] ?? 'import.csv', 'user_id' => $userId, 'options' => $options]);
        db()->commit();
        return $counts;
    } catch (Throwable $exception) {
        db()->rollBack();
        throw new RuntimeException('Import failed. No changes were saved.', 0, $exception);
    }
}
