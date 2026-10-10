<?php
/** Expects $p (product array) in scope. Optional $__favIds (array of favorited product ids). */
$__isFav = isset($__favIds) && in_array((int)$p['id'], $__favIds, true);
$__onSale = !empty($p['compare_price']) && $p['compare_price'] > $p['price'];
// Products with color/size variants are stocked per variant and can't be added
// to the cart without choosing one, so their cards link to the product page.
$__hasVariants = (int) ($p['variant_count'] ?? 0) > 0;
$__stock = $__hasVariants ? (int) ($p['variant_stock'] ?? 0) : (int) $p['stock'];
$__preorder = $__stock <= 0 && !empty($p['is_preorder']);
// "Take orders even when stock is 0": looks and behaves like an in-stock product.
$__backorder = $__stock <= 0 && !$__preorder && !empty($p['allow_backorder']);
?>
<div class="card-product">
  <div class="thumb">
    <span class="grommet"></span>
    <a href="<?= e(product_url($p)) ?>">
      <img src="<?= e(product_image_src($p['image_main'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
    </a>
    <button class="fav-btn js-fav-toggle <?= $__isFav ? 'active' : '' ?>" data-product-id="<?= (int)$p['id'] ?>" aria-label="Save to wishlist" type="button">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="<?= $__isFav ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
    </button>
    <?php if ($__onSale): ?><span class="sale-flag">Sale</span><?php endif; ?>
    <?php if ($__preorder): ?><span class="out-flag" style="background:var(--brass, #a8823c);">Pre-order</span><?php elseif ($__stock <= 0 && !$__backorder): ?><span class="out-flag">Out of stock</span><?php endif; ?>
    <?php if (!empty($p['youtube_url'])): ?>
      <span class="video-flag" title="Video available"><svg viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg></span>
    <?php endif; ?>
  </div>
  <div class="body">
    <span class="cat-label"><?= e($p['category_name'] ?? 'Shop') ?></span>
    <div class="name"><a href="<?= e(product_url($p)) ?>"><?= e($p['name']) ?></a></div>
    <div class="price-row">
      <span class="price"><?= money($p['price']) ?></span>
      <?php if ($__onSale): ?><span class="compare"><?= money($p['compare_price']) ?></span><?php endif; ?>
    </div>
    <?php if ((int) ($p['review_count'] ?? 0) > 0 || (int) ($p['wish_count'] ?? 0) > 0): ?>
    <div class="card-social">
      <?php if ((int) ($p['review_count'] ?? 0) > 0): ?><span class="cs-rating" title="<?= e(number_format((float) $p['review_avg'], 1)) ?> out of 5"><?= stars_html((float) $p['review_avg']) ?><span>(<?= (int) $p['review_count'] ?>)</span></span><?php endif; ?>
      <?php if ((int) ($p['wish_count'] ?? 0) > 0): ?><span class="cs-wish" title="Saved to <?= (int) $p['wish_count'] ?> wishlist<?= (int) $p['wish_count'] === 1 ? '' : 's' ?>"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg><?= (int) $p['wish_count'] ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if (($__stock > 0 || $__preorder || $__backorder) && $__hasVariants): ?>
  <div class="add-form">
    <a href="<?= e(product_url($p)) ?>" class="btn btn-primary btn-block btn-sm"><?= $__preorder ? 'Pre-order' : 'Choose options' ?></a>
  </div>
  <?php elseif ($__stock > 0 || $__preorder || $__backorder): ?>
  <form class="add-form js-add-cart" method="post">
    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
    <input type="hidden" name="quantity" value="1">
    <button type="submit" class="btn btn-primary btn-block btn-sm"><?= $__preorder ? 'Pre-order' : 'Add to cart' ?></button>
  </form>
  <?php endif; ?>
</div>
