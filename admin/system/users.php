<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/teachers.php';
require_once __DIR__ . '/../../includes/validators.php';

require_login();
require_role(['super_admin']);

if (is_post()) {
    csrf_require();
}

if (is_post() && ($_POST['action'] ?? '') === 'add_user') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $role = in_array($_POST['role'] ?? '', ['super_admin', 'admin', 'teacher'], true) ? (string) $_POST['role'] : 'teacher';
    $passwordMode = in_array($_POST['password_mode'] ?? 'generate', ['generate', 'manual'], true) ? (string) $_POST['password_mode'] : 'generate';
    $typedPassword = trim((string) ($_POST['new_password'] ?? ''));
    $hrtCanEnterAttendance = ($role === 'teacher' && !empty($_POST['hrt_can_enter_attendance'])) ? 1 : 0;

    $error = validate_username($username);
    if ($error !== null) {
        flash_add('error', $error);
    } elseif ($fullName === '' || mb_strlen($fullName) > 120) {
        flash_add('error', 'Full name is required and must be 120 characters or fewer.');
    } else {
        $existing = db()->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $existing->execute([':username' => $username]);
        if ($existing->fetch()) {
            flash_add('error', 'That username is already in use.');
        } else {
            $tempPassword = null;
            $mustChangePassword = 1;
            $passwordHash = null;

            if ($passwordMode === 'manual') {
                $passwordError = validate_password($typedPassword, $username);
                if ($passwordError !== null) {
                    flash_add('error', $passwordError);
                    redirect('admin/system/users.php');
                }
                $passwordHash = hash_password($typedPassword);
                $mustChangePassword = 0;
            } else {
                $tempPassword = generate_temporary_password();
                $passwordHash = hash_password($tempPassword);
            }

            $statement = db()->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active, must_change_password, hrt_can_enter_attendance, created_at) VALUES (:username, :password_hash, :full_name, :role, 1, :must_change_password, :hrt_can_enter_attendance, NOW())');
            $statement->execute([
                ':username' => $username,
                ':password_hash' => $passwordHash,
                ':full_name' => $fullName,
                ':role' => $role,
                ':must_change_password' => $mustChangePassword,
                ':hrt_can_enter_attendance' => $hrtCanEnterAttendance,
            ]);
            log_action('user_created', 'users', (int) db()->lastInsertId(), null, ['username' => $username, 'role' => $role]);
            if ($tempPassword !== null) {
                flash_add('success', 'User created. Temporary password: ' . $tempPassword . '. The user must change it on first login.');
            } else {
                flash_add('success', 'User created. The password was set by the administrator.');
            }
            redirect('admin/system/users.php');
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'update_user') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $targetStatement = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $targetStatement->execute([':id' => $userId]);
    $row = $targetStatement->fetch();
    if (!$row) {
        flash_add('error', 'The selected user could not be found.');
        redirect('admin/system/users.php');
    }

    $updates = [
        'role' => in_array($_POST['role'] ?? '', ['super_admin', 'admin', 'teacher'], true) ? (string) $_POST['role'] : (string) $row['role'],
        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    ];

    $superAdminCountStatement = db()->prepare('SELECT COUNT(*) FROM users WHERE role = :role AND is_active = 1');
    $superAdminCountStatement->execute([':role' => 'super_admin']);
    $teacherAssignmentCountStatement = db()->prepare('SELECT COUNT(*) FROM teacher_assignments WHERE user_id = :user_id');
    $teacherAssignmentCountStatement->execute([':user_id' => $userId]);
    $hrtSectionCountStatement = db()->prepare('SELECT COUNT(*) FROM sections WHERE hrt_user_id = :user_id');
    $hrtSectionCountStatement->execute([':user_id' => $userId]);

    $blockers = user_change_blockers(
        $row,
        $updates,
        (int) ($_SESSION['user_id'] ?? 0),
        (int) $superAdminCountStatement->fetchColumn(),
        (int) $teacherAssignmentCountStatement->fetchColumn(),
        (int) $hrtSectionCountStatement->fetchColumn()
    );

    if ($blockers !== []) {
        foreach ($blockers as $message) {
            flash_add('warning', $message);
        }
        redirect('admin/system/users.php');
    }

    db()->prepare('UPDATE users SET role = :role, is_active = :is_active WHERE id = :id')->execute([
        ':role' => $updates['role'],
        ':is_active' => $updates['is_active'],
        ':id' => $userId,
    ]);
    log_action('user_updated', 'users', $userId, ['role' => $row['role'], 'is_active' => $row['is_active']], ['role' => $updates['role'], 'is_active' => $updates['is_active']]);
    flash_add('success', 'The user record was updated.');
    redirect('admin/system/users.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'reset_password') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $accountStatement = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $accountStatement->execute([':id' => $userId]);
    $account = $accountStatement->fetch();
    if (!$account) {
        flash_add('error', 'The selected user could not be found.');
        redirect('admin/system/users.php');
    }

    $resetRule = user_password_reset_allowed($userId, (int) ($_SESSION['user_id'] ?? 0));
    if (!$resetRule['allowed']) {
        flash_add('error', $resetRule['message']);
        redirect('admin/system/users.php');
    }

    $tempPassword = generate_temporary_password();
    db()->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 1 WHERE id = :id')->execute([
        ':password_hash' => hash_password($tempPassword),
        ':id' => $userId,
    ]);
    log_action('password_reset', 'users', $userId, null, ['username' => $account['username']]);
    flash_add('success', 'Password reset. Temporary password: ' . $tempPassword . '.');
    redirect('admin/system/users.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'toggle_account') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $targetStatement = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $targetStatement->execute([':id' => $userId]);
    $row = $targetStatement->fetch();
    if (!$row) {
        flash_add('error', 'The selected user could not be found.');
        redirect('admin/system/users.php');
    }

    $requestedState = !empty($_POST['is_active']) ? 1 : 0;
    $superAdminCountStatement = db()->prepare('SELECT COUNT(*) FROM users WHERE role = :role AND is_active = 1');
    $superAdminCountStatement->execute([':role' => 'super_admin']);
    $blockers = user_change_blockers(
        $row,
        ['role' => $row['role'], 'is_active' => $requestedState],
        (int) ($_SESSION['user_id'] ?? 0),
        (int) $superAdminCountStatement->fetchColumn(),
        0,
        0
    );

    if ($blockers !== []) {
        foreach ($blockers as $message) {
            flash_add('warning', $message);
        }
        redirect('admin/system/users.php');
    }

    db()->prepare('UPDATE users SET is_active = :is_active WHERE id = :id')->execute([
        ':is_active' => $requestedState,
        ':id' => $userId,
    ]);
    log_action('user_status_changed', 'users', $userId, ['is_active' => $row['is_active']], ['is_active' => $requestedState]);
    flash_add('success', 'The account status was updated.');
    redirect('admin/system/users.php');
}

