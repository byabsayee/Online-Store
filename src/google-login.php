<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

if (is_logged_in()) redirect('/account');
google_login_start($_GET['next'] ?? null);
