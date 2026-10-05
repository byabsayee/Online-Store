<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/order_mail.php';
require_owner();

$defs = email_template_defs();
$key = (string) ($_GET['edit'] ?? $_POST['key'] ?? '');
if ($key !== '' && !isset($defs[$key])) $key = '';
$errors = [];

if ($key !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = (string) ($_POST['action'] ?? 'save');
    $d = $defs[$key];
    if ($act === 'reset') {
        foreach (['subject', 'heading', 'button', 'body'] as $f) set_setting('email_tpl_' . $key . '_' . $f, '');
        admin_log('email.template', 'Email "' . $d['label'] . '" reset to the default wording');
        flash_set('success', 'Back to the default wording.');
        redirect('/admin/email_templates.php?edit=' . urlencode($key));
    }
    $subject = trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['subject'] ?? '')));
    $heading = trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['heading'] ?? '')));
    $button = trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['button'] ?? '')));
    $body = sanitize_page_html((string) ($_POST['body_html'] ?? ''));
    if ($subject === '') $errors[] = 'The subject can\'t be empty.';
    if ($heading === '') $errors[] = 'The heading can\'t be empty.';
    if (trim(strip_tags($body)) === '') $errors[] = 'The message can\'t be empty. Use "Use the default wording" to go back to the original.';
    if (mb_strlen($subject) > 150 || mb_strlen($heading) > 120 || mb_strlen($button) > 40) $errors[] = 'Subject up to 150, heading up to 120 and button up to 40 characters.';
    $unknown = [];
    foreach ([$subject, $heading, $button, $body] as $txt) {
        if (preg_match_all('/\{([a-z_]+)\}/', $txt, $m)) foreach ($m[1] as $tok) if (!isset($d['vars'][$tok])) $unknown[$tok] = true;
    }
    if ($unknown) $errors[] = 'Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': {' . implode('}, {', array_keys($unknown)) . '}. Use only the ones listed on the right.';

    if (!$errors) {
        // Saving the same text as the default is stored as "no override", so future default improvements still apply.
        $vals = ['subject' => $subject, 'heading' => $heading, 'button' => $button, 'body' => $body];
        foreach ($vals as $f => $v) {
            $same = $f === 'body' ? ($v === sanitize_page_html($d['body'])) : (trim((string) $v) === trim((string) $d[$f]));
            set_setting('email_tpl_' . $key . '_' . $f, $same ? '' : $v);
        }
        admin_log('email.template', 'Edited the email "' . $d['label'] . '"');
        if ($act === 'test') {
            $to = trim((string) ($_POST['test_to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash_set('error', 'Saved, but the test address doesn\'t look right.');
            } else {
                [$ts, $th] = email_render($key, email_sample_vars($key));
                $ok = send_email($to, $to, '[Test] ' . $ts, $th, null, null, 'email-template-test');
                flash_set($ok ? 'success' : 'error', $ok ? 'Saved, and a test email was sent to ' . $to . '.' : 'Saved, but the test could not be sent: ' . (mail_last_error() ?: 'check Details & email → Outgoing email.'));
            }
        } else flash_set('success', 'Saved.');
        redirect('/admin/email_templates.php?edit=' . urlencode($key));
    }
}

$pageTitle = $key !== '' ? 'Edit email' : 'Email templates';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';

if ($key !== ''):
    $d = $defs[$key];
    $cur = email_template($key);
    if ($errors) $cur = ['subject' => (string) ($_POST['subject'] ?? ''), 'heading' => (string) ($_POST['heading'] ?? ''), 'button' => (string) ($_POST['button'] ?? ''), 'body' => sanitize_page_html((string) ($_POST['body_html'] ?? ''))];
    [$pSubject, $pHtml] = email_render($key, email_sample_vars($key));
    $testTo = order_notify_recipients()[0] ?? store_info()['email'];
?>
<p><a class="link" href="/admin/email_templates.php">← All emails</a></p>
<div class="two-col" style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:18px;align-items:start;">
<section class="panel"><div class="panel-head"><h2><?= e($d['label']) ?></h2><span class="muted small">To: <?= e($d['to']) ?> · <?= e($d['when']) ?></span></div><div class="panel-body">
<form method="post" id="tplForm"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="action" value="save">
  <div class="field"><label for="tsub">Subject line</label><input id="tsub" name="subject" maxlength="150" value="<?= e($cur['subject']) ?>"></div>
  <div class="field"><label for="thead">Heading inside the email</label><input id="thead" name="heading" maxlength="120" value="<?= e($cur['heading']) ?>"></div>
  <?php if ($d['button'] !== ''): ?><div class="field"><label for="tbtn">Button text</label><input id="tbtn" name="button" maxlength="40" value="<?= e($cur['button']) ?>"><div class="hint">Used where the message contains <code>{button}</code>.</div></div><?php endif; ?>
  <div class="field"><label>Message</label>
    <div class="rte-bar" role="toolbar" aria-label="Formatting">
      <button type="button" data-cmd="bold"><b>B</b></button><button type="button" data-cmd="italic"><i>I</i></button><button type="button" data-cmd="underline"><u>U</u></button>
      <button type="button" data-block="h3">Subheading</button><button type="button" data-block="p">Paragraph</button>
      <button type="button" data-cmd="insertUnorderedList">• List</button><button type="button" data-link>Link</button><button type="button" data-cmd="removeFormat">Clear</button>
    </div>
    <div id="rte" class="rte prose" contenteditable="true"><?= $cur['body'] ?></div>
    <textarea name="body_html" id="body_html" hidden></textarea>
    <p class="help">Click a placeholder on the right to insert it where your cursor is. It is replaced with the real value when the email is sent.</p>
  </div>
  <div class="field-row" style="align-items:end;">
    <div class="field"><label for="tto">Send a test to</label><input id="tto" type="email" name="test_to" value="<?= e($testTo) ?>"></div>
    <div class="field" style="flex:0 0 auto;"><button class="btn btn-primary" name="action" value="save">Save</button> <button class="btn btn-outline" name="action" value="test">Save &amp; send test</button></div>
  </div>
