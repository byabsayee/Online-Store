<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];
$step = max(1, min(5, (int) ($_GET['step'] ?? 1)));
$home = '/admin/setup.php?step=';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'basics') {
        $name = mb_substr(trim($_POST['store_name'] ?? ''), 0, 80);
        $email = trim($_POST['store_email'] ?? '');
        $phone = mb_substr(trim($_POST['store_phone'] ?? ''), 0, 40);
        $region = ($_POST['region'] ?? 'bd') === 'intl' ? 'intl' : 'bd';
        if ($name === '') $errors[] = 'Give your store a name.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'The email address doesn’t look right.';
        if ($phone !== '' && !preg_match('/^\+?[\d\s().-]{5,40}$/', $phone)) $errors[] = 'The phone number should only contain digits, spaces, + ( ) - or a dot.';
        $sym = mb_substr(trim($_POST['currency_symbol'] ?? ''), 0, 6); $code = strtoupper(trim($_POST['currency_code'] ?? '')); $tz = $_POST['timezone'] ?? 'Asia/Dhaka';
        if ($region === 'intl') {
            if ($sym === '') $errors[] = 'Enter your currency symbol (for example $).';
            if (!preg_match('/^[A-Z]{3}$/', $code)) $errors[] = 'Enter your 3-letter currency code (for example USD).';
            if (!in_array($tz, timezone_identifiers_list(), true)) $errors[] = 'Choose your time zone.';
        }
        if (!$errors) {
            set_setting('store_name', $name); set_setting('store_tagline', mb_substr(trim($_POST['store_tagline'] ?? ''), 0, 80));
            set_setting('store_email', $email); set_setting('store_phone', $phone); set_setting('store_address', mb_substr(trim(str_replace("\r", '', $_POST['store_address'] ?? '')), 0, 300));
            set_setting('smtp_from_name', $name);
            if ($region === 'bd') {
                foreach (['currency_symbol' => '৳', 'currency_code' => 'BDT', 'currency_pos' => 'before', 'currency_decimals' => '2', 'timezone' => 'Asia/Dhaka',
                          'zone_label_inside' => 'Inside Dhaka', 'zone_label_suburbs' => 'Dhaka Suburbs', 'zone_label_outside' => 'Outside Dhaka', 'zone_off_suburbs' => '0', 'zone_off_outside' => '0',
                          'ship_inside' => '80', 'ship_suburbs' => '100', 'ship_outside' => '130', 'ship_free_kg' => '1', 'ship_extra_kg' => '20'] as $k => $v) set_setting($k, $v);
                set_setting('setup_region', 'bd');
            } else {
                foreach (['currency_symbol' => $sym, 'currency_code' => $code, 'currency_pos' => ($_POST['currency_pos'] ?? '') === 'after' ? 'after' : 'before', 'currency_decimals' => '2', 'timezone' => $tz,
                          'zone_label_inside' => 'Local delivery', 'zone_label_suburbs' => 'Regional delivery', 'zone_label_outside' => 'International delivery', 'zone_off_suburbs' => '1', 'zone_off_outside' => '1',
                          'ship_inside' => '5', 'ship_suburbs' => '10', 'ship_outside' => '25', 'ship_free_kg' => '1', 'ship_extra_kg' => '2'] as $k => $v) set_setting($k, $v);
                set_setting('setup_region', 'intl');
            }
            redirect($home . '2');
        }
    } elseif ($act === 'look') {
        foreach (['theme_primary', 'theme_secondary'] as $k) { $v = strtolower(trim($_POST[$k] ?? '')); if (preg_match('/^#[0-9a-f]{6}$/', $v)) set_setting($k, $v); }
        redirect($home . '3');
    } elseif ($act === 'shop') {
        $fee = fn ($k) => is_numeric($_POST[$k] ?? '') && (float) $_POST[$k] >= 0 ? round((float) $_POST[$k], 2) : null;
        $f1 = $fee('fee1'); $f2 = $fee('fee2'); $f3 = $fee('fee3');
        if ($f1 === null || $f2 === null || $f3 === null) $errors[] = 'Enter a delivery fee (0 or more) for every zone.';
        foreach (['label1' => 'inside', 'label2' => 'suburbs', 'label3' => 'outside'] as $lk => $zk) if (trim($_POST[$lk] ?? '') === '') $errors[] = 'Give every delivery zone a name.';
        if (!$errors) {
            foreach (['label1' => 'inside', 'label2' => 'suburbs', 'label3' => 'outside'] as $lk => $zk) set_setting('zone_label_' . $zk, mb_substr(trim($_POST[$lk]), 0, 40));
            set_setting('ship_inside', (string) $f1); set_setting('ship_suburbs', (string) $f2); set_setting('ship_outside', (string) $f3);
            set_setting('zone_off_suburbs', empty($_POST['on2']) ? '1' : '0'); set_setting('zone_off_outside', empty($_POST['on3']) ? '1' : '0');
            $defs = ['bkash' => ['bKash', 'Send the total with “Send Money” to the number above, then enter the number you sent from and the transaction ID below.'],
                'rocket' => ['Rocket', 'Send the total to the Rocket number above, then enter the number you sent from and the transaction ID below.'],
                'nagad' => ['Nagad', 'Send the total with “Send Money” to the number above, then enter the number you sent from and the transaction ID below.'],
                'upay' => ['Upay', 'Send the total to the Upay number above, then enter the number you sent from and the transaction ID below.'],
                'bank_transfer' => ['Bank transfer', 'Transfer the total to the account above and enter the sender name/number and the transaction reference below.']];
            foreach ($defs as $code => [$nm, $ins]) {
                if (empty($_POST['pm_' . $code])) continue;
                $acc = mb_substr(trim($_POST['acc_' . $code] ?? ''), 0, 200);
                $exists = db()->prepare('SELECT id FROM payment_methods WHERE code = ?'); $exists->execute([$code]);
                if ($exists->fetchColumn()) { db()->prepare('UPDATE payment_methods SET is_active = 1, account_details = ? WHERE code = ?')->execute([$acc ?: null, $code]); continue; }
                db()->prepare("INSERT INTO payment_methods (code, name, kind, instructions, account_details, ask_txn, is_active, sort_order) VALUES (?,?,'manual',?,?,1,1,?)")->execute([$code, $nm, $ins, $acc ?: null, 10 + array_search($code, array_keys($defs), true)]);
            }
            redirect($home . '4');
        }
    } elseif ($act === 'pages') {
        $slugs = array_values(array_intersect(array_keys(site_page_defs()), (array) ($_POST['pages'] ?? [])));
        create_starter_pages($slugs);
        set_setting('cookie_notice', !empty($_POST['cookie_notice']) ? '1' : '0');
        if (!empty($_POST['sample'])) load_sample_catalog();
        set_setting('setup_done', '1');
        admin_log('setup.done', 'First-run setup finished');
        redirect($home . '5');
    }
}

