<?php

function effective_type(array $row): string
{
    $override = $row['type_override'] ?? null;
    if ($override !== null && $override !== '') {
        return (string) $override;
    }

    return (string) ($row['subject_type'] ?? $row['type'] ?? 'graded');
}

function get_class_subjects(int $yearId, int $classId, bool $onlyActive = true): array
{
    $sql = 'SELECT cs.*, s.name AS subject_name, s.short_name, s.type AS subject_type, s.counts_toward_total AS subject_counts_toward_total,
                   s.show_on_report AS subject_show_on_report, s.sort_order AS subject_sort_order, s.is_active AS subject_is_active
            FROM class_subjects cs
            INNER JOIN subjects s ON s.id = cs.subject_id
            WHERE cs.academic_year_id = :year_id AND cs.class_id = :class_id';
    if ($onlyActive) {
        $sql .= ' AND cs.is_active = 1';
    }
    $sql .= ' ORDER BY COALESCE(cs.sort_order, s.sort_order), s.sort_order, s.name';

    $statement = db()->prepare($sql);
    $statement->execute([':year_id' => $yearId, ':class_id' => $classId]);
    return $statement->fetchAll();
}

function diff_class_subjects(array $currentIdsWithActive, array $desiredIds): array
{
    $current = [];
    foreach ($currentIdsWithActive as $subjectId => $isActive) {
        $current[(int) $subjectId] = (bool) $isActive;
    }

    $desired = [];
    foreach ($desiredIds as $subjectId) {
        $desired[(int) $subjectId] = true;
    }

    $result = ['add' => [], 'reenable' => [], 'remove' => [], 'keep' => []];

    foreach ($desired as $subjectId => $_) {
        if (!isset($current[$subjectId])) {
            $result['add'][] = $subjectId;
            continue;
        }

        if ($current[$subjectId] === false) {
            $result['reenable'][] = $subjectId;
            continue;
        }

        $result['keep'][] = $subjectId;
    }

    foreach ($current as $subjectId => $isActive) {
        if (!isset($desired[$subjectId])) {
            if ($isActive) {
                $result['remove'][] = $subjectId;
            }
        }
    }

    sort($result['add']);
    sort($result['reenable']);
    sort($result['remove']);
    sort($result['keep']);
    return $result;
}

function class_subject_has_data(int $yearId, int $classId, int $subjectId): bool
{
    $sql = "SELECT 1 FROM (
        SELECT m.id FROM marks m
        INNER JOIN assessments a ON a.id = m.assessment_id
        WHERE a.academic_year_id = :year_id AND a.class_id = :class_id AND m.subject_id = :subject_id
        LIMIT 1
    ) AS marks_check
    UNION ALL
    SELECT 1 FROM (
        SELECT ge.id FROM grade_entries ge
        INNER JOIN assessments a ON a.id = ge.assessment_id
        WHERE a.academic_year_id = :year_id AND a.class_id = :class_id AND ge.subject_id = :subject_id
        LIMIT 1
    ) AS grade_check
    UNION ALL
    SELECT 1 FROM (
        SELECT c.id FROM comments c
        INNER JOIN student_enrollments se ON se.student_id = c.student_id AND se.academic_year_id = c.academic_year_id
        INNER JOIN sections s ON s.id = se.section_id AND s.class_id = :class_id AND s.academic_year_id = :year_id
        WHERE c.subject_id = :subject_id AND c.academic_year_id = :year_id
        LIMIT 1
    ) AS comment_check";

    $statement = db()->prepare($sql);
    $statement->execute([
        ':year_id' => $yearId,
        ':class_id' => $classId,
        ':subject_id' => $subjectId,
    ]);
    return $statement->fetchColumn() !== false;
}

function class_subject_has_assignments(int $yearId, int $classId, int $subjectId): bool
{
    $statement = db()->prepare(
        'SELECT 1
         FROM teacher_assignments ta
         INNER JOIN sections s ON s.id = ta.section_id
         WHERE s.academic_year_id = :year_id AND s.class_id = :class_id AND ta.subject_id = :subject_id
         LIMIT 1'
    );
    $statement->execute([
        ':year_id' => $yearId,
        ':class_id' => $classId,
        ':subject_id' => $subjectId,
    ]);
    return $statement->fetchColumn() !== false;
}

