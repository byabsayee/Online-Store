<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/product_admin.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);

    // Remember the photos so the files can be cleaned up once the rows are gone.
    $paths = [];
    $s = db()->prepare('SELECT image_main AS p FROM products WHERE id = ? UNION SELECT image_path FROM product_images WHERE product_id = ? UNION SELECT image FROM product_options WHERE product_id = ?');
    $s->execute([$id, $id, $id]);
    foreach ($s->fetchAll() as $r) if ($r['p']) $paths[] = $r['p'];

    $pi = db()->prepare('SELECT name, sku FROM products WHERE id = ?');
    $pi->execute([$id]);
    $gone = $pi->fetch();

    // A product that is linked to the accounting book is archived (hidden, restorable) rather than deleted,
    // so past orders and the book's history stay intact. Unlinked products are deleted as before.
    $linked = $gone && erp_linked() && ($lk = erp_link_by_local('product', $id)) && !empty($lk['last_payload']);
    if ($linked) {
        db()->prepare('UPDATE products SET is_active = 0, archived_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
        erp_emit('product', $id, 'archive');
        admin_log('product.archive', 'Archived product "' . $gone['name'] . '" (linked to the accounting book)', 'product', $id, array_filter(['sku' => $gone['sku']]));
        flash_set('success', 'Product archived — it is hidden from the shop and kept for the accounting book. Restore it from the Archived filter on the products page.');
    } else {
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        foreach ($paths as $p) delete_upload_if_unused($p);
        if ($gone) admin_log('product.delete', 'Deleted product "' . $gone['name'] . '"', 'product', $id, array_filter(['sku' => $gone['sku']]));
        flash_set('success', 'Product deleted.');
    }
}
redirect('/admin/products.php');