$pageTitle = 'Set up your store';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';
$stepNames = ['Your store', 'Look', 'Delivery & payments', 'Pages', 'Done'];
?>
<div class="wizard-steps"><?php foreach ($stepNames as $i => $n): ?><span class="<?= $i + 1 === $step ? 'on' : ($i + 1 < $step ? 'done' : '') ?>"><b><?= $i + 1 ?></b> <?= e($n) ?></span><?php endforeach; ?></div>

<?php if ($step === 1): $intl = ($_POST['region'] ?? get_setting('setup_region', 'bd')) === 'intl'; ?>
<section class="panel"><div class="panel-head"><h2>Welcome! Tell us about your store</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="basics">
  <div class="field-row"><div class="field"><label>Store name</label><input name="store_name" required maxlength="80" value="<?= e($_POST['store_name'] ?? (get_setting('store_name', '') ?: '')) ?>"></div>
    <div class="field"><label>Tagline <span class="muted" style="font-weight:400;">(optional)</span></label><input name="store_tagline" maxlength="80" value="<?= e($_POST['store_tagline'] ?? get_setting('store_tagline', '')) ?>"></div></div>
  <div class="field-row"><div class="field"><label>Contact email</label><input type="email" name="store_email" value="<?= e($_POST['store_email'] ?? get_setting('store_email', '')) ?>"></div>
    <div class="field"><label>Phone</label><input name="store_phone" value="<?= e($_POST['store_phone'] ?? get_setting('store_phone', '')) ?>"></div></div>
  <div class="field"><label>Address <span class="muted" style="font-weight:400;">(shown in the footer and on invoices)</span></label><textarea name="store_address" rows="2"><?= e($_POST['store_address'] ?? get_setting('store_address', '')) ?></textarea></div>
  <div class="field"><label>Where do you sell?</label>
    <label class="radio-option"><input type="radio" name="region" value="bd" <?= !$intl ? 'checked' : '' ?> onchange="document.getElementById('intl').hidden=true"><span class="radio-option-label">Bangladesh <span class="hint" style="display:block;font-weight:400;">৳ BDT, Dhaka delivery zones, cash on delivery, bKash / Nagad and more</span></span></label>
    <label class="radio-option"><input type="radio" name="region" value="intl" <?= $intl ? 'checked' : '' ?> onchange="document.getElementById('intl').hidden=false"><span class="radio-option-label">International / another country <span class="hint" style="display:block;font-weight:400;">Pick your own currency and time zone; set delivery zones in the next steps</span></span></label></div>
  <div id="intl" <?= $intl ? '' : 'hidden' ?>><div class="field-row">
    <div class="field"><label>Currency symbol</label><input name="currency_symbol" maxlength="6" placeholder="$" value="<?= e($_POST['currency_symbol'] ?? (get_setting('setup_region') === 'intl' ? store_currency_symbol() : '')) ?>"></div>
    <div class="field"><label>Currency code</label><input name="currency_code" maxlength="3" placeholder="USD" value="<?= e($_POST['currency_code'] ?? (get_setting('setup_region') === 'intl' ? store_currency_code() : '')) ?>"></div>
    <div class="field"><label>Symbol position</label><select name="currency_pos"><option value="before">Before (100)</option><option value="after">After (100 €)</option></select></div>
    <div class="field"><label>Time zone</label><select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option <?= $z === ($_POST['timezone'] ?? date_default_timezone_get()) ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></div></div></div>
  <button class="btn btn-primary">Next →</button>