function add_subjects_to_classes(int $yearId, array $classIds, array $subjectIds): int
{
    $count = 0;
    $classIds = array_values(array_unique(array_map('intval', $classIds)));
    $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));

    foreach ($classIds as $classId) {
        foreach ($subjectIds as $subjectId) {
            $subject = db()->prepare('SELECT * FROM subjects WHERE id = :id LIMIT 1');
            $subject->execute([':id' => $subjectId]);
            $row = $subject->fetch();
            if (!$row) {
                continue;
            }

            $existing = db()->prepare('SELECT * FROM class_subjects WHERE academic_year_id = :year_id AND class_id = :class_id AND subject_id = :subject_id LIMIT 1');
            $existing->execute([':year_id' => $yearId, ':class_id' => $classId, ':subject_id' => $subjectId]);
            $item = $existing->fetch();

            if ($item) {
                if ((int) $item['is_active'] === 0) {
                    db()->prepare('UPDATE class_subjects SET is_active = 1, counts_toward_total = :counts, show_on_report = :show, sort_order = :sort_order WHERE academic_year_id = :year_id AND class_id = :class_id AND subject_id = :subject_id')
                        ->execute([
                            ':counts' => ((string) ($item['counts_toward_total'] ?? 1) === '0' ? 0 : 1),
                            ':show' => ((string) ($item['show_on_report'] ?? 1) === '0' ? 0 : 1),
                            ':sort_order' => (int) ($item['sort_order'] ?? 0),
                            ':year_id' => $yearId,
                            ':class_id' => $classId,
                            ':subject_id' => $subjectId,
                        ]);
                    $count++;
                }
                continue;
            }

            $effectiveType = (string) ($row['type'] ?? 'graded');
            $countsToward = (int) $row['counts_toward_total'];
            if ($effectiveType === 'grade_only') {
                $countsToward = 0;
            }

            db()->prepare('INSERT INTO class_subjects (academic_year_id, class_id, subject_id, type_override, counts_toward_total, show_on_report, sort_order, is_active) VALUES (:year_id, :class_id, :subject_id, NULL, :counts_toward_total, :show_on_report, :sort_order, 1)')
                ->execute([
                    ':year_id' => $yearId,
                    ':class_id' => $classId,
                    ':subject_id' => $subjectId,
                    ':counts_toward_total' => $countsToward,
                    ':show_on_report' => (int) $row['show_on_report'],
                    ':sort_order' => (int) $row['sort_order'],
                ]);
            $count++;
        }
    }

    return $count;
}

function remove_subject_from_class(int $yearId, int $classId, int $subjectId): string
{
    if (class_subject_has_data($yearId, $classId, $subjectId) || class_subject_has_assignments($yearId, $classId, $subjectId)) {
        db()->prepare('UPDATE class_subjects SET is_active = 0 WHERE academic_year_id = :year_id AND class_id = :class_id AND subject_id = :subject_id')
            ->execute([
                ':year_id' => $yearId,
                ':class_id' => $classId,
                ':subject_id' => $subjectId,
            ]);
        return 'disabled';
    }

    db()->prepare('DELETE cs, mm FROM class_subjects cs LEFT JOIN max_marks mm ON mm.class_id = cs.class_id AND mm.subject_id = cs.subject_id AND mm.assessment_id IN (SELECT id FROM assessments WHERE academic_year_id = :year_id AND class_id = :class_id) WHERE cs.academic_year_id = :year_id AND cs.class_id = :class_id AND cs.subject_id = :subject_id')
        ->execute([
            ':year_id' => $yearId,
            ':class_id' => $classId,
            ':subject_id' => $subjectId,
        ]);
    return 'removed';
}

function copy_class_subjects(int $yearId, int $fromClassId, array $toClassIds, string $mode = 'add_missing'): array
{
    $toClassIds = array_values(array_unique(array_map('intval', $toClassIds)));
    $current = [];
    foreach ($toClassIds as $classId) {
        $rows = get_class_subjects($yearId, $classId, false);
        $current[$classId] = [];
        foreach ($rows as $row) {
            $current[$classId][(int) $row['subject_id']] = (bool) $row['is_active'];
        }
    }

    $sourceRows = get_class_subjects($yearId, $fromClassId, false);
    $results = ['added' => 0, 'reenabled' => 0, 'removed' => 0, 'disabled' => 0];

    foreach ($toClassIds as $classId) {
        $desired = [];
        foreach ($sourceRows as $row) {
            $desired[(int) $row['subject_id']] = true;
        }

        foreach ($sourceRows as $row) {
            $subjectId = (int) $row['subject_id'];
            if (!isset($current[$classId][$subjectId])) {
                add_subjects_to_classes($yearId, [$classId], [$subjectId]);
                $results['added']++;
            } elseif ((int) ($current[$classId][$subjectId]) === 0) {
                db()->prepare('UPDATE class_subjects SET is_active = 1 WHERE academic_year_id = :year_id AND class_id = :class_id AND subject_id = :subject_id')
                    ->execute([':year_id' => $yearId, ':class_id' => $classId, ':subject_id' => $subjectId]);
                $results['reenabled']++;
            }
        }

        if ($mode === 'exact') {
            foreach ($current[$classId] as $subjectId => $isActive) {
                if (!isset($desired[$subjectId])) {
                    $result = remove_subject_from_class($yearId, $classId, $subjectId);
                    if ($result === 'disabled') {
                        $results['disabled']++;
                    } else {
                        $results['removed']++;
                    }
                }
            }
        }
    }

    return $results;
}

function set_type_override(int $yearId, array $classIds, array $subjectIds, ?string $override): int
{
    $updated = 0;
    $classIds = array_values(array_unique(array_map('intval', $classIds)));
    $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));
    foreach ($classIds as $classId) {
        foreach ($subjectIds as $subjectId) {
            $counts = 1;
            if ($override === 'grade_only') {
                $counts = 0;
            }

            $statement = db()->prepare('UPDATE class_subjects SET type_override = :override, counts_toward_total = :counts WHERE academic_year_id = :year_id AND class_id = :class_id AND subject_id = :subject_id');
            $statement->execute([
                ':override' => $override,
                ':counts' => $counts,
                ':year_id' => $yearId,
                ':class_id' => $classId,
                ':subject_id' => $subjectId,
            ]);
            $updated += $statement->rowCount();
        }
    }
    return $updated;
}
