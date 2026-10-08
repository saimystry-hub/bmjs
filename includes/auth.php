<?php
if (!defined('SESSION_IDLE_MINUTES')) {
    define('SESSION_IDLE_MINUTES', 30);
}
if (!defined('LOGIN_MAX_FAILURES')) {
    define('LOGIN_MAX_FAILURES', 5);
}
if (!defined('LOGIN_BLOCK_MINUTES')) {
    define('LOGIN_BLOCK_MINUTES', 10);
}

function current_user(): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }

    $loaded = true;

    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }

    try {
        $statement = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => (int) $_SESSION['user_id']]);
        $user = $statement->fetch();
    } catch (Throwable $exception) {
        error_log('Current user lookup failed: ' . $exception->getMessage());
        $user = null;
    }

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
        logout_user();
        $user = null;
        return null;
    }

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function has_role(array $roles): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }

    return in_array($user['role'], $roles, true);
}

function login_is_blocked(string $username): bool|int
{
    $username = trim($username);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($username === '') {
        return false;
    }

    $cutoff = date('Y-m-d H:i:s', time() - (LOGIN_BLOCK_MINUTES * 60));

    $userStatement = db()->prepare(
        'SELECT COUNT(*) AS attempts, MIN(attempted_at) AS first_attempt FROM login_attempts WHERE ip = :ip AND username = :username AND attempted_at >= :cutoff'
    );
    $userStatement->execute([
        ':ip' => $ip,
        ':username' => $username,
        ':cutoff' => $cutoff,
    ]);
    $userResult = $userStatement->fetch();
    $userAttempts = (int) ($userResult['attempts'] ?? 0);

    if ($userAttempts >= LOGIN_MAX_FAILURES) {
        $firstAttempt = $userResult['first_attempt'] ?? null;
        if ($firstAttempt) {
            $remainingSeconds = (strtotime($firstAttempt) + (LOGIN_BLOCK_MINUTES * 60)) - time();
            return max(1, (int) ceil($remainingSeconds / 60));
        }

        return LOGIN_BLOCK_MINUTES;
    }

    $ipStatement = db()->prepare(
        'SELECT COUNT(*) AS attempts, MIN(attempted_at) AS first_attempt FROM login_attempts WHERE ip = :ip AND attempted_at >= :cutoff'
    );
    $ipStatement->execute([
        ':ip' => $ip,
        ':cutoff' => $cutoff,
    ]);
    $ipResult = $ipStatement->fetch();
    $ipAttempts = (int) ($ipResult['attempts'] ?? 0);

    if ($ipAttempts >= 20) {
        $firstAttempt = $ipResult['first_attempt'] ?? null;
        if ($firstAttempt) {
            $remainingSeconds = (strtotime($firstAttempt) + (LOGIN_BLOCK_MINUTES * 60)) - time();
            return max(1, (int) ceil($remainingSeconds / 60));
        }

        return LOGIN_BLOCK_MINUTES;
    }

    return false;
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function user_password_reset_allowed(int $targetUserId, int $loggedInUserId): array
{
    if ($targetUserId <= 0) {
        return ['allowed' => false, 'message' => 'The selected user could not be found.'];
    }

    if ($targetUserId === $loggedInUserId) {
        return ['allowed' => false, 'message' => "Use 'Change password' for your own account."];
    }

    return ['allowed' => true, 'message' => ''];
}

// Create a new one-time password for a newly created or reset account.
function generate_temporary_password(): string
{
    $randomPart = strtoupper(bin2hex(random_bytes(3)));
    return 'BmJS-' . $randomPart;
}

function attempt_login(string $username, string $password): array
{
    $username = trim($username);
    $blockedUntil = login_is_blocked($username);
    if ($blockedUntil !== false) {
        return [
            'ok' => false,
            'error' => 'Too many failed attempts. Please try again in ' . (int) $blockedUntil . ' minutes.',
        ];
    }

    $statement = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $statement->execute([':username' => $username]);
    $user = $statement->fetch();

    $dummyHash = password_hash('bmjs-dummy-password', PASSWORD_DEFAULT);
    $passwordCorrect = password_verify($password, $dummyHash);
    if ($user) {
        $passwordCorrect = password_verify($password, $user['password_hash']);
    }

    if (!$user || !$passwordCorrect) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        db()->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (:ip, :username, NOW())')->execute([
            ':ip' => $ip,
            ':username' => $username,
        ]);
        log_action('login_failed', 'users', $user['id'] ?? null, null, ['username' => $username, 'ip' => $ip]);
        return ['ok' => false, 'error' => 'Wrong username or password.'];
    }

    if ((int) ($user['is_active'] ?? 0) !== 1) {
        log_action('login_failed', 'users', (int) $user['id'], null, ['username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'reason' => 'disabled']);
        return ['ok' => false, 'error' => 'This account is disabled. Please contact the administrator.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    db()->prepare('DELETE FROM login_attempts WHERE ip = :ip AND username = :username')->execute([
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ':username' => $username,
    ]);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = hash_password($password);
        db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')->execute([
            ':password_hash' => $newHash,
            ':id' => (int) $user['id'],
        ]);
    }

    log_action('login_success', 'users', (int) $user['id'], null, ['username' => $username, 'role' => $user['role']]);
    return ['ok' => true, 'error' => null];
}

