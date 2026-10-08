<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (!is_post()) {
    redirect('index.php');
}

csrf_require();
logout_user();
flash_add('success', 'You have been logged out.');
redirect('login.php');

