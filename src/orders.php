<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    // Guests: look an order up by order number + the email used at checkout.
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $num = strtoupper(trim($_POST['order_number'] ?? ''));
        $mail = strtolower(trim($_POST['email'] ?? ''));
        $wait = login_throttle_check('track', $mail !== '' ? $mail : 'none');
        if ($wait !== null) {
            $error = 'Too many attempts. Please try again in ' . ceil($wait / 60) . ' minute(s).';
        } else {
            $q = db()->prepare('SELECT order_number, user_id FROM orders WHERE (order_number = ? OR book_invoice_no = ?) AND LOWER(customer_email) = ?');
            $q->execute([$num, $num, $mail]);
            $hit = $q->fetch();
            if ($hit && empty($hit['user_id'])) {
                guest_order_grant($hit['order_number']);
                redirect(order_url($hit['order_number']));
            } elseif ($hit) {
                $error = 'That order belongs to an account. Please log in with ' . $mail . ' to see it.';
            } else {
                login_throttle_hit('track', $mail !== '' ? $mail : 'none');
                $error = 'We couldn\'t find an order with that number and email. Check the confirmation email we sent you.';
            }
        }
    }
    $pageTitle = 'Track an order';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap">
      <div class="form-card form-narrow">
        <h2 style="text-align:center;margin-bottom:6px;">Track your order</h2>
        <p style="text-align:center;color:var(--ink-soft);margin-bottom:26px;font-size:0.9rem;">Enter the order number or invoice ID from your confirmation email and the email you used at checkout.</p>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="field"><label for="order_number">Order number or invoice ID</label><input id="order_number" name="order_number" required placeholder="RA-260928-AB12C" autocapitalize="characters" value="<?= e($_POST['order_number'] ?? '') ?>"></div>
          <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></div>
          <button type="submit" class="btn btn-primary btn-block">Track order</button>
        </form>
        <div class="form-foot">Have an account? <a href="/login?next=/orders">Log in</a> to see all your orders.</div>
      </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$user = current_user();
$stmt = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$orders = $stmt->fetchAll();

$__activeAccountTab = 'orders';
$pageTitle = 'Order history';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header wrap"><span class="eyebrow">Account</span><h1>Order history</h1></div>

<div class="wrap account-layout">
  <?php include __DIR__ . '/includes/account_nav.php'; ?>

  <div>
    <?php if (!$orders): ?>
      <div class="empty-state">
        <div class="icon"><?= ui_icon('box', 40) ?></div>
        <h2>No orders yet</h2>
        <p>Once you place an order, it'll show up here.</p>
        <a class="btn btn-primary" href="/">Start shopping</a>
      </div>
    <?php else: ?>
      <div class="table-scroll"><table class="data-table stack">
        <thead><tr><th><?= invoice_source() === 'book' ? 'Invoice' : 'Order' ?></th><th>Date</th><th>Status</th><th>Total</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td class="mono cell-order" data-label="Order"><a href="<?= e(order_url($o['order_number'])) ?>"><?= e(order_invoice_id($o)) ?></a><?php if (order_invoice_differs($o)): ?><br><span style="color:var(--ink-faint);font-size:0.78rem;">Order <?= e($o['order_number']) ?></span><?php endif; ?></td>
              <td class="cell-date" data-label="Date"><?= fmt_dt($o['created_at'], 'd M Y') ?></td>
              <td class="cell-status" data-label="Status"><span class="status-pill status-<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></td>
              <td class="mono cell-total" data-label="Total"><?= money($o['total']) ?></td>
              <td class="cell-action"><a href="<?= e(order_url($o['order_number'])) ?>" class="btn btn-outline btn-sm">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
