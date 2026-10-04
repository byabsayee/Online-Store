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
if ($itemId > 0) {
    cart_remove($itemId);
}
echo json_encode(['ok' => true, 'cart_count' => cart_count()]);
