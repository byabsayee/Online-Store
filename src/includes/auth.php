<?php
require_once __DIR__ . '/functions.php';

/** Signed in AND still an active account (asks the database, so a deleted or disabled customer is signed out at once). */
function is_logged_in(): bool {
    return current_user() !== null;
}

/** A fresh copy of a user row (current_user() caches for the whole request, so it can't show a just-saved change). */
function fetch_user_row(int $id): ?array {
    $stmt = db()->prepare('SELECT id, name, email, phone, email_verified, status, google_id, has_password, promo_emails, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        if (empty($_SESSION['user_id'])) {
            $user = null;
        } else {
            $stmt = db()->prepare('SELECT id, name, email, phone, email_verified, status, google_id, has_password, promo_emails, created_at FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
            // Deleted, or disabled by an admin since they signed in: end the session right away
            // (attempt_login() only checks status at sign-in, so without this a disabled customer
            // would stay signed in for as long as their 30-day session cookie lasts).
            if (!$user || $user['status'] !== 'active') {
                unset($_SESSION['user_id']);
                $user = null;
            }
        }
    }
    return $user;
}

function require_login(): void {
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/account';
        redirect('/login');
    }
}

/* ------------------------------------------------------------------ sign-in -- */

/**
 * Starts the session for a user who has just proved who they are (password, Google, password reset):
 * fresh session id, their guest cart folded into their account cart, and — when their email is verified —
 * any earlier guest orders placed with that email pulled into the account.
 *
 * @return int how many guest orders were merged in
 */
function complete_login(int $userId): int {
    $oldSessionId = session_id();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    cart_merge_session_into_user($userId, $oldSessionId);
    return claim_guest_orders($userId);
}

/**
 * Merges guest orders into an account: every order with no owner whose checkout email matches the account's
 * email. Only ever runs for a VERIFIED email (Google says it is, or the customer clicked the emailed link):
 * without that, anyone could register with someone else's address and read that person's guest orders.
 *
 * @return int number of orders merged
 */
function claim_guest_orders(int $userId): int {
    try {
        $s = db()->prepare('SELECT email, email_verified, status FROM users WHERE id = ?');
        $s->execute([$userId]);
        $u = $s->fetch();
        if (!$u || (int) $u['email_verified'] !== 1 || $u['status'] !== 'active') return 0;
        $upd = db()->prepare('UPDATE orders SET user_id = ?, claimed_at = NOW() WHERE user_id IS NULL AND LOWER(customer_email) = ?');
        $upd->execute([$userId, strtolower($u['email'])]);
        return $upd->rowCount();
    } catch (Throwable $e) {
        error_log('[claim_guest_orders] ' . $e->getMessage());
        return 0;
    }
}

/** "We added N earlier orders…" — the one place this sentence is written. */
function merged_orders_notice(int $n): ?string {
    if ($n < 1) return null;
    return $n === 1 ? 'We found 1 earlier order placed with your email and added it to your account.'
                    : 'We found ' . $n . ' earlier orders placed with your email and added them to your account.';
}

/**
 * @return array{0: bool, 1: ?int, 2?: string} [success, seconds_until_retry (if throttled), 'google_only' when the
 *         account has no password because it was created with Google]
 */
function attempt_login(string $email, string $password): array {
    $email = strtolower(trim($email));
    $wait = login_throttle_check('customer', $email);
    if ($wait !== null) {
        return [false, $wait];
    }

    $stmt = db()->prepare('SELECT id, password_hash, status, has_password FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && (int) $row['has_password'] === 0) {
        // Still counts as an attempt, and still runs a hash check so the response time doesn't differ.
        password_verify($password, $row['password_hash']);
        login_throttle_hit('customer', $email);
        return [false, null, 'google_only'];
    }
    if (!$row || $row['status'] !== 'active' || !password_verify($password, $row['password_hash'])) {
        login_throttle_hit('customer', $email);
        return [false, null];
    }
    login_throttle_clear('customer', $email);
    $merged = complete_login((int) $row['id']);
    $_SESSION['merged_orders_notice'] = merged_orders_notice($merged);
    return [true, null];
}

/** Flash for a just-completed login: the greeting, plus the "orders merged" line when there was one. */
function login_flash(string $greeting): void {
    flash_set('success', $greeting);
    if (!empty($_SESSION['merged_orders_notice'])) {
        flash_set('info', $_SESSION['merged_orders_notice']);
    }
    unset($_SESSION['merged_orders_notice']);
}

/* ---------------------------------------------------------------- register -- */

