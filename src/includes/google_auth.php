<?php
/**
 * "Continue with Google" — OpenID Connect authorization-code flow with PKCE, written without a library.
 *
 *   /google-login     -> google_login_start(): sends the visitor to Google with a one-time state, nonce and PKCE challenge
 *   /google-callback  -> google_login_finish(): checks state, swaps the code for an ID token over TLS (with the client
 *                        secret), validates it, then signs in / links / creates the account.
 *
 * Account rules (see google_sign_in_user):
 *   1. Known Google id            -> that account.
 *   2. Email matches an account   -> Google is linked to it (Google has verified the address), and guest orders merge.
 *   3. Otherwise                  -> a new account, already verified, no password until the customer sets one.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

/** Client id / secret: saved in Admin → Settings & email, or GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET in .env. */
function google_config(): array {
    return [
        'client_id' => trim((string) setting_or('google_client_id', env_val('GOOGLE_CLIENT_ID', ''))),
        'client_secret' => trim((string) setting_or('google_client_secret', env_val('GOOGLE_CLIENT_SECRET', ''))),
    ];
}

function google_enabled(): bool {
    $c = google_config();
    return $c['client_id'] !== '' && $c['client_secret'] !== '' && get_setting('google_login_enabled', '1') !== '0';
}

/**
 * The address Google sends the visitor back to. It has to be listed, character for character, under
 * "Authorized redirect URIs" in Google Cloud Console — which is also what stops anyone abusing a forged Host header here.
 */
function google_redirect_uri(): string {
    return rtrim(base_url(), '/') . '/google-callback';
}

function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function google_login_start(?string $next = null): void {
    if (!google_enabled()) {
        flash_set('error', 'Google sign-in isn\'t set up on this store yet.');
        redirect('/login');
    }
    $verifier = b64url(random_bytes(48));
    $state = bin2hex(random_bytes(20));
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['google_oauth'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'redirect_uri' => google_redirect_uri(), 'at' => time()];
    if ($next !== null && $next !== '') {
        $_SESSION['redirect_after_login'] = safe_local_path($next, '/account');
    }
    $q = http_build_query([
        'client_id' => google_config()['client_id'],
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
    redirect(GOOGLE_AUTH_URL . '?' . $q);
}

/** POSTs a form and returns [http status, decoded JSON|null]. */
function google_http_post(string $url, array $fields): array {
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) error_log('[google] token request failed: ' . curl_error($ch));
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n", 'content' => $body, 'timeout' => 15, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach (($http_response_header ?? []) as $h) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $code = (int) $m[1];
    }
    return [$code, is_string($resp) ? json_decode($resp, true) : null];
}

/** Payload of a JWT. The token came straight from Google's token endpoint over TLS, so its claims are checked (below) rather than its signature. */
function jwt_payload(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
    $data = $json !== false ? json_decode($json, true) : null;
    return is_array($data) ? $data : null;
}

/** Called by /google-callback. Never returns: always redirects. */
function google_login_finish(): void {
    $fail = function (string $msg): void {
        unset($_SESSION['google_oauth']);
        flash_set('error', $msg);
        redirect('/login');
    };
    $sess = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']); // one shot: a replayed callback finds nothing

    if (!google_enabled() || !$sess || (time() - (int) $sess['at']) > 600) {
        $fail('That Google sign-in expired. Please try again.');
    }
    if (!empty($_GET['error'])) {
        $fail($_GET['error'] === 'access_denied' ? 'Google sign-in was cancelled.' : 'Google sign-in didn\'t complete. Please try again.');
    }
    if (empty($_GET['state']) || !hash_equals($sess['state'], (string) $_GET['state']) || empty($_GET['code'])) {
        $fail('Google sign-in couldn\'t be verified. Please try again.');
    }

    $cfg = google_config();
    [$status, $tok] = google_http_post(GOOGLE_TOKEN_URL, [
        'code' => (string) $_GET['code'], 'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'],
        'redirect_uri' => $sess['redirect_uri'], 'grant_type' => 'authorization_code', 'code_verifier' => $sess['verifier'],
    ]);
    if ($status !== 200 || empty($tok['id_token'])) {
        error_log('[google] token exchange failed (HTTP ' . $status . '): ' . json_encode($tok));
        $fail('Google sign-in failed. If this keeps happening, the store owner needs to check the Google settings.');
    }
    $c = jwt_payload($tok['id_token']);
    $issOk = $c && in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
    $audOk = $c && (($c['aud'] ?? null) === $cfg['client_id']);
    $expOk = $c && (int) ($c['exp'] ?? 0) > time() - 30;
    $nonceOk = $c && hash_equals($sess['nonce'], (string) ($c['nonce'] ?? ''));
    if (!$issOk || !$audOk || !$expOk || !$nonceOk || empty($c['sub']) || empty($c['email'])) {
        $fail('Google sign-in couldn\'t be verified. Please try again.');
    }
    // Google marks whether it has confirmed the address belongs to this person. Never trust an unconfirmed one.
    if (!filter_var($c['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $fail('Your Google account\'s email address isn\'t verified, so we can\'t use it to sign in.');
    }

    [$userId, $isNew, $err] = google_sign_in_user((string) $c['sub'], strtolower(trim((string) $c['email'])), trim((string) ($c['name'] ?? '')) ?: trim((string) ($c['given_name'] ?? '')));
    if ($err !== null) $fail($err);

    $merged = complete_login($userId);
    $next = safe_local_path($_SESSION['redirect_after_login'] ?? null, '/account');
    unset($_SESSION['redirect_after_login']);
    $_SESSION['merged_orders_notice'] = merged_orders_notice($merged);
    login_flash($isNew ? 'Welcome to ' . store_name() . '! Your account is ready.' : 'Welcome back!');
    redirect($next);
}

