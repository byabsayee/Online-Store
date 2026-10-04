<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Reached from the footer of every promotional email. The signed link is the credential, so no login is needed.
// A GET only shows a confirmation button (mail scanners often "click" links); the POST does the work. The POST
// also answers mail apps' one-click unsubscribe (RFC 8058), which sends List-Unsubscribe=One-Click to this URL.
$uid = (int) ($_GET['uid'] ?? $_POST['uid'] ?? 0);
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$valid = $uid > 0 && $token !== '' && hash_equals(unsubscribe_token($uid), $token);
$done = false;
$resub = false;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $turnOn = ($_POST['action'] ?? '') === 'resubscribe';
    db()->prepare('UPDATE users SET promo_emails = ? WHERE id = ?')->execute([$turnOn ? 1 : 0, $uid]);
    if (isset($_POST['List-Unsubscribe'])) { http_response_code(200); echo 'OK'; exit; }
    $done = true;
    $resub = $turnOn;
}

$email = '';
if ($valid) {
    $q = db()->prepare('SELECT email FROM users WHERE id = ?');
    $q->execute([$uid]);
    $email = (string) $q->fetchColumn();
    if ($email === '') $valid = false;
}

$pageTitle = 'Email preferences';
require __DIR__ . '/includes/header.php';
?>
<div class="wrap">
  <div class="form-card form-narrow" style="text-align:center;">
    <?php if (!$valid): ?>
      <h2>Link not valid</h2>
      <p style="color:var(--ink-soft);">This unsubscribe link isn't valid. If you have an account you can change email preferences from your account page.</p>
      <a class="btn btn-primary" href="/account">Go to my account</a>
    <?php elseif ($done): ?>
      <h2><?= $resub ? 'You\'re subscribed again' : 'You\'re unsubscribed' ?></h2>
      <p style="color:var(--ink-soft);"><?= $resub ? 'We\'ll send offers to' : 'We won\'t send promotional emails to' ?> <strong><?= e($email) ?></strong><?= $resub ? '.' : ' any more. Order confirmations and account emails still arrive as normal.' ?></p>
      <?php if (!$resub): ?>
        <form method="post" style="margin-top:14px;"><input type="hidden" name="uid" value="<?= (int) $uid ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="resubscribe"><button class="btn btn-outline" type="submit">Oops, subscribe me again</button></form>
      <?php endif; ?>
    <?php else: ?>
      <h2>Unsubscribe from offers?</h2>
      <p style="color:var(--ink-soft);"><strong><?= e($email) ?></strong> will stop receiving promotional emails. Order and account emails aren't affected.</p>
      <form method="post"><input type="hidden" name="uid" value="<?= (int) $uid ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="unsubscribe"><button class="btn btn-primary" type="submit">Yes, unsubscribe me</button></form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
