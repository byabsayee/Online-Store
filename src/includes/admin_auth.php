<?php
require_once __DIR__ . '/functions.php';

function admin_logged_in(): bool {
    return !empty($_SESSION['admin_id']);
}

function current_admin(): ?array {
    static $admin = false;
    if ($admin === false) {
        if (empty($_SESSION['admin_id'])) {
            $admin = null;
        } else {
            $stmt = db()->prepare('SELECT id, username, name, role, status, must_change_password, (SELECT UNIX_TIMESTAMP(ph.updated_at) FROM admin_photos ph WHERE ph.admin_id = admins.id) AS photo_v FROM admins WHERE id = ?');
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch() ?: null;
        }
    }
    return $admin;
}

function require_admin(): void {
    if (!admin_logged_in()) {
        redirect('/admin/login.php');
    }
    // The session says "admin", but the account may have been removed since — treat that as signed out.
    $me = current_admin();
    if ($me === null) {
        unset($_SESSION['admin_id']);
        redirect('/admin/login.php');
    }
    // An owner can switch an account off: the person is signed out on their very next click.
    if (($me['status'] ?? 'active') !== 'active') {
        unset($_SESSION['admin_id']);
        flash_set('error', 'This account has been disabled. Please contact the store owner.');
        redirect('/admin/login.php');
    }
    // Given a temporary password by an owner? The only pages open until it is changed are My account and sign-out.
    if (!empty($me['must_change_password']) && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['account.php', 'logout.php', 'staff_photo.php', 'staff_document.php'], true)) {
        flash_set('info', 'Please choose your own password before continuing.');
        redirect('/admin/account.php');
    }
}

/** Only the Owner role may open the Activity log (staff accounts are the ones being audited). */
function admin_is_owner(): bool {
    $a = current_admin();
    return $a !== null && ($a['role'] ?? '') === 'owner';
}

function require_owner(): void {
    require_admin();
    if (!admin_is_owner()) {
        flash_set('error', 'Only the store owner can open that page.');
        redirect('/admin/index.php');
    }
}

/**
 * @return array{0: bool, 1: ?int, 2?: string} [success, seconds_until_retry (if throttled), 'disabled' when the account is switched off]
 */
function attempt_admin_login(string $username, string $password): array {
    $username = trim($username);
    $wait = login_throttle_check('admin', $username);
    if ($wait !== null) {
        return [false, $wait];
    }

    $stmt = db()->prepare('SELECT id, username, name, password_hash, status FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        login_throttle_hit('admin', $username);
        // Wrong password for a real account is logged under that admin's name (so they can see
        // someone tried); an unknown username is logged as the text that was typed.
        admin_log('auth.login_failed', $row ? 'Wrong password for "' . $row['username'] . '"' : 'Sign-in attempt with unknown username "' . admin_log_clip($username, 60) . '"',
            null, null, [], $row ? ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username']] : ['name' => admin_log_clip($username, 60) ?: 'Unknown', 'username' => null]);
        return [false, null];
    }
    login_throttle_clear('admin', $username);
    // Correct password but the account was switched off. Only now (after the password is proven) do we say so.
    if (($row['status'] ?? 'active') !== 'active') {
        admin_log('auth.login_failed', 'Sign-in blocked: the account "' . $row['username'] . '" is disabled', null, null, [], ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username']]);
        return [false, null, 'disabled'];
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $row['id'];
    admin_log('auth.login', 'Signed in', null, null, [], ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username']]);
    return [true, null];
}

function admin_logout(): void {
    if (admin_logged_in()) admin_log('auth.logout', 'Signed out');
    unset($_SESSION['admin_id']);
    session_regenerate_id(true);
}
