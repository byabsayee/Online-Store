<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();

const CONTACT_MAX_ADDRESSES = 6;
const CONTACT_MAX_EMAILS = 6;
const CONTACT_MAX_PHONES = 8;

$errors = [];
/** Rows posted as parallel arrays (name[] ...) -> list of assoc rows, blanks dropped. */
$rows = function (array $fields, int $max) {
    $out = [];
    $n = max(array_map(fn ($f) => is_array($_POST[$f] ?? null) ? count($_POST[$f]) : 0, $fields));
    for ($i = 0; $i < $n; $i++) {
        $r = [];
        foreach ($fields as $f) $r[$f] = trim((string) ($_POST[$f][$i] ?? ''));
        if (implode('', $r) === '') continue;
        $out[] = $r;
    }
    return [array_slice($out, 0, $max), count($out) > $max];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    [$ad, $tooMany1] = $rows(['a_label', 'a_text', 'a_map'], CONTACT_MAX_ADDRESSES);
    [$em, $tooMany2] = $rows(['e_label', 'e_email'], CONTACT_MAX_EMAILS);
    [$ph, $tooMany3] = $rows(['p_label', 'p_number'], CONTACT_MAX_PHONES);
    if ($tooMany1 || $tooMany2 || $tooMany3) $errors[] = 'Too many rows — the limits are ' . CONTACT_MAX_ADDRESSES . ' addresses, ' . CONTACT_MAX_EMAILS . ' emails and ' . CONTACT_MAX_PHONES . ' phone numbers.';

    $cleanAd = [];
    foreach ($ad as $r) {
        if ($r['a_text'] === '') { $errors[] = 'An address row has a label or map link but no address.'; continue; }
        if ($r['a_map'] !== '' && map_link_clean($r['a_map']) === '') { $errors[] = 'The map link for “' . mb_substr($r['a_text'], 0, 30) . '…” must be a full https:// address (copy it from Google Maps → Share → Copy link).'; continue; }
        $cleanAd[] = ['label' => mb_substr($r['a_label'], 0, 40), 'text' => mb_substr($r['a_text'], 0, 300), 'map' => $r['a_map']];
    }
    $cleanEm = [];
    foreach ($em as $r) {
        if (!filter_var($r['e_email'], FILTER_VALIDATE_EMAIL)) { $errors[] = '“' . $r['e_email'] . '” is not a valid email address.'; continue; }
        $cleanEm[] = ['label' => mb_substr($r['e_label'], 0, 40), 'email' => $r['e_email']];
    }
    $cleanPh = [];
    foreach ($ph as $r) {
        if (!preg_match('/^\+?[\d\s().-]{5,25}$/', $r['p_number'])) { $errors[] = '“' . $r['p_number'] . '” does not look like a phone number.'; continue; }
        $cleanPh[] = ['label' => mb_substr($r['p_label'], 0, 40), 'number' => $r['p_number']];
    }
    $mainPhone = mb_substr(trim((string) ($_POST['main_phone'] ?? '')), 0, 40);
    if ($mainPhone !== '' && !preg_match('/^\+?[\d\s().-]{5,40}$/', $mainPhone)) $errors[] = 'The main phone number should only contain digits, spaces, + ( ) - or a dot.';
    $mainEmail = trim((string) ($_POST['main_email'] ?? ''));
    if ($mainEmail !== '' && !filter_var($mainEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'The main email address doesn\'t look right.';
    $mainAddress = mb_substr(trim(str_replace("\r", '', (string) ($_POST['main_address'] ?? ''))), 0, 300);
    $mainMap = trim((string) ($_POST['main_map'] ?? ''));
    if ($mainMap !== '' && map_link_clean($mainMap) === '') $errors[] = 'The map link for the main address must be a full https:// address.';

    if (!$errors) {
        $before = store_info();
        set_setting('store_phone', $mainPhone);
        set_setting('store_phone2', '');   // the old "second phone" now lives in the list below
        set_setting('store_email', $mainEmail);
        set_setting('store_address', $mainAddress);
        set_setting('store_address_label', mb_substr(trim((string) ($_POST['main_label'] ?? '')), 0, 40));
        set_setting('store_address_map', $mainMap);
        set_setting('contact_addresses', json_encode($cleanAd, JSON_UNESCAPED_UNICODE));
        set_setting('contact_emails', json_encode($cleanEm, JSON_UNESCAPED_UNICODE));
        set_setting('contact_phones', json_encode($cleanPh, JSON_UNESCAPED_UNICODE));
        $ch = admin_log_diff(['phone' => $before['phone'], 'email' => $before['email'], 'address' => $before['address']], ['phone' => $mainPhone, 'email' => $mainEmail, 'address' => $mainAddress], ['phone' => 'Main phone', 'email' => 'Main email', 'address' => 'Main address']);
        admin_log('settings.contacts', 'Updated addresses, emails and phone numbers' . ($ch ? ': ' . admin_log_diff_summary($ch) : ''), null, null, $ch ? ['changes' => $ch] : []);
        flash_set('success', 'Saved.');
        redirect('/admin/contacts.php');
    }
}

$store = store_info();
$val = fn (string $k, array $r, string $f, string $d = '') => e($_SERVER['REQUEST_METHOD'] === 'POST' ? '' : ($r[$f] ?? $d));
$exAd = $_SERVER['REQUEST_METHOD'] === 'POST' ? [] : contact_extras('contact_addresses');
$exEm = $_SERVER['REQUEST_METHOD'] === 'POST' ? [] : contact_extras('contact_emails');
$exPh = $_SERVER['REQUEST_METHOD'] === 'POST' ? [] : contact_extras('contact_phones');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $store['phone2'] !== '') array_unshift($exPh, ['label' => '', 'number' => $store['phone2']]); // old "second phone"
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // keep what was typed when validation failed
    foreach (($_POST['a_text'] ?? []) as $i => $t) $exAd[] = ['label' => $_POST['a_label'][$i] ?? '', 'text' => $t, 'map' => $_POST['a_map'][$i] ?? ''];
    foreach (($_POST['e_email'] ?? []) as $i => $t) $exEm[] = ['label' => $_POST['e_label'][$i] ?? '', 'email' => $t];
    foreach (($_POST['p_number'] ?? []) as $i => $t) $exPh[] = ['label' => $_POST['p_label'][$i] ?? '', 'number' => $t];
}

