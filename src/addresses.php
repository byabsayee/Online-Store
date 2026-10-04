<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $label = trim($_POST['label'] ?? 'Home') ?: 'Home';
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $line1 = trim($_POST['line1'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip = trim($_POST['zip'] ?? '');
        $makeDefault = !empty($_POST['is_default']);

        if ($fullName === '' || $phone === '' || $line1 === '' || $city === '') {
            $errors[] = 'Please fill in all required fields.';
        } else {
            if ($makeDefault) {
                db()->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$user['id']]);
            }
            db()->prepare(
                'INSERT INTO addresses (user_id, label, full_name, phone, line1, city, state, zip, is_default) VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$user['id'], $label, $fullName, $phone, $line1, $city, $state ?: null, $zip ?: null, $makeDefault ? 1 : 0]);
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['address_id'] ?? 0);
        db()->prepare('DELETE FROM addresses WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
    } elseif ($action === 'make_default') {
        $id = (int) ($_POST['address_id'] ?? 0);
        db()->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$user['id']]);
        db()->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
    }
}

$stmt = db()->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC');
$stmt->execute([$user['id']]);
$addresses = $stmt->fetchAll();

$__activeAccountTab = 'addresses';
$pageTitle = 'Addresses';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header wrap"><span class="eyebrow">Account</span><h1>Your addresses</h1></div>

<div class="wrap account-layout">
  <?php include __DIR__ . '/includes/account_nav.php'; ?>

  <div>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <?php if ($addresses): ?>
      <div style="display:grid;gap:14px;margin-bottom:26px;">
        <?php foreach ($addresses as $a): ?>
          <div class="panel" style="padding:18px 20px;">
            <div style="display:flex;justify-content:space-between;align-items:start;gap:14px;flex-wrap:wrap;">
              <div>
                <strong><?= e($a['label']) ?></strong> <?php if ($a['is_default']): ?><span class="pill pill-sage">Default</span><?php endif; ?>
                <p style="margin:6px 0 0;color:var(--ink-soft);font-size:0.9rem;"><?= e($a['full_name']) ?> · <?= e($a['phone']) ?><br>
                <?= e($a['line1']) ?>, <?= e($a['city']) ?><?= $a['state'] ? ', ' . e($a['state']) : '' ?><?= $a['zip'] ? ' ' . e($a['zip']) : '' ?></p>
              </div>
              <div style="display:flex;gap:8px;flex-shrink:0;">
                <?php if (!$a['is_default']): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="make_default"><input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-outline btn-sm">Make default</button></form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Delete this address?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm">Delete</button></form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="form-card">
      <h3 style="margin-bottom:16px;">Add a new address</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="field-row">
          <div class="field"><label for="label">Label</label><input id="label" name="label" placeholder="Home, Office…" value="Home"></div>
          <div class="field"><label for="full_name">Recipient full name</label><input id="full_name" name="full_name" required></div>
        </div>
        <div class="field-row">
          <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" required></div>
          <div class="field"><label for="city">City</label><input id="city" name="city" required></div>
        </div>
        <div class="field"><label for="line1">Street address</label><input id="line1" name="line1" required></div>
        <div class="field-row">
          <div class="field"><label for="state">State / Division</label><input id="state" name="state"></div>
          <div class="field"><label for="zip">ZIP / postal code</label><input id="zip" name="zip"></div>
        </div>
        <div class="checkbox-row" style="margin-bottom:16px;"><input type="checkbox" id="is_default" name="is_default"><label for="is_default" style="margin:0;font-weight:400;">Set as default address</label></div>
        <button type="submit" class="btn btn-primary">Save address</button>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
