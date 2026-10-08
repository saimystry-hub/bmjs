<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$username = '';
$error = '';

if (is_post()) {
    $username = trim((string) ($_POST['username'] ?? ''));
    csrf_require();

    $result = attempt_login($username, (string) ($_POST['password'] ?? ''));
    if ($result['ok']) {
        redirect(safe_next($_GET['next'] ?? ''));
    }

    $error = (string) $result['error'];
}

$page_title = 'Log in';
$page_description = 'Log in to BmJS.';
$show_chrome = false;
$schoolName = (string) setting('school_name', 'Benchmark Junior School');
$motto = (string) setting('motto', 'Nurturing confident learners.');

require BASE_PATH . '/components/header.php';
?>
<section class="login-layout">
    <div class="login-panel">
        <div class="login-brand">
            <img src="<?= h(asset('img/logo.png')) ?>" alt="Benchmark Junior School logo">
            <div>
                <h1><?= h($schoolName) ?></h1>
                <p><?= h($motto) ?></p>
            </div>
        </div>
    </div>
    <div class="login-card">
        <h2 class="card-title">Log in</h2>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= h(url('login.php?next=' . rawurlencode($_GET['next'] ?? ''))) ?>" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= h($_GET['next'] ?? '') ?>">
            <div class="form-field">
                <label for="login-username">Username</label>
                <input id="login-username" name="username" type="text" value="<?= h($username) ?>" autocomplete="username" autofocus required>
            </div>
            <div class="form-field">
                <label for="login-password">Password</label>
                <div class="password-box">
                    <input id="login-password" name="password" type="password" autocomplete="current-password" required>
                    <button type="button" class="button button-secondary button-small" data-password-toggle aria-label="Show password">Show</button>
                </div>
            </div>
            <button class="button" type="submit">Log in</button>
        </form>
        <p class="help-text">Forgot your password? Please ask the school administrator.</p>
    </div>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
