<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/mail.php';
require_owner(); // Emailing every customer is an owner-only power.

const PROMO_BATCH = 15; // emails per request: small enough to finish well inside the server's time limit

/** Turns the plain text the admin typed into safe email HTML: blank line = new paragraph, links become clickable. */
function promo_body_html(string $text, string $firstName): string {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $text = str_replace(['{first_name}', '{name}'], $firstName !== '' ? $firstName : 'there', $text);
    $out = '';
    foreach (preg_split("/\n{2,}/", $text) as $para) {
        $h = e($para);
        $h = preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]])~i', '<a href="$1" style="color:#20293b;">$1</a>', $h);
        $out .= '<p style="margin:0 0 14px;">' . nl2br($h, false) . '</p>';
    }
    return $out;
}

/** The finished email for one recipient (personalised greeting + their own unsubscribe link). */
function promo_email_html(array $c, ?array $user, string $firstName): string {
    $bg = theme_settings()['primary'];
    $btn = ($c['button_label'] && $c['button_url'])
        ? '<p style="margin:22px 0;"><a href="' . e($c['button_url']) . '" style="background:' . e($bg) . ';color:' . e(contrast_text($bg)) . ';padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">' . e($c['button_label']) . '</a></p>' : '';
    $unsub = $user
        ? '<p style="font-size:12px;color:#8791a6;margin-top:22px;border-top:1px solid #eee;padding-top:12px;">You\'re receiving this because you have an account at ' . e(store_name()) . ' and opted in to offers. <a href="' . e(unsubscribe_url((int) $user['id'])) . '" style="color:#8791a6;">Unsubscribe</a>.</p>'
        : '<p style="font-size:12px;color:#8791a6;margin-top:22px;border-top:1px solid #eee;padding-top:12px;">This is a test send — real emails include a personal unsubscribe link here.</p>';
    return email_wrap($c['heading'] !== '' ? $c['heading'] : $c['subject'], promo_body_html($c['body'], $firstName) . $btn . $unsub);
}