/**
 * Finds, links or creates the account for a verified Google identity.
 * @return array{0:int,1:bool,2:?string} [user id, was created just now, error message]
 */
function google_sign_in_user(string $sub, string $email, string $name): array {
    $pdo = db();
    if ($sub === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return [0, false, 'Google didn\'t return a usable email address.'];

    // 1. Already linked.
    $q = $pdo->prepare('SELECT id, status FROM users WHERE google_id = ?');
    $q->execute([$sub]);
    if ($row = $q->fetch()) {
        if ($row['status'] !== 'active') return [0, false, 'This account has been disabled. Please contact the store.'];
        return [(int) $row['id'], false, null];
    }

    // 2. An account with this email exists (password sign-up, or earlier checkout-created): link Google to it.
    $q = $pdo->prepare('SELECT id, status, email_verified, google_id FROM users WHERE email = ?');
    $q->execute([$email]);
    if ($row = $q->fetch()) {
        if ($row['status'] !== 'active') return [0, false, 'This account has been disabled. Please contact the store.'];
        if (!empty($row['google_id'])) return [0, false, 'That email is linked to a different Google account.'];
        if ((int) $row['email_verified'] === 1) {
            $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$sub, $row['id']]);
        } else {
            // The account was created by password but its email was never confirmed — whoever typed that address at
            // sign-up may not be the owner. Google has now confirmed the real owner, so the old password is discarded
            // (they can set a new one) and the account is marked verified.
            $pdo->prepare('UPDATE users SET google_id = ?, email_verified = 1, email_verify_token = NULL, has_password = 0, password_hash = ? WHERE id = ?')
                ->execute([$sub, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $row['id']]);
        }
        return [(int) $row['id'], false, null];
    }

    // 3. New customer.
    try {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, has_password, email_verified, google_id, promo_emails) VALUES (?,?,?,0,1,?,0)')
            ->execute([mb_substr($name !== '' ? $name : explode('@', $email)[0], 0, 120), $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $sub]);
    } catch (PDOException $e) {
        // Two tabs finishing at once: the other one created it first — use that one.
        $q = $pdo->prepare('SELECT id FROM users WHERE google_id = ? OR email = ?');
        $q->execute([$sub, $email]);
        if ($id = $q->fetchColumn()) return [(int) $id, false, null];
        throw $e;
    }
    $newUserId = (int) $pdo->lastInsertId();
    try { erp_customer_touch($name !== '' ? $name : explode('@', $email)[0], $email, null, [], $newUserId); } catch (Throwable $e) { error_log('[erp] customer touch: ' . $e->getMessage()); }
    return [$newUserId, true, null];
}

/** The multi-colour "G" mark, as required by Google's branding guidelines for sign-in buttons. */
function google_button_html(string $label = 'Continue with Google', ?string $next = null): string {
    if (!google_enabled()) return '';
    $href = '/google-login' . ($next ? '?next=' . rawurlencode($next) : '');
    return '<a class="btn-google" href="' . e($href) . '">'
        . '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7c-.5-1.5-.8-3-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.4 0 20.1 0 24s.9 7.6 2.6 10.8l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>'
        . '<span>' . e($label) . '</span></a>';
}
