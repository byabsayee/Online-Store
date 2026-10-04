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
    $parsed = ['colors' => [], 'sizes' => []];
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
                    'UPDATE products SET category_id=?, name=?, slug=?, sku=?, short_desc=?, tags=?, description=?, price=?, compare_price=?, stock=?, is_preorder=?, preorder_note=?, preorder_available_date=?, weight_grams=?, height_mm=?, width_mm=?, depth_mm=?, color=?, warranty_days=?, image_main=?, link_url=?, link_title=?, is_active=?, is_featured=? WHERE id=?'
                )->execute([$categoryId, $name, $slug, $sku ?: null, $shortDesc ?: null, $tags ?: null, $description ?: null, $price, $comparePrice, max(0, $stock), $isPreorder, $preorderNote ?: null, $preorderDate, $weightGrams, $heightMm, $widthMm, $depthMm, $color ?: null, $warrantyDays, $mainImage, $linkUrl ?: null, $linkTitle ?: null, $isActive, $isFeatured, $product['id']]);
                $productId = (int) $product['id'];
            } else {
                $pdo->prepare(
                    'INSERT INTO products (category_id, name, slug, sku, short_desc, tags, description, price, compare_price, stock, is_preorder, preorder_note, preorder_available_date, weight_grams, height_mm, width_mm, depth_mm, color, warranty_days, image_main, link_url, link_title, is_active, is_featured) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$categoryId, $name, $slug, $sku ?: null, $shortDesc ?: null, $tags ?: null, $description ?: null, $price, $comparePrice, max(0, $stock), $isPreorder, $preorderNote ?: null, $preorderDate, $weightGrams, $heightMm, $widthMm, $depthMm, $color ?: null, $warrantyDays, $mainImage, $linkUrl ?: null, $linkTitle ?: null, $isActive, $isFeatured]);
                $productId = (int) $pdo->lastInsertId();
            }

            $vBefore = [];
            if ($product) foreach ($pdo->query('SELECT id, stock FROM product_variants WHERE product_id = ' . (int) $productId)->fetchAll() as $vb) $vBefore[(int) $vb['id']] = (int) $vb['stock'];
            $variantTotal = save_product_variants($pdo, $productId, $parsed['colors'], $parsed['sizes'], $combos);
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
                'is_preorder' => $isPreorder ? 'yes' : 'no', 'preorder_note' => $preorderNote, 'preorder_available_date' => $preorderDate];
            if ($product) {
                $oldVals = $product;
                $oldVals['category'] = $catName($product['category_id']);
                $oldVals['is_active'] = $product['is_active'] ? 'yes' : 'no';
                $oldVals['is_featured'] = $product['is_featured'] ? 'yes' : 'no';
                $oldVals['is_preorder'] = $product['is_preorder'] ? 'yes' : 'no';
                $diff = admin_log_diff($oldVals, $newVals, ['name' => 'Name', 'category' => 'Category', 'sku' => 'SKU', 'slug' => 'URL slug', 'short_desc' => 'Short description', 'description' => 'Description',
                    'tags' => 'Tags', 'price' => 'Price', 'compare_price' => 'Compare-at price', 'stock' => 'Stock', 'weight_grams' => 'Weight (g)', 'height_mm' => 'Height (mm)', 'width_mm' => 'Width (mm)',
                    'depth_mm' => 'Depth (mm)', 'color' => 'Colour', 'warranty_days' => 'Warranty (days)', 'link_url' => 'External link', 'link_title' => 'Link title', 'is_active' => 'Visible in shop', 'is_featured' => 'Featured',
                    'is_preorder' => 'Available for pre-order', 'preorder_note' => 'Pre-order note', 'preorder_available_date' => 'Expected availability']);
                if ($variantsBefore !== json_encode(variant_editor_data($productId))) $diff['Colours / sizes / per-variant stock'] = ['(before)', 'edited'];
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
    'is_preorder' => 0, 'preorder_note' => '', 'preorder_available_date' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name', 'slug', 'sku', 'short_desc', 'tags', 'description', 'price', 'compare_price', 'stock', 'weight_grams', 'height_mm', 'width_mm', 'depth_mm', 'color', 'warranty_days', 'link_url', 'link_title', 'preorder_note', 'preorder_available_date'] as $k) {
        if (isset($_POST[$k])) $f[$k] = $_POST[$k];
    }
    $f['category_id'] = (int) ($_POST['category_id'] ?? 0) ?: null;
    $f['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
    $f['is_featured'] = !empty($_POST['is_featured']) ? 1 : 0;
    $f['is_preorder'] = !empty($_POST['is_preorder']) ? 1 : 0;
}