</form></div></section>

<?php elseif ($step === 2): $t = theme_settings(); ?>
<section class="panel"><div class="panel-head"><h2>Pick your colours</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="look">
  <div class="field-row"><div class="field"><label>Main colour (buttons, accents)</label><input type="color" name="theme_primary" value="<?= e($t['primary']) ?>"></div>
    <div class="field"><label>Second colour</label><input type="color" name="theme_secondary" value="<?= e($t['secondary']) ?>"></div></div>
  <p class="help">Upload your logo, favicon and share image in <a class="link" href="/admin/branding.php" target="_blank">Branding &amp; sharing</a> and choose fonts in <a class="link" href="/admin/fonts.php" target="_blank">Fonts</a> — any time, no rush. These open in a new tab so you can come back here.</p>
  <a class="btn btn-outline" href="<?= $home ?>1">← Back</a> <button class="btn btn-primary">Next →</button>
</form></div></section>

<?php elseif ($step === 3): $bd = get_setting('setup_region', 'bd') === 'bd'; ?>
<section class="panel"><div class="panel-head"><h2>Delivery &amp; payments</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="shop">
  <h3 style="margin-top:0;">Delivery zones <span class="muted" style="font-weight:400;font-size:.8rem;">(<?= e(store_currency_symbol()) ?> fee for the first kg; change weight rules later in Delivery &amp; tax)</span></h3>
  <?php foreach ([[1, 'inside', true], [2, 'suburbs', false], [3, 'outside', false]] as [$n, $zk, $always]): ?>
  <div class="field-row"><div class="field"><label>Zone <?= $n ?> name</label><input name="label<?= $n ?>" maxlength="40" value="<?= e($_POST['label' . $n] ?? delivery_area_label(array_search($zk, DELIVERY_ZONE_KEYS, true))) ?>"></div>
    <div class="field"><label>Fee</label><input type="number" step="0.01" min="0" name="fee<?= $n ?>" value="<?= e((string) ($_POST['fee' . $n] ?? shipcfg($zk))) ?>"></div>
    <?php if (!$always): ?><div class="field"><label>&nbsp;</label><label class="checkbox-row" style="margin:0;"><input type="checkbox" name="on<?= $n ?>" value="1" <?= get_setting('zone_off_' . $zk, '0') !== '1' ? 'checked' : '' ?>> Offer this zone</label></div><?php else: ?><div class="field"><label>&nbsp;</label><span class="muted">Always on</span></div><?php endif; ?></div>
  <?php endforeach; ?>
  <h3>Payment methods</h3>
  <p class="help" style="margin-top:0;">Cash on delivery is always available. Tick the others you accept and enter the number/account customers should pay to — they’ll be asked for their sender number and transaction ID.</p>
  <?php foreach (['bkash' => 'bKash', 'rocket' => 'Rocket', 'nagad' => 'Nagad', 'upay' => 'Upay', 'bank_transfer' => 'Bank transfer'] as $code => $nm): ?>
  <div class="field-row"><div class="field"><label class="checkbox-row" style="margin:0;"><input type="checkbox" name="pm_<?= $code ?>" value="1" <?= !empty($_POST['pm_' . $code]) ? 'checked' : '' ?>> <?= e($nm) ?></label></div>
    <div class="field"><input name="acc_<?= $code ?>" maxlength="200" placeholder="<?= $code === 'bank_transfer' ? 'Bank, account name & number' : '01XXXXXXXXX (Personal)' ?>" value="<?= e($_POST['acc_' . $code] ?? '') ?>"></div></div>
  <?php endforeach; ?>
  <a class="btn btn-outline" href="<?= $home ?>2">← Back</a> <button class="btn btn-primary">Next →</button>
