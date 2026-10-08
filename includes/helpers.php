<?php
// Escape text before displaying it in HTML.
function h(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Build a site URL from BASE_URL and a local path.
function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

// Build a URL for a local CSS, JavaScript, or image asset.
function asset(string $path): string
{
    return url($path);
}

// Send the browser to another page on this site.
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// Tell whether the current request uses the POST method.
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// Store a short success, error, warning, or info message for the next page.
function flash_add(string $type, string $message): void
{
    $validTypes = ['success', 'error', 'warning', 'info'];
    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException('Flash type must be success, error, warning, or info.');
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

// Return pending flash messages and clear them from the session.
function flash_all(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

// Read all saved settings once per request and return the requested value.
function setting(string $key, mixed $default = null): mixed
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        $statement = db()->query('SELECT setting_key, setting_value FROM settings');
        foreach ($statement->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

// Return the active academic year row, or null when there is no active year.
function active_year(): ?array
{
    $statement = db()->query('SELECT * FROM academic_years WHERE is_active = 1 LIMIT 1');
    $year = $statement->fetch();
    return $year === false ? null : $year;
}
