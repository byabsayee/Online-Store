<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
// Sign-out needs the CSRF token (sent as ?csrf_token= on the header link or as a POST field), so
// another website can't sign an admin out by embedding this address in an image tag.
$token = (string) ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
if (admin_logged_in() && (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token))) {
    redirect('/admin/index.php');
}
admin_logout();
redirect('/admin/login.php');
