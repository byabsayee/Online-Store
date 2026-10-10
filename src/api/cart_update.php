<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Security check failed.']);
    exit;
}

$itemId = (int) ($input['item_id'] ?? 0);
$qty = (int) ($input['quantity'] ?? 1);

if ($itemId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid item.']);
    exit;
}

// Never let a line go above what's in stock (the cart page only limits this in the browser) —
// except pre-order lines, which have no real stock to cap against yet.
foreach (cart_items() as $it) {
    if ((int) $it['id'] === $itemId) {
        if (!$it['is_preorder'] && !$it['is_backorder']) $qty = min($qty, max(1, (int) $it['stock']));
        break;
    }
}
cart_set_qty($itemId, $qty);
echo json_encode(['ok' => true, 'cart_count' => cart_count()]);
