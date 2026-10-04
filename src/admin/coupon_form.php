<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$coupon = null;
if ($id) {
    $coupon = coupon_find($id);
    if (!$coupon) { flash_set('error', 'Coupon not found.'); redirect('/admin/coupons.php'); }
}

$errors = [];
$blank = ['code' => '', 'type' => 'percent', 'value' => '', 'max_discount' => '', 'min_subtotal' => '', 'starts_at' => null, 'expires_at' => null,
          'usage_limit' => '', 'per_customer_limit' => '', 'is_active' => 1, 'note' => ''];
$f = $coupon ?: $blank;
$startsInput = coupon_utc_to_local_input($f['starts_at']);
$expiresInput = coupon_utc_to_local_input($f['expires_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $code = coupon_normalize_code((string) ($_POST['code'] ?? ''));
    $type = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
    $value = (float) str_replace(',', '', (string) ($_POST['value'] ?? '0'));
    $maxDisc = trim((string) ($_POST['max_discount'] ?? '')) !== '' ? (float) str_replace(',', '', (string) $_POST['max_discount']) : null;
    $minSub = trim((string) ($_POST['min_subtotal'] ?? '')) !== '' ? (float) str_replace(',', '', (string) $_POST['min_subtotal']) : 0.0;
    $startsInput = trim((string) ($_POST['starts_at'] ?? ''));
    $expiresInput = trim((string) ($_POST['expires_at'] ?? ''));
    $startsUtc = coupon_local_to_utc($startsInput);
    $expiresUtc = coupon_local_to_utc($expiresInput);
    $usageLimit = trim((string) ($_POST['usage_limit'] ?? '')) !== '' ? (int) $_POST['usage_limit'] : null;
    $perCustomer = trim((string) ($_POST['per_customer_limit'] ?? '')) !== '' ? (int) $_POST['per_customer_limit'] : null;
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);

    if ($code === '' || strlen($code) < 3) $errors[] = 'Enter a coupon code of at least 3 letters or numbers (A–Z, 0–9, dash).';
    elseif (strlen($code) > 40) $errors[] = 'The coupon code can be at most 40 characters.';
    else {
        $dup = db()->prepare('SELECT id FROM coupons WHERE code = ? AND id != ?');
        $dup->execute([$code, $id ?: 0]);
        if ($dup->fetch()) $errors[] = 'A coupon with the code ' . $code . ' already exists.';
    }
    if ($type === 'percent') {
        if ($value <= 0 || $value > 100) $errors[] = 'A percentage discount must be more than 0 and at most 100.';
        if ($maxDisc !== null && $maxDisc <= 0) $errors[] = 'The maximum discount must be more than 0 (or leave it empty for no cap).';
    } else {
        if ($value <= 0) $errors[] = 'A fixed discount must be more than 0.';
        if ($value > 10000000) $errors[] = 'That fixed discount is unrealistically large.';
        $maxDisc = null; // a cap only makes sense for percentages
    }
    if ($minSub < 0) $errors[] = 'The minimum order can\'t be negative.';
    if ($startsInput !== '' && $startsUtc === null) $errors[] = 'The start date isn\'t valid.';
    if ($expiresInput !== '' && $expiresUtc === null) $errors[] = 'The end date isn\'t valid.';
    if ($startsUtc !== null && $expiresUtc !== null && $expiresUtc <= $startsUtc) $errors[] = 'The end date must be after the start date.';
    if ($usageLimit !== null && $usageLimit < 1) $errors[] = 'The total usage limit must be at least 1 (or leave it empty for unlimited).';
    if ($perCustomer !== null && $perCustomer < 1) $errors[] = 'The per-customer limit must be at least 1 (or leave it empty for unlimited).';

    // What the form shows again if something was wrong.
    $f = ['code' => $code, 'type' => $type, 'value' => $_POST['value'] ?? '', 'max_discount' => $_POST['max_discount'] ?? '', 'min_subtotal' => $_POST['min_subtotal'] ?? '',
          'starts_at' => $startsUtc, 'expires_at' => $expiresUtc, 'usage_limit' => $_POST['usage_limit'] ?? '', 'per_customer_limit' => $_POST['per_customer_limit'] ?? '',
          'is_active' => $isActive, 'note' => $note];

    if (!$errors) {
        $new = ['code' => $code, 'type' => $type, 'value' => $value, 'max_discount' => $maxDisc, 'min_subtotal' => $minSub, 'starts_at' => $startsUtc, 'expires_at' => $expiresUtc,
                'usage_limit' => $usageLimit, 'per_customer_limit' => $perCustomer, 'is_active' => $isActive, 'note' => $note !== '' ? $note : null];
        $args = [$code, $type, $value, $maxDisc, $minSub, $startsUtc, $expiresUtc, $usageLimit, $perCustomer, $isActive, $new['note']];
        try {
            if ($coupon) {
                db()->prepare('UPDATE coupons SET code=?, type=?, value=?, max_discount=?, min_subtotal=?, starts_at=?, expires_at=?, usage_limit=?, per_customer_limit=?, is_active=?, note=? WHERE id=?')
                    ->execute(array_merge($args, [$coupon['id']]));
                $labels = ['code' => 'Code', 'type' => 'Type', 'value' => 'Value', 'max_discount' => 'Max discount', 'min_subtotal' => 'Minimum order', 'starts_at' => 'Starts', 'expires_at' => 'Expires',
                           'usage_limit' => 'Total uses', 'per_customer_limit' => 'Uses per customer', 'is_active' => 'On', 'note' => 'Note'];
                $old = $coupon; $cmpNew = $new;
                foreach (['starts_at', 'expires_at'] as $k) { $old[$k] = $old[$k] ? fmt_dt($old[$k], 'd M Y, g:i A') : null; $cmpNew[$k] = $cmpNew[$k] ? fmt_dt($cmpNew[$k], 'd M Y, g:i A') : null; }
                $old['is_active'] = $old['is_active'] ? 'yes' : 'no'; $cmpNew['is_active'] = $cmpNew['is_active'] ? 'yes' : 'no';
                $diff = admin_log_diff($old, $cmpNew, $labels);
                admin_log('coupon.update', 'Edited coupon ' . $code . ($diff ? ': ' . admin_log_diff_summary($diff) : ' (no changes)'), 'coupon', (int) $coupon['id'], $diff ? ['changes' => $diff] : []);
                erp_emit('coupon', (int) $coupon['id'], 'auto');
                flash_set('success', 'Coupon ' . $code . ' saved.');
                redirect('/admin/coupons.php');
            } else {
                db()->prepare('INSERT INTO coupons (code, type, value, max_discount, min_subtotal, starts_at, expires_at, usage_limit, per_customer_limit, is_active, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
                $newId = (int) db()->lastInsertId();
                erp_emit('coupon', $newId, 'auto');
                admin_log('coupon.create', 'Created coupon ' . $code . ' — ' . coupon_describe($new), 'coupon', $newId, ['minimum_order' => $minSub, 'total_uses' => $usageLimit ?? 'unlimited', 'per_customer' => $perCustomer ?? 'unlimited']);
                flash_set('success', 'Coupon ' . $code . ' created.');
                redirect('/admin/coupons.php');
            }
        } catch (PDOException $e) {
            error_log('[coupon_form] ' . $e->getMessage());
            $errors[] = 'Could not save the coupon: ' . ($e->getCode() === '23000' ? 'that code is already taken.' : 'a database error occurred.');
        }
    }
}

$usedOn = $coupon ? coupon_usage_count((int) $coupon['id']) : 0;
$pageTitle = $coupon ? 'Edit coupon' : 'New coupon';
require __DIR__ . '/includes/header.php';
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" class="panel" style="max-width:820px;" autocomplete="off">
  <?= csrf_field() ?>
  <div class="panel-head"><h2><?= $coupon ? 'Edit coupon ' . e($coupon['code']) : 'Create a coupon' ?></h2><?php if ($coupon): ?><span class="sub">used on <?= (int) $usedOn ?> order<?= $usedOn === 1 ? '' : 's' ?></span><?php endif; ?></div>
  <div class="panel-body">
    <div class="field-row">
      <div class="field">
        <label for="code">Coupon code</label>
        <div style="display:flex;gap:8px;">
          <input type="text" id="code" name="code" value="<?= e($f['code']) ?>" required maxlength="40" placeholder="e.g. EID10" style="text-transform:uppercase;font-family:var(--font-mono);letter-spacing:.06em;" autocapitalize="characters" spellcheck="false">
          <button type="button" class="btn btn-outline" id="genCode">Generate</button>
        </div>
        <div class="hint">Customers type this at checkout. Letters, numbers and dashes only — capitals or small letters both work.</div>
      </div>
      <div class="field">
        <label for="type">Discount type</label>
        <select id="type" name="type">
          <option value="percent" <?= $f['type'] === 'percent' ? 'selected' : '' ?>>Percentage off (e.g. 10%)</option>
          <option value="fixed" <?= $f['type'] === 'fixed' ? 'selected' : '' ?>>Fixed amount off (e.g. <?= e(store_currency_symbol()) ?>200)</option>
        </select>
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="value">Discount value</label>
        <div class="input-affix"><input type="number" id="value" name="value" value="<?= e((string) $f['value']) ?>" step="0.01" min="0" required><span class="affix" id="valueAffix">%</span></div>
      </div>
      <div class="field" id="maxWrap">
        <label for="max_discount">Maximum discount <span class="muted" style="font-weight:400;">(optional)</span></label>
        <div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" id="max_discount" name="max_discount" value="<?= e((string) $f['max_discount']) ?>" step="0.01" min="0" placeholder="No cap"></div>
        <div class="hint">Caps how much a percentage coupon can take off, e.g. 10% off but never more than <?= e(store_currency_symbol()) ?>500.</div>
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="min_subtotal">Minimum order <span class="muted" style="font-weight:400;">(optional)</span></label>
        <div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" id="min_subtotal" name="min_subtotal" value="<?= e((string) ((float) $f['min_subtotal'] > 0 ? $f['min_subtotal'] : '')) ?>" step="0.01" min="0" placeholder="No minimum"></div>
        <div class="hint">Measured on the items total, before shipping.</div>
      </div>
      <div class="field">
        <label for="note">Note to yourself <span class="muted" style="font-weight:400;">(optional)</span></label>
        <input type="text" id="note" name="note" value="<?= e((string) $f['note']) ?>" maxlength="255" placeholder="e.g. Eid campaign, Facebook giveaway">
        <div class="hint">Only you see this.</div>
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="starts_at">Starts <span class="muted" style="font-weight:400;">(optional)</span></label>
        <input type="datetime-local" id="starts_at" name="starts_at" value="<?= e($startsInput) ?>">
        <div class="hint">Times are in <?= e(date_default_timezone_get()) ?>. Empty = works immediately.</div>
      </div>
      <div class="field">
        <label for="expires_at">Expires <span class="muted" style="font-weight:400;">(optional)</span></label>
        <input type="datetime-local" id="expires_at" name="expires_at" value="<?= e($expiresInput) ?>">
        <div class="hint">Empty = never expires.</div>
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="usage_limit">Total number of uses <span class="muted" style="font-weight:400;">(optional)</span></label>
        <input type="number" id="usage_limit" name="usage_limit" value="<?= e((string) $f['usage_limit']) ?>" min="1" step="1" placeholder="Unlimited">
        <div class="hint">Cancelled orders don't count.</div>
      </div>
      <div class="field">
        <label for="per_customer_limit">Uses per customer <span class="muted" style="font-weight:400;">(optional)</span></label>
        <input type="number" id="per_customer_limit" name="per_customer_limit" value="<?= e((string) $f['per_customer_limit']) ?>" min="1" step="1" placeholder="Unlimited">
        <div class="hint">A customer is recognised by account, email or phone number, so guests can't reuse it either.</div>
      </div>
    </div>

    <div class="field" style="margin-bottom:0;">
      <label class="switch"><input type="checkbox" name="is_active" value="1" <?= (int) $f['is_active'] ? 'checked' : '' ?>><span class="track"></span><span>Coupon is on</span></label>
      <?php if ($coupon): ?><div class="hint">Changes apply to new orders only. Orders already placed keep the discount they got.</div><?php endif; ?>
    </div>
  </div>
  <div class="panel-foot" style="display:flex;gap:10px;">
    <button class="btn btn-primary" type="submit"><?= $coupon ? 'Save coupon' : 'Create coupon' ?></button>
    <a class="btn btn-outline" href="/admin/coupons.php">Cancel</a>
  </div>
</form>

<script>
(function () {
  var type = document.getElementById('type'), affix = document.getElementById('valueAffix'), maxWrap = document.getElementById('maxWrap');
  var symbol = <?= json_encode(store_currency_symbol()) ?>;
  function sync() {
    var pct = type.value === 'percent';
    affix.textContent = pct ? '%' : symbol;
    maxWrap.style.display = pct ? '' : 'none';
  }
  type.addEventListener('change', sync); sync();
  document.getElementById('genCode').addEventListener('click', function () {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', out = '';
    var buf = new Uint32Array(8); (window.crypto || window.msCrypto).getRandomValues(buf);
    for (var i = 0; i < 8; i++) out += chars[buf[i] % chars.length];
    document.getElementById('code').value = out;
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
