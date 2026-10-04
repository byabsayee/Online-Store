<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

if (is_logged_in()) redirect('/account');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $promo = !empty($_POST['promo_emails']);

    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        [$ok, $msg] = register_user($name, $email, $password, $phone, $promo);
        if ($ok) {
            flash_set('success', 'Account created — welcome! We\'ve emailed you a link to confirm your address.');
            $next = safe_local_path($_SESSION['redirect_after_login'] ?? null, '/account');
            unset($_SESSION['redirect_after_login']);
            redirect($next);
        }
        $error = $msg;
    }
}

$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$prefEmail = $_POST['email'] ?? ($_GET['email'] ?? '');
$pageTitle = 'Create account';
require __DIR__ . '/includes/header.php';
?>
<div class="wrap">
  <div class="form-card form-narrow">
    <h2 style="text-align:center;margin-bottom:6px;">Create your account</h2>
    <p style="text-align:center;color:var(--ink-soft);margin-bottom:26px;font-size:0.9rem;">Save addresses, track orders, and build a wishlist.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if (google_enabled()): ?>
      <?= google_button_html('Sign up with Google') ?>
      <div class="or-divider"><span>or sign up with email</span></div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label for="name">Full name</label>
        <input id="name" name="name" required <?= google_enabled() ? '' : 'autofocus' ?> autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email" value="<?= e($prefEmail) ?>">
        <div class="hint">Orders you've placed as a guest with this email are added to your account once you confirm it.</div>
      </div>
      <div class="field">
        <label for="phone">Phone (optional)</label>
        <input id="phone" name="phone" autocomplete="tel" value="<?= e($_POST['phone'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        <div class="hint">At least 8 characters.</div>
      </div>
      <div class="field">
        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
      </div>
      <label class="switch" style="margin-bottom:18px;font-size:0.88rem;">
        <input type="checkbox" name="promo_emails" value="1" <?= (!$posted || !empty($_POST['promo_emails'])) ? 'checked' : '' ?>><span class="track"></span>
        <span>Email me offers and new arrivals <span style="color:var(--ink-faint);">(you can switch this off any time)</span></span>
      </label>
      <button type="submit" class="btn btn-primary btn-block">Create account</button>
    </form>
    <div class="form-foot">Already have an account? <a href="/login">Log in</a></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
