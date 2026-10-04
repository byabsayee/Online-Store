<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// A bare link to /logout used to sign people out, so any web page could log a customer out with a hidden
// image. The links now carry the session's CSRF token; a request without it is simply sent home.
$token = (string) ($_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '');
if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
    logout_user();
    flash_set('success', 'You have been logged out.');
}
redirect('/');
