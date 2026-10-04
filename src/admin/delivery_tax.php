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
    $zlabels = [];
    foreach (['inside', 'suburbs', 'outside'] as $zk) { $zlabels[$zk] = mb_substr(trim($_POST['zlabel_' . $zk] ?? ''), 0, 40); if ($zlabels[$zk] === '') $errors[] = 'Give every delivery zone a name.'; }
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
            foreach ($zlabels as $zk => $zl) set_setting('zone_label_' . $zk, $zl);
            set_setting('zone_off_suburbs', empty($_POST['zone_on_suburbs']) ? '1' : '0');
            set_setting('zone_off_outside', empty($_POST['zone_on_outside']) ? '1' : '0');
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
    <?php foreach ([['inside', 'Zone 1 (always available)'], ['suburbs', 'Zone 2'], ['outside', 'Zone 3']] as [$zk, $zt]): ?>
    <div class="field-row">
      <div class="field"><label for="zlabel_<?= $zk ?>"><?= e($zt) ?> — name shown to customers</label><input id="zlabel_<?= $zk ?>" name="zlabel_<?= $zk ?>" maxlength="40" value="<?= e($_POST['zlabel_' . $zk] ?? delivery_area_label(array_search($zk, DELIVERY_ZONE_KEYS, true))) ?>"></div>
      <div class="field"><label for="<?= $zk ?>">Delivery fee</label><div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" step="0.01" min="0" id="<?= $zk ?>" name="<?= $zk ?>" value="<?= e((string) shipcfg($zk)) ?>"></div></div>
      <?php if ($zk !== 'inside'): ?><div class="field"><label>&nbsp;</label><label class="checkbox-row" style="margin:0;"><input type="checkbox" name="zone_on_<?= $zk ?>" value="1" <?= get_setting('zone_off_' . $zk, '0') !== '1' ? 'checked' : '' ?>> Offer this zone</label></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <p class="help">Keep one zone for “deliver everywhere”, or use up to three (for example: Local, Regional, International). Customers only see the zones you offer.</p>
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
