<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $client = trim($_POST['ads_client'] ?? '');
    if ($client !== '' && !preg_match('/^ca-pub-\d{8,20}$/', $client)) $errors[] = 'The publisher ID looks like ca-pub-1234567890123456 (find it in your AdSense account).';
    $txt = trim(str_replace("\r", '', $_POST['ads_txt'] ?? ''));
    if (mb_strlen($txt) > 2000) $errors[] = 'ads.txt is too long.';
    $slots = [];
    foreach (ad_slots() as $k => $_) {
        $id = trim($_POST['ad_' . $k . '_id'] ?? '');
        $on = !empty($_POST['ad_' . $k . '_on']);
        if ($id !== '' && !preg_match('/^\d{6,20}$/', $id)) $errors[] = ad_slots()[$k][0] . ': the ad unit ID is a number of 6–20 digits.';
        if ($on && $id === '') $errors[] = ad_slots()[$k][0] . ': enter its ad unit ID, or switch the slot off.';
        $slots[$k] = [$on, $id];
    }
    if (!empty($_POST['ads_enabled']) && $client === '') $errors[] = 'Enter your AdSense publisher ID before switching ads on.';
    if (!$errors) {
        set_setting('ads_enabled', !empty($_POST['ads_enabled']) ? '1' : '0');
        set_setting('ads_client', $client);
        set_setting('ads_txt', $txt);
        foreach ($slots as $k => [$on, $id]) { set_setting('ad_' . $k . '_on', $on ? '1' : '0'); set_setting('ad_' . $k . '_id', $id); }
        admin_log('settings.ads', 'Advertising settings saved');
        flash_set('success', 'Ad settings saved.');
        redirect('/admin/ads.php');
    }
}
$a = ads_settings();
$pageTitle = 'Ads';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';
?>
<form method="post"><?= csrf_field() ?>
<section class="panel"><div class="panel-head"><h2>Google AdSense</h2></div><div class="panel-body">
  <div class="checkbox-row" style="margin-bottom:12px;"><input type="checkbox" id="ae" name="ads_enabled" value="1" <?= get_setting('ads_enabled', '0') === '1' ? 'checked' : '' ?>><label for="ae" style="margin:0;font-weight:400;">Show ads on my store</label></div>
  <div class="field"><label for="ac">Publisher ID</label><input id="ac" name="ads_client" value="<?= e($_POST['ads_client'] ?? $a['client']) ?>" placeholder="ca-pub-1234567890123456"></div>
  <div class="field"><label for="at">ads.txt <span class="muted" style="font-weight:400;">(AdSense gives you a line to paste; it is served at <code>/ads.txt</code>)</span></label><textarea id="at" name="ads_txt" rows="2" placeholder="google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0"><?= e($_POST['ads_txt'] ?? get_setting('ads_txt', '')) ?></textarea></div>
  <p class="help">Create an ad unit in AdSense for each place you want, then paste its numeric <strong>ad unit ID</strong> below. Nothing is shown until ads are on, the publisher ID is valid and the place has an ID. If you also enable the cookie notice (Pages → Cookie notice), ads load only after a visitor accepts.</p>
</div></section>
<section class="panel"><div class="panel-head"><h2>Where ads appear</h2></div>
<table class="admin-table"><thead><tr><th>Place</th><th>On</th><th>Ad unit ID</th></tr></thead><tbody>
<?php foreach (ad_slots() as $k => [$label, $where]): ?>
  <tr><td><strong><?= e($label) ?></strong><div class="muted small"><?= e($where) ?></div></td>
    <td><input type="checkbox" name="ad_<?= $k ?>_on" value="1" <?= $a['slots'][$k]['on'] ? 'checked' : '' ?>></td>
    <td><input name="ad_<?= $k ?>_id" value="<?= e($_POST['ad_' . $k . '_id'] ?? $a['slots'][$k]['id']) ?>" inputmode="numeric" placeholder="1234567890" style="max-width:200px;"></td></tr>
<?php endforeach; ?></tbody></table></section>
<button class="btn btn-primary">Save ad settings</button>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
