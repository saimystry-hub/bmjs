<?php

// Remove a UTF-8 byte-order mark if it appears at the start of the file.
function csv_strip_bom(string $text): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
}

// Convert raw CSV text to UTF-8, preserving UTF-8 input and normalising Windows-1252 files.
function csv_to_utf8(string $raw): array
{
    if ($raw === '') {
        return ['text' => '', 'converted' => false];
    }

    if (preg_match('//u', $raw) === 1) {
        return ['text' => $raw, 'converted' => false];
    }

    $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    return ['text' => is_string($converted) ? $converted : $raw, 'converted' => true];
}

// Count the most likely column delimiter in the first non-empty CSV line.
function csv_detect_delimiter(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $best = ',';
        $bestCount = -1;
        foreach ([',', ';', "\t"] as $delimiter) {
            $count = 0;
            $inQuotes = false;
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                if ($char === '"') {
                    if ($inQuotes && $i + 1 < $length && $line[$i + 1] === '"') {
                        $i++;
                        continue;
                    }
                    $inQuotes = !$inQuotes;
                    continue;
                }

                if (!$inQuotes && $char === $delimiter) {
                    $count++;
                }
            }

            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $delimiter;
            }
        }

        return $best;
    }

    return ',';
}

// Parse CSV text into headers and body rows, with row numbers as they appeared in the file.
function csv_parse(string $text, string $delimiter): array
{
    $trimmed = csv_strip_bom($text);
    if (strpos($trimmed, "\0") !== false) {
        return ['headers' => [], 'rows' => [], 'error' => 'The CSV file is not valid text.'];
    }

    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return ['headers' => [], 'rows' => [], 'error' => 'The CSV file could not be read.'];
    }

    fwrite($handle, $trimmed);
    rewind($handle);

    $headers = [];
    $rows = [];
    $lineNumber = 0;
    $dataRows = 0;
    while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;
        if ($cells === [null] || $cells === [false]) {
            continue;
        }

        $cleaned = [];
        foreach ($cells as $cell) {
            $textValue = is_string($cell) ? $cell : (string) $cell;
            $trimmedCell = str_replace(["\r", "\n"], ' ', $textValue);
            $trimmedCell = trim($trimmedCell);
            if (strlen($trimmedCell) > 500) {
                fclose($handle);
                return ['headers' => [], 'rows' => [], 'error' => 'A CSV cell is longer than 500 characters.'];
            }
            if (strpos($trimmedCell, "\0") !== false) {
                fclose($handle);
                return ['headers' => [], 'rows' => [], 'error' => 'The CSV file contains invalid binary data.'];
            }
            $cleaned[] = $trimmedCell;
        }

        if (count($cleaned) > 30) {
            fclose($handle);
            return ['headers' => [], 'rows' => [], 'error' => 'The CSV file has more than 30 columns.'];
        }

        $hasContent = false;
        foreach ($cleaned as $cell) {
            if ($cell !== '') {
                $hasContent = true;
                break;
            }
        }

        if (!$hasContent) {
            continue;
        }

        if ($lineNumber === 1) {
            $headers = $cleaned;
            continue;
        }

        $dataRows++;
        if ($dataRows > 3000) {
            fclose($handle);
            return ['headers' => [], 'rows' => [], 'error' => 'The CSV file has more than 3000 rows.'];
        }

        $rows[] = ['row' => $lineNumber, 'cells' => $cleaned];
    }

    fclose($handle);

    return ['headers' => $headers, 'rows' => $rows, 'error' => null];
}

