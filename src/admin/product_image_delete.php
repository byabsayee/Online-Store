<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/product_admin.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $imageId = (int) ($_POST['image_id'] ?? 0);
    $productId = (int) ($_POST['product_id'] ?? 0);
    $action = $_POST['action'] ?? 'delete';

    $stmt = db()->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ?');
    $stmt->execute([$imageId, $productId]);
    $img = $stmt->fetch();

    if ($img && $action === 'make_main') {
        // Swap: the gallery photo becomes the main one, and the old main one takes its place in the gallery.
        $cur = db()->prepare('SELECT image_main FROM products WHERE id = ?');
        $cur->execute([$productId]);
        $oldMain = $cur->fetchColumn();
        db()->prepare('UPDATE products SET image_main = ? WHERE id = ?')->execute([$img['image_path'], $productId]);
        if ($oldMain) {
            db()->prepare('UPDATE product_images SET image_path = ? WHERE id = ?')->execute([$oldMain, $imageId]);
        } else {
            db()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
        }
        admin_log('product.photo_main', 'Changed the main photo of ' . admin_log_product_name($productId), 'product', $productId);
        flash_set('success', 'Main photo changed.');
    } elseif ($img) {
        db()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
        delete_upload_if_unused($img['image_path']);
        admin_log('product.photo_delete', 'Removed a photo from ' . admin_log_product_name($productId), 'product', $productId);
        flash_set('success', 'Photo removed.');
    }
    redirect('/admin/product_form.php?id=' . $productId);
}
redirect('/admin/products.php');
