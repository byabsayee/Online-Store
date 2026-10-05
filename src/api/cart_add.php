<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Security check failed, please refresh the page.']);
    exit;
}

$productId = (int) ($input['product_id'] ?? 0);
$variantId = !empty($input['variant_id']) ? (int) $input['variant_id'] : null;
$customId = !empty($input['customization_id']) ? (int) $input['customization_id'] : null;
$qty = max(1, (int) ($input['quantity'] ?? 1));

$stmt = db()->prepare('SELECT id, stock, is_active, is_preorder FROM products WHERE id = ?');
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product || !$product['is_active']) {
    echo json_encode(['ok' => false, 'message' => 'This product is no longer available.']);
    exit;
}

$isPreorder = (bool) $product['is_preorder'];
$availableStock = (int) $product['stock'];
if ($variantId) {
    $vStmt = db()->prepare('SELECT id, stock, is_active FROM product_variants WHERE id = ? AND product_id = ?');
    $vStmt->execute([$variantId, $productId]);
    $variant = $vStmt->fetch();
    if (!$variant || !$variant['is_active']) {
        echo json_encode(['ok' => false, 'message' => 'That option is no longer available.']);
        exit;
    }
    $availableStock = (int) $variant['stock'];
}
if ($customId) {
    $cStmt = db()->prepare('SELECT id FROM product_customizations WHERE id = ? AND product_id = ? AND is_active = 1');
    $cStmt->execute([$customId, $productId]);
    if (!$cStmt->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'That customization is no longer available.']);
        exit;
    }
}
// A product with active variants must be ordered via a specific variant.
if (!$variantId) {
    $hasVariants = db()->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND is_active = 1');
    $hasVariants->execute([$productId]);
    if ((int) $hasVariants->fetchColumn() > 0) {
        echo json_encode(['ok' => false, 'message' => 'Please choose an option before adding to cart.']);
        exit;
    }
}

if ($availableStock < 1 && !$isPreorder) {
    echo json_encode(['ok' => false, 'message' => 'This product is out of stock.']);
    exit;
}

// Pre-order items have no real stock cap yet, so quantity isn't clamped to $availableStock the
// way an in-stock item's is — cart_set_qty()/checkout still re-check stock for non-preorder lines.
if ($availableStock < 1 && $isPreorder) {
    cart_add($productId, $qty, $variantId, null, $customId);
} else {
    cart_add($productId, min($qty, $availableStock), $variantId, $availableStock, $customId);
}

echo json_encode([
    'ok' => true,
    'message' => $isPreorder && $availableStock < 1 ? 'Added as a pre-order' : 'Added to cart',
    'cart_count' => cart_count(),
]);