$gallery = [];
if ($product) {
    $gStmt = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
    $gStmt->execute([$product['id']]);
    $gallery = $gStmt->fetchAll();
    $editorData = $editorData ?? variant_editor_data((int) $product['id']);
}
$editorData = $editorData ?? ['colors' => [], 'sizes' => [], 'combos' => []];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$pageTitle = $product ? 'Edit product' : 'Add product';
require __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-error"><strong>Please fix the following:</strong><ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="productForm" autocomplete="off">
  <?= csrf_field() ?>
  <div class="form-layout">
    <div class="form-main">

      <!-- ─────────────── Basics ─────────────── -->
      <section class="panel">
        <div class="panel-head"><h2>Basic information</h2></div>
        <div class="panel-body">
          <div class="field">
            <label for="name">Product name</label>
            <input type="text" id="name" name="name" required maxlength="200" value="<?= e($f['name']) ?>" placeholder="e.g. Titanium Pocket Pry Bar">
          </div>
          <div class="field">
            <label for="short_desc">Short description <span class="counter" data-counter-for="short_desc" data-max="255"></span></label>
            <input type="text" id="short_desc" name="short_desc" maxlength="255" value="<?= e($f['short_desc']) ?>" placeholder="One line shown on product cards and search">
          </div>
          <div class="field">
            <label for="description">Full description</label>
            <textarea id="description" name="description" rows="7"><?= e($f['description']) ?></textarea>
          </div>
          <div class="field" style="margin-bottom:0;">
            <label for="tags">Search tags</label>
            <input type="text" id="tags" name="tags" value="<?= e($f['tags']) ?>" data-chips placeholder="Type a tag and press Enter">
            <div class="hint">Extra words shoppers might search for (e.g. <em>titanium, keychain, edc, gift</em>). Press Enter or comma after each one. Products are found by partial words, so "tita" already finds "titanium".</div>
          </div>
        </div>
      </section>

      <!-- ─────────────── Media ─────────────── -->
      <section class="panel">
        <div class="panel-head"><h2>Photos &amp; video</h2></div>
        <div class="panel-body">
          <div class="field">
            <span class="field-label">Main photo</span>
            <div class="thumb-grid" id="mainPreview">
              <?php if (!empty($f['image_main'])): ?>
                <div class="thumb-tile"><img src="<?= e($f['image_main']) ?>" alt=""><span class="badge">Main</span></div>
              <?php endif; ?>
            </div>
            <div class="upload-box" style="margin-top:10px;">
              <input type="file" name="image_main" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="#mainPreview" data-replace>
              <div class="hint">JPG, PNG, WEBP or GIF, up to 5 MB. Square photos look best. <?= $product ? 'Choosing a new file replaces the current one.' : '' ?></div>
            </div>
          </div>

          <div class="field">
            <span class="field-label">More photos</span>
            <?php if ($gallery): ?>
              <div class="thumb-grid" style="margin-bottom:12px;">
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
            <div class="upload-box" style="margin-top:10px;">
              <input type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-preview="#galleryPreview">
              <div class="hint">Select several at once. They're added to the gallery when you save.</div>
            </div>
          </div>

          <div class="field-row" style="margin-bottom:0;">
            <div class="field" style="margin-bottom:0;">
              <label for="link_title">External link title <span class="muted" style="font-weight:400;">(optional)</span></label>
              <input type="text" id="link_title" name="link_title" maxlength="80" value="<?= e($f['link_title']) ?>" placeholder="e.g. Watch the video, Size guide, Manual">
            </div>
            <div class="field" style="margin-bottom:0;">
              <label for="link_url">External link <span class="muted" style="font-weight:400;">(YouTube, a PDF, any web page)</span></label>
              <input type="url" id="link_url" name="link_url" value="<?= e($f['link_url']) ?>" placeholder="https://…">
            </div>
          </div>
        </div>
        </div>
      </section>

      <!-- ─────────────── Pricing / size ─────────────── -->
      <section class="panel">
        <div class="panel-head"><h2>Pricing &amp; inventory</h2></div>
        <div class="panel-body">
          <div class="field-row cols-3">
            <div class="field">
              <label for="price">Selling price</label>
              <div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" step="0.01" min="0" id="price" name="price" required value="<?= e($f['price']) ?>"></div>
            </div>
            <div class="field">
              <label for="compare_price">Compare at <span class="muted" style="font-weight:400;">(optional)</span></label>
              <div class="input-affix"><span class="affix"><?= e(store_currency_symbol()) ?></span><input type="number" step="0.01" min="0" id="compare_price" name="compare_price" value="<?= e($f['compare_price']) ?>"></div>
              <div class="hint">Shows the old price crossed out.</div>
            </div>
            <div class="field">
              <label for="sku">SKU <span class="muted" style="font-weight:400;">(optional)</span></label>
              <input type="text" id="sku" name="sku" value="<?= e($f['sku']) ?>" maxlength="60">
            </div>
          </div>
          <div class="field-row" style="margin-bottom:0;">
            <div class="field">
              <label for="stock">Stock</label>
              <input type="number" min="0" id="stock" name="stock" value="<?= e($f['stock']) ?>">
              <div class="hint" id="stockHint">Units on hand. Ignored once you add colors or sizes below — stock is then tracked per combination.</div>
            </div>
            <div class="field">
              <label for="warranty_days">Warranty <span class="muted" style="font-weight:400;">(optional)</span></label>
              <div class="input-affix"><input type="number" min="1" max="3650" id="warranty_days" name="warranty_days" value="<?= e($f['warranty_days']) ?>" placeholder="e.g. 365"><span class="affix">days</span></div>
              <div class="hint">Shown on the product page and on the invoice once the order ships. Leave empty for no warranty.</div>
            </div>
          </div>
          <div class="field-row" style="margin-bottom:0;">
            <div class="field" style="flex:0 0 100%;">
              <label class="switch"><input type="checkbox" id="is_preorder" name="is_preorder" value="1" <?= $f['is_preorder'] ? 'checked' : '' ?>><span class="track"></span><span>Available for pre-order<small>Lets shoppers order this even while stock is 0. Shows a "Pre-order" button and note instead of "Out of stock".</small></span></label>
            </div>
            <div class="field">
              <label for="preorder_note">Pre-order note <span class="muted" style="font-weight:400;">(optional)</span></label>
              <input type="text" id="preorder_note" name="preorder_note" value="<?= e($f['preorder_note']) ?>" maxlength="255" placeholder="e.g. Ships in 2–3 weeks">
              <div class="hint">Shown next to the Pre-order button on the product page.</div>
            </div>
            <div class="field">
              <label for="preorder_available_date">Expected availability <span class="muted" style="font-weight:400;">(optional)</span></label>
              <input type="date" id="preorder_available_date" name="preorder_available_date" value="<?= e($f['preorder_available_date']) ?>">
            </div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Size &amp; weight <span class="sub">defaults for this product</span></h2></div>
        <div class="panel-body">
          <div class="field-row cols-4">
            <div class="field"><label for="weight_grams">Weight</label><div class="input-affix"><input type="number" min="1" id="weight_grams" name="weight_grams" required value="<?= e($f['weight_grams']) ?>"><span class="affix">g</span></div></div>
            <div class="field"><label for="height_mm">Height</label><div class="input-affix"><input type="number" min="0" id="height_mm" name="height_mm" value="<?= e($f['height_mm']) ?>"><span class="affix">mm</span></div></div>
            <div class="field"><label for="width_mm">Width</label><div class="input-affix"><input type="number" min="0" id="width_mm" name="width_mm" value="<?= e($f['width_mm']) ?>"><span class="affix">mm</span></div></div>
            <div class="field"><label for="depth_mm">Depth</label><div class="input-affix"><input type="number" min="0" id="depth_mm" name="depth_mm" value="<?= e($f['depth_mm']) ?>"><span class="affix">mm</span></div></div>
          </div>
          <div class="field" style="margin-bottom:0;max-width:340px;">
            <label for="color">Material / finish <span class="muted" style="font-weight:400;">(optional)</span></label>
            <input type="text" id="color" name="color" value="<?= e($f['color']) ?>" placeholder="e.g. Chestnut brown, stonewashed">
            <div class="hint">Only shown when the product has no color options below.</div>
          </div>
        </div>
      </section>

      <!-- ─────────────── Variants ─────────────── -->
      <section class="panel" id="variantPanel">
        <div class="panel-head"><h2>Colors &amp; sizes <span class="sub">shoppers pick each one separately</span></h2></div>
        <div class="panel-body">
          <p class="help">Add the colors and the sizes this product comes in. Each color can have its own photo, and each size its own dimensions and weight — the product page updates the picture, size and weight as the shopper chooses. Then set stock and any price difference per combination.</p>
          <div id="variantEditor"></div>
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
        <section class="panel">
          <div class="panel-body">
            <a class="btn btn-outline btn-sm" style="width:100%;" href="/product.php?slug=<?= e($product['slug']) ?>" target="_blank">View on store ↗</a>
          </div>
        </section>
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