function logout_user(): void
{
    log_action('logout', null, null, null, ['user_id' => $_SESSION['user_id'] ?? null]);

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_unset();
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        $target = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        redirect('login.php?next=' . rawurlencode($target));
    }

    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }

    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > (SESSION_IDLE_MINUTES * 60)) {
        log_action('session_expired', 'users', (int) $user['id'], null, ['requested_url' => $_SERVER['REQUEST_URI'] ?? null]);
        logout_user();
        flash_add('warning', 'Your session expired. Please log in again.');
        redirect('login.php');
    }

    $_SESSION['last_activity'] = time();

    if ((int) ($user['must_change_password'] ?? 0) === 1) {
        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
        if ($currentPage !== 'change_password.php' && $currentPage !== 'logout.php') {
            redirect('change_password.php');
        }
    }
}

function require_role(array $roles): void
{
    require_login();

    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        log_action('access_denied', 'users', $user['id'] ?? null, null, ['requested_url' => $_SERVER['REQUEST_URI'] ?? null, 'required_roles' => $roles]);
        abort_403();
    }
}

function abort_403(): never
{
    http_response_code(403);
    $page_title = 'Access denied';
    $page_description = 'You do not have permission to open this page.';
    $show_chrome = is_logged_in();
    require BASE_PATH . '/components/header.php';
    echo '<section class="card"><h2 class="card-title">Access denied</h2><p>You don\'t have permission to open this page.</p><a class="button" href="' . h(url(is_logged_in() ? 'dashboard.php' : 'index.php')) . '">Back to home</a></section>';
    require BASE_PATH . '/components/footer.php';
    exit;
}

function abort_404(): never
{
    http_response_code(404);
    $page_title = 'Page not found';
    $page_description = 'That page does not exist.';
    $show_chrome = is_logged_in();
    require BASE_PATH . '/components/header.php';
    echo '<section class="card"><h2 class="card-title">Page not found</h2><p>That page doesn\'t exist.</p><a class="button" href="' . h(url(is_logged_in() ? 'dashboard.php' : 'index.php')) . '">Back to home</a></section>';
    require BASE_PATH . '/components/footer.php';
    exit;
}

function coming_soon(string $title, array $roles): void
{
    require_role($roles);
    $page_title = $title;
    $page_description = 'Coming soon.';
    require BASE_PATH . '/components/header.php';
    echo '<section class="card"><h2 class="card-title">' . h($title) . '</h2><p>This page is coming soon. It will be built in a later phase.</p></section>';
    require BASE_PATH . '/components/footer.php';
}

function safe_next(string $next): string
{
    $default = 'dashboard.php';
    $value = trim((string) $next);
    if ($value === '') {
        return $default;
    }

    $path = parse_url($value, PHP_URL_PATH) ?: $value;
    $query = parse_url($value, PHP_URL_QUERY);

    if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
        return $default;
    }

    if (!preg_match('#^[A-Za-z0-9_\-/\.]+\.php$#', $path)) {
        return $default;
    }

    $relativePath = ltrim($path, '/');
    $fullPath = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($fullPath)) {
        return $default;
    }

    return $query !== null && $query !== '' ? $path . '?' . $query : $path;
}