$pageTitle = 'Addresses & contacts';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';
?>
<form method="post" id="contactsForm"><?= csrf_field() ?>
<section class="panel"><div class="panel-head"><h2>Addresses</h2></div><div class="panel-body">
  <p class="help" style="margin-top:0;">Shown in the footer, on the contact page and on invoices, each with an optional <strong>Open in Google Maps</strong> link. The first one is your main address.</p>
  <div class="field-row">
    <div class="field" style="flex:2;"><label for="main_address">Main address</label><textarea id="main_address" name="main_address" rows="3" maxlength="300" placeholder="House, road, area&#10;City"><?= e($_POST['main_address'] ?? $store['address']) ?></textarea></div>
    <div class="field"><label for="main_label">Label <span class="muted" style="font-weight:400;">(optional)</span></label><input id="main_label" name="main_label" maxlength="40" placeholder="Head office" value="<?= e($_POST['main_label'] ?? get_setting('store_address_label', '')) ?>"></div>
    <div class="field"><label for="main_map">Google Maps link</label><input id="main_map" name="main_map" type="url" placeholder="https://maps.app.goo.gl/…" value="<?= e($_POST['main_map'] ?? get_setting('store_address_map', '')) ?>"></div>
  </div>
  <div id="rows-a"><?php foreach ($exAd as $r): ?>
    <div class="field-row crow"><div class="field"><label>Label</label><input name="a_label[]" maxlength="40" placeholder="Showroom" value="<?= e($r['label'] ?? '') ?>"></div>
      <div class="field" style="flex:2;"><label>Address</label><textarea name="a_text[]" rows="2" maxlength="300"><?= e($r['text'] ?? '') ?></textarea></div>
      <div class="field"><label>Google Maps link</label><input name="a_map[]" type="url" placeholder="https://maps.app.goo.gl/…" value="<?= e($r['map'] ?? '') ?>"></div>
      <div class="field"><label>&nbsp;</label><button type="button" class="btn btn-outline btn-sm" data-remove>Remove</button></div></div>
  <?php endforeach; ?></div>
  <button type="button" class="btn btn-outline btn-sm" data-add="a">+ Add another address</button>
  <p class="help">In Google Maps: find the place → <em>Share</em> → <em>Copy link</em>, then paste it here.</p>
