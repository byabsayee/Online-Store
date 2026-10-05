<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_admin();
$__admin = current_admin();
$__path = basename($_SERVER['SCRIPT_NAME']);
/** Nav item: highlighted when the current admin script is any of $files. */
function nav_link(string $href, string $label, array $files, string $current, string $icon = ''): string {
    $cls = in_array($current, $files, true) ? ' class="active"' : '';
    return '<a href="' . $href . '"' . $cls . '><span class="ico">' . $icon . '</span>' . e($label) . '</a>';
}
/** Store-settings screens, grouped for the tab bar: group => [file => label]. */
function admin_settings_groups(): array {
    return [
        'Store' => ['settings.php' => 'Details & email', 'regional.php' => 'Region & invoices', 'contacts.php' => 'Addresses & contacts', 'notifications.php' => 'Order alerts'],
        'Look' => ['branding.php' => 'Branding', 'theme_settings.php' => 'Theme', 'fonts.php' => 'Fonts'],
        'Content' => ['pages.php' => 'Pages', 'email_templates.php' => 'Emails', 'footer_links.php' => 'Footer', 'partners.php' => 'Partners', 'ads.php' => 'Ads'],
        'Checkout' => ['payment_methods.php' => 'Payments', 'delivery_tax.php' => 'Delivery & tax'],
        'Connect' => ['erp.php' => 'Accounting link', 'preset.php' => 'Backup & presets'],
    ];
}
function admin_settings_files(): array { $f = ['settings_hub.php', 'setup.php']; foreach (admin_settings_groups() as $g) foreach ($g as $k => $_) $f[] = $k; return $f; }
function admin_settings_tabs(string $current): string {
    if (!in_array($current, admin_settings_files(), true) || $current === 'setup.php') return '';
    $h = '<div class="settings-tabs"><a href="/admin/settings_hub.php" class="hub' . ($current === 'settings_hub.php' ? ' active' : '') . '">All settings</a>';
    foreach (admin_settings_groups() as $g => $items) {
        $h .= '<span class="grp"><i>' . e($g) . '</i>';
        foreach ($items as $f => $l) $h .= '<a href="/admin/' . $f . '"' . ($f === $current ? ' class="active"' : '') . '>' . e($l) . '</a>';
        $h .= '</span>';
    }
    return $h . '</div>';
}
// Brand-new store: the owner lands in the setup wizard first.
if (!setup_done() && admin_is_owner() && $__path !== 'setup.php' && !in_array($__path, ['account.php', 'logout.php'], true)) { redirect('/admin/setup.php'); }
$__ico = [
    'dash' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
    'box' => '<svg viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>',
    'tag' => '<svg viewBox="0 0 24 24"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/></svg>',
    'cart' => '<svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>',
    'users' => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>',
    'brush' => '<svg viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2H17a5 5 0 0 0 5-5c0-4.4-4.5-8-10-8z"/></svg>',
    'gear' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
    'image' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
    'badge' => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="11" r="2.5"/><path d="M5.5 17c.6-1.8 2-2.7 3.5-2.7s2.9.9 3.5 2.7M15 9.5h3M15 13h3"/></svg>',
    'star' => '<svg viewBox="0 0 24 24"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.1 7 14.2 2 9.3l6.9-1z"/></svg>',
    'ticket' => '<svg viewBox="0 0 24 24"><path d="M3 9a2 2 0 0 0 0 6v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a2 2 0 0 1 0-6V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg>',
    'log' => '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>',
    'mail' => '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
    'key' => '<svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.8 7.8 5.5 5.5 0 0 1 7.8-7.8zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#20293b">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — Admin' : 'Admin' ?> · <?= e(store_name()) ?></title>
<?= brand_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/admin.css') ?>">
<style><?= theme_css() ?></style>
</head>
<body>
<div class="admin-shell">
  <aside class="admin-sidebar">
    <a href="/admin/index.php" class="brand"><?= brand_inner('admin') ?></a>
    <nav>
      <?= nav_link('/admin/index.php', 'Dashboard', ['index.php'], $__path, $__ico['dash']) ?>
      <div class="section-label">Sell</div>
      <?= nav_link('/admin/orders.php', 'Orders', ['orders.php', 'order_detail.php'], $__path, $__ico['cart']) ?>
      <?= nav_link('/admin/products.php', 'Products', ['products.php', 'product_form.php'], $__path, $__ico['box']) ?>
      <?= nav_link('/admin/categories.php', 'Categories', ['categories.php', 'category_form.php'], $__path, $__ico['tag']) ?>
      <?= nav_link('/admin/reviews.php', 'Reviews', ['reviews.php'], $__path, $__ico['star']) ?>
      <div class="section-label">Grow</div>
      <?= nav_link('/admin/coupons.php', 'Coupons', ['coupons.php', 'coupon_form.php'], $__path, $__ico['ticket']) ?>
      <?php if (admin_is_owner()): ?><?= nav_link('/admin/promotions.php', 'Promotional emails', ['promotions.php'], $__path, $__ico['mail']) ?><?php endif; ?>
      <?= nav_link('/admin/users.php', 'Customers', ['users.php'], $__path, $__ico['users']) ?>
      <?php if (admin_is_owner()): ?>
      <div class="section-label">Store</div>
      <?= nav_link('/admin/settings_hub.php', 'Store settings', admin_settings_files(), $__path, $__ico['gear']) ?>
      <?= nav_link('/admin/staff.php', 'Staff', ['staff.php', 'staff_form.php'], $__path, $__ico['badge']) ?>
      <?= nav_link('/admin/logs.php', 'Activity log', ['logs.php'], $__path, $__ico['log']) ?>
      <?php endif; ?>
      <?= nav_link('/admin/account.php', 'My account', ['account.php'], $__path, $__ico['key']) ?>
    </nav>
  </aside>

  <div class="admin-main">
    <div class="admin-topbar">
      <h1><?= e($pageTitle ?? 'Admin') ?></h1>
      <div class="admin-user">
        <span class="who"><?= staff_avatar_html((int) $__admin['id'], (string) $__admin['name'], $__admin['photo_v'] ?? null, 28) ?><span>Signed in as <strong><?= e($__admin['name']) ?></strong></span></span>
        <a href="/" target="_blank" class="link">View store ↗</a>
        <a href="/admin/logout.php?csrf_token=<?= e(csrf_token()) ?>" class="logout">Log out</a>
      </div>
    </div>
    <div class="admin-content">
      <?= admin_settings_tabs($__path) ?>
      <?php if (!empty($GLOBALS['__migration_error'])): ?>
        <div class="alert alert-error"><strong>Database upgrade failed:</strong> <?= e($GLOBALS['__migration_error']) ?> — run the newest file in <code>sql/migrations/</code> manually (phpMyAdmin) and reload.</div>
      <?php endif; ?>
      <?php $__flashes = flash_get(); foreach ($__flashes as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
