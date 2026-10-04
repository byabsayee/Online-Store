<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$orderNumber = $_SESSION['last_order_number'] ?? null;
if (!$orderNumber) {
    redirect('/');
}
unset($_SESSION['last_order_number']);

$stmt = db()->prepare('SELECT * FROM orders WHERE order_number = ?');
$stmt->execute([$orderNumber]);
$order = $stmt->fetch();
if (!$order) redirect('/');

$itemsStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemsStmt->execute([$order['id']]);
$items = $itemsStmt->fetchAll();

$pageTitle = 'Order confirmed';
require __DIR__ . '/includes/header.php';
?>

<div class="wrap section" style="max-width:720px;">
  <div class="empty-state" style="padding-top:20px;">
    <div class="icon"><?= ui_icon('check-circle', 40) ?></div>
    <h2>Thank you — your order is confirmed</h2>
    <p>Order <strong class="mono"><?= e($order['order_number']) ?></strong> has been placed and is being prepared.
    <?php if ($order['payment_method'] === 'cod'): ?>Pay by cash when it arrives.<?php else: ?>We'll be in touch about your online payment shortly.<?php endif; ?>
    Expect delivery in <?= (int)DELIVERY_DAYS_MIN ?>–<?= (int)DELIVERY_DAYS_MAX ?> days.</p>
  </div>

  <div class="form-card" style="text-align:left;">
    <h3 style="margin-bottom:14px;">Order details</h3>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Item</th><th>Qty</th><th>Subtotal</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr><td><?= e($it['product_name']) ?></td><td><?= (int)$it['quantity'] ?></td><td><?= money($it['subtotal']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="summary-row"><span>Subtotal</span><span class="val"><?= money($order['subtotal']) ?></span></div>
    <?php if ((float) $order['discount'] > 0): ?><div class="summary-row discount-row"><span>Discount<?= $order['coupon_code'] ? ' <small class="coupon-tag">' . e($order['coupon_code']) . '</small>' : '' ?></span><span class="val">&minus;<?= money($order['discount']) ?></span></div><?php endif; ?>
    <div class="summary-row"><span>Shipping (<?= e(delivery_area_label($order['delivery_area'])) ?>)</span><span class="val"><?= $order['shipping_fee'] > 0 ? money($order['shipping_fee']) : 'Free' ?></span></div>
    <div class="summary-row total"><span>Total</span><span class="val"><?= money($order['total']) ?></span></div>
    <p style="margin-top:18px;font-size:0.9rem;color:var(--ink-soft);">
      Shipping to: <?= e($order['shipping_name']) ?>, <?= e($order['shipping_line1']) ?>, <?= e($order['shipping_city']) ?> (<?= e(delivery_area_label($order['delivery_area'])) ?>)
    </p>
  </div>

  <div style="text-align:center;margin-top:10px;">
    <a href="/" class="btn btn-primary">Continue shopping</a>
    <?php if (is_logged_in()): ?><a href="/orders" class="btn btn-outline">View my orders</a><?php else: ?><a href="<?= e(order_url($order['order_number'])) ?>" class="btn btn-outline">Track this order</a><?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
