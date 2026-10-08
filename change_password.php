<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validators.php';

require_login();
$user = current_user();

if (!$user) {
    redirect('login.php');
}

$error = '';
$forceChange = (int) ($user['must_change_password'] ?? 0) === 1;
if (is_post()) {
    csrf_require();

    if (($_POST['action'] ?? '') === 'cancel_forced_password_change') {
        if (!$forceChange || ($user['role'] ?? '') !== 'super_admin') {
            flash_add('error', 'You cannot cancel this password change.');
            redirect('change_password.php');
        }

        db()->prepare('UPDATE users SET must_change_password = 0 WHERE id = :id')->execute([
            ':id' => (int) $user['id'],
        ]);
        log_action('password_change_cancelled', 'users', (int) $user['id'], null, ['username' => $user['username']]);
        flash_add('warning', 'Password change skipped. You can change it later from the sidebar.');
        redirect('dashboard.php');
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($currentPassword, $user['password_hash'])) {
        $error = 'Current password is incorrect.';
    } else {
        $validationError = validate_password($newPassword, (string) $user['username']);
        if ($validationError !== null) {
            $error = $validationError;
        } elseif ($newPassword === $currentPassword) {
            $error = 'New password must be different from the current password.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $passwordHash = hash_password($newPassword);
            db()->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id')->execute([
                ':hash' => $passwordHash,
                ':id' => (int) $user['id'],
            ]);
            session_regenerate_id(true);
            log_action('password_changed', 'users', (int) $user['id'], null, ['username' => $user['username']]);
            flash_add('success', 'Your password has been updated.');
            redirect('dashboard.php');
        }
    }
}

$page_title = 'Change password';
$page_description = 'Update your password.';
$show_chrome = !$forceChange;
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <?php if ($forceChange): ?>
        <h2 class="card-title">For your security, please choose a new password before continuing.</h2>
    <?php else: ?>
        <h2 class="card-title">Change password</h2>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div class="form-field">
            <label for="current-password">Current password</label>
            <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
        </div>
        <div class="form-field">
            <label for="new-password">New password</label>
            <input id="new-password" name="new_password" type="password" autocomplete="new-password" required>
        </div>
        <div class="form-field">
            <label for="confirm-password">Confirm new password</label>
            <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" required>
        </div>
        <button class="button" type="submit">Update password</button>
    </form>
    <?php if ($forceChange && ($user['role'] ?? '') === 'super_admin'): ?>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_forced_password_change">
            <button class="button button-secondary" type="submit">Cancel and go back to the app</button>
        </form>
    <?php endif; ?>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
