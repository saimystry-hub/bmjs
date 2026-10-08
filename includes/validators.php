<?php

function validate_username(string $username): ?string
{
    $value = trim($username);
    if (strlen($value) < 3 || strlen($value) > 30) {
        return 'Username must be 3 to 30 characters long.';
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
        return 'Username may only contain letters, numbers, periods, underscores, and hyphens.';
    }

    return null;
}

function validate_password(string $password, string $username): ?string
{
    $value = trim($password);
    if (strlen($value) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    if (!preg_match('/[A-Za-z]/', $value)) {
        return 'Password must contain at least one letter.';
    }

    if (!preg_match('/\d/', $value)) {
        return 'Password must contain at least one number.';
    }

    if (strcasecmp($value, trim($username)) === 0) {
        return 'Password must not be the same as the username.';
    }

    return null;
}

function valid_year_name(string $name): bool
{
    if (!preg_match('/^\d{4}-\d{2}$/', trim($name))) {
        return false;
    }

    [$startText, $endText] = explode('-', trim($name), 2);
    $start = (int) $startText;
    $end = (int) $endText;
    return $end === ($start % 100) + 1;
}

function parse_section_names(string $text): array
{
    $rawNames = preg_split('/[\r\n,]+/', $text);
    $names = [];
    $errors = [];
    $seen = [];

    if (!is_array($rawNames)) {
        return ['names' => [], 'errors' => ['Could not read section names.']];
    }

    foreach ($rawNames as $rawName) {
        $name = preg_replace('/\s+/', ' ', trim((string) $rawName));
        if ($name === '') {
            continue;
        }

        $lowerName = strtolower($name);
        if (isset($seen[$lowerName])) {
            continue;
        }
        $seen[$lowerName] = true;

        if (mb_strlen($name) > 30) {
            $errors[] = 'Section name "' . $name . '" is too long.';
            continue;
        }

        if (mb_strlen($name) < 1) {
            $errors[] = 'Section names cannot be blank.';
            continue;
        }

        $names[] = $name;
    }

    return ['names' => $names, 'errors' => $errors];
}

function would_create_class_cycle(array $nextMap, int $classId, ?int $newNextId): bool
{
    if ($newNextId === $classId) {
        return true;
    }

    if ($newNextId === null) {
        return false;
    }

    $visited = [];
    $current = $newNextId;
    while ($current !== null) {
        if ((int) $current === (int) $classId) {
            return true;
        }
        if (isset($visited[(int) $current])) {
            return true;
        }
        $visited[(int) $current] = true;
        $current = $nextMap[(int) $current] ?? null;
    }

    $nextMap[(int) $classId] = $newNextId;
    $visited = [];
    $check = $newNextId;
    while ($check !== null) {
        if ((int) $check === (int) $classId) {
            return true;
        }
        if (isset($visited[(int) $check])) {
            return true;
        }
        $visited[(int) $check] = true;
        $check = $nextMap[(int) $check] ?? null;
    }

    return false;
}
