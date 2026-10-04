<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice.php';

$order = order_for_viewer((string) ($_GET['order'] ?? ''));

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$itemsStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemsStmt->execute([$order['id']]);
$items = $itemsStmt->fetchAll();

output_order_invoice($order, $items, 'I');