function register_user(string $name, string $email, string $password, string $phone = '', bool $promo = false): array {
    $email = strtolower(trim($email));
    if (mb_strlen(trim($name)) < 2) {
        return [false, 'Please enter your full name.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
        return [false, 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }
    $stmt = db()->prepare('SELECT id, has_password FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($existing = $stmt->fetch()) {
        return [false, (int) $existing['has_password'] === 0
            ? 'An account with that email already exists (it was created with Google). Use "Continue with Google", or reset your password to add one.'
            : 'An account with that email already exists. Try logging in, or reset your password.'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));
    $ins = db()->prepare('INSERT INTO users (name, email, password_hash, phone, email_verified, email_verify_token, email_verify_sent_at, promo_emails) VALUES (?,?,?,?,0,?,NOW(),?)');
    $ins->execute([trim($name), $email, $hash, $phone ?: null, $token, $promo ? 1 : 0]);
    $userId = (int) db()->lastInsertId();
    try { erp_customer_touch(trim($name), $email, $phone ?: null, [], $userId); } catch (Throwable $e) { error_log('[erp] customer touch: ' . $e->getMessage()); }

    // Sent after the page has been delivered, so a slow mail server never holds up sign-up.
    defer_job(fn () => send_verification_email($userId, trim($name), $email, $token));

    complete_login($userId); // nothing to merge yet: the email isn't verified until they click the link
    return [true, 'Account created.'];
}

/** Builds and sends the "verify your email" message for a newly (re)issued token. */
function send_verification_email(int $userId, string $name, string $email, string $token): bool {
    require_once __DIR__ . '/mail.php';
    $link = mail_base_url() . '/verify-email?uid=' . $userId . '&token=' . $token;
    $bg = theme_settings()['primary'];
    $body = '<p>Hi ' . e(explode(' ', $name)[0]) . ',</p>'
        . '<p>Welcome to ' . e(store_name()) . '! Please confirm your email address to activate your account. Any orders you placed earlier as a guest with this email will be added to your account once it\'s confirmed.</p>'
        . '<p style="margin:24px 0;"><a href="' . e($link) . '" style="background:' . e($bg) . ';color:' . e(contrast_text($bg)) . ';padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:bold;">Verify my email</a></p>'
        . '<p class="muted" style="font-size:0.85rem;color:#8791a6;">Or paste this link into your browser:<br>' . e($link) . '</p>'
        . '<p class="muted" style="font-size:0.85rem;color:#8791a6;">The link works for 7 days. If you didn\'t create this account you can ignore this email.</p>';
    return send_email($email, $name, 'Verify your email — ' . store_name(), email_wrap('Confirm your email address', $body), null, null, 'verify');
}

/** Issues a fresh token and resends the verification email (rate-limited to once per 2 minutes). */
function resend_verification_email(int $userId): bool {
    $stmt = db()->prepare('SELECT name, email, email_verified,
                                  TIMESTAMPDIFF(SECOND, email_verify_sent_at, NOW()) AS secs_since_sent
                           FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['email_verified'] === 1) return false;
    if ($user['secs_since_sent'] !== null && (int) $user['secs_since_sent'] < 120) {
        return false; // too soon
    }
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET email_verify_token = ?, email_verify_sent_at = NOW() WHERE id = ?')->execute([$token, $userId]);
    defer_job(fn () => send_verification_email($userId, $user['name'], $user['email'], $token));
    return true;
}

/**
 * Confirms an email from the emailed link.
 * @return array{0: string, 1: int} ['success'|'already'|'invalid', orders merged]
 */
function verify_email_token(int $uid, string $token): array {
    if (!$uid || $token === '') return ['invalid', 0];
    $stmt = db()->prepare('SELECT id, email_verified, email_verify_token,
                                  TIMESTAMPDIFF(HOUR, email_verify_sent_at, NOW()) AS hrs FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if ($user && (int) $user['email_verified'] === 1) return ['already', 0];
    if ($user && $user['email_verify_token'] && hash_equals($user['email_verify_token'], $token)
        && ($user['hrs'] === null || (int) $user['hrs'] <= 24 * 7)) {
        db()->prepare('UPDATE users SET email_verified = 1, email_verify_token = NULL WHERE id = ?')->execute([$uid]);
        return ['success', claim_guest_orders($uid)];
    }
    return ['invalid', 0];
}

/* ---------------------------------------------------------- password reset -- */

/** Emails a reset link if the address belongs to an account. Silent otherwise (the page never says which). */
function request_password_reset(string $email): void {
    $email = strtolower(trim($email));
    $q = db()->prepare("SELECT id, name, email FROM users WHERE email = ? AND status = 'active'");
    $q->execute([$email]);
    $u = $q->fetch();
    if (!$u) return;
    // Only the hash of the token is stored; older unused links are cancelled when a new one is issued.
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$u['id']]);
    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
        ->execute([$u['id'], hash('sha256', $token)]);
    $uid = (int) $u['id'];
    defer_job(function () use ($u, $uid, $token) {
        require_once __DIR__ . '/mail.php';
        $link = mail_base_url() . '/reset-password?uid=' . $uid . '&token=' . $token;
        $bg = theme_settings()['primary'];
        $body = '<p>Hi ' . e(explode(' ', $u['name'])[0]) . ',</p>'
            . '<p>We got a request to reset the password for your ' . e(store_name()) . ' account. The link below works for one hour.</p>'
            . '<p style="margin:24px 0;"><a href="' . e($link) . '" style="background:' . e($bg) . ';color:' . e(contrast_text($bg)) . ';padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:bold;">Choose a new password</a></p>'
            . '<p class="muted" style="font-size:0.85rem;color:#8791a6;">Or paste this link into your browser:<br>' . e($link) . '</p>'
            . '<p class="muted" style="font-size:0.85rem;color:#8791a6;">If you didn\'t ask for this, ignore this email — your password stays the same.</p>';
        send_email($u['email'], $u['name'], 'Reset your password — ' . store_name(), email_wrap('Reset your password', $body), null, null, 'password-reset');
    });
}

/** The user id a reset link is good for, or null (unknown, used, or older than an hour). */
function valid_password_reset(int $uid, string $token): ?int {
    if (!$uid || $token === '') return null;
    $q = db()->prepare('SELECT id, user_id FROM password_resets WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $q->execute([$uid, hash('sha256', $token)]);
    $row = $q->fetch();
    return $row ? (int) $row['user_id'] : null;
}

/**
 * Sets the new password. Following the emailed link proves the customer controls the inbox, so the email
 * counts as verified (which also lets earlier guest orders merge in on their next sign-in).
 */
function complete_password_reset(int $uid, string $token, string $newPassword): bool {
    if (valid_password_reset($uid, $token) === null) return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET password_hash = ?, has_password = 1, email_verified = 1, email_verify_token = NULL WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $uid]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[reset] ' . $e->getMessage());
        return false;
    }
    return true;
}

function logout_user(): void {
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}