</form>
<?php if (email_template_customised($key)): ?>
<form method="post" style="margin-top:10px;" onsubmit="return confirm('Throw away your wording and use the default again?');"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="action" value="reset"><button class="btn btn-outline btn-sm">Use the default wording</button></form>
<?php endif; ?>
</div></section>

<div>
<section class="panel"><div class="panel-head"><h2>Placeholders</h2></div><div class="panel-body" id="chips" style="display:flex;flex-direction:column;gap:8px;">
  <?php foreach ($d['vars'] as $tok => $desc): ?>
    <div><button type="button" class="btn btn-outline btn-sm" data-token="{<?= e($tok) ?>}">{<?= e($tok) ?>}</button><div class="muted small" style="margin-top:2px;"><?= e($desc) ?></div></div>
  <?php endforeach; ?>
</div></section>
<section class="panel"><div class="panel-head"><h2>Preview <span class="sub">saved version, with sample data</span></h2></div><div class="panel-body">
  <div class="muted small" style="margin-bottom:8px;"><strong>Subject:</strong> <?= e($pSubject) ?></div>
  <iframe sandbox="" style="width:100%;height:520px;border:1px solid var(--line);border-radius:8px;background:#fff;" srcdoc="<?= e($pHtml) ?>"></iframe>
</div></section>
</div>
</div>
<script>
(function () {
  var rte = document.getElementById('rte'), ta = document.getElementById('body_html'), last = null;
  document.querySelectorAll('.rte-bar [data-cmd]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); document.execCommand(b.dataset.cmd, false, null); }); });
  document.querySelectorAll('.rte-bar [data-block]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); document.execCommand('formatBlock', false, b.dataset.block); }); });
  var lk = document.querySelector('.rte-bar [data-link]');
  lk.addEventListener('mousedown', function (e) { e.preventDefault(); var u = prompt('Link address (https://…, mailto:…)'); if (u) document.execCommand('createLink', false, u); });
  document.addEventListener('selectionchange', function () { var s = window.getSelection(); if (s.rangeCount && rte.contains(s.anchorNode)) last = s.getRangeAt(0).cloneRange(); });
  var subj = document.getElementById('tsub'), head = document.getElementById('thead'), btn = document.getElementById('tbtn'), lastInput = null;
  [subj, head, btn].forEach(function (el) { if (el) el.addEventListener('focus', function () { lastInput = el; }); });
  rte.addEventListener('focus', function () { lastInput = null; });
  document.querySelectorAll('[data-token]').forEach(function (b) {
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
    b.addEventListener('click', function () {
      var t = b.dataset.token;
      if (lastInput) { var i = lastInput, s = i.selectionStart || i.value.length; i.value = i.value.slice(0, s) + t + i.value.slice(i.selectionEnd || s); i.focus(); i.setSelectionRange(s + t.length, s + t.length); return; }
      rte.focus();
      if (last) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(last); }
      document.execCommand('insertText', false, t);
    });
  });
  document.getElementById('tplForm').addEventListener('submit', function () { ta.value = rte.innerHTML; });
})();
</script>
<?php else: ?>
<section class="panel"><div class="panel-head"><h2>Email templates <span class="sub">everything the store emails on its own</span></h2></div><div class="panel-body">
  <p class="help" style="margin-top:0;">Each email has built-in wording that works out of the box. Open one to change the subject, heading, message or button text, preview it, and send yourself a test. Emails go out through <a class="link" href="/admin/settings.php">your outgoing-email settings</a>.</p>
</div>
<?php foreach (['Customer' => 'Emails your customers receive', 'You (shop owner)' => 'Emails you receive'] as $aud => $title): ?>
  <div class="panel-head" style="border-top:1px solid var(--line);"><h3 style="margin:0;"><?= e($title) ?></h3></div>
  <table class="admin-table"><thead><tr><th>Email</th><th>Sent when</th><th>Wording</th><th></th></tr></thead><tbody>
  <?php foreach ($defs as $k => $d): if ($d['to'] !== $aud) continue; ?>
    <tr><td><strong><?= e($d['label']) ?></strong><div class="muted small">Subject: <?= e(email_plain(email_template($k)['subject'], ['store_name' => store_name()])) ?></div></td>
      <td><?= e($d['when']) ?></td>
      <td><?= email_template_customised($k) ? '<span class="pill pill-brass">Your wording</span>' : '<span class="pill pill-ink">Default</span>' ?></td>
      <td style="text-align:right;"><a class="btn btn-outline btn-sm" href="/admin/email_templates.php?edit=<?= e($k) ?>">Edit</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
<?php endforeach; ?>
<div class="panel-body" style="border-top:1px solid var(--line);">
  <p class="help" style="margin:0;"><strong>Written elsewhere:</strong> promotional emails are composed per campaign under <a class="link" href="/admin/promotions.php">Promotions</a>; the "test email" buttons send a fixed short message.</p>
</div></section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
