<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/invoice.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$itemsStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemsStmt->execute([$order['id']]);
$items = $itemsStmt->fetchAll();

$mode = ($_GET['download'] ?? '') === '1' ? 'D' : 'I';
// An invoice carries the customer's name, phone and address, so opening one is recorded.
admin_log('order.invoice', ($mode === 'D' ? 'Downloaded' : 'Opened') . ' the invoice for order ' . $order['order_number'], 'order', (int) $order['id']);
$src = in_array($_GET['src'] ?? '', ['store', 'book'], true) ? $_GET['src'] : null; // staff can preview either; default = what customers see
output_order_invoice($order, $items, $mode, $src);
