<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validators.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($userCount > 0) {
    $page_title = 'Setup complete';
    $page_description = 'Setup is already complete.';
    $show_chrome = false;
    require BASE_PATH . '/components/header.php';
    echo '<section class="card"><h2 class="card-title">Setup is already complete</h2><p><a class="button" href="' . h(url('login.php')) . '">Go to login</a></p></section>';
    require BASE_PATH . '/components/footer.php';
    exit;
}

$error = '';
if (is_post()) {
    csrf_require();

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $usernameError = validate_username($username);
    $passwordError = validate_password($password, $username);

    if ($fullName === '') {
        $error = 'Please enter the administrator name.';
    } elseif ($usernameError !== null) {
        $error = $usernameError;
    } elseif ($passwordError !== null) {
        $error = $passwordError;
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            db()->beginTransaction();
            $existingCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($existingCount > 0) {
                db()->rollBack();
                $error = 'Setup is already complete.';
            } else {
                $statement = db()->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active, must_change_password, created_at) VALUES (:username, :password_hash, :full_name, :role, 1, 0, NOW())');
                $statement->execute([
                    ':username' => $username,
                    ':password_hash' => hash_password($password),
                    ':full_name' => $fullName,
                    ':role' => 'super_admin',
                ]);
                $userId = (int) db()->lastInsertId();
                db()->commit();
                log_action('first_admin_created', 'users', $userId, null, ['username' => $username, 'full_name' => $fullName]);

                $page_title = 'Setup complete';
                $page_description = 'The first administrator account has been created.';
                $show_chrome = false;
                require BASE_PATH . '/components/header.php';
                echo '<section class="card"><h2 class="card-title">Setup complete</h2><p>The first administrator account has been created.</p><p><a class="button" href="' . h(url('login.php')) . '">Go to login</a></p><p class="muted">For safety, delete setup_admin.php from the server now.</p></section>';
                require BASE_PATH . '/components/footer.php';
                exit;
            }
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Setup admin failed: ' . $exception->getMessage());
            $error = 'The administrator account could not be created. Please try again.';
        }
    }
}

$page_title = 'Create first admin';
$page_description = 'Create the first BmJS super administrator.';
$show_chrome = false;
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <h2 class="card-title">Create the first admin</h2>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div class="form-field">
            <label for="full-name">Full name</label>
            <input id="full-name" name="full_name" type="text" value="<?= h($_POST['full_name'] ?? '') ?>" required>
        </div>
        <div class="form-field">
            <label for="setup-username">Username</label>
            <input id="setup-username" name="username" type="text" value="<?= h($_POST['username'] ?? '') ?>" required>
        </div>
        <div class="form-field">
            <label for="setup-password">Password</label>
            <input id="setup-password" name="password" type="password" required>
        </div>
        <div class="form-field">
            <label for="setup-confirm">Confirm password</label>
            <input id="setup-confirm" name="confirm_password" type="password" required>
        </div>
        <button class="button" type="submit">Create administrator</button>
    </form>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>

