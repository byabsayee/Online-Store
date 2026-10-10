<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/product_admin.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$product = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) { flash_set('error', 'Product not found.'); redirect('/admin/products.php'); }
}

$categories = category_flat_for_select();
$errors = [];
$editorData = null; // set from POST when validation fails, so nothing typed is lost

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $name = trim($_POST['name'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
    $sku = trim($_POST['sku'] ?? '');
    $shortDesc = mb_substr(trim($_POST['short_desc'] ?? ''), 0, 255);
    $description = trim($_POST['description'] ?? '');
    $tags = implode(', ', parse_tags($_POST['tags'] ?? ''));
    $price = (float) ($_POST['price'] ?? 0);
    $comparePrice = ($_POST['compare_price'] ?? '') !== '' ? (float) $_POST['compare_price'] : null;
    $stock = (int) ($_POST['stock'] ?? 0);
    $showStock = !empty($_POST['show_stock']) ? 1 : 0;
    $allowBackorder = !empty($_POST['allow_backorder']) ? 1 : 0;
    $weightGrams = (int) ($_POST['weight_grams'] ?? 500);
    $heightMm = int_or_null($_POST['height_mm'] ?? '');
    $widthMm = int_or_null($_POST['width_mm'] ?? '');
    $depthMm = int_or_null($_POST['depth_mm'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $warrantyDays = int_or_null($_POST['warranty_days'] ?? '');
    $linkUrl = trim($_POST['link_url'] ?? '');
    $linkTitle = mb_substr(trim($_POST['link_title'] ?? ''), 0, 80);
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $isFeatured = !empty($_POST['is_featured']) ? 1 : 0;
    $isPreorder = !empty($_POST['is_preorder']) ? 1 : 0;
    $preorderNote = mb_substr(trim($_POST['preorder_note'] ?? ''), 0, 255);
    $preorderDate = trim($_POST['preorder_available_date'] ?? '') ?: null;
    $slugInput = trim($_POST['slug'] ?? '');

    if (mb_strlen($name) < 2) $errors[] = 'Please enter a product name.';
    if ($price <= 0) $errors[] = 'Please enter a valid price.';
    if ($comparePrice !== null && $comparePrice <= $price) $errors[] = 'The "compare at" price should be higher than the selling price (or leave it empty).';
    if ($weightGrams <= 0) $errors[] = 'Please enter a valid weight in grams.';
    if ($warrantyDays !== null && ($warrantyDays < 1 || $warrantyDays > 3650)) $errors[] = 'Warranty should be between 1 day and 10 years (3650 days), or left empty for no warranty.';
    if ($linkUrl !== '' && !preg_match('~^https?://[^\s]+$~i', $linkUrl)) $errors[] = 'The external link must start with https:// (or http://).';
    if ($linkUrl !== '' && $linkTitle === '') $linkTitle = is_youtube_url($linkUrl) ? 'Watch video' : 'Learn more';
    if ($preorderDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $preorderDate)) $errors[] = 'Please enter a valid expected availability date.';

    // Colors / sizes / per-combination stock. Photos are stored as a side effect of parsing.
    $parsed = ['colors' => [], 'sizes' => [], 'customs' => []];
    $newMain = null; $newGallery = [];
    try {
        $parsed = parse_option_posts($errors);
        $newMain = handle_product_image_upload('image_main');
        if (!empty($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['name'])) {
            foreach (array_keys($_FILES['gallery_images']['name']) as $i) {
                $p = handle_indexed_image_upload('gallery_images', (int) $i);
                if ($p) $newGallery[] = $p;
            }
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    $combos = expected_combos($parsed['colors'], $parsed['sizes'], $_POST['combos'] ?? []);
    if (!$combos && $stock < 0) $errors[] = 'Stock cannot be negative.';
    // However the optional extra prices are combined, the shopper's total must stay above zero.
    $lowest = fn (array $rows) => $rows ? min(0, min(array_column($rows, 'price_delta'))) : 0;
    if ($price > 0 && $price + $lowest($parsed['colors']) + $lowest($parsed['sizes']) + $lowest($combos) <= 0) {
        $errors[] = 'The negative price adjustments on colors, sizes and combinations would bring the price to zero or below. Please lower them.';
    }

    if (!$errors) {
        // URL slug: keep the existing one unless the admin edited it, so live links don't break on a rename.
        $slug = slugify($slugInput !== '' ? $slugInput : ($product && $product['slug'] && $product['name'] === $name ? $product['slug'] : $name));
        $baseSlug = $slug; $n = 1;
        while (true) {
            $check = db()->prepare('SELECT id FROM products WHERE slug = ? AND id != ?');
            $check->execute([$slug, $id ?: 0]);
            if (!$check->fetch()) break;
            $slug = $baseSlug . '-' . (++$n);
        }

        $pdo = db();
        // Snapshot of the colour/size/stock table before saving, so the log can say whether it changed.
        $variantsBefore = $product ? json_encode(variant_editor_data((int) $product['id'])) : null;
        try {
            $pdo->beginTransaction();
            $mainImage = $newMain ?: ($product['image_main'] ?? null);
            $oldMain = $newMain && $product ? $product['image_main'] : null;

            if ($product) {
                $pdo->prepare(
                    'UPDATE products SET category_id=?, name=?, slug=?, sku=?, short_desc=?, tags=?, description=?, price=?, compare_price=?, stock=?, show_stock=?, allow_backorder=?, is_preorder=?, preorder_note=?, preorder_available_date=?, weight_grams=?, height_mm=?, width_mm=?, depth_mm=?, color=?, warranty_days=?, image_main=?, link_url=?, link_title=?, is_active=?, is_featured=? WHERE id=?'
                )->execute([$categoryId, $name, $slug, $sku ?: null, $shortDesc ?: null, $tags ?: null, $description ?: null, $price, $comparePrice, max(0, $stock), $showStock, $allowBackorder, $isPreorder, $preorderNote ?: null, $preorderDate, $weightGrams, $heightMm, $widthMm, $depthMm, $color ?: null, $warrantyDays, $mainImage, $linkUrl ?: null, $linkTitle ?: null, $isActive, $isFeatured, $product['id']]);
                $productId = (int) $product['id'];
            } else {
                $pdo->prepare(
                    'INSERT INTO products (category_id, name, slug, sku, short_desc, tags, description, price, compare_price, stock, show_stock, allow_backorder, is_preorder, preorder_note, preorder_available_date, weight_grams, height_mm, width_mm, depth_mm, color, warranty_days, image_main, link_url, link_title, is_active, is_featured) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$categoryId, $name, $slug, $sku ?: null, $shortDesc ?: null, $tags ?: null, $description ?: null, $price, $comparePrice, max(0, $stock), $showStock, $allowBackorder, $isPreorder, $preorderNote ?: null, $preorderDate, $weightGrams, $heightMm, $widthMm, $depthMm, $color ?: null, $warrantyDays, $mainImage, $linkUrl ?: null, $linkTitle ?: null, $isActive, $isFeatured]);
                $productId = (int) $pdo->lastInsertId();
            }

            $vBefore = [];
            if ($product) foreach ($pdo->query('SELECT id, stock FROM product_variants WHERE product_id = ' . (int) $productId)->fetchAll() as $vb) $vBefore[(int) $vb['id']] = (int) $vb['stock'];
            $variantTotal = save_product_variants($pdo, $productId, $parsed['colors'], $parsed['sizes'], $combos);
            save_product_customizations($pdo, $productId, $parsed['customs']);
            if ($variantTotal !== null) {
                // Variant products are stocked per combination; keep the product-level number in step.
                $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$variantTotal, $productId]);
            }

            // Accounting: send the product, then any hand-made quantity change as a delta (never an absolute overwrite).
            // A brand-new product's starting quantity travels inside its create event as an opening balance.
            erp_emit('product', $productId, 'auto');
            if ($product) {
                if ($variantTotal === null) {
                    erp_stock_adjust_emit($productId, null, max(0, $stock) - (int) $product['stock'], 'Edited in the product form');
                } else {
                    foreach (product_variants_for($productId, false) as $vNow) {
                        $delta = (int) $vNow['stock'] - ($vBefore[(int) $vNow['id']] ?? 0);
                        erp_stock_adjust_emit($productId, (int) $vNow['id'], $delta, 'Edited in the product form');
                    }
                }
            }

            if ($newGallery) {
                $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = ' . $productId)->fetchColumn();
                $ins = $pdo->prepare('INSERT INTO product_images (product_id, image_path, sort_order) VALUES (?,?,?)');
                foreach ($newGallery as $k => $path) $ins->execute([$productId, $path, $next + 1 + $k]);
            }
            $pdo->commit();

            // ---- audit log (after the save has really succeeded) ----
            $catName = fn ($cid) => $cid ? (array_column($categories, 'name', 'id')[(int) $cid] ?? ('#' . (int) $cid)) : 'None';
            $finalStock = $variantTotal !== null ? $variantTotal : max(0, $stock);
            $newVals = ['name' => $name, 'category' => $catName($categoryId), 'sku' => $sku, 'slug' => $slug, 'short_desc' => $shortDesc, 'tags' => $tags, 'description' => $description,
                'price' => $price, 'compare_price' => $comparePrice, 'stock' => $finalStock, 'weight_grams' => $weightGrams, 'height_mm' => $heightMm, 'width_mm' => $widthMm,
                'depth_mm' => $depthMm, 'color' => $color, 'warranty_days' => $warrantyDays, 'link_url' => $linkUrl, 'link_title' => $linkTitle, 'is_active' => $isActive ? 'yes' : 'no', 'is_featured' => $isFeatured ? 'yes' : 'no',
                'show_stock' => $showStock ? 'yes' : 'no', 'allow_backorder' => $allowBackorder ? 'yes' : 'no', 'is_preorder' => $isPreorder ? 'yes' : 'no', 'preorder_note' => $preorderNote, 'preorder_available_date' => $preorderDate];
            if ($product) {
                $oldVals = $product;
                $oldVals['category'] = $catName($product['category_id']);
                $oldVals['is_active'] = $product['is_active'] ? 'yes' : 'no';
                $oldVals['is_featured'] = $product['is_featured'] ? 'yes' : 'no';
                $oldVals['is_preorder'] = $product['is_preorder'] ? 'yes' : 'no';
                $oldVals['show_stock'] = !empty($product['show_stock']) ? 'yes' : 'no';
                $oldVals['allow_backorder'] = !empty($product['allow_backorder']) ? 'yes' : 'no';
                $diff = admin_log_diff($oldVals, $newVals, ['name' => 'Name', 'category' => 'Category', 'sku' => 'SKU', 'slug' => 'URL slug', 'short_desc' => 'Short description', 'description' => 'Description',
                    'tags' => 'Tags', 'price' => 'Price', 'compare_price' => 'Compare-at price', 'stock' => 'Stock', 'weight_grams' => 'Weight (g)', 'height_mm' => 'Height (mm)', 'width_mm' => 'Width (mm)',
                    'depth_mm' => 'Depth (mm)', 'color' => 'Colour', 'warranty_days' => 'Warranty (days)', 'link_url' => 'External link', 'link_title' => 'Link title', 'is_active' => 'Visible in shop', 'is_featured' => 'Featured',
                    'show_stock' => 'Show stock to shoppers', 'allow_backorder' => 'Take orders at 0 stock', 'is_preorder' => 'Available for pre-order', 'preorder_note' => 'Pre-order note', 'preorder_available_date' => 'Expected availability']);
                if ($variantsBefore !== json_encode(variant_editor_data($productId))) $diff['Colours / sizes / customization / per-variant stock'] = ['(before)', 'edited'];
                if ($newMain) $diff['Main photo'] = ['(old photo)', 'replaced'];
                if ($newGallery) $diff['Gallery photos'] = ['—', count($newGallery) . ' added'];
                admin_log('product.update', 'Edited product "' . admin_log_clip($name, 80) . '"' . ($diff ? ': ' . admin_log_diff_summary($diff) : ' (saved, nothing changed)'), 'product', $productId, $diff ? ['changes' => $diff] : []);
            } else {
                admin_log('product.create', 'Created product "' . admin_log_clip($name, 80) . '" at ' . money($price), 'product', $productId,
                    array_filter(['sku' => $sku, 'category' => $catName($categoryId), 'stock' => $finalStock, 'visible_in_shop' => $isActive ? 'yes' : 'no']));
            }

            delete_upload_if_unused($oldMain);
            flash_set('success', $product ? 'Product updated.' : 'Product created.');
            redirect('/admin/product_form.php?id=' . $productId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[product_form] ' . $e->getMessage());
            $errors[] = 'Could not save the product: ' . $e->getMessage();
        }
    }
    $editorData = variant_editor_data_from_post($parsed, $_POST['combos'] ?? []);
}

// Values to show: the saved product, overlaid with whatever was just submitted.
$f = $product ?: ['name' => '', 'slug' => '', 'sku' => '', 'category_id' => null, 'short_desc' => '', 'tags' => '', 'description' => '', 'price' => '', 'compare_price' => '',
    'stock' => 0, 'weight_grams' => 300, 'height_mm' => '', 'width_mm' => '', 'depth_mm' => '', 'color' => '', 'warranty_days' => '', 'link_url' => '', 'link_title' => '', 'is_active' => 1, 'is_featured' => 0, 'image_main' => null,
    'show_stock' => 1, 'allow_backorder' => 0, 'is_preorder' => 0, 'preorder_note' => '', 'preorder_available_date' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name', 'slug', 'sku', 'short_desc', 'tags', 'description', 'price', 'compare_price', 'stock', 'weight_grams', 'height_mm', 'width_mm', 'depth_mm', 'color', 'warranty_days', 'link_url', 'link_title', 'preorder_note', 'preorder_available_date'] as $k) {
        if (isset($_POST[$k])) $f[$k] = $_POST[$k];
    }
    $f['category_id'] = (int) ($_POST['category_id'] ?? 0) ?: null;
    $f['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
    $f['is_featured'] = !empty($_POST['is_featured']) ? 1 : 0;
    $f['is_preorder'] = !empty($_POST['is_preorder']) ? 1 : 0;
    $f['show_stock'] = !empty($_POST['show_stock']) ? 1 : 0;
    $f['allow_backorder'] = !empty($_POST['allow_backorder']) ? 1 : 0;
}

$gallery = [];
if ($product) {
    $gStmt = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
    $gStmt->execute([$product['id']]);
    $gallery = $gStmt->fetchAll();
    $editorData = $editorData ?? variant_editor_data((int) $product['id']);
}
$editorData = $editorData ?? ['colors' => [], 'sizes' => [], 'combos' => [], 'customs' => []];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$pageTitle = $product ? 'Edit product' : 'Add product';
require __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-error"><strong>Please fix the following:</strong><ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php
$hasLink = !empty($f['link_url']) || !empty($f['link_title']);
$sym = e(store_currency_symbol());
?>
<form method="post" enctype="multipart/form-data" id="productForm" autocomplete="off" class="product-form">
  <?= csrf_field() ?>

  <nav class="form-steps" id="formSteps" aria-label="Jump to a section">
    <a href="#sec-basics" class="is-active">Basics</a>
    <a href="#sec-photos">Photos</a>
    <a href="#sec-pricing">Price &amp; stock</a>
    <a href="#sec-shipping">Shipping</a>
    <a href="#variantPanel">Colors &amp; sizes</a>
    <a href="#customPanel">Customization</a>
  </nav>

  <div class="form-layout">
    <div class="form-main">

      <!-- ─────────────── 1 · Basics ─────────────── -->
      <section class="panel" id="sec-basics">
        <div class="panel-head"><h2><span class="num">1</span>Basic information</h2><span class="sub">What shoppers read first</span></div>
        <div class="panel-body">
          <div class="field">
            <label for="name">Product name</label>
            <input type="text" id="name" name="name" required maxlength="200" value="<?= e($f['name']) ?>" placeholder="e.g. Titanium Pocket Pry Bar">
          </div>
          <div class="field">
            <label for="short_desc">Short description <span class="opt">optional</span><span class="counter" data-counter-for="short_desc" data-max="255"></span></label>
            <input type="text" id="short_desc" name="short_desc" maxlength="255" value="<?= e($f['short_desc']) ?>" placeholder="One line shown on product cards and search results">
          </div>
          <div class="field">
            <label for="description">Full description <span class="opt">optional</span></label>
            <textarea id="description" name="description" rows="6" placeholder="Materials, what's in the box, care instructions…"><?= e($f['description']) ?></textarea>
          </div>
          <div class="field" style="margin-bottom:0;">
            <label for="tags">Search tags <span class="opt">optional</span></label>
            <input type="text" id="tags" name="tags" value="<?= e($f['tags']) ?>" data-chips placeholder="Type a tag and press Enter">
            <div class="hint">Extra words shoppers might search for (e.g. <em>titanium, keychain, gift</em>). Partial words work too.</div>
          </div>
        </div>
      </section>

      <!-- ─────────────── 2 · Photos ─────────────── -->
      <section class="panel" id="sec-photos">
        <div class="panel-head"><h2><span class="num">2</span>Photos &amp; video</h2><span class="sub">Square photos look best</span></div>
        <div class="panel-body">
          <div class="photo-grid">
            <div class="field">
              <span class="field-label">Main photo</span>
              <div class="thumb-grid" id="mainPreview">
                <?php if (!empty($f['image_main'])): ?>
                  <div class="thumb-tile"><img src="<?= e($f['image_main']) ?>" alt=""><span class="badge">Main</span></div>
                <?php endif; ?>
              </div>
              <div class="upload-box">
                <input type="file" name="image_main" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="#mainPreview" data-replace>
                <div class="hint">JPG, PNG, WEBP or GIF, up to 5 MB. <?= $product ? 'Choosing a new file replaces the current one.' : '' ?></div>
              </div>
            </div>
            <div class="field">
              <span class="field-label">More photos <span class="opt">optional</span></span>
              <?php if ($gallery): ?>
                <div class="thumb-grid" style="margin-bottom:10px;">
                  <?php foreach ($gallery as $g): ?>
                    <div class="thumb-tile">
                      <img src="<?= e($g['image_path']) ?>" alt="">
                      <div class="tile-actions">
                        <!-- These buttons belong to forms placed after the main form (nested forms aren't valid HTML). -->
                        <button type="submit" form="imgform-<?= (int) $g['id'] ?>" name="action" value="make_main" title="Use as the main photo">Make main</button>
                        <button type="submit" form="imgform-<?= (int) $g['id'] ?>" name="action" value="delete" class="del" title="Remove this photo">Remove</button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <div class="thumb-grid" id="galleryPreview"></div>
              <div class="upload-box">
                <input type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-preview="#galleryPreview">
                <div class="hint">Select several at once. They're added when you save.</div>
              </div>
            </div>
          </div>

          <details class="more" <?= $hasLink ? 'open' : '' ?>>
            <summary>Add a video or external link <span class="opt">optional</span></summary>
            <div class="field-row" style="margin:12px 0 0;">
              <div class="field" style="margin-bottom:0;">
                <label for="link_title">Link title</label>
                <input type="text" id="link_title" name="link_title" maxlength="80" value="<?= e($f['link_title']) ?>" placeholder="e.g. Watch the video, Size guide">
              </div>
              <div class="field" style="margin-bottom:0;">
                <label for="link_url">Link address</label>
                <input type="url" id="link_url" name="link_url" value="<?= e($f['link_url']) ?>" placeholder="https://… (YouTube, a PDF, any page)">
              </div>
            </div>
          </details>
        </div>
      </section>

      <!-- ─────────────── 3 · Price & stock ─────────────── -->
      <section class="panel" id="sec-pricing">
        <div class="panel-head"><h2><span class="num">3</span>Price &amp; stock</h2><span class="sub">Base price — options below can add to it</span></div>
        <div class="panel-body">
          <div class="field-row cols-3">
            <div class="field">
              <label for="price">Selling price</label>
              <div class="input-affix"><span class="affix"><?= $sym ?></span><input type="number" step="0.01" min="0" id="price" name="price" required value="<?= e($f['price']) ?>"></div>
            </div>
            <div class="field">
              <label for="compare_price">Compare at <span class="opt">optional</span></label>
              <div class="input-affix"><span class="affix"><?= $sym ?></span><input type="number" step="0.01" min="0" id="compare_price" name="compare_price" value="<?= e($f['compare_price']) ?>"></div>
              <div class="hint">Shows the old price crossed out.</div>
            </div>
            <div class="field">
              <label for="stock">Stock</label>
              <input type="number" min="0" id="stock" name="stock" value="<?= e($f['stock']) ?>">
              <div class="hint" id="stockHint">Units on hand. Tracked per combination once you add colors or sizes.</div>
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="sku">SKU <span class="opt">optional</span></label>
              <input type="text" id="sku" name="sku" value="<?= e($f['sku']) ?>" maxlength="60">
            </div>
            <div class="field">
              <label for="warranty_days">Warranty <span class="opt">optional</span></label>
              <div class="input-affix"><input type="number" min="1" max="3650" id="warranty_days" name="warranty_days" value="<?= e($f['warranty_days']) ?>" placeholder="e.g. 365"><span class="affix">days</span></div>
              <div class="hint">Shown on the product page and invoice. Empty = no warranty.</div>
            </div>
          </div>

          <div class="toggle-block">
            <label class="switch"><input type="checkbox" id="show_stock" name="show_stock" value="1" <?= !empty($f['show_stock']) ? 'checked' : '' ?>><span class="track"></span><span>Show stock<small>On: shoppers see how many are left (e.g. "Only 3 left"). Off: they only see "In stock" or "Out of stock", never the number.</small></span></label>
          </div>
          <div class="toggle-block">
            <label class="switch"><input type="checkbox" id="allow_backorder" name="allow_backorder" value="1" <?= !empty($f['allow_backorder']) ? 'checked' : '' ?>><span class="track"></span><span>Take orders even when stock is 0<small>Shoppers can still order this once it runs out, with the normal "Add to cart" button. Stock is not deducted for those orders. Use "Available for pre-order" below if you want a "Pre-order" label instead.</small></span></label>
          </div>

          <div class="toggle-block">
            <label class="switch"><input type="checkbox" id="is_preorder" name="is_preorder" value="1" <?= $f['is_preorder'] ? 'checked' : '' ?>><span class="track"></span><span>Available for pre-order<small>Shoppers can order this even while stock is 0, and see a "Pre-order" button instead of "Out of stock".</small></span></label>
            <div class="field-row toggle-body" id="preorderFields" <?= $f['is_preorder'] ? '' : 'hidden' ?>>
              <div class="field" style="margin-bottom:0;">
                <label for="preorder_note">Pre-order note <span class="opt">optional</span></label>
                <input type="text" id="preorder_note" name="preorder_note" value="<?= e($f['preorder_note']) ?>" maxlength="255" placeholder="e.g. Ships in 2–3 weeks">
              </div>
              <div class="field" style="margin-bottom:0;">
                <label for="preorder_available_date">Expected availability <span class="opt">optional</span></label>
                <input type="date" id="preorder_available_date" name="preorder_available_date" value="<?= e($f['preorder_available_date']) ?>">
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ─────────────── 4 · Shipping ─────────────── -->
      <section class="panel" id="sec-shipping">
        <div class="panel-head"><h2><span class="num">4</span>Weight &amp; size</h2><span class="sub">Used for shipping and shown on the page</span></div>
        <div class="panel-body">
          <div class="field-row cols-4">
            <div class="field"><label for="weight_grams">Weight</label><div class="input-affix"><input type="number" min="1" id="weight_grams" name="weight_grams" required value="<?= e($f['weight_grams']) ?>"><span class="affix">g</span></div></div>
            <div class="field"><label for="height_mm">Height <span class="opt">opt.</span></label><div class="input-affix"><input type="number" min="0" id="height_mm" name="height_mm" value="<?= e($f['height_mm']) ?>"><span class="affix">mm</span></div></div>
            <div class="field"><label for="width_mm">Width <span class="opt">opt.</span></label><div class="input-affix"><input type="number" min="0" id="width_mm" name="width_mm" value="<?= e($f['width_mm']) ?>"><span class="affix">mm</span></div></div>
            <div class="field"><label for="depth_mm">Depth <span class="opt">opt.</span></label><div class="input-affix"><input type="number" min="0" id="depth_mm" name="depth_mm" value="<?= e($f['depth_mm']) ?>"><span class="affix">mm</span></div></div>
          </div>
          <div class="field" style="margin-bottom:0;max-width:360px;">
            <label for="color">Material / finish <span class="opt">optional</span></label>
            <input type="text" id="color" name="color" value="<?= e($f['color']) ?>" placeholder="e.g. Chestnut brown, stonewashed">
            <div class="hint">Only shown when the product has no color options.</div>
          </div>
        </div>
      </section>

      <!-- ─────────────── 5 · Colors & sizes ─────────────── -->
      <section class="panel" id="variantPanel">
        <div class="panel-head"><h2><span class="num">5</span>Colors &amp; sizes</h2><span class="pill pill-ink">Optional</span></div>
        <div class="panel-body">
          <p class="help">Skip this if your product comes in one version. Otherwise add the colors and/or sizes — shoppers pick each separately and the photo, weight and price update as they choose. Each color or size can add an optional extra amount to the price.</p>
          <div id="variantEditor"></div>
        </div>
      </section>

      <!-- ─────────────── 6 · Customization ─────────────── -->
      <section class="panel" id="customPanel">
        <div class="panel-head"><h2><span class="num">6</span>Customization</h2><span class="pill pill-ink">Optional</span></div>
        <div class="panel-body">
          <p class="help">Offer personalised extras — for example <em>Name engraving</em>, <em>Gold stitching</em> or <em>Gift wrap</em>. Shoppers can pick one (or keep the standard product). Each choice can add its own amount to the price and show its own photo.</p>
          <div id="customEditor"></div>
        </div>
      </section>
    </div>

    <!-- ─────────────── Sidebar ─────────────── -->
    <aside class="form-side">
      <section class="panel">
        <div class="panel-head"><h2>Visibility</h2></div>
        <div class="panel-body">
          <div class="field">
            <label class="switch"><input type="checkbox" name="is_active" value="1" <?= $f['is_active'] ? 'checked' : '' ?>><span class="track"></span><span>Visible in store<small>Hidden products can't be found or bought.</small></span></label>
          </div>
          <div class="field" style="margin-bottom:0;">
            <label class="switch"><input type="checkbox" name="is_featured" value="1" <?= $f['is_featured'] ? 'checked' : '' ?>><span class="track"></span><span>Featured on home page</span></label>
          </div>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head"><h2>Organization</h2></div>
        <div class="panel-body">
          <div class="field">
            <label for="category_id">Category</label>
            <select id="category_id" name="category_id">
              <option value="">— None —</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $f['category_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $c['depth']) ?><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="margin-bottom:0;">
            <label for="slug">Page address</label>
            <input type="text" id="slug" name="slug" value="<?= e($f['slug']) ?>" placeholder="auto from the name" maxlength="220">
            <div class="hint"><?= e(parse_url(base_url(), PHP_URL_HOST) ?: 'yourstore') ?>/product.php?slug=<strong id="slugPreview"><?= e($f['slug'] ?: '…') ?></strong></div>
          </div>
        </div>
      </section>
      <?php if ($product): ?>
        <a class="btn btn-outline btn-sm" style="width:100%;" href="/product.php?slug=<?= e($product['slug']) ?>" target="_blank">View on store ↗</a>
      <?php endif; ?>
    </aside>
  </div>

  <div class="savebar">
    <button type="submit" class="btn btn-primary"><?= $product ? 'Save changes' : 'Create product' ?></button>
    <a href="/admin/products.php" class="btn btn-outline">Cancel</a>
    <span class="note" id="dirtyNote"></span>
  </div>
</form>

<?php /* Forms for gallery buttons live OUTSIDE the main form (HTML forbids nesting). */ ?>
<?php foreach ($gallery as $g): ?>
  <form id="imgform-<?= (int) $g['id'] ?>" method="post" action="/admin/product_image_delete.php" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="image_id" value="<?= (int) $g['id'] ?>">
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
  </form>
<?php endforeach; ?>

<script>window.STORE_CURRENCY = <?= json_encode(store_currency_symbol(), $jsonFlags) ?>; window.STORE_VARIANT_EDITOR = <?= json_encode($editorData, $jsonFlags) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
