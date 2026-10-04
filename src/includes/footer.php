</main>
<?php
$__store = $__store ?? store_info();
$__theme = $__theme ?? theme_settings();
$__cartCount = $__cartCount ?? cart_count();
$__user = $__user ?? current_user();
$__categories = $__categories ?? [];
$__currentPath = $__currentPath ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$__assetV = $__assetV ?? fn (string $f) => (int) @filemtime(__DIR__ . '/../assets/' . $f);
?>
<footer class="site-footer">
  <div class="wrap">
    <div class="footer-grid">
      <div class="footer-brand">
        <a href="/" class="brand"><?= brand_inner('footer') ?></a>
        <?php if ($__store['description'] !== ''): ?><p><?= e($__store['description']) ?></p><?php endif; ?>
        <?= social_row_html('contact-links') ?>
      </div>
      <div>
        <h4>Contact</h4>
        <ul class="footer-contact">
          <?php if ($__store['address'] !== ''): ?><li><?= ui_icon('pin', 16) ?><span><?= nl2br(e($__store['address'])) ?></span></li><?php endif; ?>
          <?php $__phones = store_phones(); if ($__phones): ?><li><?= ui_icon('phone', 16) ?><span><?php foreach ($__phones as $__i => $__ph): ?><?= $__i ? ' <span class="sep"></span> ' : '' ?><a href="<?= e(tel_href($__ph)) ?>"><?= e($__ph) ?></a><?php endforeach; ?></span></li><?php endif; ?>
          <?php if ($__store['email'] !== ''): ?><li><?= ui_icon('mail', 16) ?><a href="mailto:<?= e($__store['email']) ?>"><?= e($__store['email']) ?></a></li><?php endif; ?>
        </ul>
      </div>
      <div>
        <h4>Support</h4>
        <ul>
          <li><a href="/contact">Contact us</a></li>
          <li><a href="/about">About the shop</a></li>
          <li><a href="/orders">Track an order</a></li>
          <li><a href="<?= is_logged_in() ? '/account' : '/login' ?>">My account</a></li>
        </ul>
      </div>
      <div>
        <h4>Legal</h4>
        <ul>
          <li><a href="/terms">Terms of Service</a></li>
          <li><a href="/privacy-policy">Privacy Policy</a></li>
          <li><a href="/refund-policy">Refund & Return Policy</a></li>
          <li><a href="/shipping-policy">Shipping & Delivery Policy</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= e($__store['name']) ?>. All rights reserved.</span>
      <span>Developed by <a href="https://github.com/TechZeeLand">TechZeeLand</a></span>
    </div>
  </div>
</footer>

<!-- Mobile bottom tab bar. Always present on phones; the tab for the current section is highlighted. -->
<?php
$__tabActive = [
    'home' => in_array($__currentPath, ['/', '/index.php'], true),
    'search' => $__currentPath === '/search',
    'cart' => in_array($__currentPath, ['/cart', '/checkout', '/order-success'], true),
    'saved' => $__currentPath === '/wishlist',
    'account' => in_array($__currentPath, ['/account', '/login', '/register', '/orders', '/addresses', '/verify-email', '/resend-verification'], true) || str_starts_with($__currentPath, '/order/'),
];
$__cur = fn (string $k) => !empty($__tabActive[$k]) ? ' active" aria-current="page' : '';
?>
<nav class="tabbar" aria-label="Quick navigation">
  <a href="/" class="tab<?= $__cur('home') ?>"><?= ui_icon('home', 22) ?><span>Home</span></a>
  <button type="button" class="tab<?= $__cur('search') ?>" data-open-search><?= ui_icon('search', 22) ?><span>Search</span></button>
  <a href="/cart" class="tab<?= $__cur('cart') ?>"><?= ui_icon('cart', 22) ?><span>Cart</span><span class="badge" data-cart-badge<?= $__cartCount > 0 ? '' : ' hidden' ?>><?= (int) $__cartCount ?></span></a>
  <a href="<?= $__user ? '/wishlist' : '/login' ?>" class="tab<?= $__cur('saved') ?>"><?= ui_icon('heart', 22) ?><span>Saved</span></a>
  <a href="<?= $__user ? '/account' : '/login' ?>" class="tab<?= $__cur('account') ?>"><?= ui_icon('user', 22) ?><span><?= $__user ? 'Account' : 'Log in' ?></span></a>
</nav>

<div id="toast" role="status" aria-live="polite"></div>
<script src="/assets/js/main.js?v=<?= $__assetV('js/main.js') ?>"></script>
<?php if (!empty($__theme['seasonal_enabled'])): ?>
<script src="/assets/js/seasonal.js?v=<?= $__assetV('js/seasonal.js') ?>" data-effect="<?= e($__theme['seasonal_effect']) ?>"></script>
<?php endif; ?>
</body>
</html>
