<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $user = current_user();
    if ((int) ($user['email_verified'] ?? 0) === 1) {
        flash_set('info', 'Your email is already verified.');
    } elseif (resend_verification_email((int) $user['id'])) {
        flash_set('success', 'Verification email sent — check your inbox.');
    } else {
        flash_set('error', 'Please wait a couple of minutes before requesting another verification email.');
    }
}

redirect(safe_local_path($_SERVER['HTTP_REFERER'] ?? null, '/account'));
