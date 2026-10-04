<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

if (is_logged_in()) redirect('/account');

// A page that needs a login can send people here with ?next=/somewhere.
if (!empty($_GET['next'])) $_SESSION['redirect_after_login'] = safe_local_path($_GET['next'], '/account');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    [$ok, $wait, $why] = array_pad(attempt_login($email, $password), 3, null);
    if ($ok) {
        $redirectTo = safe_local_path($_SESSION['redirect_after_login'] ?? null, '/account');
        unset($_SESSION['redirect_after_login']);
        login_flash('Welcome back!');
        redirect($redirectTo);
    }
    if ($wait !== null) {
        $error = 'Too many login attempts. Please try again in ' . ceil($wait / 60) . ' minute(s).';
    } elseif ($why === 'google_only') {
        $error = google_enabled()
            ? 'That account was created with Google. Use "Continue with Google" below, or use "Forgot password?" to add a password.'
            : 'That account has no password yet. Use "Forgot password?" to set one.';
    } else {
        $error = 'That email and password combination doesn\'t match our records.';
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<div class="wrap">
  <div class="form-card form-narrow">
    <h2 style="text-align:center;margin-bottom:6px;">Welcome back</h2>
    <p style="text-align:center;color:var(--ink-soft);margin-bottom:26px;font-size:0.9rem;">Log in to track orders and manage your wishlist.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if (google_enabled()): ?>
      <?= google_button_html('Continue with Google') ?>
      <div class="or-divider"><span>or log in with email</span></div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required <?= google_enabled() ? '' : 'autofocus' ?> autocomplete="email" value="<?= e($_POST['email'] ?? ($_GET['email'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="password" style="display:flex;justify-content:space-between;align-items:baseline;">Password <a href="/forgot-password" style="font-weight:400;font-size:0.82rem;">Forgot password?</a></label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </form>
    <div class="form-foot">New here? <a href="/register">Create an account</a></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
