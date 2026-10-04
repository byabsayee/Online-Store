<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) redirect('/account');

$sent = false;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Same 5-per-5-minutes guard as sign-in, so this can't be used to flood someone's inbox.
        $wait = login_throttle_check('reset', $email);
        if ($wait !== null) {
            $error = 'Too many requests. Please try again in ' . ceil($wait / 60) . ' minute(s).';
        } else {
            login_throttle_hit('reset', $email);
            request_password_reset($email);
            $sent = true; // always the same answer, whether or not the address has an account
        }
    }
}

$pageTitle = 'Forgot password';
require __DIR__ . '/includes/header.php';
?>
<div class="wrap">
  <div class="form-card form-narrow">
    <?php if ($sent): ?>
      <div style="text-align:center;">
        <div class="icon" style="color:var(--accent-text);"><?= ui_icon('mail', 40) ?></div>
        <h2 style="margin:10px 0 8px;">Check your email</h2>
        <p style="color:var(--ink-soft);font-size:0.92rem;">If there's an account for <strong><?= e($email) ?></strong>, we've sent a link to choose a new password. It works for one hour. Nothing arrived? Check your spam folder.</p>
        <a class="btn btn-outline" href="/login" style="margin-top:14px;">Back to log in</a>
      </div>
    <?php else: ?>
      <h2 style="text-align:center;margin-bottom:6px;">Forgot your password?</h2>
      <p style="text-align:center;color:var(--ink-soft);margin-bottom:26px;font-size:0.9rem;">Enter your email and we'll send you a link to set a new one. Signed up with Google? This also lets you add a password.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required autofocus autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
      </form>
      <div class="form-foot"><a href="/login">Back to log in</a></div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
