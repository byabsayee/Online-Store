<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pageTitle = 'Orders';

$validStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
$status = in_array($_GET['status'] ?? '', $validStatuses, true) ? $_GET['status'] : '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

/**
 * One search box for everything you'd look an order up by: the invoice ID
 * (order number, with or without the "#" or prefix, or just a fragment of
 * it), the customer's name / phone / email, or a product in the order.
 */
$searchSql = ''; $searchParams = [];
if ($q !== '') {
    $needle = ltrim($q, "# \t");
    $like = '%' . like_escape($needle) . '%';
    $searchSql = " AND (o.order_number LIKE ? ESCAPE '|' OR o.book_invoice_no LIKE ? ESCAPE '|' OR o.shipping_name LIKE ? ESCAPE '|' OR o.shipping_phone LIKE ? ESCAPE '|'
                        OR o.customer_email LIKE ? ESCAPE '|'
                        OR EXISTS (SELECT 1 FROM users u WHERE u.id = o.user_id AND u.email LIKE ? ESCAPE '|')
                        OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.product_name LIKE ? ESCAPE '|'))";
    $searchParams = array_fill(0, 7, $like);
}

// Status tab counts reflect the current search.
$counts = ['' => 0];
foreach ($validStatuses as $s) $counts[$s] = 0;
$cs = db()->prepare("SELECT o.status, COUNT(*) c FROM orders o WHERE 1=1 $searchSql GROUP BY o.status");
$cs->execute($searchParams);
foreach ($cs->fetchAll() as $r) { $counts[$r['status']] = (int) $r['c']; $counts[''] += (int) $r['c']; }

$where = 'WHERE 1=1' . $searchSql . ($status ? ' AND o.status = ?' : '');
$params = array_merge($searchParams, $status ? [$status] : []);
$total = $counts[$status];
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o $where ORDER BY o.created_at DESC, o.id DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

function orders_url(array $over = []): string {
    $q = array_filter(array_merge($_GET, $over), fn ($v) => $v !== '');
    if (isset($q['page']) && (int) $q['page'] <= 1) unset($q['page']);
    return '/admin/orders.php' . ($q ? '?' . http_build_query($q) : '');
}
function hl_order(string $text, string $q): string {
    $needle = ltrim($q, "# \t");
    if ($needle === '') return e($text);
    $out = e($text);
    return preg_replace('/(' . preg_quote(e($needle), '/') . ')/iu', '<mark class="hit">$1</mark>', $out);
}

require __DIR__ . '/includes/header.php';
?>

<div class="panel">
  <div class="panel-head"><h2>Orders <span class="sub"><?= $counts[''] ?> <?= $q !== '' ? 'matching' : 'total' ?></span></h2></div>

  <form class="toolbar" method="get">
    <div class="search">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice ID, customer, phone, email or product…" aria-label="Search orders" autofocus>
    </div>
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline" href="<?= e(orders_url(['q' => '', 'page' => 1])) ?>">Clear</a><?php endif; ?>
    <span class="spacer"></span>
    <div class="seg">
      <a href="<?= e(orders_url(['status' => '', 'page' => 1])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All<span class="n"><?= $counts[''] ?></span></a>
      <?php foreach ($validStatuses as $s): ?>
        <a href="<?= e(orders_url(['status' => $s, 'page' => 1])) ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?><span class="n"><?= $counts[$s] ?></span></a>
      <?php endforeach; ?>
    </div>
  </form>
  <?php if ($q !== ''): ?>
    <div class="result-note">
      <?= $total ?> order<?= $total === 1 ? '' : 's' ?> found for “<strong><?= e($q) ?></strong>”<?= $status ? ' in ' . e($status) : '' ?>.
    </div>
  <?php endif; ?>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th>Invoice ID</th><th>Customer</th><th>Items</th><th>Payment</th><th>Status</th><th>Total</th><th>Placed</th><th></th></tr></thead>
      <tbody>
        <?php if (!$orders): ?>
          <tr class="empty-row"><td colspan="8"><?= $q !== '' ? 'No orders match your search.' : 'No orders found.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="mono"><a href="/admin/order_detail.php?id=<?= (int) $o['id'] ?>" style="font-weight:600;"><?= hl_order(order_invoice_id($o), $q) ?></a><?php if (order_invoice_differs($o)): ?><br><span class="muted small">Order <?= hl_order($o['order_number'], $q) ?></span><?php endif; ?></td>
            <td><?= hl_order($o['shipping_name'], $q) ?><br><span class="muted small"><?= hl_order($o['shipping_phone'], $q) ?></span></td>
            <td class="mono"><?= (int) $o['item_count'] ?></td>
            <td><?= $o['payment_method'] === 'cod' ? 'COD' : 'Advance' ?></td>
            <td><span class="status-pill status-<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></td>
            <td class="mono"><?= e(money((float) $o['total'])) ?></td>
            <td><?= e(fmt_dt($o['created_at'], 'd M Y')) ?><br><span class="muted small"><?= e(fmt_dt($o['created_at'], 'g:i A')) ?></span></td>
            <td class="actions"><a href="/admin/order_detail.php?id=<?= (int) $o['id'] ?>" class="btn btn-outline btn-sm">Manage</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= e(orders_url(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
