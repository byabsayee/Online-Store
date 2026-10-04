<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$me = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $t = staff_get((int) ($_POST['id'] ?? 0));
    if (!$t) {
        flash_set('error', 'That staff member no longer exists.');
    } elseif ((int) $t['id'] === (int) $me['id']) {
        flash_set('error', 'You can\'t delete your own account.');
    } elseif ($t['role'] === 'owner' && $t['status'] === 'active' && staff_active_owner_count((int) $t['id']) < 1) {
        flash_set('error', 'There must always be at least one active owner.');
    } else {
        // The document goes with them (foreign key). Their past activity-log entries stay, under the name they had.
        db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$t['id']]);
        admin_log('staff.delete', 'Deleted the account of ' . $t['name'] . ' (@' . $t['username'] . ', ' . $t['role'] . ')', 'staff', (int) $t['id'], ['username' => $t['username'], 'role' => $t['role']]);
        flash_set('success', $t['name'] . ' was deleted.');
    }
}
redirect('/admin/staff.php');
