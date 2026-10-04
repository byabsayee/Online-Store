<?php
/**
 * Admin-side helpers for saving a product's colors, sizes and per-combination
 * stock, plus safe handling of uploaded files. Kept out of the admin folder
 * so nginx never serves it directly.
 */

/** Accepts only paths our own uploader could have produced. */
function valid_upload_path(?string $path): ?string {
    $path = trim((string) $path);
    return preg_match('~^' . preg_quote(UPLOAD_URL, '~') . '/[a-f0-9]{24}\.(jpg|png|webp|gif)$~', $path) ? $path : null;
}

/** Is this uploaded file still referenced anywhere in the database? */
function upload_in_use(string $path): bool {
    $checks = [
        'SELECT 1 FROM products WHERE image_main = ? LIMIT 1',
        'SELECT 1 FROM product_images WHERE image_path = ? LIMIT 1',
        'SELECT 1 FROM product_options WHERE image = ? LIMIT 1',
        'SELECT 1 FROM categories WHERE image = ? LIMIT 1',
    ];
    foreach ($checks as $sql) {
        $s = db()->prepare($sql);
        $s->execute([$path]);
        if ($s->fetchColumn()) return true;
    }
    return false;
}

/** Deletes an uploaded file once nothing points at it any more. */
function delete_upload_if_unused(?string $path): void {
    $path = valid_upload_path($path);
    if ($path && !upload_in_use($path)) delete_upload_file($path);
}

/** Like handle_product_image_upload(), for one slot of a `name[]` multi-input. */
function handle_indexed_image_upload(string $field, int $i): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name']) || !isset($_FILES[$field]['error'][$i])) return null;
    if ($_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) return null;
    $_FILES['__slot'] = [
        'name' => $_FILES[$field]['name'][$i], 'type' => $_FILES[$field]['type'][$i], 'tmp_name' => $_FILES[$field]['tmp_name'][$i],
        'error' => $_FILES[$field]['error'][$i], 'size' => $_FILES[$field]['size'][$i],
    ];
    return handle_product_image_upload('__slot');
}

function int_or_null($v): ?int {
    $v = trim((string) $v);
    return $v !== '' && is_numeric($v) && (int) $v >= 0 ? (int) $v : null;
}

/**
 * Reads the color[] / size[] option rows from the submitted form (and stores
 * any newly chosen photos). Returns ['colors' => [...], 'sizes' => [...]].
 */
function parse_option_posts(array &$errors): array {
    $out = ['colors' => [], 'sizes' => []];

    $seen = [];
    foreach (($_POST['color_name'] ?? []) as $i => $name) {
        $name = mb_substr(trim((string) $name), 0, 60);
        if ($name === '') continue;
        if (isset($seen[mb_strtolower($name)])) { $errors[] = 'Color "' . $name . '" is listed twice.'; continue; }
        $seen[mb_strtolower($name)] = true;
        $swatch = trim((string) ($_POST['color_swatch'][$i] ?? ''));
        $image = handle_indexed_image_upload('color_image', (int) $i) ?? valid_upload_path($_POST['color_image_existing'][$i] ?? '');
        $out['colors'][] = ['name' => $name, 'swatch' => preg_match('/^#[0-9a-fA-F]{6}$/', $swatch) ? strtolower($swatch) : null, 'image' => $image];
    }

    $seen = [];
    foreach (($_POST['size_name'] ?? []) as $i => $name) {
        $name = mb_substr(trim((string) $name), 0, 60);
        if ($name === '') continue;
        if (isset($seen[mb_strtolower($name)])) { $errors[] = 'Size "' . $name . '" is listed twice.'; continue; }
        $seen[mb_strtolower($name)] = true;
        $image = handle_indexed_image_upload('size_image', (int) $i) ?? valid_upload_path($_POST['size_image_existing'][$i] ?? '');
        $weight = int_or_null($_POST['size_weight'][$i] ?? '');
        $out['sizes'][] = [
            'name' => $name, 'image' => $image, 'weight_grams' => $weight && $weight > 0 ? $weight : null,
            'height_mm' => int_or_null($_POST['size_h'][$i] ?? ''), 'width_mm' => int_or_null($_POST['size_w'][$i] ?? ''), 'depth_mm' => int_or_null($_POST['size_d'][$i] ?? ''),
        ];
    }
    return $out;
}

