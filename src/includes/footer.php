<?= ad_slot('footer_above') ?>
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
      <?php foreach (footer_groups() as $__gt => $__gl): ?>
      <div>
        <h4><?= e($__gt) ?></h4>
        <ul>
          <?php foreach ($__gl as $__l): $__u = $__l['url']; if ($__u === '/account') $__u = is_logged_in() ? '/account' : '/login'; ?>
          <li><a href="<?= e($__u) ?>"<?= $__l['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($__l['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= e($__store['name']) ?>. All rights reserved.<?php if (footer_extra_text() !== ''): ?> <?= e(footer_extra_text()) ?><?php endif; ?></span>
      <span class="footer-credits">
        <?php if ($__credit = site_credit()): ?><?php if ($__credit['url'] !== ''): ?><a href="<?= e($__credit['url']) ?>" target="_blank" rel="noopener"><?= e($__credit['text']) ?></a><?php else: ?><?= e($__credit['text']) ?><?php endif; ?><?php endif; ?>
        <?php if ($__src = source_link()): ?><?= site_credit() ? ' · ' : '' ?><a href="<?= e($__src) ?>" target="_blank" rel="noopener">Source code</a><?php endif; ?>
      </span>
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

<?php if (cookie_notice_on()): ?>
<div id="cookieNotice" class="cookie-notice" role="dialog" aria-label="Cookie notice" hidden>
  <p>We use cookies to keep the site working<?= !empty($GLOBALS['__ads_used']) ? ' and to show ads' : '' ?>. See our <a href="/privacy-policy">Privacy Policy</a>.</p>
  <div><button type="button" class="btn btn-primary btn-sm" data-consent="yes">Accept</button> <button type="button" class="btn btn-outline btn-sm" data-consent="no">Decline</button></div>
</div>
<script>(function(){var n=document.getElementById('cookieNotice'),v=null;try{v=localStorage.getItem('store-consent')}catch(e){}if(!v&&n)n.hidden=false;
document.querySelectorAll('[data-consent]').forEach(function(b){b.addEventListener('click',function(){var c=b.getAttribute('data-consent');try{localStorage.setItem('store-consent',c)}catch(e){}n.hidden=true;if(c==='yes')document.dispatchEvent(new Event('store-consent-yes'))})})})();</script>
<?php endif; ?>
<div id="toast" role="status" aria-live="polite"></div>
<script src="/assets/js/main.js?v=<?= $__assetV('js/main.js') ?>"></script>
<?php if (!empty($__theme['seasonal_enabled'])): ?>
<script src="/assets/js/seasonal.js?v=<?= $__assetV('js/seasonal.js') ?>" data-effect="<?= e($__theme['seasonal_effect']) ?>"></script>
<?php endif; ?>
<?= ads_script_html() ?>
</body>
</html>
