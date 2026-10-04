<?php
require_once __DIR__ . '/../includes/admin_auth.php';

if (admin_logged_in()) redirect('/admin/index.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    [$ok, $wait, $why] = array_pad(attempt_admin_login($username, $password), 3, null);
    if ($ok) {
        redirect('/admin/index.php');
    }
    $error = $wait !== null
        ? 'Too many login attempts. Please try again in ' . ceil($wait / 60) . ' minute(s).'
        : ($why === 'disabled' ? 'This account has been disabled. Please contact the store owner.' : 'Invalid username or password.');
}
if ($error === '' || $error === null) { $__fl = flash_get(); if ($__fl) $error = $__fl[0]['message']; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin login · <?= e(store_name()) ?></title>
<?= brand_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<div class="login-shell">
  <div class="login-card">
    <span class="brand"><?= brand_logo() ? '<img src="' . e(brand_logo()) . '" alt="' . e(store_name()) . '" class="login-logo">' : ui_icon('gear', 20) . ' ' . e(store_name()) ?></span>
    <div class="sub">Admin portal</div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label for="username">Username</label><input id="username" name="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required></div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Log in</button>
    </form>
  </div>
</div>
</body>
</html>