/**
 * Turns the submitted per-combination rows into the full expected list:
 * every color × size that should exist, with the entered SKU/price/stock (or
 * zero-stock defaults for anything not submitted).
 */
function expected_combos(array $colors, array $sizes, array $submitted): array {
    $cNames = array_column($colors, 'name');
    $sNames = array_column($sizes, 'name');
    $canon = function (array $names, string $v): ?string {
        foreach ($names as $n) if (mb_strtolower($n) === mb_strtolower(trim($v))) return $n;
        return null;
    };
    $bySubmitted = [];
    foreach ($submitted as $row) {
        $c = $cNames ? $canon($cNames, $row['color'] ?? '') : '';
        $s = $sNames ? $canon($sNames, $row['size'] ?? '') : '';
        if (($cNames && $c === null) || ($sNames && $s === null)) continue;
        $bySubmitted[($c ?? '') . "\0" . ($s ?? '')] ??= $row;
    }
    $list = [];
    foreach ($cNames ?: [''] as $c) {
        foreach ($sNames ?: [''] as $s) {
            if ($c === '' && $s === '') continue;
            $row = $bySubmitted[$c . "\0" . $s] ?? [];
            $list[] = [
                'id' => (int) ($row['id'] ?? 0), 'color' => $c !== '' ? $c : null, 'size' => $s !== '' ? $s : null,
                'sku' => trim((string) ($row['sku'] ?? '')) ?: null,
                'price_delta' => round((float) ($row['delta'] ?? 0), 2),
                'stock' => max(0, (int) ($row['stock'] ?? 0)),
                'is_active' => array_key_exists('active', $row) ? (int) !empty($row['active']) : 1,
            ];
        }
    }
    return $list;
}

/**
 * Makes the database match the submitted options and combinations.
 * Returns the total stock across active combinations (or null if the product
 * has no variants, in which case the product's own stock applies).
 */
function save_product_variants(PDO $pdo, int $productId, array $colors, array $sizes, array $combos): ?int {
    // --- options: upsert by name, drop the rest
    $existing = [];
    $stmt = $pdo->prepare('SELECT id, kind, name, image FROM product_options WHERE product_id = ?');
    $stmt->execute([$productId]);
    foreach ($stmt->fetchAll() as $o) $existing[$o['kind']][mb_strtolower($o['name'])] = $o;

    $oldImages = []; $keep = [];
    $upsert = $pdo->prepare(
        'INSERT INTO product_options (product_id, kind, name, swatch, image, weight_grams, height_mm, width_mm, depth_mm, sort_order)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE swatch = VALUES(swatch), image = VALUES(image), weight_grams = VALUES(weight_grams),
           height_mm = VALUES(height_mm), width_mm = VALUES(width_mm), depth_mm = VALUES(depth_mm), sort_order = VALUES(sort_order)'
    );
    foreach (['color' => $colors, 'size' => $sizes] as $kind => $list) {
        foreach ($list as $i => $o) {
            $prev = $existing[$kind][mb_strtolower($o['name'])] ?? null;
            if ($prev && $prev['image'] && $prev['image'] !== ($o['image'] ?? null)) $oldImages[] = $prev['image'];
            $upsert->execute([$productId, $kind, $o['name'], $o['swatch'] ?? null, $o['image'] ?? null,
                $o['weight_grams'] ?? null, $o['height_mm'] ?? null, $o['width_mm'] ?? null, $o['depth_mm'] ?? null, $i]);
            $keep[$kind][mb_strtolower($o['name'])] = true;
        }
    }
    foreach ($existing as $kind => $rows) {
        foreach ($rows as $key => $o) {
            if (!isset($keep[$kind][$key])) {
                $pdo->prepare('DELETE FROM product_options WHERE id = ?')->execute([$o['id']]);
                if ($o['image']) $oldImages[] = $o['image'];
            }
        }
    }

    // --- combinations
    $existingVariants = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
    $existingVariants->execute([$productId]);
    $existingIds = array_map('intval', array_column($existingVariants->fetchAll(), 'id'));
    $keptIds = [];
    $upd = $pdo->prepare('UPDATE product_variants SET color=?, size=?, sku=?, price_delta=?, stock=?, is_active=?, sort_order=? WHERE id=? AND product_id=?');
    $ins = $pdo->prepare('INSERT INTO product_variants (product_id, color, size, sku, price_delta, stock, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?)');
    $total = 0;
    foreach ($combos as $i => $c) {
        if ($c['id'] && in_array($c['id'], $existingIds, true) && !isset($keptIds[$c['id']])) {
            $upd->execute([$c['color'], $c['size'], $c['sku'], $c['price_delta'], $c['stock'], $c['is_active'], $i, $c['id'], $productId]);
            $keptIds[$c['id']] = true;
        } else {
            // Same color/size as an unclaimed existing row? Reuse it (keeps order history links).
            $match = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? AND color <=> ? AND size <=> ?');
            $match->execute([$productId, $c['color'], $c['size']]);
            $mid = 0;
            foreach ($match->fetchAll() as $m) { if (!isset($keptIds[(int) $m['id']])) { $mid = (int) $m['id']; break; } }
            if ($mid) {
                $upd->execute([$c['color'], $c['size'], $c['sku'], $c['price_delta'], $c['stock'], $c['is_active'], $i, $mid, $productId]);
                $keptIds[$mid] = true;
            } else {
                $ins->execute([$productId, $c['color'], $c['size'], $c['sku'], $c['price_delta'], $c['stock'], $c['is_active'], $i]);
                $keptIds[(int) $pdo->lastInsertId()] = true;
            }
        }
        if ($c['is_active']) $total += $c['stock'];
    }
    foreach ($existingIds as $id) {
        if (!isset($keptIds[$id])) $pdo->prepare('DELETE FROM product_variants WHERE id = ? AND product_id = ?')->execute([$id, $productId]);
    }

    foreach (array_unique($oldImages) as $img) delete_upload_if_unused($img);
    return $combos ? $total : null;
}

