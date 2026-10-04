<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare(
    "SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.slug = ? AND p.is_active = 1"
);
$stmt->execute([$slug]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="wrap section"><div class="empty-state"><h2>Product not found</h2><p>This item may be sold out permanently or removed.</p><a class="btn btn-primary" href="/">Back to shop</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$imgStmt = db()->prepare('SELECT image_path FROM product_images WHERE product_id = ? ORDER BY sort_order');
$imgStmt->execute([$product['id']]);
$gallery = array_column($imgStmt->fetchAll(), 'image_path');
if ($product['image_main']) {
    array_unshift($gallery, $product['image_main']);
}
$gallery = array_values(array_unique($gallery));
if (!$gallery) { $gallery = [null]; }

$relStmt = db()->prepare(
    "SELECT p.*, c.name AS category_name" . PRODUCT_LIST_EXTRA . " FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.category_id = ? AND p.id != ? AND p.is_active = 1
     ORDER BY RAND() LIMIT 4"
);
$relStmt->execute([$product['category_id'], $product['id']]);
$related = $relStmt->fetchAll();

$variants = product_variants_for((int) $product['id']);
$options = product_options_for((int) $product['id']);
// Only offer options that at least one active variant actually uses.
$usedColors = array_flip(array_filter(array_column($variants, 'color')));
$usedSizes = array_flip(array_filter(array_column($variants, 'size')));
$colorOpts = array_values(array_filter($options['color'], fn ($o) => isset($usedColors[$o['name']])));
$sizeOpts = array_values(array_filter($options['size'], fn ($o) => isset($usedSizes[$o['name']])));
$totalVariantStock = 0;
foreach ($variants as $v) { $totalVariantStock += (int) $v['stock']; }
$effectiveStock = $variants ? $totalVariantStock : (int) $product['stock'];
$isPreorder = $effectiveStock <= 0 && !empty($product['is_preorder']);
$tags = product_tags($product);

// Everything the page's picker script needs, in one JSON blob.
$pickerData = [
    'symbol' => store_currency_symbol(),
    'basePrice' => (float) $product['price'],
    'base' => [
        'weight' => (int) $product['weight_grams'],
        'h' => $product['height_mm'] !== null ? (int) $product['height_mm'] : null,
        'w' => $product['width_mm'] !== null ? (int) $product['width_mm'] : null,
        'd' => $product['depth_mm'] !== null ? (int) $product['depth_mm'] : null,
        'stock' => (int) $product['stock'],
        'image' => product_image_src($gallery[0]),
    ],
    'colors' => array_map(fn ($o) => ['name' => $o['name'], 'image' => $o['image'] ?: null], $colorOpts),
    'sizes' => array_map(fn ($o) => [
        'name' => $o['name'], 'image' => $o['image'] ?: null,
        'weight' => $o['weight_grams'] !== null ? (int) $o['weight_grams'] : null,
        'h' => $o['height_mm'] !== null ? (int) $o['height_mm'] : null,
        'w' => $o['width_mm'] !== null ? (int) $o['width_mm'] : null,
        'd' => $o['depth_mm'] !== null ? (int) $o['depth_mm'] : null,
    ], $sizeOpts),
    'variants' => array_map(fn ($v) => [
        'id' => (int) $v['id'], 'color' => $v['color'] ?: null, 'size' => $v['size'] ?: null,
        'delta' => (float) $v['price_delta'], 'stock' => (int) $v['stock'],
    ], $variants),
    'preorder' => $isPreorder,
    'preorderNote' => $product['preorder_note'] ?: null,
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$__user = current_user();
$wishCount = product_wish_count((int) $product['id']);
$reviewSummary = review_summary((int) $product['id']);
$reviewPage = max(1, (int) ($_GET['rpage'] ?? 1));
$reviewPages = max(1, (int) ceil($reviewSummary['count'] / REVIEWS_PER_PAGE));
$reviewPage = min($reviewPage, $reviewPages);
$reviews = review_list((int) $product['id'], REVIEWS_PER_PAGE, ($reviewPage - 1) * REVIEWS_PER_PAGE);
$reviewBox = review_box_state($__user, (int) $product['id']);
// Text typed into a review that failed validation (see review_submit.php), so nothing is lost.
$reviewOld = $_SESSION['review_old'][(int) $product['id']] ?? null;
unset($_SESSION['review_old'][(int) $product['id']]);
$__favIds = $__user ? favorite_ids_for_user($__user['id']) : [];
$isFav = in_array((int)$product['id'], $__favIds, true);
$onSale = !empty($product['compare_price']) && $product['compare_price'] > $product['price'];

$pageTitle = $product['name'];
// What a shared link to this product shows (see render_head_meta()): the product's own photo, name and description.
$seoPrice = (float) $product['price'] + ($variants ? min(array_map(fn ($v) => (float) $v['price_delta'], $variants)) : 0);
$seo = [
    'type' => 'product',
    'title' => $product['name'],
    'description' => product_share_description($product),
    'image' => $gallery[0] ?? null,
    'images' => array_values(array_filter($gallery)),
    'price' => $seoPrice,
    'in_stock' => $effectiveStock > 0,
    'sku' => $product['sku'],
    'category' => $product['category_name'],
    'rating' => $reviewSummary['count'] ? $reviewSummary['avg'] : null,
    'review_count' => $reviewSummary['count'],
];
$bodyClass = ($effectiveStock > 0 || $isPreorder) ? 'has-action-bar' : '';
require __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <div class="breadcrumb">
    <a href="/">Home</a> /
    <?php if ($product['category_slug']): ?>
      <a href="<?= e(category_url(['slug' => $product['category_slug']])) ?>"><?= e($product['category_name']) ?></a> /
    <?php endif; ?>
    <?= e($product['name']) ?>
  </div>
</div>

<div class="wrap product-view">
  <div>
    <div class="gallery-main">
      <img id="galleryMainImg" src="<?= e(product_image_src($gallery[0])) ?>" alt="<?= e($product['name']) ?>">
    </div>
    <?php if (count($gallery) > 1): ?>
      <div class="gallery-thumbs">
        <?php foreach ($gallery as $i => $img): ?>
          <img src="<?= e(product_image_src($img)) ?>" data-full="<?= e(product_image_src($img)) ?>" class="<?= $i === 0 ? 'active' : '' ?>" alt="">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="product-info">
    <span class="sku">SKU <?= e($product['sku'] ?: '—') ?></span>
    <h1><?= e($product['name']) ?></h1>
    <?php if ($reviewSummary['count']): ?>
      <a class="rating-line" href="#reviews" aria-label="<?= e(number_format($reviewSummary['avg'], 1)) ?> out of 5 from <?= (int) $reviewSummary['count'] ?> reviews">
        <?= stars_html($reviewSummary['avg']) ?><span class="rating-num"><?= e(number_format($reviewSummary['avg'], 1)) ?></span><span class="rating-count">(<?= (int) $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?>)</span>
      </a>
    <?php endif; ?>
    <div class="price-row">
      <span class="price" id="productPrice"><?= money($product['price']) ?></span>
      <?php if ($onSale): ?><span class="compare"><?= money($product['compare_price']) ?></span><span class="pill pill-rust">Sale</span><?php endif; ?>
    </div>

    <?php if ($product['short_desc']): ?><p class="desc"><?= e($product['short_desc']) ?></p><?php endif; ?>

    <?php if (!empty($product['link_url']) && preg_match('~^https?://~i', $product['link_url'])): ?>
      <a href="<?= e($product['link_url']) ?>" target="_blank" rel="noopener nofollow" class="video-btn" style="margin-bottom:18px;">
        <?= ui_icon('link', 18) ?>
        <?= e($product['link_title'] ?: 'Learn more') ?>
      </a>
    <?php endif; ?>

    <div class="stock-line" id="stockLine">
      <?php if ($effectiveStock > 10): ?>
        <span class="pill pill-sage">In stock</span>
      <?php elseif ($effectiveStock > 0): ?>
        <span class="pill pill-rust">Only <?= (int)$effectiveStock ?> left</span>
      <?php elseif ($isPreorder): ?>
        <span class="pill pill-brass">Pre-order<?= $product['preorder_note'] ? ' — ' . e($product['preorder_note']) : '' ?></span>
        <?php if ($product['preorder_available_date']): ?><span class="muted small">Expected <?= e(date('j M Y', strtotime($product['preorder_available_date']))) ?></span><?php endif; ?>
      <?php else: ?>
        <span class="pill pill-ink">Out of stock</span>
      <?php endif; ?>
    </div>

    <div class="wish-line" data-wish-count="<?= (int) $product['id'] ?>"<?= $wishCount < 1 ? ' hidden' : '' ?>>
      <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
      <span><strong data-wish-num><?= (int) $wishCount ?></strong> <span data-wish-word><?= $wishCount === 1 ? 'person has' : 'people have' ?></span> this in their wishlist</span>
    </div>

    <?php
    // Renders one picker (color or size). Colors with a swatch color or photo
    // become round swatches; everything else is a text chip.
    $renderChoices = function (string $kind, array $opts) {
        foreach ($opts as $o):
            $isSwatch = $kind === 'color' && (!empty($o['swatch']) || !empty($o['image']));
            $style = $isSwatch && !empty($o['swatch']) ? ' style="--dot:' . e($o['swatch']) . '"' : '';
    ?>
        <?php if ($isSwatch): ?>
          <button type="button" class="swatch" data-kind="<?= $kind ?>" data-value="<?= e($o['name']) ?>" aria-label="<?= e($o['name']) ?>" title="<?= e($o['name']) ?>"<?= $style ?>><span class="dot"><?php if (empty($o['swatch']) && !empty($o['image'])): ?><img src="<?= e($o['image']) ?>" alt=""><?php endif; ?></span></button>
        <?php else: ?>
          <button type="button" class="chip" data-kind="<?= $kind ?>" data-value="<?= e($o['name']) ?>"><?= e($o['name']) ?></button>
        <?php endif; ?>
    <?php endforeach; };
    ?>
    <?php if ($colorOpts): ?>
      <div class="option-group" id="colorGroup">
        <div class="option-label">Color <span class="chosen" id="colorChosen"></span></div>
        <div class="option-choices" role="group" aria-label="Color"><?php $renderChoices('color', $colorOpts); ?></div>
      </div>
    <?php endif; ?>
    <?php if ($sizeOpts): ?>
      <div class="option-group" id="sizeGroup">
        <div class="option-label">Size <span class="chosen" id="sizeChosen"></span></div>
        <div class="option-choices" role="group" aria-label="Size"><?php $renderChoices('size', $sizeOpts); ?></div>
      </div>
    <?php endif; ?>

    <?php if ($effectiveStock > 0 || $isPreorder): ?>
      <form class="js-add-cart" method="post" id="addCartForm">
        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
        <?php if ($variants): ?><input type="hidden" name="variant_id" id="variantIdField" value=""><?php endif; ?>
        <div class="qty-row">
          <div class="qty-stepper">
            <button type="button" class="minus" aria-label="Decrease">−</button>
            <input type="number" name="quantity" id="qtyField" value="1" min="1" max="<?= $effectiveStock > 0 ? (int)$effectiveStock : 99 ?>">
            <button type="button" class="plus" aria-label="Increase">+</button>
          </div>
        </div>
        <div class="product-actions">
          <button type="submit" class="btn btn-primary" id="addCartBtn" <?= $variants ? 'disabled' : '' ?>><?= (!$variants && $isPreorder) ? 'Pre-order' : 'Add to cart' ?></button>
          <button type="button" class="btn btn-outline js-fav-toggle <?= $isFav ? 'active' : '' ?>" data-product-id="<?= (int)$product['id'] ?>" data-off-label="Save for later">
            <?= ui_icon('heart', 18) ?><span class="fav-label"><?= $isFav ? 'Saved' : 'Save for later' ?></span>
          </button>
        </div>
      </form>
    <?php else: ?>
      <div class="product-actions">
        <button class="btn btn-primary" disabled>Out of stock</button>
        <button type="button" class="btn btn-outline js-fav-toggle <?= $isFav ? 'active' : '' ?>" data-product-id="<?= (int)$product['id'] ?>" data-off-label="Notify me / save">
          <?= ui_icon('heart', 18) ?><span class="fav-label"><?= $isFav ? 'Saved' : 'Notify me / save' ?></span>
        </button>
      </div>
    <?php endif; ?>

    <?php if ($product['description']): ?>
      <div class="desc"><?= nl2br(e($product['description'])) ?></div>
    <?php endif; ?>

    <div class="meta-list">
      <div><b>Category:</b> <?= e($product['category_name'] ?? 'Uncategorized') ?></div>
      <?php if ($product['color'] && !$colorOpts): ?><div><b>Color:</b> <?= e($product['color']) ?></div><?php endif; ?>
      <?php $hasAnyDims = $product['height_mm'] || $product['width_mm'] || $product['depth_mm'] || array_filter($sizeOpts, fn ($o) => $o['height_mm'] || $o['width_mm'] || $o['depth_mm']); ?>
      <?php if ($hasAnyDims): ?>
        <div id="metaDims"><b>Dimensions (H×W×D):</b> <span id="metaDimsVal">
          <?= $product['height_mm'] ? (int)$product['height_mm'] : '—' ?> ×
          <?= $product['width_mm'] ? (int)$product['width_mm'] : '—' ?> ×
          <?= $product['depth_mm'] ? (int)$product['depth_mm'] : '—' ?> mm</span>
        </div>
      <?php endif; ?>
      <div id="metaWeight"><b>Weight:</b> <span id="metaWeightVal"><?= (int)$product['weight_grams'] ?>g</span></div>
      <?php if ($tags): ?>
        <div><b>Tags:</b>
          <div class="tag-list"><?php foreach ($tags as $t): ?><a href="/search?q=<?= urlencode($t) ?>"><?= e($t) ?></a><?php endforeach; ?></div>
        </div>
      <?php endif; ?>
      <div><b>Shipping:</b> <?= e(shipping_summary_text()) ?></div>
      <div><b>Delivery time:</b> <?= e(implode('–', delivery_days_range())) ?> days</div>
      <div><b>Payment:</b> Cash on delivery <span style="color:var(--ink-faint);">(online payment coming soon)</span></div>
      <?php if ($warranty = warranty_label($product['warranty_days'] ?? null)): ?>
        <div><b>Warranty:</b> <?= e($warranty) ?></div>
      <?php endif; ?>
      <div><b>Returns:</b> 7-day no-questions returns on unused items</div>
    </div>
  </div>
</div>

<?php if ($effectiveStock > 0 || $isPreorder): ?>
<div class="buy-bar" id="buyBar" aria-label="Add to cart">
  <div class="bb-price"><small>Price</small><strong id="buyBarPrice"><?= money($product['price']) ?></strong></div>
  <button type="button" class="btn btn-primary" id="buyBarBtn"><?= (!$variants && $isPreorder) ? 'Pre-order' : 'Add to cart' ?></button>
</div>
<?php endif; ?>

<?php if ($variants): ?>
<script>window.STORE_PRODUCT = <?= json_encode($pickerData, $jsonFlags) ?>;</script>
<script src="/assets/js/product.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/product.js') ?>"></script>
<?php endif; ?>

<?= ad_slot('product_below') ?>
<section class="section reviews" id="reviews">
  <div class="wrap">
    <div class="section-head"><div><span class="tag">Customer reviews</span><h2>What customers say</h2></div></div>

    <div class="reviews-layout">
      <aside class="review-summary" aria-label="Rating summary">
        <?php if ($reviewSummary['count']): ?>
          <div class="rs-score"><span class="rs-avg"><?= e(number_format($reviewSummary['avg'], 1)) ?></span><span class="rs-of">out of 5</span></div>
          <?= stars_html($reviewSummary['avg'], 'stars-lg') ?>
          <div class="rs-count"><?= (int) $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?></div>
          <div class="rs-bars">
            <?php foreach ($reviewSummary['dist'] as $star => $n): $pct = $reviewSummary['count'] ? round($n / $reviewSummary['count'] * 100) : 0; ?>
              <div class="rs-bar"><span class="rs-star"><?= (int) $star ?> <?= ui_icon('star', 11) ?></span><span class="rs-track"><span style="width:<?= (int) $pct ?>%"></span></span><span class="rs-n"><?= (int) $n ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="rs-empty">No reviews yet</div>
        <?php endif; ?>
        <div class="rs-wish" data-wish-count="<?= (int) $product['id'] ?>"<?= $wishCount < 1 ? ' hidden' : '' ?>><span data-wish-num><?= (int) $wishCount ?></span> <span data-wish-word><?= $wishCount === 1 ? 'person has' : 'people have' ?></span> saved this to a wishlist</div>
      </aside>

      <div class="review-main">
        <?php
        $existing = $reviewBox['existing'];
        $val = fn (string $k, $fallback = '') => $reviewOld[$k] ?? ($existing[$k] ?? $fallback);
        $curRating = (int) $val('rating', 0);
        ?>
        <?php if ($reviewBox['state'] === 'guest'): ?>
          <div class="review-note">
            <strong>Bought this?</strong> <a href="/login" style="text-decoration:underline;">Log in</a> to share what you think — only customers who have received the product can review it.
          </div>
        <?php elseif ($reviewBox['state'] === 'cannot'): ?>
          <div class="review-note">Only customers who have received this product can review it. Once your order ships, you can come back and leave a review.</div>
        <?php else: ?>
          <form class="review-form" id="review-form" method="post" action="/review_submit.php">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <h3><?= $existing ? 'Your review' : 'Write a review' ?></h3>
            <?php if ($existing && $existing['status'] === 'hidden'): ?>
              <div class="alert alert-info">The store has hidden your review, so it isn't shown to other customers. You can still edit or delete it.</div>
            <?php endif; ?>
            <fieldset class="star-input">
              <legend>Your rating</legend>
              <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" name="rating" id="rate<?= $i ?>" value="<?= $i ?>"<?= $curRating === $i ? ' checked' : '' ?> required>
                <label for="rate<?= $i ?>" title="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>"><span class="sr-only"><?= $i ?> star<?= $i === 1 ? '' : 's' ?></span></label>
              <?php endfor; ?>
            </fieldset>
            <div class="field"><label for="review_title">Title <span style="font-weight:400;color:var(--ink-faint);">(optional)</span></label><input id="review_title" name="title" maxlength="120" value="<?= e($val('title')) ?>" placeholder="Sum it up in a few words"></div>
            <div class="field"><label for="review_body">Your review</label><textarea id="review_body" name="body" rows="4" required minlength="<?= (int) REVIEW_MIN_BODY ?>" maxlength="<?= (int) REVIEW_MAX_BODY ?>" placeholder="What did you like? How is the quality?"><?= e($val('body')) ?></textarea></div>
            <div class="review-actions">
              <button type="submit" class="btn btn-primary" name="action" value="save"><?= $existing ? 'Update review' : 'Submit review' ?></button>
              <?php if ($existing): ?><button type="submit" class="btn btn-ghost" name="action" value="delete" formnovalidate onclick="return confirm('Delete your review?');">Delete</button><?php endif; ?>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($reviews): ?>
          <ul class="review-list">
            <?php foreach ($reviews as $r): ?>
              <li class="review">
                <div class="review-head">
                  <?= stars_html((float) $r['rating']) ?>
                  <?php if ($r['title']): ?><strong class="review-title"><?= e($r['title']) ?></strong><?php endif; ?>
                </div>
                <p class="review-body"><?= nl2br(e($r['body'])) ?></p>
                <div class="review-meta"><?= e(review_display_name($r['author_name'])) ?> · <span class="verified">Verified purchase</span> · <?= e(fmt_dt($r['created_at'], 'j M Y')) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($reviewPages > 1): ?>
            <div class="pagination" aria-label="Review pages">
              <?php for ($i = 1; $i <= $reviewPages; $i++): ?>
                <?php if ($i === $reviewPage): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= e(product_url($product)) ?>?rpage=<?= $i ?>#reviews"><?= $i ?></a><?php endif; ?>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        <?php elseif ($reviewBox['state'] !== 'can_review'): ?>
          <p class="review-empty">Be the first to review this product once you've received it.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="section section-alt">
  <div class="wrap">
    <div class="section-head"><div><span class="tag">You might also like</span><h2>Related products</h2></div></div>
    <div class="product-grid">
      <?php foreach ($related as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
