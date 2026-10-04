<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$uid = (int) ($_GET['uid'] ?? $_POST['uid'] ?? 0);
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$valid = valid_password_reset($uid, $token) !== null;
$error = null;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'The two passwords don\'t match.';
    } elseif (complete_password_reset($uid, $token, $new)) {
        flash_set('success', 'Your password has been changed. Log in with your new password.');
        redirect('/login');
    } else {
        $valid = false;
    }
}

$pageTitle = 'Choose a new password';
require __DIR__ . '/includes/header.php';
?>
<div class="wrap">
  <div class="form-card form-narrow">
    <?php if (!$valid): ?>
      <div style="text-align:center;">
        <div class="icon" style="color:var(--rust);"><?= ui_icon('alert', 40) ?></div>
        <h2 style="margin:10px 0 8px;">Link invalid or expired</h2>
        <p style="color:var(--ink-soft);font-size:0.92rem;">Reset links work once and expire after an hour. Request a new one and try again.</p>
        <a class="btn btn-primary" href="/forgot-password" style="margin-top:14px;">Request a new link</a>
      </div>
    <?php else: ?>
      <h2 style="text-align:center;margin-bottom:6px;">Choose a new password</h2>
      <p style="text-align:center;color:var(--ink-soft);margin-bottom:26px;font-size:0.9rem;">At least 8 characters.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="uid" value="<?= (int) $uid ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="new_password">New password</label><input type="password" id="new_password" name="new_password" required minlength="8" autofocus autocomplete="new-password"></div>
        <div class="field"><label for="confirm_password">Confirm new password</label><input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
        <button type="submit" class="btn btn-primary btn-block">Save new password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