$users = db()->query('SELECT * FROM users ORDER BY role, full_name, id')->fetchAll();
$page_title = 'User management';
$page_description = 'Manage accounts, roles, activation status and temporary passwords.';
require BASE_PATH . '/components/header.php';
?>
<section class="card">
    <h2 class="card-title">Add a user</h2>
    <form method="post" action="<?= h(url('admin/system/users.php')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">
        <div class="form-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" required maxlength="30" pattern="[A-Za-z0-9._-]+" autocomplete="off">
        </div>
        <div class="form-field">
            <label for="full_name">Full name</label>
            <input id="full_name" name="full_name" type="text" required maxlength="120">
        </div>
        <div class="form-field">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="teacher">Teacher</option>
                <option value="admin">Admin</option>
                <option value="super_admin">Super admin</option>
            </select>
        </div>
        <div class="form-field" id="password-mode-field">
            <label for="password-mode">Password</label>
            <select id="password-mode" name="password_mode">
                <option value="generate" selected>Generate a temporary password</option>
                <option value="manual">I will type one</option>
            </select>
        </div>
        <div class="form-field" id="manual-password-field" style="display:none;">
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password">
        </div>
        <div class="form-field checkbox-row" id="attendance-field">
            <label><input type="checkbox" name="hrt_can_enter_attendance" value="1"> Class teacher can enter attendance</label>
        </div>
        <div class="form-field checkbox-row">
            <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
        </div>
        <button class="button" type="submit">Create user</button>
    </form>
    <script>
        (() => {
            const roleField = document.getElementById('role');
            const attendanceField = document.getElementById('attendance-field');
            const passwordMode = document.getElementById('password-mode');
            const manualPasswordField = document.getElementById('manual-password-field');
            const updateTeacherControls = () => {
                const isTeacher = roleField && roleField.value === 'teacher';
                if (attendanceField) {
                    attendanceField.style.display = isTeacher ? '' : 'none';
                }
            };
            const updatePasswordControls = () => {
                if (passwordMode && manualPasswordField) {
                    const showManual = passwordMode.value === 'manual';
                    manualPasswordField.style.display = showManual ? '' : 'none';
                    if (!showManual) {
                        const input = document.getElementById('new_password');
                        if (input) {
                            input.value = '';
                        }
                    }
                }
            };
            if (roleField) {
                roleField.addEventListener('change', updateTeacherControls);
            }
            if (passwordMode) {
                passwordMode.addEventListener('change', updatePasswordControls);
            }
            updateTeacherControls();
            updatePasswordControls();
        })();
    </script>
