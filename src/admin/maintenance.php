<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner(); // Taking the whole store offline is an owner-only power. Must run before any POST handling.

$errors = [];
$m0 = maintenance_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $enabled = !empty($_POST['maintenance_enabled']);
    $title = mb_substr(trim($_POST['maintenance_title'] ?? ''), 0, 80);
    $message = mb_substr(trim($_POST['maintenance_message'] ?? ''), 0, 600);
    $eta = mb_substr(trim($_POST['maintenance_eta'] ?? ''), 0, 80);

    set_setting('maintenance_enabled', $enabled ? '1' : '0');
    set_setting('maintenance_title', $title);
    set_setting('maintenance_message', $message);
    set_setting('maintenance_eta', $eta);

    $diff = admin_log_diff(
        ['on' => $m0['enabled'] ? 'yes' : 'no', 'title' => $m0['title'], 'message' => $m0['message'], 'eta' => $m0['eta']],
        ['on' => $enabled ? 'yes' : 'no', 'title' => $title ?: MAINTENANCE_DEFAULT_TITLE, 'message' => $message ?: MAINTENANCE_DEFAULT_MESSAGE, 'eta' => $eta],
        ['on' => 'Maintenance mode', 'title' => 'Title', 'message' => 'Message', 'eta' => 'Expected back']
    );
    admin_log('settings.maintenance', ($enabled ? 'Maintenance mode switched ON' : 'Maintenance mode switched OFF') . ($diff ? ': ' . admin_log_diff_summary($diff) : ''), null, null, $diff ? ['changes' => $diff] : []);
    flash_set('success', $enabled ? 'Maintenance mode is ON. Visitors now see the "Under Maintenance" page — you can still browse the store while signed in.' : 'Maintenance mode is OFF. The store is open to everyone.');
    redirect('/admin/maintenance.php');
}

$m = maintenance_settings();
$rawTitle = trim((string) get_setting('maintenance_title', ''));
$rawMsg = trim((string) get_setting('maintenance_message', ''));
$pageTitle = 'Maintenance';
require __DIR__ . '/includes/header.php';
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" class="panel" id="maintForm">
  <?= csrf_field() ?>
  <div class="panel-head">
    <h2>Maintenance mode</h2>
    <span class="pill <?= $m['enabled'] ? 'pill-rust' : 'pill-sage' ?>"><?= $m['enabled'] ? 'ON — store is closed to visitors' : 'OFF — store is open' ?></span>
  </div>
  <div class="panel-body">
    <p class="help">Use this while you update products, change settings or deploy a new version. When it is on, everyone who opens the website sees an "Under Maintenance" page instead of the store. The admin portal keeps working, and you can still browse the store while you are signed in (a red bar reminds you that it is on).</p>
    <div class="field">
      <label class="switch"><input type="checkbox" name="maintenance_enabled" value="1" id="maintOn" <?= $m['enabled'] ? 'checked' : '' ?>><span class="track"></span><span>Turn on maintenance mode<small>Customers cannot browse, order or log in while this is on.</small></span></label>
    </div>
    <div class="field">
      <label for="maintTitle">Page title <span class="muted" style="font-weight:400;">(optional)</span></label>
      <input type="text" id="maintTitle" name="maintenance_title" maxlength="80" value="<?= e($rawTitle) ?>" placeholder="<?= e(MAINTENANCE_DEFAULT_TITLE) ?>">
    </div>
    <div class="field">
      <label for="maintMsg">Message <span class="muted" style="font-weight:400;">(optional)</span><span class="counter" data-counter-for="maintMsg" data-max="600"></span></label>
      <textarea id="maintMsg" name="maintenance_message" rows="4" maxlength="600" placeholder="<?= e(MAINTENANCE_DEFAULT_MESSAGE) ?>"><?= e($rawMsg) ?></textarea>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label for="maintEta">Expected back <span class="muted" style="font-weight:400;">(optional)</span></label>
      <input type="text" id="maintEta" name="maintenance_eta" maxlength="80" value="<?= e($m['eta']) ?>" placeholder="e.g. Back by 6:00 PM today">
      <div class="hint">Shown as a small badge on the page. Free text — write it however you like.</div>
    </div>
  </div>
  <div class="panel-foot">
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-outline" href="/?__maint_preview=1" target="_blank" rel="noopener">Preview the page ↗</a>
    <span class="muted small">Preview shows the last <em>saved</em> text.</span>
  </div>
</form>

<script>
(function () {
  var form = document.getElementById('maintForm'), on = document.getElementById('maintOn');
  var wasOn = <?= $m['enabled'] ? 'true' : 'false' ?>;
  form.addEventListener('submit', function (e) {
    if (on.checked && !wasOn && !confirm('Turn maintenance mode on? Visitors will see the "Under Maintenance" page until you turn it off.')) e.preventDefault();
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
