<?php

// Return active or all teacher accounts for assignment pickers and coverage views.
function get_teachers(bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM users WHERE role = :role';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY full_name, id';

    $statement = db()->prepare($sql);
    $statement->execute([':role' => 'teacher']);
    return $statement->fetchAll();
}

// Read the current teacher assignment map for one section.
function get_section_assignments(int $sectionId): array
{
    $statement = db()->prepare('SELECT subject_id, user_id FROM teacher_assignments WHERE section_id = :section_id');
    $statement->execute([':section_id' => $sectionId]);

    $map = [];
    foreach ($statement->fetchAll() as $row) {
        $map[(int) $row['subject_id']] = (int) $row['user_id'];
    }

    return $map;
}

// Compare the current and desired assignment map and split changes into add/change/clear actions.
function diff_assignments(array $current, array $desired): array
{
    $result = ['add' => [], 'change' => [], 'clear' => [], 'keep' => []];
    $normalizedCurrent = [];
    foreach ($current as $subjectId => $teacherId) {
        $normalizedCurrent[(int) $subjectId] = $teacherId === null || $teacherId === 0 ? null : (int) $teacherId;
    }

    $normalizedDesired = [];
    foreach ($desired as $subjectId => $teacherId) {
        $normalizedDesired[(int) $subjectId] = $teacherId === null || $teacherId === 0 ? null : (int) $teacherId;
    }

    foreach ($normalizedDesired as $subjectId => $teacherId) {
        $currentTeacher = $normalizedCurrent[$subjectId] ?? null;
        if ($currentTeacher === null && $teacherId !== null) {
            $result['add'][$subjectId] = $teacherId;
            continue;
        }
        if ($currentTeacher !== null && $teacherId === null) {
            $result['clear'][] = $subjectId;
            continue;
        }
        if ($currentTeacher !== null && $teacherId !== null && $currentTeacher !== $teacherId) {
            $result['change'][$subjectId] = [$currentTeacher, $teacherId];
            continue;
        }
        if ($currentTeacher === $teacherId) {
            $result['keep'][] = $subjectId;
        }
    }

    foreach ($normalizedCurrent as $subjectId => $teacherId) {
        if (!array_key_exists($subjectId, $normalizedDesired) && $teacherId !== null) {
            $result['clear'][] = $subjectId;
        }
    }

    sort($result['clear']);
    sort($result['keep']);
    ksort($result['add']);
    ksort($result['change']);

    return $result;
}

// Save a complete assignment set for one section while enforcing the allowed class subjects and teacher list.
function save_section_assignments(int $sectionId, array $desired, array $allowedSubjectIds, array $allowedTeacherIds): array
{
    $allowedSubjects = array_fill_keys(array_map('intval', $allowedSubjectIds), true);
    $allowedTeachers = array_fill_keys(array_map('intval', $allowedTeacherIds), true);
    $current = get_section_assignments($sectionId);

    $filtered = [];
    foreach ($desired as $subjectId => $teacherId) {
        $subjectKey = (int) $subjectId;
        $teacherKey = $teacherId === null || $teacherId === 0 ? null : (int) $teacherId;
        if (!isset($allowedSubjects[$subjectKey])) {
            continue;
        }
        if ($teacherKey !== null && !isset($allowedTeachers[$teacherKey])) {
            $teacherKey = null;
        }
        $filtered[$subjectKey] = $teacherKey;
    }

    $diff = diff_assignments($current, $filtered);

    db()->beginTransaction();
    try {
        foreach ($diff['add'] as $subjectId => $teacherId) {
            db()->prepare('INSERT INTO teacher_assignments (user_id, section_id, subject_id) VALUES (:user_id, :section_id, :subject_id)')->execute([
                ':user_id' => $teacherId,
                ':section_id' => $sectionId,
                ':subject_id' => $subjectId,
            ]);
        }

        foreach ($diff['change'] as $subjectId => $pair) {
            db()->prepare('UPDATE teacher_assignments SET user_id = :user_id WHERE section_id = :section_id AND subject_id = :subject_id')->execute([
                ':user_id' => $pair[1],
                ':section_id' => $sectionId,
                ':subject_id' => $subjectId,
            ]);
        }

        foreach ($diff['clear'] as $subjectId) {
            db()->prepare('DELETE FROM teacher_assignments WHERE section_id = :section_id AND subject_id = :subject_id')->execute([
                ':section_id' => $sectionId,
                ':subject_id' => $subjectId,
            ]);
        }

        $counts = [
            'add' => count($diff['add']),
            'change' => count($diff['change']),
            'clear' => count($diff['clear']),
            'keep' => count($diff['keep']),
        ];

        log_action('assignments_saved', 'teacher_assignments', $sectionId, null, [
            'section_id' => $sectionId,
            'counts' => $counts,
            'changes' => $diff,
        ]);
        db()->commit();
        return $counts;
    } catch (Throwable $exception) {
        db()->rollBack();
        error_log('save_section_assignments failed: ' . $exception->getMessage());
        return ['add' => 0, 'change' => 0, 'clear' => 0, 'keep' => 0];
    }
}

