<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $admin = current_admin();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([$admin['id']]);
    $hash = (string) $stmt->fetchColumn();

    // Guard against password guessing through this form as well.
    $wait = login_throttle_check('admin_pw', (string) $admin['username']);
    if ($wait !== null) {
        $errors[] = 'Too many attempts. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).';
    } elseif (!password_verify($current, $hash)) {
        login_throttle_hit('admin_pw', (string) $admin['username']);
        $errors[] = 'Your current password is incorrect.';
    } elseif (strlen($new) < 10) {
        $errors[] = 'Choose a new password of at least 10 characters.';
    } elseif ($new === 'ChangeMe123!' || $new === $current) {
        $errors[] = 'The new password must be different from the old one.';
    } elseif ($new !== $confirm) {
        $errors[] = 'The two new passwords don\'t match.';
    } else {
        db()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        login_throttle_clear('admin_pw', (string) $admin['username']);
        session_regenerate_id(true);
        admin_log('auth.password', 'Changed their own password');
        flash_set('success', 'Password changed.');
        redirect('/admin/account.php');
    }
}

$admin = current_admin();
$mine = staff_get((int) $admin['id']);
$myDoc = staff_document_meta((int) $admin['id']);
$pageTitle = 'My account';
require __DIR__ . '/includes/header.php';
$val = fn ($v) => ($v !== null && $v !== '') ? e((string) $v) : '<span class="muted">Not added yet</span>';
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="panel" style="max-width:720px;">
  <div class="panel-head"><h2>My details</h2><span class="pill <?= $mine['role'] === 'owner' ? 'pill-brass' : 'pill-ink' ?>"><?= $mine['role'] === 'owner' ? 'Owner' : 'Staff' ?></span></div>
  <div class="panel-body">
    <div class="profile-head">
      <?= staff_avatar_html((int) $admin['id'], $mine['name'], $mine['photo_v'], 84) ?>
      <div><strong><?= e($mine['name']) ?></strong><br><span class="muted">@<?= e($mine['username']) ?></span></div>
    </div>
    <dl class="detail-list">
      <div><dt>Name</dt><dd><?= $val($mine['name']) ?></dd></div>
      <div><dt>Username</dt><dd class="mono"><?= $val($mine['username']) ?></dd></div>
      <div><dt>Number</dt><dd><?= $val($mine['phone']) ?></dd></div>
      <div><dt>Email</dt><dd><?= $val($mine['email']) ?></dd></div>
      <div><dt>Gender</dt><dd><?= $val($mine['gender']) ?></dd></div>
      <div><dt>Blood group</dt><dd><?= $val($mine['blood_group']) ?></dd></div>
      <div><dt>Date of birth</dt><dd><?php if ($mine['date_of_birth']): ?><?= e(date('j M Y', strtotime($mine['date_of_birth']))) ?> <span class="muted small">(<?= (int) staff_age($mine['date_of_birth']) ?> years)</span><?php else: ?><span class="muted">Not added yet</span><?php endif; ?></dd></div>
      <div><dt><?= e(STAFF_ID_TYPES[$mine['id_type']] ?? 'ID') ?> number</dt><dd class="mono"><?= $val($mine['id_number']) ?></dd></div>
      <div class="wide"><dt>Facebook</dt><dd><?php if ($mine['facebook_url']): ?><a href="<?= e($mine['facebook_url']) ?>" target="_blank" rel="noopener noreferrer nofollow" style="text-decoration:underline;"><?= e(preg_replace('~^https://(www\.)?~', '', $mine['facebook_url'])) ?></a><?php else: ?><span class="muted">Not added yet</span><?php endif; ?></dd></div>
      <div><dt>Member since</dt><dd><?= e(fmt_dt($mine['created_at'], 'd M Y')) ?></dd></div>
      <div class="wide"><dt>Address</dt><dd><?= $mine['address'] ? nl2br(e($mine['address'])) : '<span class="muted">Not added yet</span>' ?></dd></div>
      <div class="wide"><dt>Document</dt><dd>
        <?php if ($myDoc): ?><?= ui_icon('paperclip', 15) ?> <strong><?= e($myDoc['original_name']) ?></strong> <span class="muted small">(<?= e(staff_human_size((int) $myDoc['size'])) ?>)</span>
          &nbsp;<a href="/admin/staff_document.php?id=<?= (int) $admin['id'] ?>" target="_blank" rel="noopener" style="text-decoration:underline;">View</a> · <a href="/admin/staff_document.php?id=<?= (int) $admin['id'] ?>&amp;download=1" style="text-decoration:underline;">Download</a>
        <?php else: ?><span class="muted">None attached</span><?php endif; ?></dd></div>
    </dl>
  </div>
  <div class="panel-foot" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <?php if (admin_is_owner()): ?>
      <a class="btn btn-outline btn-sm" href="/admin/staff_form.php?id=<?= (int) $admin['id'] ?>">Edit my details</a>
    <?php else: ?>
      <span class="help" style="margin:0;">These details can only be changed by a store owner. If something is wrong, ask them to update it.</span>
    <?php endif; ?>
  </div>
</div>

<form method="post" class="panel" style="max-width:520px;" autocomplete="off">
  <?= csrf_field() ?>
  <div class="panel-head"><h2>Change password</h2></div>
  <div class="panel-body">
    <p class="help">Signed in as <strong><?= e($admin['username']) ?></strong>. Use a long, unique password — 10 characters or more.</p>
    <div class="field"><label for="current_password">Current password</label><input type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><label for="new_password">New password</label><input type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password"></div>
    <div class="field" style="margin-bottom:0;"><label for="confirm_password">Repeat new password</label><input type="password" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password"></div>
  </div>
  <div class="panel-foot"><button class="btn btn-primary" type="submit">Update password</button></div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