</form></div></section>

<?php elseif ($step === 4): ?>
<section class="panel"><div class="panel-head"><h2>Pages for your store</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pages">
  <p class="help" style="margin-top:0;">We create these pages with editable starter text that already uses your store’s name, email and delivery details. <strong>The wording is a generic sample, not legal advice</strong> — review it before you go live.</p>
  <?php foreach (site_page_defs() as $slug => $d): ?><div class="checkbox-row" style="margin-bottom:6px;"><input type="checkbox" id="pg_<?= e($slug) ?>" name="pages[]" value="<?= e($slug) ?>" checked><label for="pg_<?= e($slug) ?>" style="margin:0;font-weight:400;"><?= e($d['title']) ?></label></div><?php endforeach; ?>
  <div class="checkbox-row" style="margin:14px 0 6px;"><input type="checkbox" id="cn" name="cookie_notice" value="1"><label for="cn" style="margin:0;font-weight:400;">Show a cookie notice</label></div>
  <div class="checkbox-row" style="margin-bottom:14px;"><input type="checkbox" id="sm" name="sample" value="1"><label for="sm" style="margin:0;font-weight:400;">Add four sample products so the store doesn’t look empty (you can delete them)</label></div>
  <a class="btn btn-outline" href="<?= $home ?>3">← Back</a> <button class="btn btn-primary">Finish setup</button>
</form></div></section>

<?php else: ?>
<section class="panel"><div class="panel-head"><h2>You’re set up 🎉</h2></div><div class="panel-body">
  <p>Your store is ready. A few things worth doing next:</p>
  <ul class="checklist">
    <li><a class="link" href="/admin/account.php">Change your admin password</a> (and username) if you haven’t yet</li>
    <li><a class="link" href="/admin/branding.php">Upload your logo, favicon and share image</a></li>
    <li><a class="link" href="/admin/settings.php">Set up outgoing email</a> so order and password emails are delivered</li>
    <li><a class="link" href="/admin/notifications.php">Get an email for every new order</a></li>
    <li><a class="link" href="/admin/products.php">Add your products</a></li>
    <li><a class="link" href="/admin/pages.php">Read and edit your pages</a>, and the <a class="link" href="/admin/pages.php?edit=home">home page text</a></li>
    <li><a class="link" href="/admin/footer_links.php">Edit footer links</a> · <a class="link" href="/admin/partners.php">Partners page</a> · <a class="link" href="/admin/ads.php">Ads</a></li>
    <li><a class="link" href="/admin/erp.php">Link to Byabsayee accounting</a> (optional)</li>
  </ul>
  <a class="btn btn-primary" href="/admin/index.php">Go to the dashboard</a> <a class="btn btn-outline" href="/" target="_blank">View my store ↗</a>
</div></section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
