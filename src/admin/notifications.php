<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/mail.php';
require_owner();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'cancel_setting') {
    require_csrf();
    set_setting('customer_cancel', !empty($_POST['customer_cancel']) ? '1' : '0');
    admin_log('settings.cancel', 'Customer self-cancellation ' . (!empty($_POST['customer_cancel']) ? 'on' : 'off'));
    flash_set('success', 'Saved.');
    redirect('/admin/notifications.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $on = !empty($_POST['notify_enabled']);
    $raw = trim($_POST['notify_email'] ?? '');
    $list = [];
    foreach (preg_split('/[,;\s]+/', $raw) as $a) { if ($a === '') continue; if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { $errors[] = '“' . $a . '” is not a valid email address.'; } else $list[strtolower($a)] = $a; }
    if (count($list) > 3) $errors[] = 'Use up to 3 addresses.';
    if ($on && !$list) $errors[] = 'Enter at least one email address, or switch the alerts off.';
    if (!$errors) {
        set_setting('notify_enabled', $on ? '1' : '0');
        set_setting('notify_email', implode(', ', $list));
        admin_log('settings.notify', 'New-order email alerts ' . ($on ? 'on' : 'off'));
        if (!empty($_POST['send_test']) && $list) {
            $ok = true;
            foreach ($list as $a) $ok = send_email($a, store_name(), 'Test: new-order alerts', email_wrap('Test', '<p>This is a test. When a customer places an order, you will get an email like this one with the order details.</p>'), null, null, 'order_notify_test') && $ok;
            flash_set($ok ? 'success' : 'error', $ok ? 'Saved, and a test email was sent.' : 'Saved, but the test email could not be sent. Check Settings & email → Outgoing email.');
        } else flash_set('success', 'Saved.');
        redirect('/admin/notifications.php');
    }
}
$pageTitle = 'Order alerts';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';
?>
<section class="panel"><div class="panel-head"><h2>Receive an email when a new order arrives</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?>
  <div class="checkbox-row" style="margin-bottom:12px;"><input type="checkbox" id="ne" name="notify_enabled" value="1" <?= get_setting('notify_enabled', '0') === '1' ? 'checked' : '' ?>><label for="ne" style="margin:0;font-weight:400;">Email me about every new order</label></div>
  <div class="field"><label for="nm">Send to <span class="muted" style="font-weight:400;">(up to 3 addresses, separated by commas)</span></label><input id="nm" name="notify_email" value="<?= e($_POST['notify_email'] ?? get_setting('notify_email', '')) ?>" placeholder="owner@example.com"></div>
  <div class="checkbox-row" style="margin-bottom:14px;"><input type="checkbox" id="nt" name="send_test" value="1"><label for="nt" style="margin:0;font-weight:400;">Send a test email now</label></div>
  <p class="help">The alert lists the items, total, customer, delivery address and — for bKash/Nagad-type payments — the sender number and transaction ID. Emails go out through your outgoing-email settings.</p>
  <button class="btn btn-primary">Save</button>
</form></div></section>
<section class="panel"><div class="panel-head"><h2>Order cancellation</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="cancel_setting">
  <div class="checkbox-row" style="margin-bottom:12px;"><input type="checkbox" id="cc" name="customer_cancel" value="1" <?= customer_cancel_enabled() ? 'checked' : '' ?>><label for="cc" style="margin:0;font-weight:400;">Let customers cancel their own order from the website until it is <strong>Shipped</strong></label></div>
  <p class="help">A cancelled order puts its items back in stock and voids recorded payments. The customer gets a confirmation email, and you get an alert (to the addresses above, or the store email) — it says when a refund is needed.</p>
  <button class="btn btn-primary">Save</button>
</form></div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