</section>
<section class="card">
    <h2 class="card-title">Accounts</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Must change password</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($users as $userRow): ?>
                    <tr>
                        <td><?= h($userRow['full_name']) ?></td>
                        <td><?= h($userRow['username']) ?></td>
                        <td><?= h(str_replace('_', ' ', $userRow['role'])) ?></td>
                        <td><?= (int) $userRow['is_active'] === 1 ? '<span class="badge grade-a">Active</span>' : '<span class="badge grade-incomplete">Disabled</span>' ?></td>
                        <td><?= (int) $userRow['must_change_password'] === 1 ? 'Yes' : 'No' ?></td>
                        <td>
                            <form method="post" action="<?= h(url('admin/system/users.php')) ?>" class="inline-form" data-confirm="Reset the password for <?= h($userRow['full_name']) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= h((string) $userRow['id']) ?>">
                                <button class="button button-secondary button-small" type="submit">Reset password</button>
                            </form>
                            <form method="post" action="<?= h(url('admin/system/users.php')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_account">
                                <input type="hidden" name="user_id" value="<?= h((string) $userRow['id']) ?>">
                                <input type="hidden" name="is_active" value="0">
                                <button class="button button-secondary button-small" type="submit" <?= ((int) $userRow['id'] === (int) ($_SESSION['user_id'] ?? 0)) ? 'disabled' : '' ?>>Disable</button>
                            </form>
                            <form method="post" action="<?= h(url('admin/system/users.php')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_account">
                                <input type="hidden" name="user_id" value="<?= h((string) $userRow['id']) ?>">
                                <input type="hidden" name="is_active" value="1">
                                <button class="button button-secondary button-small" type="submit" <?= ((int) $userRow['id'] === (int) ($_SESSION['user_id'] ?? 0)) ? 'disabled' : '' ?>>Enable</button>
                            </form>
                            <form method="post" action="<?= h(url('admin/system/users.php')) ?>" class="inline-form" data-confirm="Save the role and status changes?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?= h((string) $userRow['id']) ?>">
                                <select name="role">
                                    <option value="teacher" <?= $userRow['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                                    <option value="admin" <?= $userRow['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="super_admin" <?= $userRow['role'] === 'super_admin' ? 'selected' : '' ?>>Super admin</option>
                                </select>
                                <label><input type="checkbox" name="is_active" value="1" <?= (int) $userRow['is_active'] === 1 ? 'checked' : '' ?>> Active</label>
                                <button class="button button-small" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
