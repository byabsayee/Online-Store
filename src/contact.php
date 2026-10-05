<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$store = store_info();
$socials = store_socials();
$sent = false;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $lastSent = (int) ($_SESSION['contact_last_sent'] ?? 0);
    if (trim($_POST['website'] ?? '') !== '') {
        // Hidden "website" field: only bots fill it in. Pretend it worked.
        $sent = true;
    } elseif (time() - $lastSent < 30) {
        $errors[] = 'Please wait a few seconds before sending another message.';
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 5) {
        $errors[] = 'Please fill in your name, a valid email, and a short message.';
    } elseif (mb_strlen($message) > 5000 || mb_strlen($name) > 120) {
        $errors[] = 'That message is too long — please shorten it a little.';
    } else {
        require_once __DIR__ . '/includes/mail.php';
        [$subject, $html] = email_render('contact_message', [
            'sender_name' => e($name), 'sender_email' => e($email),
            'message' => '<p style="white-space:pre-wrap;background:#f8f6ee;padding:14px;border-radius:6px;">' . e($message) . '</p>',
        ]);
        $delivered = send_email($store['email'], $store['name'], $subject, $html, $email, $name);
        if ($delivered) {
            $sent = true;
            $_SESSION['contact_last_sent'] = time();
        } else {
            $errors[] = "We couldn't send your message right now. Please try messaging us or emailing " . $store['email'] . ' directly.';
        }
    }
}

$pageTitle = 'Contact';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header wrap"><span class="eyebrow">Contact</span><h1>Get in touch</h1></div>
<div class="wrap section" style="padding-top:8px;">
  <div class="cart-layout contact-layout">
  <div class="form-card" style="margin-bottom:0;">
    <h3 style="margin-bottom:12px;">Reach us directly</h3>
    <p style="color:var(--ink-soft);">
      <?php
      $dm = [];
      if (isset($socials['messenger'])) $dm[] = '<a href="' . e($socials['messenger']['url']) . '" target="_blank" rel="noopener"><strong>Facebook Messenger</strong></a>';
      if (isset($socials['instagram'])) $dm[] = '<a href="' . e($socials['instagram']['url']) . '" target="_blank" rel="noopener"><strong>Instagram</strong></a>';
      if (isset($socials['whatsapp'])) $dm[] = '<a href="' . e($socials['whatsapp']['url']) . '" target="_blank" rel="noopener"><strong>WhatsApp</strong></a>';
      ?>
      <?php if ($dm): ?>Message us on <?= count($dm) > 1 ? implode(', ', array_slice($dm, 0, -1)) . ' or ' . end($dm) : $dm[0] ?>.<?php endif; ?>
      <?php if ($store['email'] !== ''): ?>You can also send a direct mail and our staff will reach out to you.<?php endif; ?>
    </p>

    <ul class="contact-list">
      <?php $phoneRows = store_phone_entries(); if ($phoneRows): ?>
        <li><?= ui_icon('phone', 20) ?><div><small>Call us</small><?php foreach ($phoneRows as $i => $pe): ?><?= $i ? '<br>' : '' ?><?= contact_label_html($pe['label']) ?><a href="<?= e(tel_href($pe['number'])) ?>"><?= e($pe['number']) ?></a><?php endforeach; ?></div></li>
      <?php endif; ?>
      <?php $emailRows = store_emails(); if ($emailRows): ?>
        <li><?= ui_icon('mail', 20) ?><div><small>Email</small><?php foreach ($emailRows as $i => $em): ?><?= $i ? '<br>' : '' ?><?= contact_label_html($em['label']) ?><a href="mailto:<?= e($em['email']) ?>"><?= e($em['email']) ?></a><?php endforeach; ?></div></li>
      <?php endif; ?>
      <?php foreach (store_addresses() as $ad): ?>
        <li><?= ui_icon('pin', 20) ?><div><small><?= e($ad['label'] !== '' ? $ad['label'] : 'Visit / post') ?></small><span><?= nl2br(e($ad['text'])) ?></span><?php if ($ad['map'] !== ''): ?><br><a href="<?= e($ad['map']) ?>" target="_blank" rel="noopener">Open in Google Maps ↗</a><?php endif; ?></div></li>
      <?php endforeach; ?>
    </ul>

    <?= social_row_html('contact-links contact-social') ?>
  </div>

  <div class="form-card" style="margin-bottom:0;">
    <?php if ($sent): ?>
      <div class="alert alert-success">Thanks — your message has been received. We'll reply by email soon.</div>
    <?php else: ?>
      <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field" style="position:absolute;left:-5000px;" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <div class="field"><label for="name">Name</label><input id="name" name="name" required autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>"></div>
        <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></div>
        <div class="field"><label for="message">Message</label><textarea id="message" name="message" rows="5" required maxlength="5000"><?= e($_POST['message'] ?? '') ?></textarea></div>
        <button type="submit" class="btn btn-primary btn-block">Send message</button>
      </form>
    <?php endif; ?>
  </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>