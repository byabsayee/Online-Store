<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $c = coupon_find($id);
    if ($c) {
        $used = (int) db()->query('SELECT COUNT(*) FROM orders WHERE coupon_id = ' . (int) $id)->fetchColumn();
        $lk = erp_linked() ? erp_link_by_local('coupon', $id) : null;
        if ($lk && !empty($lk['last_payload'])) {
            // Linked to the accounting book: switch it off instead of deleting, so the book keeps its history.
            db()->prepare('UPDATE coupons SET is_active = 0 WHERE id = ?')->execute([$id]);
            erp_emit('coupon', $id, 'archive');
            admin_log('coupon.archive', 'Switched off coupon ' . $c['code'] . ' (linked to the accounting book, so it is kept)', 'coupon', $id, ['code' => $c['code']]);
            flash_set('success', 'Coupon ' . $c['code'] . ' switched off. It is linked to the accounting book, so it is kept rather than deleted.');
            redirect('/admin/coupons.php');
        }
        // Orders that used it keep their code and the discount they got (coupon_id is set to NULL by the foreign key).
        db()->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
        admin_log('coupon.delete', 'Deleted coupon ' . $c['code'] . ' (' . coupon_describe($c) . ')' . ($used ? ', used on ' . $used . ' order(s)' : ''), 'coupon', $id,
            ['code' => $c['code'], 'discount' => coupon_describe($c), 'orders_that_used_it' => $used]);
        flash_set('success', 'Coupon ' . $c['code'] . ' deleted.');
    }
}
redirect('/admin/coupons.php');
