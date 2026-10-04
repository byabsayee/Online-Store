<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner(); // must run before any POST handling below
$errors = [];
$conn = erp_conn();
$bookOwns = ($conn['authority'] ?? null) === 'book' && erp_linked();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $num = fn ($k) => isset($_POST[$k]) && is_numeric($_POST[$k]) && (float) $_POST[$k] >= 0 ? round((float) $_POST[$k], 2) : null;
    $vals = ['ship_inside' => $num('inside'), 'ship_suburbs' => $num('suburbs'), 'ship_outside' => $num('outside'), 'ship_free_kg' => $num('free_kg'), 'ship_extra_kg' => $num('extra_kg')];
    foreach ($vals as $k => $v) if ($v === null) $errors[] = 'Enter a number (0 or more) for every delivery field.';
    $errors = array_unique($errors);
    $rate = isset($_POST['tax_rate']) && is_numeric($_POST['tax_rate']) ? round((float) $_POST['tax_rate'], 3) : null;
    $taxOn = !empty($_POST['tax_enabled']);
    if ($taxOn && ($rate === null || $rate <= 0 || $rate > 100)) $errors[] = 'Enter a tax rate between 0 and 100.';
    $label = mb_substr(trim($_POST['tax_label'] ?? ''), 0, 40) ?: 'Tax';
    if (!$errors) {
        $before = ['ship' => [shipcfg('inside'), shipcfg('suburbs'), shipcfg('outside'), shipcfg('free_kg'), shipcfg('extra_kg')], 'tax' => tax_settings()];
        try {
            db()->beginTransaction();
            foreach ($vals as $k => $v) set_setting($k, (string) $v);
            set_setting('tax_enabled', $taxOn ? '1' : '0');
            set_setting('tax_rate', (string) ($rate ?? 0));
            set_setting('tax_inclusive', ($_POST['tax_mode'] ?? '') === 'inclusive' ? '1' : '0');
            set_setting('tax_label', $label);
            foreach ([1, 2, 3] as $z) erp_emit('delivery_charge', $z, 'auto');
            erp_emit('tax', 1, 'auto');
            db()->commit();
            admin_log('settings.delivery_tax', 'Delivery charges and tax settings updated', null, null, ['before' => $before]);
            flash_set('success', 'Saved. New orders use these numbers; existing orders keep theirs.');
            redirect('/admin/delivery_tax.php');
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $errors[] = 'Could not save. Please try again.'; error_log('[delivery_tax] ' . $e->getMessage()); }
    }
}
$t = tax_settings();
$pageTitle = 'Delivery & tax';
require __DIR__ . '/includes/header.php';
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
<?php if ($bookOwns): ?><div class="alert alert-info">The accounting book is the source of truth for tax settings — a change made here can be overwritten by the book.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<section class="panel">
  <div class="panel-head"><h2>Delivery charges</h2></div>
  <div class="panel-body">
    <div class="field-row">
      <div class="field"><label for="inside">Inside Dhaka</label><div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" step="0.01" min="0" id="inside" name="inside" value="<?= e((string) shipcfg('inside')) ?>"></div></div>
      <div class="field"><label for="suburbs">Dhaka suburbs</label><div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" step="0.01" min="0" id="suburbs" name="suburbs" value="<?= e((string) shipcfg('suburbs')) ?>"></div></div>
      <div class="field"><label for="outside">Outside Dhaka</label><div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" step="0.01" min="0" id="outside" name="outside" value="<?= e((string) shipcfg('outside')) ?>"></div></div>
    </div>
    <div class="field-row">
      <div class="field"><label for="free_kg">Weight covered by the base fee (kg)</label><input type="number" step="0.01" min="0" id="free_kg" name="free_kg" value="<?= e((string) shipcfg('free_kg')) ?>"></div>
      <div class="field"><label for="extra_kg">Extra per additional kg</label><div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" step="0.01" min="0" id="extra_kg" name="extra_kg" value="<?= e((string) shipcfg('extra_kg')) ?>"></div></div>
    </div>
  </div>
</section>
<section class="panel">
  <div class="panel-head"><h2>Tax</h2></div>
  <div class="panel-body">
    <div class="checkbox-row" style="margin-bottom:14px;"><input type="checkbox" id="tax_enabled" name="tax_enabled" value="1" <?= $t['enabled'] ? 'checked' : '' ?>><label for="tax_enabled" style="margin:0;font-weight:400;">Charge tax on orders</label></div>
    <div class="field-row">
      <div class="field"><label for="tax_label">Name on invoices</label><input id="tax_label" name="tax_label" maxlength="40" value="<?= e($t['label']) ?>" placeholder="VAT"></div>
      <div class="field"><label for="tax_rate">Rate (%)</label><input type="number" step="0.001" min="0" max="100" id="tax_rate" name="tax_rate" value="<?= e((string) $t['rate']) ?>"></div>
    </div>
    <div class="field"><label>How prices are quoted</label>
      <label class="radio-option"><input type="radio" name="tax_mode" value="exclusive" <?= !$t['inclusive'] ? 'checked' : '' ?>><span class="radio-option-label">Tax is added on top of the price</span></label>
      <label class="radio-option"><input type="radio" name="tax_mode" value="inclusive" <?= $t['inclusive'] ? 'checked' : '' ?>><span class="radio-option-label">Prices already include tax (it is shown as a part of the total)</span></label>
    </div>
    <p class="help">Tax applies to the goods after any discount, not to delivery. Orders already placed keep the tax they were charged.</p>
    <button class="btn btn-primary">Save</button>
  </div>
</section>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
