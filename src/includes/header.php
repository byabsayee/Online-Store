<?php
require_once __DIR__ . '/auth.php';
$__user = current_user();
$__cartCount = cart_count();
// Top-level only: subcategories (e.g. "Men" / "Women" under "Bags & Carry") show as
// chips on their parent's own category page instead of crowding this nav.
$__categories = db()->query('SELECT id, name, slug FROM categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY sort_order, name')->fetchAll();
$__currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$__isHome = $__currentPath === '/' || $__currentPath === '/index.php';
$__store = store_info();
$__theme = theme_settings();
$__topbar = topbar_settings();
$__assetV = fn (string $f) => (int) @filemtime(__DIR__ . '/../assets/' . $f);
$__pageClass = 'page-' . preg_replace('/[^a-z0-9-]/', '', str_replace('.php', '', basename($_SERVER['SCRIPT_NAME'] ?? 'index')));
// A page that shows a sticky action bar above the tab bar (cart, checkout, product) sets $bodyClass = 'has-action-bar'
// so the page gets the matching bottom padding — and only when that bar is really on the page.
if (!empty($bodyClass)) $__pageClass .= ' ' . preg_replace('/[^a-z0-9 -]/', '', (string) $bodyClass);
$__fxOn = !empty($__theme['seasonal_enabled']) && $__theme['seasonal_effect'] !== 'none';
$__activeCat = $_GET['slug'] ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?= render_head_meta() ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f8f6ee">
<script>
/* Apply the saved (or system) day/night choice before first paint — no flash. */
(function () {
  try {
    var t = localStorage.getItem('store-theme');
    if (t !== 'light' && t !== 'dark') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', t === 'dark' ? '#1a2030' : '#f8f6ee');
  } catch (e) {}
})();
</script>
<?= brand_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= $__assetV('css/style.css') ?>">
<?= font_head_html() ?>
<style><?= theme_css() ?></style>
</head>
<body class="<?= e($__pageClass) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<?php if ($__topbar['enabled']): ?>
<div class="topbar" role="region" aria-label="Announcement">
  <div class="wrap">
    <?php if ($__topbar['link'] !== '' && preg_match('~^(https?://|/)~i', $__topbar['link'])): ?>
      <a href="<?= e($__topbar['link']) ?>"><?= e($__topbar['text']) ?></a>
    <?php else: ?>
      <span><?= e($__topbar['text']) ?></span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<header class="site-header" id="siteHeader">
  <div class="wrap header-row">
    <button class="nav-toggle" id="navToggle" type="button" aria-label="Open menu" aria-controls="mobileNav" aria-expanded="false"><span></span></button>
    <a href="/" class="brand" aria-label="<?= e($__store['name']) ?> — home"><?= brand_inner('header') ?></a>

    <nav class="main-nav" aria-label="Main">
      
    </nav>

    <form class="search-form" id="siteSearch" action="/search" method="get" role="search" data-suggest>
      <input type="search" name="q" placeholder="Search products…" value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off" enterkeyhint="search" aria-label="Search products">
      <div class="suggest" id="searchSuggest" hidden></div>
      <button type="submit" aria-label="Search"><?= ui_icon('search', 18) ?></button>
    </form>

    <div class="header-actions">
      <button type="button" class="icon-btn search-toggle" id="searchToggle" aria-label="Search" aria-controls="siteSearch" aria-expanded="false"><?= ui_icon('search', 22) ?></button>
      <div class="header-tools">
        <?php if ($__fxOn): ?>
        <button type="button" class="tool-btn" data-fx-toggle id="fxToggle" aria-pressed="true" aria-label="Screen animation" title="Turn screen animation on/off">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M4.9 7l14.2 10M19.1 7 4.9 17"/><path class="fx-slash" d="M3 3l18 18" stroke-width="2.4"/></svg>
        </button>
        <?php endif; ?>
        <button type="button" class="tool-btn" data-theme-toggle id="themeToggle" aria-label="Switch to dark mode" title="Switch day / night">
          <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
          <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
      </div>
      <a href="<?= $__user ? '/wishlist' : '/login' ?>" class="icon-btn hide-sm"><?= ui_icon('heart', 21) ?><span class="lbl">Saved</span></a>
      <a href="/cart" class="icon-btn" aria-label="Cart"><?= ui_icon('cart', 22) ?><span class="lbl">Cart</span><span class="badge" data-cart-badge<?= $__cartCount > 0 ? '' : ' hidden' ?>><?= $__cartCount ?></span></a>
      <a href="<?= $__user ? '/account' : '/login' ?>" class="icon-btn hide-sm"><?= ui_icon('user', 21) ?><span class="lbl"><?= $__user ? e(explode(' ', $__user['name'])[0]) : 'Login' ?></span></a>
    </div>
  </div>
</header>

<div class="cat-strip">
  <div class="wrap">
    <a href="/search?sort=newest" class="<?= $__currentPath === '/search' && ($_GET['q'] ?? '') === '' ? 'active' : '' ?>">All products</a>
    <?php foreach ($__categories as $c): ?>
      <a href="<?= e(category_url($c)) ?>" class="<?= ($__currentPath === '/category' && $__activeCat === $c['slug']) ? 'active' : '' ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Mobile menu drawer -->
