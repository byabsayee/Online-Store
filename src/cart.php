<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Your cart';
$totals = cart_totals();
require __DIR__ . '/includes/header.php';
?>

<div class="page-header wrap">
  <span class="eyebrow">Cart</span>
  <h1>Your cart</h1>
</div>

<div class="wrap">
<?php if (!$totals['items']): ?>
  <div class="empty-state">
    <div class="icon"><?= ui_icon('cart', 22) ?></div>
    <h2>Your cart is empty</h2>
    <p>Looks like you haven't added anything yet.</p>
    <a class="btn btn-primary" href="/">Start shopping</a>
  </div>
<?php else: ?>
  <div class="cart-layout">
    <div>
      <?php foreach ($totals['items'] as $it): ?>
        <div class="cart-line">
          <a href="<?= e(product_url($it)) ?>"><img src="<?= e(product_image_src($it['image_main'])) ?>" alt="<?= e($it['name']) ?>"></a>
          <div class="cl-info">
            <div class="name"><a href="<?= e(product_url($it)) ?>"><?= e($it['name']) ?></a><?php if ($it['is_preorder']): ?> <span class="pill pill-brass" style="font-size:11px;vertical-align:2px;">Pre-order</span><?php endif; ?></div>
            <?php if ($it['variant_label']): ?><div class="unit" style="color:var(--ink-faint);"><?= e($it['variant_label']) ?></div><?php endif; ?>
            <div class="unit"><?= money($it['price']) ?> each</div>
            <?php if (!$it['available']): ?>
              <div class="cl-warn">No longer available — please remove it to check out.</div>
            <?php elseif ($it['quantity'] > $it['stock'] && !$it['is_preorder']): ?>
              <div class="cl-warn">Only <?= (int) $it['stock'] ?> left in stock — please lower the quantity.</div>
            <?php elseif ($it['is_preorder']): ?>
              <div class="unit" style="color:var(--accent-text);"><?= $it['preorder_note'] ? e($it['preorder_note']) : 'Ships once back in stock' ?><?= $it['preorder_available_date'] ? ' · expected ' . e(date('j M Y', strtotime($it['preorder_available_date']))) : '' ?></div>
            <?php endif; ?>
            <button type="button" class="remove-btn js-cart-remove" data-item-id="<?= (int)$it['id'] ?>">Remove</button>
          </div>
          <div class="cl-buy">
            <div class="qty-stepper">
              <button type="button" class="minus" aria-label="Decrease">−</button>
              <input type="number" class="js-cart-qty" data-item-id="<?= (int)$it['id'] ?>" value="<?= (int)$it['quantity'] ?>" min="1" max="<?= $it['is_preorder'] ? 99 : max(1, (int)$it['stock']) ?>" aria-label="Quantity">
              <button type="button" class="plus" aria-label="Increase">+</button>
            </div>
            <div class="line-total"><?= money($it['price'] * $it['quantity']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <div style="padding-top:18px;">
        <a href="/" class="btn btn-outline btn-sm">← Continue shopping</a>
      </div>
    </div>

    <div class="summary-card">
      <h3>Order summary</h3>
      <div class="summary-row"><span>Subtotal</span><span class="val"><?= money($totals['subtotal']) ?></span></div>
      <div class="summary-row"><span>Shipping</span><span class="val">Calculated at checkout</span></div>
      <p style="font-size:0.8rem;color:var(--ink-soft);margin-top:8px;">
        <?= money(shipcfg('inside')) ?> inside Dhaka · <?= money(shipcfg('suburbs')) ?> suburbs · <?= money(shipcfg('outside')) ?> outside Dhaka
        (+<?= money(shipcfg('extra_kg')) ?>/kg over <?= (int)shipcfg('free_kg') ?>kg)
      </p>
      <a href="/checkout" class="btn btn-primary btn-block cart-checkout-desktop" style="margin-top:16px;">Proceed to checkout</a>
    </div>
  </div>

  <div class="checkout-bar">
    <div class="bb-price"><small>Subtotal</small><strong><?= money($totals['subtotal']) ?></strong></div>
    <a href="/checkout" class="btn btn-primary">Checkout</a>
  </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
