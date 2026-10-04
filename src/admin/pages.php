<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];
$defs = site_page_defs();
$editSlug = $_GET['edit'] ?? '';
if ($editSlug !== '' && !isset($defs[$editSlug]) && $editSlug !== 'home') $editSlug = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    $slug = $_POST['slug'] ?? '';
    if ($act === 'page' && isset($defs[$slug])) {
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 120);
        $body = sanitize_page_html((string) ($_POST['body_html'] ?? ''));
        if ($title === '') $title = $defs[$slug]['title'];
        // An empty editor means "use the built-in starter text".
        db()->prepare('INSERT INTO site_pages (slug, title, body_html, is_enabled) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title = VALUES(title), body_html = VALUES(body_html), is_enabled = VALUES(is_enabled)')
            ->execute([$slug, $title, $body !== '' ? $body : null, !empty($_POST['is_enabled']) ? 1 : 0]);
        admin_log('page.update', 'Edited the page "' . $title . '"', 'page', null);
        flash_set('success', 'Page saved.');
        redirect('/admin/pages.php');
    } elseif ($act === 'reset' && isset($defs[$slug])) {
        db()->prepare('UPDATE site_pages SET body_html = NULL WHERE slug = ?')->execute([$slug]);
        flash_set('success', 'Back to the built-in starter text.');
        redirect('/admin/pages.php?edit=' . urlencode($slug));
    } elseif ($act === 'toggle' && isset($defs[$slug])) {
        $on = !empty($_POST['on']) ? 1 : 0;
        db()->prepare('INSERT INTO site_pages (slug, title, is_enabled) VALUES (?,?,?) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)')->execute([$slug, $defs[$slug]['title'], $on]);
        flash_set('success', $defs[$slug]['title'] . ($on ? ' is on.' : ' is off — its footer links are hidden too.'));
        redirect('/admin/pages.php');
    } elseif ($act === 'home') {
        $keys = ['home_eyebrow' => 80, 'home_headline' => 160, 'home_lead' => 300, 'home_cta' => 40, 'home_card_title' => 80, 'home_stamp' => 30, 'home_why_tag' => 60, 'home_why_title' => 100,
            'home_c1_title' => 60, 'home_c1_text' => 220, 'home_c2_title' => 60, 'home_c2_text' => 220, 'home_c3_title' => 60, 'home_c3_text' => 220];
        foreach ($keys as $k => $max) set_setting($k, mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max));
        $pts = array_slice(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['home_points'] ?? '')))), 0, 8);
        set_setting('home_points', implode("\n", array_map(fn ($x) => mb_substr($x, 0, 90), $pts)));
        admin_log('page.home', 'Home page text edited');
        flash_set('success', 'Home page text saved.');
        redirect('/admin/pages.php');
    } elseif ($act === 'cookie') {
        set_setting('cookie_notice', !empty($_POST['cookie_notice']) ? '1' : '0');
        flash_set('success', 'Saved.');
        redirect('/admin/pages.php');
    }
}

$pageTitle = $editSlug === 'home' ? 'Home page text' : ($editSlug ? 'Edit page' : 'Pages');
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';

if ($editSlug === 'home'):
    $h = home_content();
    $raw = fn (string $k, string $d = '') => e((string) get_setting($k, $d));
?>
<section class="panel"><div class="panel-head"><h2>Home page text</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="home">
  <div class="field"><label>Small line above the headline <span class="muted" style="font-weight:400;">(optional)</span></label><input name="home_eyebrow" maxlength="80" value="<?= e($h['eyebrow']) ?>"></div>
  <div class="field"><label>Headline</label><input name="home_headline" maxlength="160" value="<?= e($h['headline']) ?>"></div>
  <div class="field"><label>Intro sentence</label><textarea name="home_lead" rows="2" maxlength="300"><?= e($h['lead']) ?></textarea></div>
  <div class="field-row"><div class="field"><label>Button text</label><input name="home_cta" maxlength="40" value="<?= e($h['cta']) ?>"></div>
    <div class="field"><label>Round stamp text <span class="muted" style="font-weight:400;">(optional, e.g. “EST. 2026”)</span></label><input name="home_stamp" maxlength="30" value="<?= e($h['stamp']) ?>"></div></div>
  <div class="field"><label>Highlight card title</label><input name="home_card_title" maxlength="80" value="<?= e($h['card_title']) ?>"></div>
  <div class="field"><label>Highlight card points <span class="muted" style="font-weight:400;">(one per line, up to 8; leave empty to hide the card)</span></label><textarea name="home_points" rows="5"><?= e(implode("\n", $h['points'])) ?></textarea></div>
  <div class="field-row"><div class="field"><label>“Why choose us” small tag</label><input name="home_why_tag" maxlength="60" value="<?= e($h['why_tag']) ?>"></div>
    <div class="field"><label>“Why choose us” title</label><input name="home_why_title" maxlength="100" value="<?= e($h['why_title']) ?>"></div></div>
  <?php foreach ([1, 2, 3] as $n): ?>
  <div class="field-row"><div class="field"><label>Card <?= $n ?> title</label><input name="home_c<?= $n ?>_title" maxlength="60" value="<?= e($h['cards'][$n - 1][0]) ?>"></div>
    <div class="field"><label>Card <?= $n ?> text</label><input name="home_c<?= $n ?>_text" maxlength="220" value="<?= e($h['cards'][$n - 1][1]) ?>"></div></div>
  <?php endforeach; ?>
  <button class="btn btn-primary">Save</button> <a class="btn btn-outline" href="/admin/pages.php">Back</a>
