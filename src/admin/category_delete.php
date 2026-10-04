<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $ci = db()->prepare('SELECT name FROM categories WHERE id = ?');
    $ci->execute([$id]);
    $gone = $ci->fetchColumn();
    $lk = $gone !== false && erp_linked() ? erp_link_by_local('category', $id) : null;
    if ($lk && !empty($lk['last_payload'])) {
        // Linked to the accounting book: hide instead of deleting, so its history stays intact.
        db()->prepare('UPDATE categories SET is_active = 0 WHERE id = ?')->execute([$id]);
        erp_emit('category', $id, 'archive');
        admin_log('category.archive', 'Hid category "' . $gone . '" (linked to the accounting book, so it is kept)', 'category', $id);
        flash_set('success', 'Category hidden from the shop. It is linked to the accounting book, so it is kept rather than deleted.');
    } else {
        // Any subcategories under this one become top-level categories rather than being deleted with it.
        $kids = db()->prepare('SELECT id FROM categories WHERE parent_id = ?'); $kids->execute([$id]);
        $kidIds = $kids->fetchAll(PDO::FETCH_COLUMN);
        db()->prepare('UPDATE categories SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        foreach ($kidIds as $kid) erp_emit('category', (int) $kid, 'auto');
        if ($gone !== false) admin_log('category.delete', 'Deleted category "' . $gone . '"', 'category', $id);
        flash_set('success', 'Category deleted.');
    }
}
redirect('/admin/categories.php');
