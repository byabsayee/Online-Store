<?php /** Expects $__activeAccountTab in scope: overview|addresses|orders|wishlist */ ?>
<nav class="account-nav">
  <a href="/account" class="<?= $__activeAccountTab === 'overview' ? 'active' : '' ?>">Account overview</a>
  <a href="/addresses" class="<?= $__activeAccountTab === 'addresses' ? 'active' : '' ?>">Addresses</a>
  <a href="/orders" class="<?= $__activeAccountTab === 'orders' ? 'active' : '' ?>">Order history</a>
  <a href="/wishlist" class="<?= $__activeAccountTab === 'wishlist' ? 'active' : '' ?>">Wishlist</a>
  <a href="/logout?csrf_token=<?= e(csrf_token()) ?>">Log out</a>
</nav>