</div></section>

<section class="panel"><div class="panel-head"><h2>Email addresses</h2></div><div class="panel-body">
  <div class="field"><label for="main_email">Main email</label><input id="main_email" name="main_email" type="email" value="<?= e($_POST['main_email'] ?? $store['email']) ?>"><div class="hint">Messages from the contact form are delivered here, and it is the "reply to" address on customer emails. Add more addresses (sales, support…) below.</div></div>
  <div id="rows-e"><?php foreach ($exEm as $r): ?>
    <div class="field-row crow"><div class="field"><label>Label</label><input name="e_label[]" maxlength="40" placeholder="Support" value="<?= e($r['label'] ?? '') ?>"></div>
      <div class="field" style="flex:2;"><label>Email</label><input name="e_email[]" type="email" value="<?= e($r['email'] ?? '') ?>"></div>
      <div class="field"><label>&nbsp;</label><button type="button" class="btn btn-outline btn-sm" data-remove>Remove</button></div></div>
  <?php endforeach; ?></div>
  <button type="button" class="btn btn-outline btn-sm" data-add="e">+ Add another email</button>
</div></section>

<section class="panel"><div class="panel-head"><h2>Phone numbers</h2></div><div class="panel-body">
  <div class="field"><label for="main_phone">Main phone number</label><input id="main_phone" name="main_phone" maxlength="40" placeholder="+880 1XXX-XXXXXX" value="<?= e($_POST['main_phone'] ?? $store['phone']) ?>"></div>
  <p class="help" style="margin-top:0;">Add more numbers below (one per branch, a WhatsApp line…).</p>
  <div id="rows-p"><?php foreach ($exPh as $r): ?>
    <div class="field-row crow"><div class="field"><label>Label</label><input name="p_label[]" maxlength="40" placeholder="Sales" value="<?= e($r['label'] ?? '') ?>"></div>
      <div class="field" style="flex:2;"><label>Number</label><input name="p_number[]" maxlength="25" placeholder="+880 1XXX-XXXXXX" value="<?= e($r['number'] ?? '') ?>"></div>
      <div class="field"><label>&nbsp;</label><button type="button" class="btn btn-outline btn-sm" data-remove>Remove</button></div></div>
  <?php endforeach; ?></div>
  <button type="button" class="btn btn-outline btn-sm" data-add="p">+ Add another number</button>
</div></section>

<button class="btn btn-primary">Save</button>
</form>

<template id="tpl-a"><div class="field-row crow"><div class="field"><label>Label</label><input name="a_label[]" maxlength="40" placeholder="Showroom"></div>
  <div class="field" style="flex:2;"><label>Address</label><textarea name="a_text[]" rows="2" maxlength="300"></textarea></div>
  <div class="field"><label>Google Maps link</label><input name="a_map[]" type="url" placeholder="https://maps.app.goo.gl/…"></div>
  <div class="field"><label>&nbsp;</label><button type="button" class="btn btn-outline btn-sm" data-remove>Remove</button></div></div></template>
<template id="tpl-e"><div class="field-row crow"><div class="field"><label>Label</label><input name="e_label[]" maxlength="40" placeholder="Support"></div>
  <div class="field" style="flex:2;"><label>Email</label><input name="e_email[]" type="email"></div>
  <div class="field"><label>&nbsp;</label><button type="button" class="btn btn-outline btn-sm" data-remove>Remove</button></div></div></template>
<template id="tpl-p"><div class="field-row crow"><div class="field"><label>Label</label><input name="p_label[]" maxlength="40" placeholder="Sales"></div>
  <div class="field" style="flex:2;"><label>Number</label><input name="p_number[]" maxlength="25" placeholder="+880 1XXX-XXXXXX"></div>
  <div class="field"><label>&nbsp;</label><button type="button" class="btn btn-outline btn-sm" data-remove>Remove</button></div></div></template>
<script>
(function(){
  var f=document.getElementById('contactsForm');
  f.addEventListener('click',function(ev){
    var add=ev.target.closest('[data-add]'),rm=ev.target.closest('[data-remove]');
    if(add){var k=add.getAttribute('data-add');document.getElementById('rows-'+k).appendChild(document.getElementById('tpl-'+k).content.cloneNode(true));}
    if(rm){var r=rm.closest('.crow');if(r)r.remove();}
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
