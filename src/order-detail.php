<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$orderNumber = (string) ($_GET['order'] ?? '');
$order = order_for_viewer($orderNumber);
if (!$order && !$user) {
    // Not signed in and no guest access to this order: ask them to log in (or track it with their email).
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/orders';
    flash_set('info', 'Log in to see this order, or track it with your order number and email.');
    redirect('/orders');
}
$__guestView = $order && empty($order['user_id']);

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="wrap section"><div class="empty-state"><h2>Order not found</h2><a class="btn btn-primary" href="/orders">Back to orders</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$itemsStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemsStmt->execute([$order['id']]);
$items = $itemsStmt->fetchAll();

// Customer view of the timeline: status, note and time only. Who made a change is admin-only information.
$history = order_status_history($order['id']);

// Items from a shipped/completed order can be reviewed: map product id → slug for the "Write a review" link.
$reviewSlugs = [];
if (in_array($order['status'], ['shipped', 'completed'], true)) {
    $ids = array_values(array_filter(array_map(fn ($i) => (int) $i['product_id'], $items)));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $ps = db()->prepare("SELECT id, slug FROM products WHERE is_active = 1 AND id IN ($in)");
        $ps->execute($ids);
        foreach ($ps->fetchAll() as $row) $reviewSlugs[(int) $row['id']] = $row['slug'];
    }
}
$statusLabels = ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

$__activeAccountTab = 'orders';
$pageTitle = (order_invoice_differs($order) ? 'Invoice ' : 'Order ') . order_invoice_id($order);
require __DIR__ . '/includes/header.php';
?>

<div class="page-header wrap"><span class="eyebrow"><?= order_invoice_differs($order) ? 'Invoice' : 'Order' ?></span><h1 class="mono" style="font-family:var(--font-mono);font-size:1.6rem;"><?= e(order_invoice_id($order)) ?></h1><?php if (order_invoice_differs($order)): ?><div class="mono" style="color:var(--ink-faint);font-size:0.85rem;margin-top:4px;">Order <?= e($order['order_number']) ?></div><?php endif; ?></div>

<div class="wrap <?= $__guestView ? '' : 'account-layout' ?>" <?= $__guestView ? 'style="max-width:820px;"' : '' ?>>
  <?php if (!$__guestView) include __DIR__ . '/includes/account_nav.php'; ?>

  <div>
    <div class="panel" style="padding:20px;margin-bottom:20px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;">
      <div><div style="font-size:0.78rem;color:var(--ink-faint);">Status</div><span class="status-pill status-<?= e($order['status']) ?>"><?= e(ucfirst($order['status'])) ?></span></div>
      <div><div style="font-size:0.78rem;color:var(--ink-faint);">Placed on</div><?= fmt_dt($order['created_at'], 'd M Y, H:i') ?></div>
      <div><div style="font-size:0.78rem;color:var(--ink-faint);">Payment</div><?= e(payment_method_label($order['payment_method'])) ?></div>
      <div><div style="font-size:0.78rem;color:var(--ink-faint);">Total</div><strong class="mono"><?= money($order['total']) ?></strong></div>
    </div>

    <div class="form-card" style="margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="margin:0;">Items</h3>
        <a href="<?= e(invoice_url($order['order_number'])) ?>" target="_blank" class="btn btn-outline btn-sm"><?= ui_icon('file', 16) ?> Download invoice</a>
      </div>
      <div class="table-scroll"><table class="data-table items-table">
        <thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['product_name']) ?><?php if (!empty($it['is_preorder'])): ?> <span class="pill pill-brass" style="font-size:11px;">Pre-order</span><?php endif; ?><?php if (!empty($it['variant_label'])): ?><br><span style="color:var(--ink-faint);font-size:0.82rem;"><?= e($it['variant_label']) ?></span><?php endif; ?><?php if ($w = warranty_label($it['warranty_days'] ?? null)): ?><br><span style="color:var(--ink-faint);font-size:0.82rem;"><?= e($w) ?></span><?php endif; ?><?php if (!empty($reviewSlugs[$it['product_id']] ?? null)): ?><br><a class="review-link" href="<?= e(product_url(['slug' => $reviewSlugs[$it['product_id']]])) ?>#reviews"><?= ui_icon('star', 14) ?> Write a review</a><?php endif; ?></td><td class="mono"><?= money($it['price']) ?></td><td><?= (int)$it['quantity'] ?></td><td class="mono"><?= money($it['subtotal']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="summary-row"><span>Subtotal</span><span class="val"><?= money($order['subtotal']) ?></span></div>
      <?php if ((float) $order['discount'] > 0): ?><div class="summary-row discount-row"><span>Discount<?= $order['coupon_code'] ? ' <small class="coupon-tag">' . e($order['coupon_code']) . '</small>' : '' ?></span><span class="val">&minus;<?= money($order['discount']) ?></span></div><?php endif; ?>
      <div class="summary-row"><span>Shipping (<?= e(delivery_area_label($order['delivery_area'])) ?>)</span><span class="val"><?= $order['shipping_fee'] > 0 ? money($order['shipping_fee']) : 'Free' ?></span></div>
      <div class="summary-row total"><span>Total</span><span class="val"><?= money($order['total']) ?></span></div>
    </div>

    <div class="form-card" style="margin-bottom:20px;">
      <h3 style="margin-bottom:10px;">Order tracking</h3>
      <?php if (!$history): ?>
        <p style="color:var(--ink-faint);">No status updates yet — we'll update this as soon as your order moves.</p>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($history as $h): ?>
            <li>
              <strong><?= e($statusLabels[$h['status']] ?? ucfirst($h['status'])) ?></strong>
              <span style="color:var(--ink-faint);"> — <?= e(fmt_dt($h['changed_at'], 'j M Y, g:i A')) ?></span>
              <?php if ($h['note']): ?><div style="color:var(--ink-faint);font-size:0.85rem;"><?= e($h['note']) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="form-card">
      <h3 style="margin-bottom:10px;">Shipping to</h3>
      <p style="color:var(--ink-soft);"><?= e($order['shipping_name']) ?> · <?= e($order['shipping_phone']) ?><br>
      <?= e($order['shipping_line1']) ?>, <?= e($order['shipping_city']) ?><?= $order['shipping_state'] ? ', ' . e($order['shipping_state']) : '' ?><?= $order['shipping_zip'] ? ' ' . e($order['shipping_zip']) : '' ?></p>

      <?php if (empty($order['billing_same_as_shipping'])): ?>
        <h3 style="margin:18px 0 10px;">Billing address</h3>
        <p style="color:var(--ink-soft);"><?= e($order['billing_name']) ?> · <?= e($order['billing_phone']) ?><br>
        <?= e($order['billing_line1']) ?>, <?= e($order['billing_city']) ?><?= $order['billing_state'] ? ', ' . e($order['billing_state']) : '' ?><?= $order['billing_zip'] ? ' ' . e($order['billing_zip']) : '' ?></p>
      <?php endif; ?>

      <?php if ($order['notes']): ?><p style="color:var(--ink-soft);"><strong>Notes:</strong> <?= e($order['notes']) ?></p><?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
