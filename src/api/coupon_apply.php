<?php
/**
 * Apply or remove a coupon code on the checkout page.
 * POST JSON: {"code": "SAVE10"}  or  {"remove": true}
 * The coupon is remembered in the visitor's session; the real discount is worked out again
 * on the server when the order is placed, so nothing here can be used to lower a price.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Security check failed, please refresh the page.']);
    exit;
}

if (!empty($input['remove'])) {
    coupon_session_clear();
    echo json_encode(['ok' => true, 'removed' => true, 'discount' => 0]);
    exit;
}

$totals = cart_totals();
if (!$totals['items']) {
    echo json_encode(['ok' => false, 'message' => 'Your cart is empty.']);
    exit;
}

$code = coupon_normalize_code((string) ($input['code'] ?? ''));
if ($code === '') {
    echo json_encode(['ok' => false, 'message' => 'Please enter a coupon code.']);
    exit;
}

// Guessing codes is slowed down: 8 wrong tries per 5 minutes per visitor address.
$wait = login_throttle_check('coupon', client_ip(), 8);
if ($wait !== null) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'message' => 'Too many wrong codes. Please try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).']);
    exit;
}

$user = current_user();
$coupon = coupon_find_by_code($code);
$v = coupon_validate($coupon, (float) $totals['subtotal'], coupon_shopper($user));
if (!$v['ok']) {
    login_throttle_hit('coupon', client_ip());
    echo json_encode(['ok' => false, 'message' => $v['message']]);
    exit;
}

$_SESSION[COUPON_SESSION_KEY] = $coupon['code'];
echo json_encode([
    'ok' => true,
    'code' => $coupon['code'],
    'description' => coupon_describe($coupon),
    'discount' => $v['discount'],
    'message' => 'Coupon ' . $coupon['code'] . ' applied.',
]);
