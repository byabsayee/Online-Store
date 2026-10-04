<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$pageTitle = 'Store settings';
$desc = [
    'settings.php' => 'Name, contact details, social links, announcement bar, outgoing email, Google login',
    'regional.php' => 'Currency, time zone, order-number prefix, delivery time, invoice text',
    'notifications.php' => 'Get an email whenever a new order arrives',
    'branding.php' => 'Logo, favicon, share image and link-preview text',
    'theme_settings.php' => 'Colours and seasonal effects',
    'fonts.php' => 'Title, primary and secondary fonts — upload or Google Fonts',
    'pages.php' => 'About, FAQ, policies, home page text, cookie notice',
    'footer_links.php' => 'Footer link columns, credit line and source-code link',
    'partners.php' => 'A public page promoting your partners',
    'ads.php' => 'Google AdSense placement',
    'payment_methods.php' => 'Cash on delivery, bKash, Nagad, bank transfer and more',
    'delivery_tax.php' => 'Delivery zones and fees, weight rules, tax',
    'erp.php' => 'Connect this store to Byabsayee accounting',
    'preset.php' => 'Export or import your store settings; run the setup wizard again',
];
require __DIR__ . '/includes/header.php';
?>
<div class="hub-grid">
<?php foreach (admin_settings_groups() as $g => $items): ?>
  <section class="panel"><div class="panel-head"><h2><?= e($g) ?></h2></div><div class="panel-body" style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($items as $f => $l): ?><a href="/admin/<?= e($f) ?>" class="hub-item"><strong><?= e($l) ?></strong><span class="muted small"><?= e($desc[$f] ?? '') ?></span></a><?php endforeach; ?>
  </div></section>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
