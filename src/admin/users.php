<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pageTitle = 'Customers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $userId = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_status') {
        $cu = db()->prepare('SELECT id, name, email, status FROM users WHERE id = ?');
        $cu->execute([$userId]);
        if ($target = $cu->fetch()) {
            db()->prepare("UPDATE users SET status = IF(status = 'active','disabled','active') WHERE id = ?")->execute([$userId]);
            $nowDisabled = $target['status'] === 'active';
            admin_log($nowDisabled ? 'customer.disable' : 'customer.enable', ($nowDisabled ? 'Disabled' : 'Re-enabled') . ' customer account ' . $target['name'] . ' (' . $target['email'] . ')', 'customer', (int) $target['id']);
            flash_set('success', $target['name'] . '\'s account is now ' . ($nowDisabled ? 'disabled.' : 'active.'));
        }
    }
    redirect('/admin/users.php');
}

$users = db()->query(
    "SELECT u.*, COUNT(o.id) AS order_count, COALESCE(SUM(o.total),0) AS lifetime_value
     FROM users u LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
     GROUP BY u.id ORDER BY u.created_at DESC"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="panel">
  <div class="panel-head"><h2>Customers (<?= count($users) ?>)</h2></div>
  <div class="table-wrap"><table class="admin-table">
    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Orders</th><th>Lifetime value</th><th>Joined</th><th>Sign-in</th><th>Offers</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$users): ?>
        <tr class="empty-row"><td colspan="10">No customers yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e($u['phone'] ?? '—') ?></td>
          <td><?= (int)$u['order_count'] ?></td>
          <td class="mono"><?= money((float)$u['lifetime_value']) ?></td>
          <td><?= fmt_dt($u['created_at'], 'd M Y') ?></td>
          <td><?= !empty($u['google_id']) ? 'Google' . ((int) $u['has_password'] ? ' + password' : '') : 'Password' ?><?= (int) $u['email_verified'] ? '' : ' <span class="pill pill-rust" title="Email not confirmed">unverified</span>' ?></td>
          <td><?= !empty($u['promo_emails']) ? '<span class="pill pill-sage">On</span>' : '<span class="muted">Off</span>' ?></td>
          <td><?= $u['status'] === 'active' ? '<span class="pill pill-sage">Active</span>' : '<span class="pill pill-rust">Disabled</span>' ?></td>
          <td>
            <form method="post" onsubmit="return confirm('<?= $u['status'] === 'active' ? 'Disable' : 'Re-enable' ?> this account?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
