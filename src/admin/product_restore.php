<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT id, name, sku FROM products WHERE id = ? AND archived_at IS NOT NULL');
    $st->execute([$id]);
    if ($p = $st->fetch()) {
        db()->prepare('UPDATE products SET archived_at = NULL, is_active = 1 WHERE id = ?')->execute([$id]);
        erp_emit('product', $id, 'restore');
        admin_log('product.restore', 'Restored archived product "' . $p['name'] . '"', 'product', $id, array_filter(['sku' => $p['sku']]));
        flash_set('success', 'Product restored and visible in the shop again.');
    }
}
redirect('/admin/products.php');