<div class="mobile-nav" id="mobileNav" aria-hidden="true">
  <div class="mnav-backdrop" data-nav-close></div>
  <aside class="mnav-panel" role="dialog" aria-modal="true" aria-label="Menu">
    <div class="mnav-head">
      <a href="/" class="brand"><?= brand_inner('drawer') ?></a>
      <button type="button" class="mnav-close" data-nav-close aria-label="Close menu"><?= ui_icon('close', 22) ?></button>
    </div>
    <div class="mnav-body">
      <form class="mnav-search" action="/search" method="get" role="search">
        <?= ui_icon('search', 18) ?>
        <input type="search" name="q" placeholder="Search products…" enterkeyhint="search" aria-label="Search products">
      </form>

      <nav class="mnav-links" aria-label="Menu">
        <a href="/" class="<?= $__isHome ? 'active' : '' ?>"><?= ui_icon('home') ?>Home<?= ui_icon('chevron', 16) ?></a>
        <a href="/search?sort=newest"><?= ui_icon('grid') ?>All products<?= ui_icon('chevron', 16) ?></a>
        <?php if (site_page_enabled('about')): ?><a href="/about" class="<?= $__currentPath === '/about' ? 'active' : '' ?>"><?= ui_icon('info') ?>About us<?= ui_icon('chevron', 16) ?></a><?php endif; ?>
        <a href="/contact" class="<?= $__currentPath === '/contact' ? 'active' : '' ?>"><?= ui_icon('mail') ?>Contact<?= ui_icon('chevron', 16) ?></a>
        <a href="/orders"><?= ui_icon('truck') ?>Track an order<?= ui_icon('chevron', 16) ?></a>
      </nav>

      <?php if ($__categories): ?>
      <div class="mnav-title">Shop by category</div>
      <nav class="mnav-cats" aria-label="Categories">
        <?php foreach ($__categories as $c): ?>
          <a href="<?= e(category_url($c)) ?>" class="<?= ($__currentPath === '/category' && $__activeCat === $c['slug']) ? 'active' : '' ?>"><span><?= e($c['name']) ?></span><?= ui_icon('chevron', 16) ?></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>

      <div class="mnav-account">
        <?php if ($__user): ?>
          <div class="who"><span class="avatar"><?= e(mb_strtoupper(mb_substr($__user['name'], 0, 1))) ?></span><div><strong>Hi, <?= e(explode(' ', $__user['name'])[0]) ?></strong><small><?= e($__user['email']) ?></small></div></div>
          <div class="mnav-btns">
            <a class="btn btn-outline btn-sm" href="/account">My account</a>
            <a class="btn btn-outline btn-sm" href="/wishlist">Saved items</a>
            <a class="btn btn-ghost btn-sm" href="/logout?csrf_token=<?= e(csrf_token()) ?>"><?= ui_icon('logout', 16) ?>Log out</a>
          </div>
        <?php else: ?>
          <p>Log in to track orders and save items.</p>
          <div class="mnav-btns">
            <a class="btn btn-primary btn-sm" href="/login">Log in</a>
            <a class="btn btn-outline btn-sm" href="/register">Create account</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if (store_phones() || $__store['email'] !== '' || store_socials()): ?>
      <div class="mnav-contact">
        <div class="mnav-title">Get in touch</div>
        <?php foreach (store_phones() as $__ph): ?><a href="<?= e(tel_href($__ph)) ?>"><?= ui_icon('phone', 18) ?><?= e($__ph) ?></a><?php endforeach; ?>
        <?php if ($__store['email'] !== ''): ?><a href="mailto:<?= e($__store['email']) ?>"><?= ui_icon('mail', 18) ?><?= e($__store['email']) ?></a><?php endif; ?>
        <?= social_row_html('contact-links mnav-social') ?>
      </div>
      <?php endif; ?>

      <div class="mnav-prefs">
        <button type="button" data-theme-toggle class="mnav-pref" aria-pressed="false">
          <span class="ico-l"><?= ui_icon('moon', 18) ?></span><span class="ico-d"><?= ui_icon('sun', 18) ?></span>
          <span class="txt" data-theme-label>Dark mode</span><span class="switch" aria-hidden="true"></span>
        </button>
        <?php if ($__fxOn): ?>
        <button type="button" data-fx-toggle class="mnav-pref" aria-pressed="true">
          <?= ui_icon('snow', 18) ?><span class="txt">Screen animation</span><span class="switch" aria-hidden="true"></span>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </aside>
</div>

<main id="main">
<?php $__flashes = flash_get(); if ($__flashes): ?>
  <div class="wrap flash-wrap">
    <?php foreach ($__flashes as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($__user && (int)($__user['email_verified'] ?? 1) === 0): ?>
  <div class="wrap flash-wrap">
    <div class="alert alert-info verify-alert">
      <span>Please verify your email address (<?= e($__user['email']) ?>) to secure your account.</span>
      <form method="post" action="/resend-verification">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline btn-sm">Resend verification email</button>
      </form>
    </div>
  </div>
<?php endif; ?>