<?php

function bulk_preview_save(string $action, array $data): string
{
    if (!isset($_SESSION)) {
        session_start();
    }

    $token = bin2hex(random_bytes(16));
    $userId = current_user()['id'] ?? null;
    $snapshot = [
        'action' => $action,
        'user_id' => $userId,
        'created_at' => time(),
        'data' => $data,
    ];

    if (!isset($_SESSION['bulk_previews']) || !is_array($_SESSION['bulk_previews'])) {
        $_SESSION['bulk_previews'] = [];
    }

    foreach ($_SESSION['bulk_previews'] as $key => $preview) {
        if (($preview['created_at'] ?? 0) < (time() - 900)) {
            unset($_SESSION['bulk_previews'][$key]);
        }
    }

    $_SESSION['bulk_previews'][$token] = $snapshot;
    if (count($_SESSION['bulk_previews']) > 10) {
        $oldestToken = null;
        $oldestTime = PHP_INT_MAX;
        foreach ($_SESSION['bulk_previews'] as $key => $preview) {
            $time = (int) ($preview['created_at'] ?? 0);
            if ($time < $oldestTime) {
                $oldestTime = $time;
                $oldestToken = $key;
            }
        }
        if ($oldestToken !== null) {
            unset($_SESSION['bulk_previews'][$oldestToken]);
        }
    }

    return $token;
}

function bulk_preview_load(string $token, string $action): ?array
{
    if (!isset($_SESSION['bulk_previews']) || !is_array($_SESSION['bulk_previews'])) {
        return null;
    }

    if (!isset($_SESSION['bulk_previews'][$token])) {
        return null;
    }

    $preview = $_SESSION['bulk_previews'][$token];
    if (($preview['action'] ?? '') !== $action) {
        return null;
    }

    $userId = current_user()['id'] ?? null;
    if (($preview['user_id'] ?? null) !== $userId) {
        return null;
    }

    $createdAt = (int) ($preview['created_at'] ?? 0);
    if ($createdAt < (time() - 900)) {
        unset($_SESSION['bulk_previews'][$token]);
        return null;
    }

    return $preview['data'] ?? null;
}

function bulk_preview_clear(string $token): void
{
    if (!empty($_SESSION['bulk_previews']) && is_array($_SESSION['bulk_previews'])) {
        unset($_SESSION['bulk_previews'][$token]);
    }
}

function scope_class_counts(int $yearId): array
{
    $statement = db()->prepare(
        'SELECT c.id AS class_id,
                COUNT(DISTINCT s.id) AS section_count,
                SUM(CASE WHEN st.status = "active" THEN 1 ELSE 0 END) AS student_count
         FROM classes c
         LEFT JOIN sections s ON s.class_id = c.id AND s.academic_year_id = :year_id
         LEFT JOIN students st ON st.section_id = s.id
         GROUP BY c.id
         ORDER BY c.sort_order, c.id'
    );
    $statement->execute([':year_id' => $yearId]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[(int) $row['class_id']] = [
            'sections' => (int) ($row['section_count'] ?? 0),
            'students' => (int) ($row['student_count'] ?? 0),
        ];
    }

    return $rows;
}