/** Sends the next batch of a campaign. @return array{sent:int,failed:int,skipped:int,left:int,done:bool} */
function promo_send_batch(int $campaignId): array {
    $pdo = db();
    ignore_user_abort(true); // a closed tab must not leave a half-claimed batch behind
    $c = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id = ? AND status = 'sending'");
    $c->execute([$campaignId]);
    $camp = $c->fetch();
    if (!$camp) return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'left' => 0, 'done' => true];
    // A batch abandoned by a crashed request would stay "claimed" forever: when nothing unclaimed is left, release such leftovers.
    $free = $pdo->prepare("SELECT COUNT(*) FROM promo_recipients WHERE campaign_id = ? AND status = 'queued' AND error IS NULL");
    $free->execute([$campaignId]);
    if ((int) $free->fetchColumn() === 0) {
        $pdo->prepare("UPDATE promo_recipients SET error = NULL WHERE campaign_id = ? AND status = 'queued'")->execute([$campaignId]);
    }
    // Claim a batch first (queued -> a private marker) so two open tabs can't send the same people twice.
    $marker = 'claim-' . bin2hex(random_bytes(4));
    $pdo->prepare("UPDATE promo_recipients SET error = ? WHERE campaign_id = ? AND status = 'queued' AND error IS NULL ORDER BY id LIMIT " . PROMO_BATCH)->execute([$marker, $campaignId]);
    $rows = $pdo->prepare("SELECT * FROM promo_recipients WHERE campaign_id = ? AND status = 'queued' AND error = ?");
    $rows->execute([$campaignId, $marker]);
    $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    @set_time_limit(120);
    foreach ($rows->fetchAll() as $r) {
        // Re-check at send time: someone may have switched promotions off, or been disabled, since the campaign was queued.
        $u = $pdo->prepare("SELECT id, name, email, promo_emails, status, email_verified FROM users WHERE id = ?");
        $u->execute([$r['user_id']]);
        $user = $u->fetch();
        if (!$user || !$user['promo_emails'] || $user['status'] !== 'active') {
            $pdo->prepare("UPDATE promo_recipients SET status = 'skipped', error = 'opted out or disabled' WHERE id = ?")->execute([$r['id']]);
            $res['skipped']++;
            continue;
        }
        $first = explode(' ', trim($user['name']))[0];
        $ok = send_email($user['email'], $user['name'], $camp['subject'], promo_email_html($camp, $user, $first), null, null, 'promo', [
            'List-Unsubscribe' => '<' . unsubscribe_url((int) $user['id']) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
        $pdo->prepare('UPDATE promo_recipients SET status = ?, error = ?, sent_at = NOW() WHERE id = ?')
            ->execute([$ok ? 'sent' : 'failed', $ok ? null : mb_substr((string) mail_last_error(), 0, 250), $r['id']]);
        $ok ? $res['sent']++ : $res['failed']++;
        // Several failures in a row means the mail server is refusing us (bad login, daily limit): stop wasting time.
        if ($res['failed'] >= 5 && $res['sent'] === 0) break;
    }
    // Put any unsent claims back in the queue when we bailed out early.
    $pdo->prepare("UPDATE promo_recipients SET error = NULL WHERE campaign_id = ? AND status = 'queued' AND error = ?")->execute([$campaignId, $marker]);
    $left = $pdo->prepare("SELECT COUNT(*) FROM promo_recipients WHERE campaign_id = ? AND status = 'queued'");
    $left->execute([$campaignId]);
    $res['left'] = (int) $left->fetchColumn();
    $stalled = $res['failed'] >= 5 && $res['sent'] === 0;
    $res['done'] = $res['left'] === 0;
    $res['stalled'] = $stalled;
    if ($res['done']) $pdo->prepare("UPDATE promo_campaigns SET status = 'done', finished_at = NOW() WHERE id = ?")->execute([$campaignId]);
    if ($stalled) $res['error'] = (string) mail_last_error();
    return $res;
}

$errors = [];
$campId = (int) ($_GET['c'] ?? 0);

$section = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $section = $_POST['section'] ?? '';

    if ($section === 'topbar') {
        $tb0 = topbar_settings();
        $text = mb_substr(trim($_POST['topbar_text'] ?? ''), 0, 200);
        $link = trim($_POST['topbar_link'] ?? '');
        $enabled = !empty($_POST['topbar_enabled']);
        if ($link !== '' && !preg_match('~^(https?://|/)~i', $link)) $errors[] = 'The link must start with https:// (or / for a page on your own site).';
        if ($enabled && $text === '') $errors[] = 'Type a message first, or switch the bar off.';
        if (!$errors) {
            set_setting('topbar_enabled', $enabled ? '1' : '0');
            set_setting('topbar_text', $text);
            set_setting('topbar_link', $link);
            admin_log('settings.announcement', $enabled ? 'Announcement bar switched on: "' . admin_log_clip($text, 100) . '"' : 'Announcement bar switched off',
                null, null, ['changes' => admin_log_diff(['on' => $tb0['enabled'] ? 'yes' : 'no', 'text' => $tb0['text'], 'link' => $tb0['link']], ['on' => $enabled ? 'yes' : 'no', 'text' => $text, 'link' => $link], ['on' => 'Shown', 'text' => 'Message', 'link' => 'Link'])]);
            flash_set('success', $enabled ? 'Announcement bar saved and showing on every page.' : 'Announcement bar is switched off.');
            redirect('/admin/promotions.php#topbar');
        }
    }

    if ($action === 'send_batch') {          // called by the progress bar on this page
        header('Content-Type: application/json');
        $res = promo_send_batch((int) ($_POST['campaign_id'] ?? 0));
        echo json_encode($res);
        exit;
    }

    if ($action === 'cancel') {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        db()->prepare("UPDATE promo_campaigns SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND status = 'sending'")->execute([$id]);
        admin_log('promo.cancel', 'Stopped promotional campaign #' . $id, null, null);
        flash_set('info', 'Campaign stopped. Emails already sent stay sent.');
        redirect('/admin/promotions.php');
    }

    if ($action === 'test' || $action === 'create') {
        $subject = mb_substr(trim($_POST['subject'] ?? ''), 0, 200);
        $heading = mb_substr(trim($_POST['heading'] ?? ''), 0, 200);
        $body = trim(str_replace("\r", '', $_POST['body'] ?? ''));
        $btnLabel = mb_substr(trim($_POST['button_label'] ?? ''), 0, 60);
        $btnUrl = trim($_POST['button_url'] ?? '');
        if ($subject === '') $errors[] = 'Give the email a subject line.';
        if ($body === '') $errors[] = 'Write the message.';
        if (mb_strlen($body) > 8000) $errors[] = 'The message is too long (8,000 characters at most).';
        if (($btnLabel === '') !== ($btnUrl === '')) $errors[] = 'For a button, fill in both the button text and its link — or leave both empty.';
        if ($btnUrl !== '' && !preg_match('~^https?://[^\s]+$~i', $btnUrl)) $errors[] = 'The button link must start with https://.';
        $camp = ['subject' => $subject, 'heading' => $heading, 'body' => $body, 'button_label' => $btnLabel ?: null, 'button_url' => $btnUrl ?: null];

        if (!$errors && $action === 'test') {
            $to = trim($_POST['test_to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address to send the test to.';
            } else {
                $ok = send_email($to, $to, '[Test] ' . $subject, promo_email_html($camp, null, 'there'), null, null, 'promo-test');
                admin_log('promo.test', ($ok ? 'Sent' : 'Tried to send') . ' a promotional test email to ' . $to . ($ok ? '' : ' (failed)'));
                if ($ok) flash_set('success', 'Test email sent to ' . $to . '. Check the inbox (and spam folder).');
                else flash_set('error', 'The test email failed: ' . (mail_last_error() ?: 'unknown error') . (($h = mail_error_hint(mail_last_error())) ? ' — ' . $h : ''));
                $_SESSION['promo_draft'] = $camp;
                redirect('/admin/promotions.php');
            }
        }
        if (!$errors && $action === 'create') {
            if (smtp_settings()['host'] === '') {
                $errors[] = 'Email (SMTP) isn\'t set up yet — nothing would be delivered. Set it up under Settings & email first.';
            } else {
                $pdo = db();
                $admin = current_admin();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO promo_campaigns (subject, heading, body, button_label, button_url, created_by_name) VALUES (?,?,?,?,?,?)')
                        ->execute([$subject, $heading, $body, $camp['button_label'], $camp['button_url'], $admin['name'] ?? null]);
                    $id = (int) $pdo->lastInsertId();
                    // Only active customers who opted in AND whose address is confirmed (a mistyped, unconfirmed address is how mail gets flagged as spam).
                    $ins = $pdo->prepare("INSERT INTO promo_recipients (campaign_id, user_id, email, name)
                        SELECT ?, id, email, name FROM users WHERE promo_emails = 1 AND status = 'active' AND email_verified = 1");
                    $ins->execute([$id]);
                    $n = $ins->rowCount();
                    if ($n === 0) throw new RuntimeException('No customers are subscribed yet (they must be active, have a confirmed email, and have promotional emails switched on).');
                    $pdo->prepare('UPDATE promo_campaigns SET total = ? WHERE id = ?')->execute([$n, $id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = $e->getMessage();
                }
                if (!$errors) {
                    admin_log('promo.send', 'Started promotional campaign "' . admin_log_clip($subject, 80) . '" to ' . $n . ' customer' . ($n === 1 ? '' : 's'), null, null, ['campaign' => $id, 'recipients' => $n]);
                    unset($_SESSION['promo_draft']);
                    redirect('/admin/promotions.php?c=' . $id);
                }
            }
        }
    }
}

$draft = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($_SESSION['promo_draft'] ?? []);
unset($_SESSION['promo_draft']);

$subscribers = (int) db()->query("SELECT COUNT(*) FROM users WHERE promo_emails = 1 AND status = 'active' AND email_verified = 1")->fetchColumn();
$optedInUnverified = (int) db()->query("SELECT COUNT(*) FROM users WHERE promo_emails = 1 AND status = 'active' AND email_verified = 0")->fetchColumn();
$totalCustomers = (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$smtpOk = smtp_settings()['host'] !== '';
$campaigns = db()->query("SELECT c.*, (SELECT COUNT(*) FROM promo_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS sent_n,
    (SELECT COUNT(*) FROM promo_recipients r WHERE r.campaign_id = c.id AND r.status = 'failed') AS failed_n,
    (SELECT COUNT(*) FROM promo_recipients r WHERE r.campaign_id = c.id AND r.status = 'skipped') AS skipped_n,
    (SELECT COUNT(*) FROM promo_recipients r WHERE r.campaign_id = c.id AND r.status = 'queued') AS queued_n
    FROM promo_campaigns c ORDER BY c.id DESC LIMIT 15")->fetchAll();
$active = null;
foreach ($campaigns as $c) if ($c['status'] === 'sending' && ($campId === 0 || $campId === (int) $c['id'])) { $active = $c; break; }

$tb = topbar_settings();
$pageTitle = 'Promotions';
require __DIR__ . '/includes/header.php';
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if (!$smtpOk): ?>
  <div class="alert alert-warn">Email (SMTP) isn't set up, so nothing can be delivered. <a href="/admin/settings.php#smtp" style="text-decoration:underline;font-weight:600;">Set it up first</a>.</div>
<?php endif; ?>

<?php if ($active): ?>
<div class="panel" id="progressPanel" data-campaign="<?= (int) $active['id'] ?>" data-total="<?= (int) $active['total'] ?>">
  <div class="panel-head"><h2>Sending: <?= e($active['subject']) ?></h2><span class="pill pill-brass" id="progState">In progress</span></div>
  <div class="panel-body">
    <div style="height:10px;background:var(--line);border-radius:99px;overflow:hidden;"><div id="progBar" style="height:100%;width:0;background:var(--brass);transition:width .3s;"></div></div>
    <p id="progText" class="help" style="margin-top:10px;">Starting…</p>
    <p class="help">Keep this page open until it finishes — emails go out in small batches. If you close it, come back here and it carries on from where it stopped.</p>
    <form method="post" onsubmit="return confirm('Stop sending? Emails already sent stay sent.');" style="margin-top:8px;">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="campaign_id" value="<?= (int) $active['id'] ?>">
      <button class="btn btn-outline btn-sm" type="submit">Stop sending</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ───────────── Announcement bar ───────────── -->
<form method="post" id="topbar" class="panel">
  <?= csrf_field() ?><input type="hidden" name="section" value="topbar">
  <div class="panel-head"><h2>Announcement bar</h2></div>
  <div class="panel-body">
    <p class="help">A slim bar above the header on every page — good for sales, holiday delivery notices or anything you want everyone to see. When it's off, the bar is hidden completely.</p>
    <div class="field">
      <label class="switch"><input type="checkbox" name="topbar_enabled" value="1" id="tbOn" <?= ($section === 'topbar' ? !empty($_POST['topbar_enabled']) : $tb['raw_enabled']) ? 'checked' : '' ?>><span class="track"></span><span>Show the announcement bar</span></label>
    </div>
    <div class="field">
      <label for="tbText">Message <span class="counter" data-counter-for="tbText" data-max="200"></span></label>
      <input type="text" id="tbText" name="topbar_text" maxlength="200" value="<?= e($section === 'topbar' ? ($_POST['topbar_text'] ?? '') : $tb['text']) ?>" placeholder="e.g. Eid sale — 15% off all leather goods this week">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label for="tbLink">Link <span class="muted" style="font-weight:400;">(optional)</span></label>
      <input type="text" id="tbLink" name="topbar_link" value="<?= e($section === 'topbar' ? ($_POST['topbar_link'] ?? '') : $tb['link']) ?>" placeholder="https://… or /category.php?slug=leather">
      <div class="hint">If set, the whole message becomes a link.</div>
    </div>
    <div class="preview" style="margin-top:18px;"><div class="pv-top" id="tbPreview" style="background:var(--surface-dark);color:var(--on-dark);"></div></div>
  </div>
  <div class="panel-foot"><button class="btn btn-primary" type="submit">Save announcement bar</button></div>
</form>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <div class="panel-head"><h2>New promotional email</h2><span class="pill pill-sage"><?= $subscribers ?> subscriber<?= $subscribers === 1 ? '' : 's' ?></span></div>
  <div class="panel-body">
    <p class="help">Goes to logged-in customers (accounts) who switched on <em>Send me promotional emails</em> — in their account page or at sign-up — and whose email is confirmed.
      Right now that's <strong><?= $subscribers ?></strong> of <?= $totalCustomers ?> active customers.<?php if ($optedInUnverified): ?> <?= $optedInUnverified ?> more opted in but haven't confirmed their email yet, so they're skipped.<?php endif; ?>
      Every email carries a one-click unsubscribe link.</p>
    <div class="field"><label for="subject">Subject line</label><input type="text" id="subject" name="subject" maxlength="200" required value="<?= e($draft['subject'] ?? '') ?>" placeholder="Eid sale — 15% off all leather goods"></div>
    <div class="field"><label for="heading">Headline <span class="muted" style="font-weight:400;">(optional — big text at the top of the email; defaults to the subject)</span></label><input type="text" id="heading" name="heading" maxlength="200" value="<?= e($draft['heading'] ?? '') ?>"></div>
    <div class="field"><label for="body">Message</label>
      <textarea id="body" name="body" rows="9" required maxlength="8000" placeholder="Hi {first_name},&#10;&#10;This week only, take 15% off every leather wallet and belt with code EID15.&#10;&#10;Thanks for shopping with us."><?= e($draft['body'] ?? '') ?></textarea>
      <div class="hint">Leave a blank line between paragraphs. <code>{first_name}</code> becomes the customer's first name. Web addresses turn into links automatically.</div></div>
    <div class="field-row">
      <div class="field"><label for="button_label">Button text <span class="muted" style="font-weight:400;">(optional)</span></label><input type="text" id="button_label" name="button_label" maxlength="60" value="<?= e($draft['button_label'] ?? '') ?>" placeholder="Shop the sale"></div>
      <div class="field"><label for="button_url">Button link</label><input type="url" id="button_url" name="button_url" value="<?= e($draft['button_url'] ?? '') ?>" placeholder="https://…"></div>
    </div>
  </div>
  <div class="panel-foot" style="flex-wrap:wrap;">
    <input type="email" name="test_to" placeholder="your@email.com" style="max-width:240px;" value="<?= e($_POST['test_to'] ?? '') ?>">
    <button class="btn btn-outline" type="submit" name="action" value="test" formnovalidate>Send me a test</button>
    <span style="flex:1"></span>
    <button class="btn btn-primary" type="submit" name="action" value="create" <?= (!$smtpOk || !$subscribers) ? 'disabled' : '' ?> onclick="return confirm('Send this to <?= $subscribers ?> customer<?= $subscribers === 1 ? '' : 's' ?>? This can\'t be undone.');">Send to <?= $subscribers ?> customer<?= $subscribers === 1 ? '' : 's' ?></button>
  </div>
</form>

<div class="panel">
  <div class="panel-head"><h2>Recent campaigns</h2></div>
  <div class="table-wrap"><table class="admin-table">
    <thead><tr><th>Sent</th><th>Subject</th><th>By</th><th>Delivered</th><th>Failed</th><th>Skipped</th><th>Waiting</th><th>Status</th></tr></thead>
    <tbody>
      <?php if (!$campaigns): ?><tr class="empty-row"><td colspan="8">No campaigns yet.</td></tr><?php endif; ?>
      <?php foreach ($campaigns as $c): ?>
        <tr>
          <td><?= fmt_dt($c['created_at'], 'd M Y, H:i') ?></td>
          <td><?= e($c['subject']) ?></td>
          <td><?= e($c['created_by_name'] ?? '—') ?></td>
          <td><?= (int) $c['sent_n'] ?></td>
          <td><?= (int) $c['failed_n'] ?: '0' ?></td>
          <td><?= (int) $c['skipped_n'] ?></td>
          <td><?= (int) $c['queued_n'] ?></td>
          <td><?= $c['status'] === 'done' ? '<span class="pill pill-sage">Done</span>' : ($c['status'] === 'sending' ? '<a class="pill pill-brass" href="/admin/promotions.php?c=' . (int) $c['id'] . '">Sending — open</a>' : '<span class="pill pill-rust">Stopped</span>') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($active): ?>
<script>
(function () {
  var panel = document.getElementById('progressPanel'); if (!panel) return;
  var id = panel.dataset.campaign, total = parseInt(panel.dataset.total, 10) || 1;
  var bar = document.getElementById('progBar'), txt = document.getElementById('progText'), state = document.getElementById('progState');
  var csrf = document.querySelector('meta[name="csrf-token"]').content, sent = 0, failed = 0, skipped = 0, running = true;
  function step() {
    var body = new URLSearchParams({ action: 'send_batch', campaign_id: id, csrf_token: csrf });
    fetch('/admin/promotions.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        sent += d.sent; failed += d.failed; skipped += d.skipped;
        var left = d.left, doneN = total - left;
        bar.style.width = Math.min(100, Math.round(doneN / total * 100)) + '%';
        txt.textContent = doneN + ' of ' + total + ' processed — ' + sent + ' delivered' + (failed ? ', ' + failed + ' failed' : '') + (skipped ? ', ' + skipped + ' skipped (opted out)' : '') + ' this session.';
        if (d.stalled) { state.textContent = 'Paused'; txt.textContent = 'Paused: the mail server is refusing messages (' + (d.error || 'unknown error') + '). Fix Settings & email, then reload this page to continue.'; return; }
        if (d.done) { state.textContent = 'Finished'; state.className = 'pill pill-sage'; bar.style.width = '100%'; setTimeout(function () { location.href = '/admin/promotions.php'; }, 1800); return; }
        step();
      })
      .catch(function () { state.textContent = 'Paused'; txt.textContent = 'Lost connection. Reload this page to continue where it stopped.'; });
  }
  step();
})();
</script>
<?php endif; ?>
<script>
(function () {
  var on = document.getElementById('tbOn'), text = document.getElementById('tbText'), prev = document.getElementById('tbPreview');
  function paint() {
    var t = text.value.trim();
    prev.textContent = on.checked ? (t || 'Your message appears here') : 'The bar is off — nothing is shown above the header.';
    prev.style.opacity = on.checked && t ? 1 : .55;
  }
  on.addEventListener('change', paint); text.addEventListener('input', paint); paint();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
