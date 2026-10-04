<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Security check failed.']);
    exit;
}

if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'login_required' => true, 'message' => 'Log in to save items.']);
    exit;
}

$productId = (int) ($input['product_id'] ?? 0);
$stmt = db()->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
$stmt->execute([$productId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'Product not found.']);
    exit;
}

$user = current_user();
$favorited = favorite_toggle($user['id'], $productId);
echo json_encode(['ok' => true, 'favorited' => $favorited, 'wish_count' => product_wish_count($productId)]);
