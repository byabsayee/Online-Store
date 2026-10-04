<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $sym = mb_substr(trim($_POST['currency_symbol'] ?? ''), 0, 6);
    $code = strtoupper(trim($_POST['currency_code'] ?? ''));
    $pos = ($_POST['currency_pos'] ?? '') === 'after' ? 'after' : 'before';
    $dec = in_array($_POST['currency_decimals'] ?? '', ['0', '1', '2'], true) ? $_POST['currency_decimals'] : '2';
    $tz = $_POST['timezone'] ?? '';
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['order_prefix'] ?? ''));
    $d1 = (int) ($_POST['days_min'] ?? 0); $d2 = (int) ($_POST['days_max'] ?? 0);
    if ($sym === '') $errors[] = 'Enter a currency symbol (for example ৳, $, €).';
    if (!preg_match('/^[A-Z]{3}$/', $code)) $errors[] = 'The currency code is 3 letters, like BDT, USD or EUR.';
    if (!in_array($tz, timezone_identifiers_list(), true)) $errors[] = 'Choose a time zone from the list.';
    if (strlen($prefix) < 2 || strlen($prefix) > 6) $errors[] = 'The order-number prefix is 2–6 letters or digits (e.g. ORD).';
    if ($d1 < 0 || $d2 < $d1 || $d2 > 90) $errors[] = 'Delivery days: the longest time can’t be shorter than the shortest.';
    if (!$errors) {
        foreach (['currency_symbol' => $sym, 'currency_code' => $code, 'currency_pos' => $pos, 'currency_decimals' => $dec, 'timezone' => $tz, 'order_prefix' => $prefix,
                  'delivery_days_min' => (string) $d1, 'delivery_days_max' => (string) $d2,
                  'invoice_header' => mb_substr(trim($_POST['invoice_header'] ?? ''), 0, 200), 'invoice_footer' => mb_substr(trim($_POST['invoice_footer'] ?? ''), 0, 300),
                  'invoice_tax_number' => mb_substr(trim($_POST['invoice_tax_number'] ?? ''), 0, 40)] as $k => $v) set_setting($k, $v);
        admin_log('settings.regional', 'Currency, time zone, order prefix and invoice text saved');
        flash_set('success', 'Saved. New orders use the new prefix; existing orders keep theirs.');
        redirect('/admin/regional.php');
    }
}
$g = fn ($k, $d = '') => e($_POST[$k] ?? (string) get_setting($k, $d));
$pageTitle = 'Region & invoices';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';
$linked = function_exists('erp_linked') && erp_linked();
?>
<?php if ($linked): ?><div class="alert alert-info">This store is linked to Byabsayee. Keep the currency and time zone the same in both apps.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<section class="panel"><div class="panel-head"><h2>Currency</h2></div><div class="panel-body">
  <div class="field-row">
    <div class="field"><label>Symbol</label><input name="currency_symbol" maxlength="6" value="<?= e($_POST['currency_symbol'] ?? store_currency_symbol()) ?>"></div>
    <div class="field"><label>Code <span class="muted" style="font-weight:400;">(3 letters)</span></label><input name="currency_code" maxlength="3" value="<?= e($_POST['currency_code'] ?? store_currency_code()) ?>"></div>
    <div class="field"><label>Symbol position</label><select name="currency_pos"><option value="before" <?= currency_position() === 'before' ? 'selected' : '' ?>>Before the amount (৳100.00)</option><option value="after" <?= currency_position() === 'after' ? 'selected' : '' ?>>After the amount (100.00 €)</option></select></div>
    <div class="field"><label>Decimals shown</label><select name="currency_decimals"><?php foreach (['2', '1', '0'] as $d): ?><option value="<?= $d ?>" <?= (string) currency_decimals() === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
  </div>
  <p class="help">Presets: Bangladesh — ৳ / BDT · USA — $ / USD · Eurozone — € / EUR (after) · UK — £ / GBP · India — ₹ / INR. Prices are always stored with 2 decimals; “decimals shown” only changes how they look.</p>
</div></section>
<section class="panel"><div class="panel-head"><h2>Time zone, orders &amp; delivery time</h2></div><div class="panel-body">
  <div class="field-row">
    <div class="field"><label>Time zone</label><select name="timezone"><?php $cur = $_POST['timezone'] ?? date_default_timezone_get(); foreach (timezone_identifiers_list() as $z): ?><option <?= $cur === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Order-number prefix</label><input name="order_prefix" maxlength="6" value="<?= e($_POST['order_prefix'] ?? order_prefix()) ?>"></div>
  </div>
  <div class="field-row">
    <div class="field"><label>Delivery takes at least (days)</label><input type="number" min="0" name="days_min" value="<?= e((string) ($_POST['days_min'] ?? delivery_days_range()[0])) ?>"></div>
    <div class="field"><label>… and at most (days)</label><input type="number" min="0" max="90" name="days_max" value="<?= e((string) ($_POST['days_max'] ?? delivery_days_range()[1])) ?>"></div>
  </div>
</div></section>
<section class="panel"><div class="panel-head"><h2>Invoice text</h2></div><div class="panel-body">
  <div class="field"><label>Tax / registration number <span class="muted" style="font-weight:400;">(printed in the “From” box)</span></label><input name="invoice_tax_number" maxlength="40" value="<?= $g('invoice_tax_number') ?>"></div>
  <div class="field"><label>Line under the invoice header <span class="muted" style="font-weight:400;">(optional)</span></label><input name="invoice_header" maxlength="200" value="<?= $g('invoice_header') ?>"></div>
  <div class="field"><label>Footer note <span class="muted" style="font-weight:400;">(optional, e.g. thank-you, bank details, terms)</span></label><textarea name="invoice_footer" rows="3" maxlength="300"><?= $g('invoice_footer') ?></textarea></div>
  <p class="help">Your store name, logo, address and phone come from Settings → Store details and Branding.</p>
  <button class="btn btn-primary">Save</button>
</div></section>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
