<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';
require_login();

$user = current_user();
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if (strlen($name) < 2) {
            $errors[] = 'Please enter your full name.';
        } else {
            db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $user['id']]);
            try { erp_customer_profile_updated((int) $user['id'], $name, $phone ?: null); } catch (Throwable $e) { error_log('[erp] profile sync: ' . $e->getMessage()); }
            $success = 'Profile updated.';
            $user = fetch_user_row((int) $user['id']);
        }
    } elseif ($action === 'update_prefs') {
        $promo = !empty($_POST['promo_emails']) ? 1 : 0;
        db()->prepare('UPDATE users SET promo_emails = ? WHERE id = ?')->execute([$promo, $user['id']]);
        $success = $promo ? 'You\'ll get offers and new-arrival emails.' : 'Promotional emails are off. Order and account emails will still reach you.';
        $user = fetch_user_row((int) $_SESSION['user_id']);
    } elseif ($action === 'set_password' && (int) $user['has_password'] === 0) {
        // Google-only account adding a password (they are already signed in, so no current password to ask for).
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ?, has_password = 1 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            $success = 'Password added. You can now log in with your email and password as well as Google.';
            $user = fetch_user_row((int) $_SESSION['user_id']);
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($current, $hash)) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            $success = 'Password changed.';
        }
    }
}

$orderCountStmt = db()->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
$orderCountStmt->execute([$user['id']]);
$orderCount = (int) $orderCountStmt->fetchColumn();

$favCountStmt = db()->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$favCountStmt->execute([$user['id']]);
$favCount = (int) $favCountStmt->fetchColumn();

$__activeAccountTab = 'overview';
$pageTitle = 'My account';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header wrap"><span class="eyebrow">Account</span><h1>Hi, <?= e(explode(' ', $user['name'])[0]) ?></h1></div>

<div class="wrap account-layout">
  <?php include __DIR__ . '/includes/account_nav.php'; ?>

  <div>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="stat-grid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:26px;">
      <div class="panel" style="padding:20px;"><div class="mono" style="color:var(--ink-faint);font-size:0.75rem;text-transform:uppercase;">Orders placed</div><div style="font-family:var(--font-mono);font-size:1.8rem;font-weight:700;margin-top:6px;"><?= $orderCount ?></div></div>
      <div class="panel" style="padding:20px;"><div class="mono" style="color:var(--ink-faint);font-size:0.75rem;text-transform:uppercase;">Wishlist items</div><div style="font-family:var(--font-mono);font-size:1.8rem;font-weight:700;margin-top:6px;"><?= $favCount ?></div></div>
    </div>

    <div class="form-card" style="margin-bottom:22px;">
      <h3 style="margin-bottom:16px;">Profile details</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="field-row">
          <div class="field"><label for="name">Full name</label><input id="name" name="name" required value="<?= e($user['name']) ?>"></div>
          <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= e($user['phone'] ?? '') ?>"></div>
        </div>
        <div class="field"><label>Email</label><input value="<?= e($user['email']) ?>" disabled></div>
        <button type="submit" class="btn btn-primary">Save changes</button>
      </form>
    </div>

    <div class="form-card" style="margin-bottom:22px;">
      <h3 style="margin-bottom:6px;">Email preferences</h3>
      <p style="color:var(--ink-soft);font-size:0.88rem;margin-bottom:14px;">Order confirmations, shipping updates and account emails are always sent. This switch only controls offers and new-arrival emails.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_prefs">
        <label class="switch" style="margin-bottom:16px;">
          <input type="checkbox" name="promo_emails" value="1" <?= !empty($user['promo_emails']) ? 'checked' : '' ?>><span class="track"></span>
          <span>Send me promotional emails</span>
        </label>
        <div><button type="submit" class="btn btn-primary">Save preferences</button></div>
      </form>
    </div>

    <div class="form-card" style="margin-bottom:22px;">
      <h3 style="margin-bottom:12px;">How you sign in</h3>
      <ul style="list-style:none;padding:0;margin:0;display:grid;gap:8px;font-size:0.92rem;">
        <li><strong>Email &amp; password:</strong> <?= (int) $user['has_password'] === 1 ? 'set' : 'not set yet' ?></li>
        <li><strong>Google:</strong> <?= !empty($user['google_id']) ? 'connected' : 'not connected' ?><?php if (empty($user['google_id']) && google_enabled()): ?> — sign in with Google once using <em><?= e($user['email']) ?></em> and it links automatically<?php endif; ?></li>
        <li><strong>Email address:</strong> <?= e($user['email']) ?> <?= (int) $user['email_verified'] === 1 ? '<span class="pill pill-sage" style="font-size:11px;">verified</span>' : '<span class="pill pill-brass" style="font-size:11px;">not verified</span>' ?></li>
      </ul>
    </div>

    <?php if ((int) $user['has_password'] === 0): ?>
    <div class="form-card">
      <h3 style="margin-bottom:6px;">Add a password</h3>
      <p style="color:var(--ink-soft);font-size:0.88rem;margin-bottom:14px;">Optional — lets you also log in with your email and password.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_password">
        <div class="field-row">
          <div class="field"><label for="new_password">New password</label><input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"></div>
          <div class="field"><label for="confirm_password">Confirm password</label><input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
        </div>
        <button type="submit" class="btn btn-primary">Add password</button>
      </form>
    </div>
    <?php else: ?>
    <div class="form-card">
      <h3 style="margin-bottom:16px;">Change password</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="field"><label for="current_password">Current password</label><input type="password" id="current_password" name="current_password" required></div>
        <div class="field-row">
          <div class="field"><label for="new_password">New password</label><input type="password" id="new_password" name="new_password" required minlength="8"></div>
          <div class="field"><label for="confirm_password">Confirm new password</label><input type="password" id="confirm_password" name="confirm_password" required minlength="8"></div>
        </div>
        <button type="submit" class="btn btn-primary">Update password</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
