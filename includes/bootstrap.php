<?php
$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Setup is not complete. Copy config/config.sample.php to config/config.php, then edit the settings.');
}

require_once $configFile;
define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set(TIMEZONE);

$isDevelopment = APP_ENV === 'dev';
error_reporting(E_ALL);
ini_set('display_errors', $isDevelopment ? '1' : '0');
ini_set('display_startup_errors', $isDevelopment ? '1' : '0');
ini_set('log_errors', '1');

// Log unexpected failures and show an environment-appropriate error page.
set_exception_handler(static function (Throwable $exception) use ($isDevelopment): void {
    error_log((string) $exception);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Something went wrong</title>';
    echo '<body><main><h1>Something went wrong</h1><p>Please try again later or contact the system administrator.</p>';
    if ($isDevelopment) {
        echo '<pre>' . htmlspecialchars((string) $exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
    echo '</main></body></html>';
});

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
$basePath = parse_url(BASE_URL, PHP_URL_PATH);
$cookiePath = rtrim(is_string($basePath) ? $basePath : '/', '/') . '/';
session_name('BMJS_SESSION');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/auth.php';
