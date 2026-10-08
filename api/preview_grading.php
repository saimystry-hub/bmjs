<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/grading.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

// Send a JSON response and stop processing the preview request.
function grading_api_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') grading_api_reply(405, ['ok' => false, 'errors' => ['Use POST to preview grade changes.']]);
$user = current_user();
if (!$user) grading_api_reply(401, ['ok' => false, 'errors' => ['Please sign in first.']]);
if (($user['role'] ?? '') !== 'super_admin') grading_api_reply(403, ['ok' => false, 'errors' => ['You do not have permission to preview grade changes.']]);
$providedToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$expectedToken = (string) ($_SESSION['csrf_token'] ?? '');
if ($providedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    grading_api_reply(419, ['ok' => false, 'errors' => ['Your page expired. Please refresh and try again.']]);
}
$decoded = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($decoded) || !is_numeric($decoded['year_id'] ?? null) || !is_array($decoded['bands'] ?? null) || !is_string($decoded['rounding'] ?? null)) {
    grading_api_reply(400, ['ok' => false, 'errors' => ['Enter the academic year, grade bands and rounding rule.']]);
}
$bands = [];
foreach ($decoded['bands'] as $band) {
    if (!is_array($band) || !is_scalar($band['label'] ?? null) || !is_scalar($band['min'] ?? null)) {
        grading_api_reply(400, ['ok' => false, 'errors' => ['Every grade needs a label and minimum percentage.']]);
    }
    $bands[] = ['label' => trim((string) $band['label']), 'min' => trim((string) $band['min'])];
}
$errors = validate_bands($bands);
if (!in_array($decoded['rounding'], ['nearest', 'none', 'down'], true)) $errors[] = 'Choose a valid rounding rule.';
if ((int) $decoded['year_id'] <= 0) $errors[] = 'Choose a valid academic year.';
if ($errors !== []) grading_api_reply(400, ['ok' => false, 'errors' => array_values(array_unique($errors))]);
try {
    grading_api_reply(200, ['ok' => true, 'impact' => grading_impact((int) $decoded['year_id'], $bands, $decoded['rounding'])]);
} catch (Throwable $exception) {
    error_log('Grading preview failed: ' . $exception->getMessage());
    grading_api_reply(500, ['ok' => false, 'errors' => ['The grade changes could not be previewed. Please try again.']]);
}
