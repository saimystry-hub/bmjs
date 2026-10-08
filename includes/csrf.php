<?php
require_once __DIR__ . '/audit.php';

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($expected)
        && $expected !== ''
        && $token !== ''
        && hash_equals($expected, $token);
}

function csrf_require(): void
{
    if (csrf_verify()) {
        return;
    }

    log_action('csrf_failed', null, null, null, [
        'url' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    ]);

    http_response_code(419);
    $page_title = 'Your page expired';
    $page_description = 'Your form session expired.';
    $show_chrome = !empty($_SESSION['user_id']);

    require BASE_PATH . '/components/header.php';
    echo '<section class="card"><h2 class="card-title">Your page expired</h2><p class="muted">Your page expired. Please go back and try again.</p><a class="button" href="' . h(url('index.php')) . '">Back to home</a></section>';
    require BASE_PATH . '/components/footer.php';
    exit;
}
