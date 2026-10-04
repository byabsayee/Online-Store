<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';

$productCount = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$categoryCount = (int) db()->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pendingOrders = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$revenue = (float) db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'cancelled'")->fetchColumn();
// Products with variants are stocked per variant, so measure the variants' total, not products.stock.
$lowStock = db()->query(
    'SELECT id, name, stock FROM (
         SELECT p.id, p.name, p.is_active,
                CASE WHEN (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) > 0
                     THEN (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)
                     ELSE p.stock END AS stock
         FROM products p) t
     WHERE stock <= 5 AND is_active = 1 ORDER BY stock ASC LIMIT 6'
)->fetchAll();

// The seeded first-run password is public knowledge (it's in the repo) — nag until it's changed.
$usingDefaultPassword = admin_uses_default_password();
$__checklist = admin_is_owner() ? setup_checklist() : [];
$__todo = count(array_filter($__checklist, fn ($c) => !$c[0]));
$recentOrders = db()->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 8')->fetchAll();
?>

<?php if ($usingDefaultPassword): ?>
  <div class="alert alert-warn"><strong>Change your admin password.</strong> You're still using the default one that ships with the store. <a href="/admin/account.php" style="text-decoration:underline;font-weight:600;">Change it now →</a></div>
<?php endif; ?>

<?php if ($__todo > 0): ?>
<div class="panel"><div class="panel-head"><h2>Getting started</h2><span class="muted small"><?= count($__checklist) - $__todo ?> of <?= count($__checklist) ?> done</span></div>
  <div class="panel-body"><ul class="checklist" style="list-style:none;padding-left:0;margin:0;">
    <?php foreach ($__checklist as [$ok, $label, $href]): ?>
      <li><?= $ok ? '<span style="color:var(--sage,#5f7d5b)">✓</span> <span class="muted" style="text-decoration:line-through;">' . e($label) . '</span>' : '<span class="muted">○</span> <a class="link" href="' . e($href) . '">' . e($label) . '</a>' ?></li>
    <?php endforeach; ?>
  </ul></div></div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card"><div class="label">Total revenue</div><div class="value"><?= money($revenue) ?></div></div>
  <div class="stat-card"><div class="label">Pending orders</div><div class="value"><?= $pendingOrders ?></div></div>
  <div class="stat-card"><div class="label">Products</div><div class="value"><?= $productCount ?></div></div>
  <div class="stat-card"><div class="label">Customers</div><div class="value"><?= $userCount ?></div></div>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Recent orders</h2>
    <a href="/admin/orders.php" class="btn btn-outline btn-sm">View all</a>
  </div>
  <div class="table-wrap"><table class="admin-table">
    <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th><th>Date</th><th></th></tr></thead>
    <tbody>
      <?php if (!$recentOrders): ?>
        <tr class="empty-row"><td colspan="6">No orders yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td class="mono"><?= e(order_invoice_id($o)) ?></td>
          <td><?= e($o['shipping_name']) ?></td>
          <td><span class="status-pill status-<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></td>
          <td class="mono"><?= money($o['total']) ?></td>
          <td><?= fmt_dt($o['created_at'], 'd M Y') ?></td>
          <td><a href="/admin/order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-outline btn-sm">Manage</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Low stock</h2><span style="color:var(--ink-faint);font-size:0.82rem;"><?= count($lowStock) ?> item(s) at or below 5 units</span></div>
  <div class="table-wrap"><table class="admin-table">
    <thead><tr><th>Product</th><th>Stock left</th><th></th></tr></thead>
    <tbody>
      <?php if (!$lowStock): ?>
        <tr class="empty-row"><td colspan="3">Everything is well stocked.</td></tr>
      <?php endif; ?>
      <?php foreach ($lowStock as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td class="<?= $p['stock'] == 0 ? 'stock-low' : '' ?>"><?= (int)$p['stock'] ?></td>
          <td><a href="/admin/product_form.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline btn-sm">Restock</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