// Normalise a CSV header so it can be matched consistently across files.
function csv_normalize_header(string $header): string
{
    $value = strtolower(trim($header));
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

// Guess which CSV columns match the student fields we need.
function csv_guess_mapping(array $headers): array
{
    $aliases = [
        'bmjs_id' => ['id', 'bmjs id', 'bmss id', 'bmss', 'bmjs', 'admission', 'admission no', 'admission number', 'adm no', 'student id', 'reg no', 'registration no'],
        'full_name' => ['name', 'student name', 'full name', 'student'],
        'class' => ['class', 'grade', 'class grade', 'grade class', 'std'],
        'section' => ['section', 'sec'],
        'dob' => ['dob', 'date of birth', 'birth date', 'birthdate'],
        'status' => ['status'],
    ];

    $result = ['bmjs_id' => null, 'full_name' => null, 'class' => null, 'section' => null, 'dob' => null, 'status' => null];
    $assigned = [];

    foreach ($headers as $index => $header) {
        $key = csv_normalize_header((string) $header);
        if ($key === '') {
            continue;
        }

        foreach ($aliases as $field => $matchList) {
            if (isset($assigned[$field])) {
                continue;
            }
            foreach ($matchList as $alias) {
                if ($key === $alias) {
                    $result[$field] = $index;
                    $assigned[$field] = true;
                    break 2;
                }
            }
        }
    }

    return $result;
}

// Normalise a class label like "Class VII" or "Grade 3" to a comparable machine value.
function normalize_class_name(string $s): string
{
    $value = strtolower(trim($s));
    $value = str_replace(['&nbsp;', "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], ' ', $value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? $value;
    $value = preg_replace('/\b(class|grade|cls|std)\b/u', ' ', $value) ?? $value;
    $value = preg_replace('/[[:punct:]]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = trim($value);

    $ordinals = ['1st' => '1', '2nd' => '2', '3rd' => '3', '4th' => '4', '5th' => '5', '6th' => '6', '7th' => '7', '8th' => '8', '9th' => '9', '10th' => '10', '11th' => '11', '12th' => '12'];
    foreach ($ordinals as $from => $to) {
        $value = preg_replace('/\b' . preg_quote($from, '/') . '\b/u', $to, $value) ?? $value;
    }

    $wordMap = ['zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10', 'eleven' => '11', 'twelve' => '12'];
    foreach ($wordMap as $word => $digit) {
        $value = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', $digit, $value) ?? $value;
    }

    $romanMap = ['xii' => '12', 'xi' => '11', 'x' => '10', 'ix' => '9', 'viii' => '8', 'vii' => '7', 'vi' => '6', 'v' => '5', 'iv' => '4', 'iii' => '3', 'ii' => '2', 'i' => '1'];
    foreach ($romanMap as $roman => $digit) {
        $value = preg_replace('/\b' . preg_quote($roman, '/') . '\b/u', $digit, $value) ?? $value;
    }

    $value = preg_replace('/[^a-z0-9]+/u', '', $value) ?? $value;
    return trim($value);
}

// Match the incoming class label to the real class list, returning the best match and ambiguity flag.
function match_class(string $input, array $classes): array
{
    $normal = normalize_class_name($input);
    if ($normal === '') {
        return ['id' => null, 'ambiguous' => false];
    }

    $matches = [];
    foreach ($classes as $id => $name) {
        if (normalize_class_name((string) $name) === $normal) {
            $matches[] = (int) $id;
        }
    }

    if (count($matches) === 0) {
        return ['id' => null, 'ambiguous' => false];
    }

    if (count($matches) > 1) {
        return ['id' => null, 'ambiguous' => true];
    }

    return ['id' => $matches[0], 'ambiguous' => false];
}

// Parse a date and validate whether it is a plausible student date of birth.
function parse_dob(string $s): array
{
    $value = trim($s);
    if ($value === '') {
        return ['ok' => true, 'date' => null, 'error' => null, 'warning' => null];
    }

    $patterns = [
        'Y-m-d' => '!Y-m-d',
        'd/m/Y' => '!d/m/Y',
        'd-m-Y' => '!d-m-Y',
        'd.m.Y' => '!d.m.Y',
    ];

    foreach ($patterns as $label => $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }

            $today = new DateTimeImmutable('today');
            if ($date > $today) {
                return ['ok' => false, 'date' => null, 'error' => 'Date should be today or earlier.', 'warning' => null];
            }

            $age = $today->diff($date, false)->y;
            if ($age < 2 || $age > 25) {
                return ['ok' => true, 'date' => $date->format('Y-m-d'), 'error' => null, 'warning' => 'The date looks unusual for a student age.'];
            }

            return ['ok' => true, 'date' => $date->format('Y-m-d'), 'error' => null, 'warning' => null];
        }
    }

    return ['ok' => false, 'date' => null, 'error' => 'Date should look like 15/03/2020.', 'warning' => null];
}

// Clean a student's name to a readable, consistent form.
function clean_name(string $s): string
{
    $value = trim((string) $s);
    $value = str_replace(["\xC2\xA0", "\xEF\xBB\xBF", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xE2\x80\x8E", "\xE2\x80\x8F"], ' ', $value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

// Parse a status value and allow blank values to mean "use the default".
function parse_status(string $s): array
{
    $value = trim($s);
    if ($value === '') {
        return ['ok' => true, 'status' => null];
    }

    $normalized = strtolower($value);
    $allowed = ['active', 'sor', 'freeze', 'left'];
    if (in_array($normalized, $allowed, true)) {
        return ['ok' => true, 'status' => $normalized];
    }

    return ['ok' => false, 'status' => null, 'error' => 'Status should be Active, SOR, Freeze or Left.'];
}

// Prefix dangerous spreadsheet cell values with a single quote to prevent formula injection.
function csv_safe_cell(string $s): string
{
    $value = (string) $s;
    if ($value === '') {
        return $value;
    }

    if (preg_match('/^[=\+\-@\t\r]/', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

// Validate a BMJS ID against the configured pattern, with a safe fallback if needed.
function validate_bmjs_id(string $id, string $pattern): ?string
{
    $value = trim($id);
    if ($value === '') {
        return 'BMJS ID is missing.';
    }

    $regex = $pattern !== '' ? $pattern : '/^(?=.*\d)[A-Za-z0-9-]{3,30}$/';
    $compiled = @preg_match($regex, $value);
    if ($compiled === false) {
        $regex = '/^(?=.*\d)[A-Za-z0-9-]{3,30}$/';
    }

    if (@preg_match($regex, $value) !== 1) {
        return 'ID should look like BmSS-JS-22-1418 (letters, numbers and dashes).';
    }

    return null;
}

// Build a validation plan for each row in an imported CSV file.
function build_import_plan(array $rows, array $mapping, array $lookups, array $options): array
{
    $defaults = ['create_missing' => false, 'update_existing' => false, 'allow_move' => false];
    $options = array_merge($defaults, $options);
    $counts = ['new' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'warning' => 0];
    $creates = ['classes' => [], 'sections' => []];
    $planRows = [];
    $seenIds = [];

    $classes = $lookups['classes'] ?? [];
    $sections = $lookups['sections'] ?? [];
    $existing = $lookups['existing'] ?? [];
    $idPattern = $lookups['id_pattern'] ?? '/^(?=.*\d)[A-Za-z0-9-]{3,30}$/';

    foreach ($rows as $row) {
        $cells = $row['cells'] ?? [];
        $rawBmjsId = $mapping['bmjs_id'] !== null && isset($cells[$mapping['bmjs_id']]) ? $cells[$mapping['bmjs_id']] : '';
        $rawName = $mapping['full_name'] !== null && isset($cells[$mapping['full_name']]) ? $cells[$mapping['full_name']] : '';
        $rawClass = $mapping['class'] !== null && isset($cells[$mapping['class']]) ? $cells[$mapping['class']] : '';
        $rawSection = $mapping['section'] !== null && isset($cells[$mapping['section']]) ? $cells[$mapping['section']] : '';
        $rawDob = $mapping['dob'] !== null && isset($cells[$mapping['dob']]) ? $cells[$mapping['dob']] : '';
        $rawStatus = $mapping['status'] !== null && isset($cells[$mapping['status']]) ? $cells[$mapping['status']] : '';

        $bmjsId = trim((string) $rawBmjsId);
        $name = clean_name((string) $rawName);
        $classLabel = trim((string) $rawClass);
        $sectionLabel = trim((string) $rawSection);
        $dobDate = trim((string) $rawDob);
        $statusText = trim((string) $rawStatus);

        $entry = [
            'row' => (int) ($row['row'] ?? 0),
            'bmjs_id' => $bmjsId,
            'full_name' => $name,
            'dob' => $dobDate,
            'class_label' => $classLabel,
            'section_label' => $sectionLabel,
            'status' => 'new',
            'problems' => [],
            'notes' => [],
            'warnings' => [],
            'changes' => [],
            'class_id' => null,
            'section_id' => null,
            'resolved_class' => null,
            'resolved_section' => null,
        ];

        $missing = [];
        if ($bmjsId === '') { $missing[] = 'BMJS ID is missing'; }
        if ($name === '') { $missing[] = 'Name is missing'; }
        if ($classLabel === '') { $missing[] = 'Class is missing'; }
        if ($sectionLabel === '') { $missing[] = 'Section is missing'; }
        if ($missing !== []) {
            $entry['problems'] = $missing;
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }

        $idError = validate_bmjs_id($bmjsId, $idPattern);
        if ($idError !== null) {
            $entry['problems'][] = $idError;
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }

        $lowerId = strtolower($bmjsId);
        if (isset($seenIds[$lowerId])) {
            $entry['problems'][] = 'Same ID as row ' . $seenIds[$lowerId];
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }
        $seenIds[$lowerId] = $entry['row'];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $entry['problems'][] = 'Name should be 2 to 150 characters.';
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }

        $dobResult = parse_dob($dobDate);
        if ($dobDate !== '' && !$dobResult['ok']) {
            $entry['problems'][] = $dobResult['error'];
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }
        if ($dobResult['warning'] !== null) {
            $entry['warnings'][] = $dobResult['warning'];
            $counts['warning']++;
        }

        $statusResult = parse_status($statusText);
        if (!$statusResult['ok']) {
            $entry['problems'][] = $statusResult['error'];
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }
        $resolvedStatus = $statusResult['status'];

        $classMatch = match_class($classLabel, $classes);
        if ($classMatch['id'] === null && !$options['create_missing']) {
            $classNames = implode(', ', array_map('strval', $classes));
            $entry['problems'][] = "Class '" . $classLabel . "' not found. Classes are: " . $classNames;
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }
        if ($classMatch['ambiguous']) {
            $entry['problems'][] = 'Class name is ambiguous; please use the exact class label.';
            $entry['status'] = 'error';
            $counts['error']++;
            $planRows[] = $entry;
            continue;
        }
        if ($classMatch['id'] !== null) {
            $entry['class_id'] = (int) $classMatch['id'];
            $entry['resolved_class'] = $classes[$classMatch['id']] ?? null;
        } else {
            if (!in_array($classLabel, $creates['classes'], true)) {
                $creates['classes'][] = $classLabel;
            }
            $entry['class_id'] = 'to be created';
            $entry['resolved_class'] = $classLabel;
        }

        $sectionKey = strtolower(trim($sectionLabel));
        $classSectionMap = $sections[$entry['class_id']] ?? [];
        if ($classMatch['id'] === null) {
            $classSectionMap = [];
        }

        if ($classMatch['id'] !== null && !isset($classSectionMap[$sectionKey])) {
            if ($options['create_missing']) {
                if (!in_array([$entry['resolved_class'], $sectionLabel], $creates['sections'], true)) {
                    $creates['sections'][] = [$entry['resolved_class'], $sectionLabel];
                }
                $entry['section_id'] = 'to be created';
                $entry['resolved_section'] = $sectionLabel;
            } else {
                $available = implode(', ', array_keys($classSectionMap));
                $entry['problems'][] = "Class " . ($entry['resolved_class'] ?? $classLabel) . " has no section '" . $sectionLabel . "'. Sections: " . $available;
                $entry['status'] = 'error';
                $counts['error']++;
                $planRows[] = $entry;
                continue;
            }
        } elseif ($classMatch['id'] !== null) {
            $entry['section_id'] = (int) $classSectionMap[$sectionKey];
            $entry['resolved_section'] = $sectionLabel;
        }

        $studentRow = $existing[$lowerId] ?? null;
        if ($studentRow === null) {
            $entry['status'] = 'new';
            $entry['status_value'] = $resolvedStatus ?? 'active';
            $entry['notes'][] = 'New student';
            $counts['new']++;
            $planRows[] = $entry;
            continue;
        }

        if (!$options['update_existing']) {
            $entry['status'] = 'skip';
            $entry['notes'][] = 'Already exists. Turn on \'Update existing students\' to change it.';
            $counts['skip']++;
            $planRows[] = $entry;
            continue;
        }

        $changes = [];
        $currentName = (string) ($studentRow['full_name'] ?? '');
        if ($currentName !== $name) {
            $changes[] = ['field' => 'full_name', 'old' => $currentName, 'new' => $name];
        }

        $currentDob = (string) ($studentRow['dob'] ?? '');
        if ($dobDate !== '' && $currentDob !== $dobDate) {
            $changes[] = ['field' => 'dob', 'old' => $currentDob, 'new' => $dobDate];
        }

        $currentStatus = (string) ($studentRow['status'] ?? 'active');
        if ($resolvedStatus !== null && $resolvedStatus !== $currentStatus) {
            $changes[] = ['field' => 'status', 'old' => $currentStatus, 'new' => $resolvedStatus];
        }

        $desiredSectionId = $entry['section_id'];
        if (is_int($desiredSectionId) && (int) $desiredSectionId !== (int) ($studentRow['section_id'] ?? 0)) {
            $hasData = (int) ($studentRow['has_data'] ?? 0);
            if (!$options['allow_move']) {
                $entry['warnings'][] = 'Different section: not moved';
            } else {
                if ($hasData === 1) {
                    $sameClass = (int) ($studentRow['class_id'] ?? 0) === (int) ($entry['class_id'] ?? 0);
                    if (!$sameClass) {
                        $entry['problems'][] = 'Has marks or grades in this year, so they cannot move to another class.';
                        $entry['status'] = 'error';
                        $counts['error']++;
                        $planRows[] = $entry;
                        continue;
                    }
                }
                $changes[] = ['field' => 'section_id', 'old' => (int) ($studentRow['section_id'] ?? 0), 'new' => $desiredSectionId];
            }
        }

        if ($changes === []) {
            $entry['status'] = 'skip';
            $entry['notes'][] = 'No changes';
            $counts['skip']++;
            $planRows[] = $entry;
            continue;
        }

        $entry['status'] = 'update';
        $entry['changes'] = $changes;
        $entry['notes'][] = 'Will update student details';
        $counts['update']++;
        $planRows[] = $entry;
    }

    $signature = md5((string) json_encode(['rows' => $planRows, 'counts' => $counts, 'creates' => $creates]));
    return ['rows' => $planRows, 'counts' => $counts, 'creates' => $creates, 'signature' => $signature];
}

