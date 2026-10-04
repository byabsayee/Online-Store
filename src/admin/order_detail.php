<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/order_mail.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { flash_set('error', 'Order not found.'); redirect('/admin/orders.php'); }

$validStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
$validAreas = ['inside_dhaka', 'suburbs', 'outside_dhaka'];
$detailErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'edit_details') {
    require_csrf();
    $d = [
        'shipping_name' => trim($_POST['shipping_name'] ?? ''),
        'shipping_phone' => trim($_POST['shipping_phone'] ?? ''),
        'customer_email' => trim($_POST['customer_email'] ?? ''),
        'shipping_line1' => trim($_POST['shipping_line1'] ?? ''),
        'shipping_city' => trim($_POST['shipping_city'] ?? ''),
        'shipping_state' => trim($_POST['shipping_state'] ?? ''),
        'shipping_zip' => trim($_POST['shipping_zip'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
    ];
    $deliveryArea = in_array($_POST['delivery_area'] ?? '', $validAreas, true) ? $_POST['delivery_area'] : $order['delivery_area'];
    $shippingFee = is_numeric($_POST['shipping_fee'] ?? null) ? max(0, round((float) $_POST['shipping_fee'], 2)) : (float) $order['shipping_fee'];

    if ($d['shipping_name'] === '') $detailErrors[] = 'Recipient name can\'t be empty.';
    if ($d['shipping_phone'] === '') $detailErrors[] = 'Phone can\'t be empty.';
    if ($d['customer_email'] !== '' && !filter_var($d['customer_email'], FILTER_VALIDATE_EMAIL)) $detailErrors[] = 'That email address doesn\'t look valid.';
    if ($d['shipping_line1'] === '') $detailErrors[] = 'Address line can\'t be empty.';
    if ($d['shipping_city'] === '') $detailErrors[] = 'City can\'t be empty.';

    if (!$detailErrors && abs($shippingFee - (float) $order['shipping_fee']) > 0.004 && erp_order_locked($order)) {
        $detailErrors[] = 'This order already has payments (or is completed), so its amounts are locked. Change the shipping fee by cancelling and re-creating the order, or record the difference as a return/refund.';
    }
    if (!$detailErrors) {
        $newTotal = round((float) $order['subtotal'] - (float) $order['discount'] + ($order['tax_inclusive'] ? 0 : (float) $order['tax']) + $shippingFee, 2);
        db()->prepare(
            'UPDATE orders SET shipping_name=?, shipping_phone=?, customer_email=?, shipping_line1=?, shipping_city=?, shipping_state=?, shipping_zip=?, delivery_area=?, shipping_fee=?, total=?, notes=? WHERE id=?'
        )->execute([$d['shipping_name'], $d['shipping_phone'], $d['customer_email'] ?: null, $d['shipping_line1'], $d['shipping_city'], $d['shipping_state'] ?: null, $d['shipping_zip'] ?: null, $deliveryArea, $shippingFee, $newTotal, $d['notes'] ?: null, $order['id']]);

        $diff = admin_log_diff(
            ['name' => $order['shipping_name'], 'phone' => $order['shipping_phone'], 'email' => $order['customer_email'], 'line1' => $order['shipping_line1'], 'city' => $order['shipping_city'], 'state' => $order['shipping_state'], 'zip' => $order['shipping_zip'], 'area' => delivery_area_label($order['delivery_area']), 'shipping_fee' => money((float) $order['shipping_fee']), 'notes' => $order['notes']],
            ['name' => $d['shipping_name'], 'phone' => $d['shipping_phone'], 'email' => $d['customer_email'], 'line1' => $d['shipping_line1'], 'city' => $d['shipping_city'], 'state' => $d['shipping_state'], 'zip' => $d['shipping_zip'], 'area' => delivery_area_label($deliveryArea), 'shipping_fee' => money($shippingFee), 'notes' => $d['notes']],
            ['name' => 'Recipient name', 'phone' => 'Phone', 'email' => 'Email', 'line1' => 'Address', 'city' => 'City', 'state' => 'State/area', 'zip' => 'ZIP', 'area' => 'Delivery area', 'shipping_fee' => 'Shipping fee', 'notes' => 'Notes']
        );
        erp_emit('order', (int) $order['id'], 'auto');
        admin_log('order.edit_details', 'Order ' . $order['order_number'] . ' details edited' . ($diff ? ': ' . admin_log_diff_summary($diff) : ' (saved, nothing changed)'), 'order', (int) $order['id'], $diff ? ['changes' => $diff] : []);
        flash_set('success', 'Order details updated.');
        redirect('/admin/order_detail.php?id=' . $order['id']);
    }
}

$__orderActions = ['edit_details', 'record_payment', 'void_payment', 'record_return', 'clear_attention'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form_action'] ?? '', ['record_payment', 'void_payment', 'record_return', 'clear_attention'], true)) {
    require_csrf();
    $act = $_POST['form_action']; $admin = current_admin(); $back = '/admin/order_detail.php?id=' . $order['id'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($act === 'record_payment') {
            $mid = (int) ($_POST['method_id'] ?? 0) ?: null;
            $ok = $mid ? $pdo->prepare('SELECT 1 FROM payment_methods WHERE id = ?') : null;
            if ($ok) { $ok->execute([$mid]); if (!$ok->fetchColumn()) throw new RuntimeException('Choose a payment method.'); }
            $paidAt = null;
            if (!empty($_POST['paid_at'])) { try { $paidAt = (new DateTimeImmutable((string) $_POST['paid_at'], new DateTimeZone(date_default_timezone_get())))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (Throwable $e) { throw new RuntimeException('That payment date is not valid.'); } }
            $pid = erp_payment_record((int) $order['id'], $mid, (float) ($_POST['amount'] ?? 0), $paidAt, trim($_POST['reference'] ?? '') ?: null, trim($_POST['pnote'] ?? '') ?: null, $admin['name']);
            erp_emit('payment', $pid, 'create');
            admin_log('order.payment', 'Order ' . $order['order_number'] . ': recorded a payment of ' . money((float) $_POST['amount']), 'order', (int) $order['id']);
            flash_set('success', 'Payment recorded.');
        } elseif ($act === 'void_payment') {
            $pid = (int) ($_POST['payment_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id FROM order_payments WHERE id = ? AND order_id = ?'); $chk->execute([$pid, $order['id']]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Payment not found.');
            if (erp_payment_void($pid)) { erp_emit('payment', $pid, 'void'); admin_log('order.payment_void', 'Order ' . $order['order_number'] . ': voided a payment', 'order', (int) $order['id']); flash_set('success', 'Payment voided.'); }
        } elseif ($act === 'record_return') {
            $items = [];
            foreach ((array) ($_POST['ret_qty'] ?? []) as $lineId => $qty) if ((int) $qty > 0) $items[] = ['order_item_id' => (int) $lineId, 'quantity' => (int) $qty, 'restock' => !empty($_POST['ret_restock'][$lineId])];
            $rm = (int) ($_POST['refund_method_id'] ?? 0) ?: null;
            $rid = erp_return_create((int) $order['id'], $items, trim($_POST['reason'] ?? '') ?: null, (float) ($_POST['refund_amount'] ?? 0), $rm, $admin['name']);
            erp_emit('return', $rid, 'create');
            admin_log('order.return', 'Order ' . $order['order_number'] . ': recorded a return (refund ' . money((float) ($_POST['refund_amount'] ?? 0)) . ')', 'order', (int) $order['id']);
            flash_set('success', 'Return recorded.');
        } else {
            $pdo->prepare('UPDATE orders SET attention = NULL, attention_note = NULL WHERE id = ?')->execute([$order['id']]);
            admin_log('order.attention_clear', 'Order ' . $order['order_number'] . ': dismissed the needs-attention flag', 'order', (int) $order['id']);
            flash_set('success', 'Flag dismissed.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.');
        if (!($e instanceof RuntimeException)) error_log('[order action] ' . $e->getMessage());
    }
    redirect($back);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['form_action'] ?? '', $__orderActions, true)) {
    require_csrf();
    $newStatus = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');
    if (in_array($newStatus, $validStatuses, true)) {
        if ($newStatus !== $order['status']) {
            $pdo = db();
            $admin = current_admin();
            $fromStatus = $order['status'];
            $pdo->beginTransaction();
            try {
                // Locks the order, moves stock (cancel returns items to the shelf, reviving takes them out again),
                // voids recorded payments on cancel and writes the timeline — one shared code path with inbound sync.
                $voiding = [];
                if ($newStatus === 'cancelled') {
                    $vp = $pdo->prepare("SELECT id FROM order_payments WHERE order_id = ? AND status = 'recorded'");
                    $vp->execute([$order['id']]);
                    $voiding = $vp->fetchAll(PDO::FETCH_COLUMN);
                }
                $from = erp_order_set_status((int) $order['id'], $newStatus, $note ?: null, ['id' => (int) $admin['id'], 'name' => $admin['name']]);
                if ($from === null) {
                    $pdo->rollBack();
                    flash_set('info', 'Someone else already set this order to ' . ucfirst($newStatus) . '.');
                    redirect('/admin/order_detail.php?id=' . $order['id']);
                }
                $fromStatus = $from;
                foreach ($voiding as $pid) erp_emit('payment', (int) $pid, 'void');
                erp_emit('order', (int) $order['id'], $newStatus === 'cancelled' ? 'cancel' : 'auto');
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash_set('error', $e->getMessage());
                redirect('/admin/order_detail.php?id=' . $order['id']);
            }
            admin_log('order.status', 'Order ' . $order['order_number'] . ': ' . (ORDER_STATUS_LABELS[$fromStatus] ?? $fromStatus) . ' → ' . (ORDER_STATUS_LABELS[$newStatus] ?? $newStatus),
                'order', (int) $order['id'], array_filter(['from' => $fromStatus, 'to' => $newStatus, 'note' => $note ?: null]));

            // Email the customer (registered or guest, whichever email we have).
            require_once __DIR__ . '/../includes/order_mail.php';
            defer_job(fn () => send_order_status_email($order, $newStatus, $note ?: null));
            flash_set('success', 'Order status updated to ' . ucfirst($newStatus) . '.');
        } else {
            flash_set('info', 'Status unchanged.');
        }
        redirect('/admin/order_detail.php?id=' . $order['id']);
    }
}

$itemsStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemsStmt->execute([$order['id']]);
$items = $itemsStmt->fetchAll();

$payStmt = db()->prepare('SELECT p.*, m.name AS method_name FROM order_payments p LEFT JOIN payment_methods m ON m.id = p.method_id WHERE p.order_id = ? ORDER BY p.id');
$payStmt->execute([$order['id']]);
$payments = $payStmt->fetchAll();
$paidTotal = erp_order_paid_total((int) $order['id']);
$amountDue = max(0, round((float) $order['total'] - $paidTotal, 2));
$payStatus = erp_order_payment_status($order);
$retStmt = db()->prepare('SELECT r.*, m.name AS method_name FROM order_returns r LEFT JOIN payment_methods m ON m.id = r.refund_method_id WHERE r.order_id = ? ORDER BY r.id');
$retStmt->execute([$order['id']]);
$returns = $retStmt->fetchAll();
$returnedQty = [];
foreach (db()->query('SELECT ri.order_item_id, SUM(ri.quantity) q FROM order_return_items ri JOIN order_returns r ON r.id = ri.return_id WHERE r.order_id = ' . (int) $order['id'] . ' GROUP BY ri.order_item_id')->fetchAll() as $rq) $returnedQty[(int) $rq['order_item_id']] = (int) $rq['q'];
$allMethods = db()->query('SELECT id, name, is_active FROM payment_methods ORDER BY sort_order, id')->fetchAll();
$customer = null;
if ($order['user_id']) {
    $custStmt = db()->prepare('SELECT id, name, email, phone FROM users WHERE id = ?');
    $custStmt->execute([$order['user_id']]);
    $customer = $custStmt->fetch();
}

// Admin view: includes what each change was from and who made it.
$history = order_status_history((int) $order['id'], true);
// Older rows (from before this was tracked) have no 'from': work it out from the row before.
$__prev = null;
foreach ($history as &$__h) { if (empty($__h['from_status']) && $__prev !== null) $__h['from_status'] = $__prev; $__prev = $__h['status']; }
unset($__h);
$statusLabels = ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

$pageTitle = 'Order ' . order_invoice_id($order);
require __DIR__ . '/includes/header.php';
?>

<div class="panel">
  <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;">
    <h2>Order items</h2>
    <span style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="/admin/invoice.php?id=<?= (int)$order['id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="Exactly what customers see"><?= ui_icon('file', 15) ?> View invoice<?= invoice_source() === 'book' && order_book_invoice_no($order) ? ' (' . e(order_book_invoice_no($order)) . ')' : '' ?></a>
      <?php if (invoice_source() === 'book'): ?><a href="/admin/invoice.php?id=<?= (int)$order['id'] ?>&amp;src=store" target="_blank" class="btn btn-outline btn-sm">Store invoice</a><?php endif; ?>
      <?php if ($__bookUrl = order_book_invoice_admin_url($order)): ?><a href="<?= e($__bookUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">Open in Byabsayee ↗</a><?php endif; ?>
    </span>
  </div>
  <table class="admin-table">
    <thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['product_name']) ?><?php if (!empty($it['is_preorder'])): ?> <span class="pill pill-brass" style="font-size:11px;">Pre-order</span><?php endif; ?><?php if (!empty($it['variant_label'])): ?><br><span style="color:var(--ink-faint);font-size:0.82rem;"><?= e($it['variant_label']) ?></span><?php endif; ?><?php if ($w = warranty_label($it['warranty_days'] ?? null)): ?><br><span style="color:var(--ink-faint);font-size:0.82rem;"><?= e($w) ?></span><?php endif; ?></td><td class="mono"><?= money($it['price']) ?></td><td><?= (int)$it['quantity'] ?></td><td class="mono"><?= money($it['subtotal']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="panel-body" style="border-top:1px solid var(--line);">
    <div style="display:flex;justify-content:flex-end;gap:26px;font-size:0.92rem;">
      <div>Subtotal: <strong class="mono"><?= money($order['subtotal']) ?></strong></div>
      <?php if ((float) $order['discount'] > 0): ?><div>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?>: <strong class="mono" style="color:var(--sage);">&minus;<?= money($order['discount']) ?></strong></div><?php endif; ?>
      <?php if ((float) $order['tax'] > 0): $__t = tax_settings(); ?><div><?= e($__t['label']) ?><?= $order['tax_inclusive'] ? ' (included)' : '' ?>: <strong class="mono"><?= money($order['tax']) ?></strong></div><?php endif; ?>
      <div>Shipping (<?= e(delivery_area_label($order['delivery_area'])) ?>): <strong class="mono"><?= $order['shipping_fee'] > 0 ? money($order['shipping_fee']) : 'Free' ?></strong></div>
      <div>Total: <strong class="mono"><?= money($order['total']) ?></strong></div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
  <div class="panel">
    <div class="panel-head"><h2>Shipping details</h2></div>
    <div class="panel-body">
      <p><strong><?= e($order['shipping_name']) ?></strong><br>
      <?= e($order['shipping_phone']) ?><br>
      <?php $__em = order_customer_email($order); if ($__em): ?><?= e($__em) ?><br><?php endif; ?>
      <?= e($order['shipping_line1']) ?><br>
      <?= e($order['shipping_city']) ?><?= $order['shipping_state'] ? ', ' . e($order['shipping_state']) : '' ?><?= $order['shipping_zip'] ? ' ' . e($order['shipping_zip']) : '' ?></p>
      <?php if (empty($order['billing_same_as_shipping'])): ?>
        <p><strong>Billing address</strong><br>
        <?= e($order['billing_name']) ?> · <?= e($order['billing_phone']) ?><br>
        <?= e($order['billing_line1']) ?>, <?= e($order['billing_city']) ?><?= $order['billing_state'] ? ', ' . e($order['billing_state']) : '' ?><?= $order['billing_zip'] ? ' ' . e($order['billing_zip']) : '' ?></p>
      <?php endif; ?>
      <?php if ($order['notes']): ?><p><strong>Notes:</strong> <?= e($order['notes']) ?></p><?php endif; ?>
      <p style="color:var(--ink-faint);font-size:0.85rem;">Payment method: <?= e(order_payment_name($order)) ?><?php if (order_payment_proof($order) !== ''): ?> — <strong><?= e(order_payment_proof($order)) ?></strong> <span class="muted">(check it, then record the payment below)</span><?php endif; ?></p>
      <?php if ($customer): ?><p style="color:var(--ink-faint);font-size:0.85rem;">Account: <?= e($customer['name']) ?> (<?= e($customer['email']) ?>)</p>
      <?php else: ?><p style="color:var(--ink-faint);font-size:0.85rem;">Guest checkout</p><?php endif; ?>

      <details class="log-details">
        <summary>Edit shipping details</summary>
        <form method="post" style="margin-top:12px;">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="edit_details">
          <?php if ($detailErrors): ?><div class="alert alert-error"><?php foreach ($detailErrors as $e2): ?><div><?= e($e2) ?></div><?php endforeach; ?></div><?php endif; ?>
          <div class="field-row">
            <div class="field"><label for="shipping_name">Recipient name</label><input id="shipping_name" name="shipping_name" value="<?= e($order['shipping_name']) ?>"></div>
            <div class="field"><label for="shipping_phone">Phone</label><input id="shipping_phone" name="shipping_phone" value="<?= e($order['shipping_phone']) ?>"></div>
          </div>
          <div class="field"><label for="customer_email">Email <span class="muted" style="font-weight:400;">(optional)</span></label><input type="email" id="customer_email" name="customer_email" value="<?= e($order['customer_email'] ?? '') ?>"></div>
          <div class="field"><label for="shipping_line1">Address</label><input id="shipping_line1" name="shipping_line1" value="<?= e($order['shipping_line1']) ?>"></div>
          <div class="field-row">
            <div class="field"><label for="shipping_city">City</label><input id="shipping_city" name="shipping_city" value="<?= e($order['shipping_city']) ?>"></div>
            <div class="field"><label for="shipping_state">State/area <span class="muted" style="font-weight:400;">(optional)</span></label><input id="shipping_state" name="shipping_state" value="<?= e($order['shipping_state'] ?? '') ?>"></div>
          </div>
          <div class="field-row">
            <div class="field"><label for="shipping_zip">ZIP <span class="muted" style="font-weight:400;">(optional)</span></label><input id="shipping_zip" name="shipping_zip" value="<?= e($order['shipping_zip'] ?? '') ?>"></div>
            <div class="field">
              <label for="delivery_area">Delivery area</label>
              <select id="delivery_area" name="delivery_area">
                <?php foreach ($validAreas as $a): ?><option value="<?= e($a) ?>" <?= $order['delivery_area'] === $a ? 'selected' : '' ?>><?= e(delivery_area_label($a)) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field">
            <label for="shipping_fee">Shipping fee</label>
            <input type="number" min="0" step="0.01" id="shipping_fee" name="shipping_fee" value="<?= e($order['shipping_fee']) ?>">
            <div class="hint">Changing the delivery area above doesn't recalculate this on its own — adjust it here if the fee should change too. The order total is recalculated from this automatically.</div>
          </div>
          <div class="field"><label for="notes">Notes <span class="muted" style="font-weight:400;">(optional)</span></label><input id="notes" name="notes" value="<?= e($order['notes'] ?? '') ?>"></div>
          <button type="submit" class="btn btn-outline">Save details</button>
        </form>
      </details>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Update status</h2></div>
    <div class="panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="field">
          <label for="status">Order status</label>
          <select id="status" name="status">
            <?php foreach ($validStatuses as $s): ?>
              <option value="<?= e($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="note">Note (optional, shown to customer)</label>
          <input id="note" name="note" placeholder="e.g. Handed to courier, tracking #...">
        </div>
        <p class="help" style="margin-top:-4px;">Cancelling an order puts its items back in stock. The customer is emailed automatically if we have their email.</p>
        <button type="submit" class="btn btn-primary">Update status</button>
      </form>
    </div>
  </div>
</div>

<?php if (!empty($order['attention'])): ?>
  <div class="alert alert-error" style="display:flex;justify-content:space-between;gap:16px;align-items:center;">
    <div><strong>Needs attention:</strong> <?= e($order['attention_note'] ?: 'Something needs a look.') ?><?= $order['attention'] === 'oversold' ? ' The accounting book has less stock than was sold. Either fulfil it as a back-order, or cancel the order (that puts the items back).' : '' ?></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="clear_attention"><button class="btn btn-outline btn-sm">Dismiss</button></form>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
  <div class="panel">
    <div class="panel-head"><h2>Payments <span class="sub"><?= e(ucfirst($payStatus)) ?> · <?= money($paidTotal) ?> of <?= money((float) $order['total']) ?></span></h2></div>
    <div class="panel-body">
      <?php if ($payments): ?>
        <table class="admin-table"><thead><tr><th>Date</th><th>Method</th><th>Amount</th><th></th></tr></thead><tbody>
          <?php foreach ($payments as $p): ?>
            <tr<?= $p['status'] === 'void' ? ' style="opacity:.55;text-decoration:line-through;"' : '' ?>>
              <td><?= e(fmt_dt($p['paid_at'], 'j M Y')) ?></td><td><?= e($p['method_name'] ?: '—') ?><?= $p['reference'] ? ' <span class="muted">#' . e($p['reference']) . '</span>' : '' ?></td><td class="mono"><?= money((float) $p['amount']) ?></td>
              <td><?php if ($p['status'] === 'recorded'): ?><form method="post" onsubmit="return confirm('Void this payment?');" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="form_action" value="void_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-outline btn-sm">Void</button></form><?php else: ?><span class="muted">Voided</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?></tbody></table>
      <?php else: ?><p class="muted">No payments recorded yet.</p><?php endif; ?>
      <?php if ($amountDue > 0 && $order['status'] !== 'cancelled'): ?>
        <details class="log-details" style="margin-top:12px;"><summary>Record a payment</summary>
          <form method="post" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="form_action" value="record_payment">
            <div class="field-row">
              <div class="field"><label for="pay_amount">Amount</label><input type="number" step="0.01" min="0.01" max="<?= e((string) $amountDue) ?>" id="pay_amount" name="amount" value="<?= e((string) $amountDue) ?>" required></div>
              <div class="field"><label for="pay_method">Method</label><select id="pay_method" name="method_id"><?php foreach ($allMethods as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === (int) $order['payment_method_id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field-row">
              <div class="field"><label for="pay_ref">Reference <span class="muted" style="font-weight:400;">(optional)</span></label><input id="pay_ref" name="reference" maxlength="120"></div>
              <div class="field"><label for="pay_at">Paid on <span class="muted" style="font-weight:400;">(optional)</span></label><input type="datetime-local" id="pay_at" name="paid_at"></div>
            </div>
            <div class="field"><label for="pay_note">Note <span class="muted" style="font-weight:400;">(optional)</span></label><input id="pay_note" name="pnote" maxlength="255"></div>
            <button class="btn btn-primary">Record payment</button>
          </form></details>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Returns &amp; refunds</h2></div>
    <div class="panel-body">
      <?php foreach ($returns as $r): ?>
        <p style="margin:0 0 8px;"><strong><?= e(fmt_dt($r['returned_at'], 'j M Y')) ?></strong> — refund <span class="mono"><?= money((float) $r['refund_amount']) ?></span><?= $r['method_name'] ? ' via ' . e($r['method_name']) : '' ?><?= $r['reason'] ? '<br><span class="muted">' . e($r['reason']) . '</span>' : '' ?></p>
      <?php endforeach; ?>
      <?php if (!$returns): ?><p class="muted">No returns.</p><?php endif; ?>
      <?php if ($order['status'] !== 'cancelled'): ?>
        <details class="log-details" style="margin-top:12px;"><summary>Record a return</summary>
          <form method="post" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="form_action" value="record_return">
            <table class="admin-table"><thead><tr><th>Item</th><th>Qty back</th><th>Restock</th></tr></thead><tbody>
              <?php foreach ($items as $it): $left = (int) $it['quantity'] - ($returnedQty[(int) $it['id']] ?? 0); if ($left < 1) continue; ?>
                <tr><td><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' <span class="muted">(' . e($it['variant_label']) . ')</span>' : '' ?></td>
                  <td><input type="number" min="0" max="<?= $left ?>" value="0" name="ret_qty[<?= (int) $it['id'] ?>]" style="width:70px;"></td>
                  <td><input type="checkbox" name="ret_restock[<?= (int) $it['id'] ?>]" value="1" checked></td></tr>
              <?php endforeach; ?></tbody></table>
            <div class="field-row" style="margin-top:12px;">
              <div class="field"><label for="ret_refund">Refund amount</label><input type="number" step="0.01" min="0" id="ret_refund" name="refund_amount" value="0"></div>
              <div class="field"><label for="ret_method">Refunded via</label><select id="ret_method" name="refund_method_id"><option value="">—</option><?php foreach ($allMethods as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field"><label for="ret_reason">Reason <span class="muted" style="font-weight:400;">(optional)</span></label><input id="ret_reason" name="reason" maxlength="255"></div>
            <p class="help">Items ticked "Restock" go back on the shelf. The refund is recorded here — send the money back yourself.</p>
            <button class="btn btn-primary">Record return</button>
          </form></details>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Status timeline</h2></div>
  <div class="panel-body">
    <?php if (!$history): ?>
      <p style="color:var(--ink-faint);">No status changes recorded yet.</p>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($history as $h): ?>
          <li>
            <?php if (!empty($h['from_status'])): ?>
              <span style="color:var(--ink-faint);"><?= e($statusLabels[$h['from_status']] ?? ucfirst($h['from_status'])) ?> →</span>
            <?php endif; ?>
            <strong><?= e($statusLabels[$h['status']] ?? ucfirst($h['status'])) ?></strong>
            <span style="color:var(--ink-faint);"> — <?= e(fmt_dt($h['changed_at'], 'j M Y, g:i A')) ?></span>
            <div class="tl-by">
              <?php if (!empty($h['changed_by_name'])): ?>Updated by <strong><?= e($h['changed_by_name']) ?></strong>
              <?php elseif (empty($h['from_status'])): ?>Order placed by the customer
              <?php else: ?>Updated by — <span title="Recorded before admin names were tracked">(not recorded)</span><?php endif; ?>
            </div>
            <?php if ($h['note']): ?><div style="color:var(--ink-faint);font-size:0.85rem;">Note: <?= e($h['note']) ?></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