// Build a section-by-subject coverage map for an academic year and class.
function assignment_coverage(int $yearId, int $classId): array
{
    $statement = db()->prepare(
        'SELECT sec.id AS section_id, cs.subject_id, ta.user_id AS teacher_id
         FROM sections sec
         INNER JOIN class_subjects cs ON cs.class_id = sec.class_id AND cs.academic_year_id = sec.academic_year_id AND cs.is_active = 1
         LEFT JOIN teacher_assignments ta ON ta.section_id = sec.id AND ta.subject_id = cs.subject_id
         WHERE sec.academic_year_id = :year_id AND sec.class_id = :class_id
         ORDER BY sec.id, cs.subject_id'
    );
    $statement->execute([':year_id' => $yearId, ':class_id' => $classId]);

    $coverage = [];
    foreach ($statement->fetchAll() as $row) {
        $sectionId = (int) $row['section_id'];
        $subjectId = (int) $row['subject_id'];
        $teacherId = $row['teacher_id'] === null ? null : (int) $row['teacher_id'];

        if (!isset($coverage[$sectionId])) {
            $coverage[$sectionId] = [];
        }
        $coverage[$sectionId][$subjectId] = $teacherId;
    }

    return $coverage;
}

// Gather the assignments for one teacher in the selected year.
function teacher_assignments_for_user(int $userId, int $yearId): array
{
    $statement = db()->prepare(
        'SELECT c.name AS class_name, s.name AS section_name, sub.name AS subject_name
         FROM teacher_assignments ta
         INNER JOIN sections s ON s.id = ta.section_id
         INNER JOIN classes c ON c.id = s.class_id
         INNER JOIN subjects sub ON sub.id = ta.subject_id
         WHERE ta.user_id = :user_id AND s.academic_year_id = :year_id
         ORDER BY c.sort_order, s.name, sub.name'
    );
    $statement->execute([':user_id' => $userId, ':year_id' => $yearId]);
    return $statement->fetchAll();
}

// Return the validation messages that block a user update because of role, status or ownership rules.
function user_change_blockers(array $target, array $changes, int $actingUserId, int $activeSuperAdminCount, int $assignmentCount, int $hrtSectionCount): array
{
    $messages = [];
    $currentRole = (string) ($target['role'] ?? 'teacher');
    $nextRole = (string) (($changes['role'] ?? $currentRole));
    $nextActive = array_key_exists('is_active', $changes) ? (int) $changes['is_active'] : (int) ($target['is_active'] ?? 1);

    if ((int) ($target['id'] ?? 0) === $actingUserId && array_key_exists('is_active', $changes) && (int) $changes['is_active'] === 0) {
        $messages[] = 'You cannot disable your own account.';
    }

    if ((int) ($target['id'] ?? 0) === $actingUserId && array_key_exists('role', $changes) && $nextRole !== $currentRole) {
        $messages[] = 'You cannot change your own role.';
    }

    $targetIsSuperAdmin = $currentRole === 'super_admin';
    $nextIsSuperAdmin = $nextRole === 'super_admin';
    if (($targetIsSuperAdmin || $nextIsSuperAdmin) && ($nextRole !== 'super_admin' || $nextActive !== 1) && $activeSuperAdminCount <= 1) {
        $messages[] = 'There must always be at least one active super admin.';
    }

    if ($currentRole === 'teacher' && $nextRole !== 'teacher' && ($assignmentCount > 0 || $hrtSectionCount > 0)) {
        $messages[] = 'Move or remove their assignments first.';
    }

    return $messages;
}