</form></div></section>
<?php elseif ($editSlug): $row = site_page_row($editSlug); $body = ($row && trim((string) $row['body_html']) !== '') ? $row['body_html'] : site_page_starter($editSlug); ?>
<section class="panel"><div class="panel-head"><h2>Edit: <?= e($defs[$editSlug]['title']) ?></h2></div><div class="panel-body">
<form method="post" id="pageForm"><?= csrf_field() ?><input type="hidden" name="action" value="page"><input type="hidden" name="slug" value="<?= e($editSlug) ?>">
  <div class="field"><label for="ptitle">Page title</label><input id="ptitle" name="title" maxlength="120" value="<?= e(site_page_title($editSlug)) ?>"></div>
  <div class="field"><label>Text</label>
    <div class="rte-bar" role="toolbar" aria-label="Formatting">
      <button type="button" data-cmd="bold"><b>B</b></button><button type="button" data-cmd="italic"><i>I</i></button><button type="button" data-cmd="underline"><u>U</u></button>
      <button type="button" data-block="h2">Heading</button><button type="button" data-block="h3">Subheading</button><button type="button" data-block="p">Paragraph</button>
      <button type="button" data-cmd="insertUnorderedList">• List</button><button type="button" data-cmd="insertOrderedList">1. List</button>
      <button type="button" data-link>Link</button><button type="button" data-cmd="removeFormat">Clear</button>
    </div>
    <div id="rte" class="rte prose" contenteditable="true"><?= $body ?></div>
    <textarea name="body_html" id="body_html" hidden></textarea>
    <p class="help">Placeholders fill in by themselves and update when your store details change: <code>{store_name}</code> <code>{store_email}</code> <code>{store_phone}</code> <code>{store_address}</code> <code>{updated}</code> <code>{currency}</code> <code>{delivery_days}</code> <code>{delivery_zones}</code> <code>{free_kg}</code> <code>{extra_per_kg}</code>. The starter wording is a generic sample, not legal advice — please review it for your country and business.</p>
  </div>
  <div class="checkbox-row" style="margin-bottom:14px;"><input type="checkbox" id="pen" name="is_enabled" value="1" <?= site_page_enabled($editSlug) ? 'checked' : '' ?>><label for="pen" style="margin:0;font-weight:400;">This page is on (visible to visitors)</label></div>
  <button class="btn btn-primary">Save page</button> <a class="btn btn-outline" href="/admin/pages.php">Back</a>
</form>
<form method="post" style="margin-top:12px;" onsubmit="return confirm('Replace your wording with the built-in starter text?');"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="slug" value="<?= e($editSlug) ?>"><button class="btn btn-outline btn-sm">Use the starter text again</button></form>
</div></section>
<script>
(function () {
  var rte = document.getElementById('rte'), ta = document.getElementById('body_html');
  document.querySelectorAll('.rte-bar [data-cmd]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); document.execCommand(b.dataset.cmd, false, null); }); });
  document.querySelectorAll('.rte-bar [data-block]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); document.execCommand('formatBlock', false, b.dataset.block); }); });
  var lk = document.querySelector('.rte-bar [data-link]');
  lk.addEventListener('mousedown', function (e) { e.preventDefault(); var u = prompt('Link address (https://…, /page, mailto:…)'); if (u) document.execCommand('createLink', false, u); });
  document.getElementById('pageForm').addEventListener('submit', function () { ta.value = rte.innerHTML; });
})();
</script>
<?php else: ?>
<section class="panel"><div class="panel-head"><h2>Pages <span class="sub">edit the wording of your public pages</span></h2></div>
<table class="admin-table"><thead><tr><th>Page</th><th>Address</th><th>Status</th><th></th></tr></thead><tbody>
  <tr><td><strong>Home page text</strong><div class="muted small">Headline, highlights and “why choose us” cards</div></td><td><a class="link" href="/" target="_blank">/</a></td><td><span class="pill pill-sage">On</span></td><td style="text-align:right;"><a class="btn btn-outline btn-sm" href="/admin/pages.php?edit=home">Edit</a></td></tr>
  <?php foreach ($defs as $slug => $d): $on = site_page_enabled($slug); $row = site_page_row($slug); ?>
  <tr><td><strong><?= e(site_page_title($slug)) ?></strong><div class="muted small"><?= $row && trim((string) $row['body_html']) !== '' ? 'Your own wording' : 'Starter text' ?></div></td>
    <td><a class="link" href="<?= e($d['url']) ?>" target="_blank"><?= e($d['url']) ?></a></td>
    <td><?= $on ? '<span class="pill pill-sage">On</span>' : '<span class="pill pill-ink">Off</span>' ?></td>
    <td style="text-align:right;"><a class="btn btn-outline btn-sm" href="/admin/pages.php?edit=<?= e($slug) ?>">Edit</a>
      <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="slug" value="<?= e($slug) ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>"><button class="btn btn-outline btn-sm"><?= $on ? 'Switch off' : 'Switch on' ?></button></form></td></tr>
  <?php endforeach; ?>
</tbody></table></section>
<section class="panel"><div class="panel-head"><h2>Cookie notice</h2></div><div class="panel-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cookie">
  <div class="checkbox-row" style="margin-bottom:10px;"><input type="checkbox" id="cn" name="cookie_notice" value="1" <?= cookie_notice_on() ? 'checked' : '' ?>><label for="cn" style="margin:0;font-weight:400;">Show a small cookie notice with Accept / Decline (ads only load after “Accept”)</label></div>
  <button class="btn btn-primary">Save</button></form></div></section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
