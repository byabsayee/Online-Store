<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$uid = (int) ($_GET['uid'] ?? 0);
$token = trim($_GET['token'] ?? '');
[$status, $merged] = verify_email_token($uid, $token);

$pageTitle = 'Verify email';
require __DIR__ . '/includes/header.php';
?>
<div class="wrap section">
  <div class="empty-state">
    <?php if ($status === 'success'): ?>
      <div class="icon"><?= ui_icon('check-circle', 40) ?></div>
      <h2>Email verified</h2>
      <p>Your email address is confirmed. Thanks!</p>
      <?php if ($note = merged_orders_notice($merged)): ?><p><strong><?= e($note) ?></strong></p><?php endif; ?>
      <a class="btn btn-primary" href="<?= is_logged_in() ? '/account' : '/login' ?>">Continue</a>
    <?php elseif ($status === 'already'): ?>
      <div class="icon"><?= ui_icon('check-circle', 40) ?></div>
      <h2>Already verified</h2>
      <p>This email address was already confirmed.</p>
      <a class="btn btn-primary" href="<?= is_logged_in() ? '/account' : '/login' ?>">Continue</a>
    <?php else: ?>
      <div class="icon"><?= ui_icon('alert', 40) ?></div>
      <h2>Link invalid or expired</h2>
      <p>This verification link isn't valid. If you're logged in, you can request a new one from your account page.</p>
      <a class="btn btn-primary" href="<?= is_logged_in() ? '/account' : '/login' ?>">Go to <?= is_logged_in() ? 'account' : 'login' ?></a>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