/** Shape the variant editor's JS needs, from the database. */
function variant_editor_data(int $productId): array {
    $opts = product_options_for($productId);
    return [
        'colors' => array_map(fn ($o) => ['name' => $o['name'], 'swatch' => $o['swatch'] ?? null, 'image' => $o['image'] ?? null], $opts['color']),
        'sizes' => array_map(fn ($o) => ['name' => $o['name'], 'image' => $o['image'] ?? null, 'weight' => $o['weight_grams'] ?? null,
            'h' => $o['height_mm'] ?? null, 'w' => $o['width_mm'] ?? null, 'd' => $o['depth_mm'] ?? null], $opts['size']),
        'combos' => array_map(fn ($v) => ['id' => (int) $v['id'], 'color' => $v['color'], 'size' => $v['size'], 'sku' => $v['sku'],
            'delta' => (float) $v['price_delta'], 'stock' => (int) $v['stock'], 'active' => (int) $v['is_active']], product_variants_for($productId, false)),
    ];
}

/** Same shape, rebuilt from a failed form submission so nothing the admin typed is lost. */
function variant_editor_data_from_post(array $parsed, array $submittedCombos): array {
    return [
        'colors' => array_map(fn ($o) => ['name' => $o['name'], 'swatch' => $o['swatch'], 'image' => $o['image']], $parsed['colors']),
        'sizes' => array_map(fn ($o) => ['name' => $o['name'], 'image' => $o['image'], 'weight' => $o['weight_grams'], 'h' => $o['height_mm'], 'w' => $o['width_mm'], 'd' => $o['depth_mm']], $parsed['sizes']),
        'combos' => array_map(fn ($r) => ['id' => (int) ($r['id'] ?? 0), 'color' => ($r['color'] ?? '') !== '' ? $r['color'] : null, 'size' => ($r['size'] ?? '') !== '' ? $r['size'] : null,
            'sku' => $r['sku'] ?? '', 'delta' => (float) ($r['delta'] ?? 0), 'stock' => (int) ($r['stock'] ?? 0), 'active' => !empty($r['active']) ? 1 : 0], array_values($submittedCombos)),
    ];
}
