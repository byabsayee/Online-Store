<?php
require_once __DIR__ . '/db.php';

/* ---------------------------------------------------------- CSRF ---- */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void {
    if (!csrf_verify()) {
        http_response_code(419);
        die('Security check failed. Please go back and try again.');
    }
}

/* ------------------------------------------------- login throttling - */

/**
 * DB-backed brute-force guard for login forms, keyed by both the
 * attempted identifier (email/username) and the client IP so an
 * attacker can't dodge it just by clearing cookies (a session-based
 * guard could be reset that easily). Limit: 5 failed attempts per
 * 5-minute window: whichever of (identifier, IP) is more attacked
 * trips the lock first.
 */
function client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Behind a reverse proxy (Nginx Proxy Manager, Caddy, Traefik, Cloudflare…) REMOTE_ADDR is
    // the proxy, i.e. the same for every visitor — that would put everyone in one throttle
    // bucket and stamp the proxy's address on every audit-log entry. So when the direct peer
    // is a private/loopback address (= our own proxy), read the visitor from X-Forwarded-For:
    // the right-most public address, since each proxy appends the peer it saw and only the
    // entries added by our own proxies can be trusted. A visitor connecting straight to the
    // container has a public REMOTE_ADDR, so a forged header from them is never consulted.
    $isPrivate = fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($isPrivate($remote) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !$isPrivate($ip)) return $ip;
        }
    }
    return $remote;
}

/** Returns seconds remaining before another attempt is allowed, or null if clear. */
function login_throttle_check(string $bucket, string $identifier, int $limit = 5): ?int {
    // Everything is computed inside the database so it can never disagree
    // with the timestamps it stored (PHP's clock/timezone is not involved).
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS cnt,
                GREATEST(0, 300 - COALESCE(TIMESTAMPDIFF(SECOND, MIN(attempted_at), NOW()), 0)) AS remaining
         FROM login_attempts
         WHERE bucket = ? AND (identifier = ? OR ip = ?) AND attempted_at > (NOW() - INTERVAL 5 MINUTE)'
    );
    $stmt->execute([$bucket, strtolower($identifier), client_ip()]);
    $row = $stmt->fetch();
    if ($row && (int) $row['cnt'] >= $limit && (int) $row['remaining'] > 0) {
        return (int) $row['remaining'];
    }
    return null;
}

function login_throttle_hit(string $bucket, string $identifier): void {
    db()->prepare('INSERT INTO login_attempts (bucket, identifier, ip) VALUES (?,?,?)')
        ->execute([$bucket, strtolower($identifier), client_ip()]);
}

function login_throttle_clear(string $bucket, string $identifier): void {
    db()->prepare('DELETE FROM login_attempts WHERE bucket = ? AND (identifier = ? OR ip = ?)')
        ->execute([$bucket, strtolower($identifier), client_ip()]);
}

/* ------------------------------------------------------- flash msg -- */

function flash_set(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ----------------------------------------------------- formatting --- */

function money(float $amount): string {
    $n = number_format($amount, currency_decimals());
    return currency_position() === 'after' ? $n . ' ' . store_currency_symbol() : store_currency_symbol() . $n;
}

function slugify(string $text): string {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = iconv('utf-8', 'ascii//TRANSLIT', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    return $text !== '' ? $text : 'item-' . substr(md5((string)microtime(true)), 0, 6);
}

function e($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Database timestamps are stored in UTC (see db.php). This converts one to
 * the store's timezone (TZ env, default Asia/Dhaka) for display.
 */
function db_time(?string $utc): ?DateTimeImmutable {
    if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) return null;
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable $e) {
        return null;
    }
}

function fmt_dt(?string $utc, string $format = 'd M Y, g:i A'): string {
    $d = db_time($utc);
    return $d ? $d->format($format) : '—';
}

/**
 * Public base URL for links in emails, link previews and the sitemap. The
 * current request's Host wins whenever it looks like a real hostname (a
 * forged Host header must never end up in canonical links or emails, so
 * it's validated against the same host-syntax check either way) — that's
 * what lets the store move between domains (a temporary subdomain today, the
 * real domain later) without ever having to update SITE_URL. SITE_URL is
 * only the fallback for the rare case there's no usable request context.
 */
function base_url(): string {
    $configured = SITE_URL;
    $reqHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($reqHost !== '' && preg_match('/^[a-z0-9.-]+(:\d{1,5})?$|^\[[0-9a-f:]+\](:\d{1,5})?$/i', $reqHost)) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . $reqHost;
    }
    return $configured;
}

/** Only allows same-site relative paths, for post-action redirects. */
function safe_local_path(?string $url, string $fallback = '/'): string {
    if (!$url) return $fallback;
    $parts = parse_url($url);
    if ($parts === false) return $fallback;
    if (!empty($parts['host']) && !empty($_SERVER['HTTP_HOST']) && strcasecmp($parts['host'], preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) !== 0) {
        return $fallback;
    }
    $path = $parts['path'] ?? '/';
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) return $fallback;
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function redirect(string $path): void {
    header('Location: ' . $path);
    run_deferred_jobs();
    exit;
}

/* ------------------------------------------------- deferred work ---- */
/**
 * Queues work (mostly sending email) to run AFTER the visitor has been sent their response, so a slow or
 * unreachable mail server never makes a page hang. redirect() flushes the response and then runs the queue;
 * pages that render normally run it when the script ends.
 */
function defer_job(callable $job): void {
    static $registered = false;
    $GLOBALS['__deferred_jobs'][] = $job;
    if (!$registered) { $registered = true; register_shutdown_function('run_deferred_jobs'); }
}

function run_deferred_jobs(): void {
    $jobs = $GLOBALS['__deferred_jobs'] ?? [];
    if (!$jobs) return;
    $GLOBALS['__deferred_jobs'] = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    ignore_user_abort(true);
    @set_time_limit(120);
    foreach ($jobs as $job) {
        try { $job(); } catch (Throwable $e) { error_log('[deferred] ' . $e->getMessage()); }
    }
}

/* ------------------------------------------- public address & tokens -- */

/**
 * The public address of the store for links inside emails. Emails carry tokens (verify, reset password),
 * so the address must not be taken from the request's Host header — anyone can send a request with a forged
 * Host and have the store email its customers a link to their own site. Saved value (Settings & email) wins,
 * then SITE_URL from .env; only when neither is set does it fall back to the address the request came in on.
 */
function site_url(): string {
    $saved = trim((string) get_setting('site_url', ''));
    return rtrim($saved !== '' ? $saved : SITE_URL, '/');
}

function mail_base_url(): string {
    $configured = site_url();
    return $configured !== '' ? $configured : rtrim(base_url(), '/');
}

/** Random secret for signing links (unsubscribe). Created once, kept in the settings table. */
function app_secret(): string {
    $s = (string) get_setting('app_secret', '');
    if (strlen($s) < 32) {
        $s = bin2hex(random_bytes(32));
        set_setting('app_secret', $s);
    }
    return $s;
}

function unsubscribe_token(int $userId): string {
    return substr(hash_hmac('sha256', 'unsub:' . $userId, app_secret()), 0, 40);
}

function unsubscribe_url(int $userId): string {
    return mail_base_url() . '/unsubscribe?uid=' . $userId . '&token=' . unsubscribe_token($userId);
}

/* -------------------------------------------------------- pretty URLs ----- */
/**
 * The storefront is served without .php extensions (see docker/nginx/default.conf for the
 * rewrite rules) — these build the pretty path for a given record so every template links the
 * same way. Kept close to redirect()/e() since almost every page-link in the app goes through one.
 */
function product_url(array $product): string {
    return '/product/' . rawurlencode($product['slug']);
}
function category_url(array $category): string {
    return '/category/' . rawurlencode($category['slug']);
}
/** $orderNumber is the public order code (e.g. ORD-260925-AB12C), not the numeric id. */
function order_url(string $orderNumber): string {
    return '/order/' . rawurlencode($orderNumber);
}
/**
 * Guest orders can be viewed in the browser session that placed them (right after checkout) or after typing the
 * order number together with the email used at checkout (Track an order). Orders that belong to an account are
 * only ever shown to that account.
 */
function guest_order_grant(string $orderNumber): void {
    $_SESSION['guest_orders'][$orderNumber] = time();
    if (count($_SESSION['guest_orders']) > 20) $_SESSION['guest_orders'] = array_slice($_SESSION['guest_orders'], -20, null, true);
}

function guest_order_granted(string $orderNumber): bool {
    return isset($_SESSION['guest_orders'][$orderNumber]);
}

/** The order if the visitor may see it (owner, or a guest order they hold a grant for), else null. */
function order_for_viewer(string $orderNumber): ?array {
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_number = ?');
    $stmt->execute([$orderNumber]);
    $o = $stmt->fetch();
    if (!$o) return null;
    if (!empty($o['user_id'])) {
        return (!empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $o['user_id'] && function_exists('current_user') && current_user()) ? $o : null;
    }
    return guest_order_granted($orderNumber) ? $o : null;
}

function invoice_url(string $orderNumber): string {
    return '/invoice/' . rawurlencode($orderNumber);
}

/* -------------------------------------------------------- categories ------ */
/**
 * Categories can nest (e.g. "Men" / "Women" under "Bags & Carry") via
 * categories.parent_id. These helpers build the tree, flatten it for
 * <select> menus, and resolve "this category plus every descendant" so a
 * parent category page can show products filed under its subcategories.
 */

/** Every category as parent → children, ordered by sort_order/name at each level. */
function category_tree(bool $activeOnly = true): array {
    $sql = 'SELECT id, parent_id, name, slug, description, image, sort_order, is_active FROM categories'
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
    $rows = db()->query($sql)->fetchAll();
    $byParent = [];
    foreach ($rows as $r) $byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
    $build = function (int $parentId) use (&$build, &$byParent): array {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $r) {
            $r['children'] = $build((int) $r['id']);
            $out[] = $r;
        }
        return $out;
    };
    return $build(0);
}

/** All categories (active + hidden — for admin use) as a flat, depth-ordered list, each row carrying 'depth'. Handy for an indented <select>. */
function category_flat_for_select(): array {
    $out = [];
    $walk = function (array $nodes, int $depth) use (&$walk, &$out): void {
        foreach ($nodes as $n) {
            $n['depth'] = $depth;
            $children = $n['children'] ?? [];
            unset($n['children']);
            $out[] = $n;
            $walk($children, $depth + 1);
        }
    };
    $walk(category_tree(false), 0);
    return $out;
}

/** A category id plus every descendant id (children, grandchildren, …), so browsing a parent category also surfaces products filed under its subcategories. */
function category_descendant_ids(int $id): array {
    static $childrenOf = null;
    if ($childrenOf === null) {
        $childrenOf = [];
        foreach (db()->query('SELECT id, parent_id FROM categories')->fetchAll() as $r) {
            $childrenOf[(int) ($r['parent_id'] ?? 0)][] = (int) $r['id'];
        }
    }
    $ids = [$id];
    $queue = [$id];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($childrenOf[$cur] ?? [] as $child) {
            if (in_array($child, $ids, true)) continue; // guards against a corrupted/cyclic parent_id chain
            $ids[] = $child;
            $queue[] = $child;
        }
    }
    return $ids;
}

/* -------------------------------------------------------- cart ------ */

function cart_identity(): array {
    // Returns [user_id or null, session_id or null] - always one is set.
    if (!empty($_SESSION['user_id'])) {
        return [(int)$_SESSION['user_id'], null];
    }
    return [null, session_id()];
}

/** Combined "Red / Large" style label for a variant row (color and/or size). */
function variant_label(array $variant): string {
    $parts = array_filter([$variant['color'] ?? null, $variant['size'] ?? null]);
    return $parts ? implode(' / ', $parts) : '';
}

function cart_items(): array {
    // A cart line's photo and weight follow the chosen options: the color's
    // (or else the size's) preview image, and the size's weight override.
    $sql = 'SELECT c.id, c.quantity, c.variant_id, c.customization_id, p.id AS product_id, p.name, p.slug, p.price, p.warranty_days,
                   COALESCE(cz.image, co.image, so.image, p.image_main) AS image_main,
                   co.price_delta AS color_delta, so.price_delta AS size_delta,
                   cz.name AS custom_name, cz.price_delta AS custom_delta, cz.is_active AS custom_active,
                   p.stock AS product_stock, p.is_active AS product_active, p.is_preorder, p.preorder_note, p.preorder_available_date,
                   COALESCE(so.weight_grams, p.weight_grams) AS weight_grams,
                   v.color AS variant_color, v.size AS variant_size, v.price_delta, v.stock AS variant_stock,
                   v.is_active AS variant_active
            FROM cart_items c
            JOIN products p ON p.id = c.product_id
            LEFT JOIN product_variants v ON v.id = c.variant_id
            LEFT JOIN product_options co ON co.product_id = v.product_id AND co.kind = \'color\' AND co.name = v.color
            LEFT JOIN product_options so ON so.product_id = v.product_id AND so.kind = \'size\' AND so.name = v.size
            LEFT JOIN product_customizations cz ON cz.id = c.customization_id AND cz.product_id = c.product_id
            WHERE %s ORDER BY c.id DESC';
    [$uid, $sid] = cart_identity();
    if ($uid) {
        $stmt = db()->prepare(sprintf($sql, 'c.user_id = ?'));
        $stmt->execute([$uid]);
    } else {
        $stmt = db()->prepare(sprintf($sql, 'c.session_id = ?'));
        $stmt->execute([$sid]);
    }
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        // Unit price = base + color extra + size extra + combination extra + customization extra.
        $r['price'] = (float) $r['price'] + (float) ($r['price_delta'] ?? 0) + (float) ($r['color_delta'] ?? 0) + (float) ($r['size_delta'] ?? 0) + (float) ($r['custom_delta'] ?? 0);
        $r['stock'] = $r['variant_id'] ? (int) $r['variant_stock'] : (int) $r['product_stock'];
        $r['available'] = $r['product_active'] && (!$r['variant_id'] || $r['variant_active']) && (!$r['customization_id'] || ($r['custom_name'] !== null && $r['custom_active']));
        // Pre-order is a product-level promise (not tracked per variant), so a variant line still
        // counts as a pre-order once its own stock is out, as long as the product allows it.
        $r['is_preorder'] = (bool) $r['is_preorder'] && $r['stock'] <= 0;
        $label = $r['variant_id'] ? variant_label(['color' => $r['variant_color'], 'size' => $r['variant_size']]) : '';
        if (!empty($r['custom_name'])) $label = ($label !== '' ? $label . ' · ' : '') . 'Custom: ' . $r['custom_name'];
        $r['variant_label'] = $label !== '' ? $label : null;
    }
    unset($r);
    return $rows;
}

function cart_count(): int {
    [$uid, $sid] = cart_identity();
    if ($uid) {
        $stmt = db()->prepare('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE user_id = ?');
        $stmt->execute([$uid]);
    } else {
        $stmt = db()->prepare('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE session_id = ?');
        $stmt->execute([$sid]);
    }
    return (int) $stmt->fetchColumn();
}

/**
 * Cart contents + subtotal + total weight. Shipping isn't included here
 * because it depends on the delivery area, which is only known at
 * checkout — see shipping_fee_for_area().
 */
function cart_totals(): array {
    $items = cart_items();
    $subtotal = 0.0;
    $weightGrams = 0;
    foreach ($items as $it) {
        $subtotal += $it['price'] * $it['quantity'];
        $weightGrams += (int) $it['weight_grams'] * (int) $it['quantity'];
    }
    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'weight_grams' => $weightGrams,
    ];
}

/**
 * Delivery zones. The three internal codes are fixed (the accounting link and stored orders use them), but the
 * names are admin-editable and the 2nd/3rd zone can be switched off (Admin -> Delivery & tax).
 */
const DELIVERY_ZONE_KEYS = ['inside_dhaka' => 'inside', 'suburbs' => 'suburbs', 'outside_dhaka' => 'outside'];
const DELIVERY_ZONE_DEFAULT_LABELS = ['inside_dhaka' => 'Inside Dhaka', 'suburbs' => 'Dhaka Suburbs', 'outside_dhaka' => 'Outside Dhaka'];

/** Human label for a delivery_area value (the admin's own zone name). */
function delivery_area_label(string $area): string {
    if (!isset(DELIVERY_ZONE_KEYS[$area])) $area = 'inside_dhaka';
    $v = trim((string) get_setting('zone_label_' . DELIVERY_ZONE_KEYS[$area], ''));
    return $v !== '' ? $v : DELIVERY_ZONE_DEFAULT_LABELS[$area];
}

/** Zones customers can pick: code => label. The first zone is always available. */
function delivery_zones(bool $enabledOnly = true): array {
    $out = [];
    foreach (DELIVERY_ZONE_KEYS as $code => $key) {
        if ($enabledOnly && $code !== 'inside_dhaka' && get_setting('zone_off_' . $key, '0') === '1') continue;
        $out[$code] = delivery_area_label($code);
    }
    return $out;
}

/** One line for product / cart pages: "Local ৳80 · Regional ৳100 (+৳20/kg over 1kg)". */
function shipping_summary_text(): string {
    $parts = [];
    foreach (delivery_zones() as $code => $label) $parts[] = $label . ' ' . money(shipcfg(DELIVERY_ZONE_KEYS[$code]));
    $t = implode(' · ', $parts);
    if (shipcfg('extra_kg') > 0) $t .= ' (+' . money(shipcfg('extra_kg')) . '/kg over ' . rtrim(rtrim(number_format(shipcfg('free_kg'), 2, '.', ''), '0'), '.') . 'kg)';
    return $t;
}

/**
 * Human label for an orders.payment_method value. Kept in one place so the storefront, admin
 * and invoice always describe payment the same way. Only "cod" is actually processed today —
 * "bank_transfer" exists in the schema for when an online payment gateway is wired up.
 */
function payment_method_label(string $method): string {
    return $method === 'cod' ? 'Cash on delivery' : 'Online Payment';
}

/** "12-month warranty" / "45-day warranty" — the friendliest whole unit that exactly fits the day count. Null/0 = no warranty. */
function warranty_label(?int $days): ?string {
    if (!$days || $days < 1) return null;
    if ($days % 365 === 0) { $n = intdiv($days, 365); return $n . '-year warranty'; }
    if ($days % 30 === 0) { $n = intdiv($days, 30); return $n . '-month warranty'; }
    return $days . '-day warranty';
}

/** Base flat fee for a delivery zone, before the over-weight surcharge. */
function shipping_base_fee_for_area(string $area): float {
    if ($area === 'outside_dhaka') return shipcfg('outside');
    if ($area === 'suburbs') return shipcfg('suburbs');
    return shipcfg('inside');
}

/**
 * Shipping fee for a given delivery area + parcel weight: a flat zone fee,
 * plus a per-kg surcharge for every kg (or part of a kg) over the free
 * weight allowance.
 */
function shipping_fee_for_area(string $area, int $weightGrams): float {
    $base = shipping_base_fee_for_area($area);
    $freeGrams = shipcfg('free_kg') * 1000;
    $extraGrams = max(0, $weightGrams - $freeGrams);
    $extraKg = (int) ceil($extraGrams / 1000);
    return $base + ($extraKg * shipcfg('extra_kg'));
}

function cart_add(int $productId, int $qty = 1, ?int $variantId = null, ?int $maxQty = null, ?int $customizationId = null): void {
    [$uid, $sid] = cart_identity();
    $qty = max(1, $qty);
    $pdo = db();
    // variant_id <=> ? is a NULL-safe equality comparison, so "no variant"
    // still matches an existing no-variant cart line rather than always
    // inserting a new row.
    if ($uid) {
        $stmt = $pdo->prepare('SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ? AND variant_id <=> ? AND customization_id <=> ?');
        $stmt->execute([$uid, $productId, $variantId, $customizationId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, quantity FROM cart_items WHERE session_id = ? AND product_id = ? AND variant_id <=> ? AND customization_id <=> ?');
        $stmt->execute([$sid, $productId, $variantId, $customizationId]);
    }
    $row = $stmt->fetch();
    if ($row) {
        // Never let repeated "add" clicks push the line past what's in stock.
        $newQty = (int) $row['quantity'] + $qty;
        if ($maxQty !== null) $newQty = min($newQty, $maxQty);
        $upd = $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?');
        $upd->execute([max(1, $newQty), $row['id']]);
    } else {
        $ins = $pdo->prepare('INSERT INTO cart_items (user_id, session_id, product_id, variant_id, customization_id, quantity) VALUES (?,?,?,?,?,?)');
        $ins->execute([$uid, $uid ? null : $sid, $productId, $variantId, $customizationId, $maxQty !== null ? min($qty, $maxQty) : $qty]);
    }
}

function cart_set_qty(int $cartItemId, int $qty): void {
    [$uid, $sid] = cart_identity();
    $pdo = db();
    if ($qty <= 0) {
        cart_remove($cartItemId);
        return;
    }
    if ($uid) {
        $stmt = $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$qty, $cartItemId, $uid]);
    } else {
        $stmt = $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND session_id = ?');
        $stmt->execute([$qty, $cartItemId, $sid]);
    }
}

function cart_remove(int $cartItemId): void {
    [$uid, $sid] = cart_identity();
    $pdo = db();
    if ($uid) {
        $stmt = $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?');
        $stmt->execute([$cartItemId, $uid]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND session_id = ?');
        $stmt->execute([$cartItemId, $sid]);
    }
}

function cart_clear(): void {
    [$uid, $sid] = cart_identity();
    $pdo = db();
    if ($uid) {
        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
    } else {
        $pdo->prepare('DELETE FROM cart_items WHERE session_id = ?')->execute([$sid]);
    }
}

/** Merge a guest session's cart into a user's cart after login. */
function cart_merge_session_into_user(int $userId, string $sessionId): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT product_id, variant_id, customization_id, quantity FROM cart_items WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    foreach ($stmt->fetchAll() as $row) {
        $existing = $pdo->prepare('SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ? AND variant_id <=> ? AND customization_id <=> ?');
        $existing->execute([$userId, $row['product_id'], $row['variant_id'], $row['customization_id']]);
        $ex = $existing->fetch();
        if ($ex) {
            $pdo->prepare('UPDATE cart_items SET quantity = quantity + ? WHERE id = ?')
                ->execute([$row['quantity'], $ex['id']]);
        } else {
            $pdo->prepare('INSERT INTO cart_items (user_id, product_id, variant_id, customization_id, quantity) VALUES (?,?,?,?,?)')
                ->execute([$userId, $row['product_id'], $row['variant_id'], $row['customization_id'], $row['quantity']]);
        }
    }
    $pdo->prepare('DELETE FROM cart_items WHERE session_id = ?')->execute([$sessionId]);
}

/* ----------------------------------------------------- favorites ---- */

function favorite_ids_for_user(int $userId): array {
    $stmt = db()->prepare('SELECT product_id FROM favorites WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'product_id'));
}

/** How many customers have this product in their wishlist. */
function product_wish_count(int $productId): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM favorites WHERE product_id = ?');
    $stmt->execute([$productId]);
    return (int) $stmt->fetchColumn();
}

function favorite_toggle(int $userId, int $productId): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM favorites WHERE user_id = ? AND product_id = ?');
    $stmt->execute([$userId, $productId]);
    if ($row = $stmt->fetch()) {
        $pdo->prepare('DELETE FROM favorites WHERE id = ?')->execute([$row['id']]);
        return false; // now removed
    }
    $pdo->prepare('INSERT INTO favorites (user_id, product_id) VALUES (?,?)')->execute([$userId, $productId]);
    return true; // now added
}

/* --------------------------------------------------- image upload --- */

/**
 * Handles a single <input type=file> upload, validates it, and stores it
 * under /uploads/products. Returns the relative URL path or null.
 */
function handle_product_image_upload(string $fieldName): ?string {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code ' . $file['error']);
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Image is too large (max 5MB).');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Unsupported image type. Use JPG, PNG, WEBP or GIF.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $filename = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $dest = UPLOAD_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save uploaded image.');
    }
    return UPLOAD_URL . '/' . $filename;
}

/** Loosely validates that a URL points at YouTube (watch, youtu.be, or shorts links). */
function is_youtube_url(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $host = strtolower(preg_replace('/^www\./', '', $host));
    return in_array($host, ['youtube.com', 'youtu.be', 'm.youtube.com'], true);
}

function product_image_src(?string $path): string {
    if (!$path) {
        return '/assets/img/placeholder.svg';
    }
    return $path;
}

/* ------------------------------------------------------- settings --- */

/** All settings as a flat [key => value] array, cached for the request (and kept current by set_setting()). */
function all_settings(): array {
    if (!isset($GLOBALS['__settings_cache'])) {
        $load = function (): array {
            $out = [];
            foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
                $out[$row['setting_key']] = $row['setting_value'];
            }
            return $out;
        };
        $cache = $load();
        require_once __DIR__ . '/migrate.php';
        if ((int) ($cache['schema_version'] ?? 3) < MIGRATE_LATEST) {
            // First request after an upgrade: apply pending migrations.
            try {
                run_pending_migrations(db(), (int) ($cache['schema_version'] ?? 3));
                $cache = $load();
            } catch (Throwable $e) {
                error_log('[migrate] ' . $e->getMessage());
                $GLOBALS['__migration_error'] = $e->getMessage();
            }
        }
        $GLOBALS['__settings_cache'] = $cache;
        // A timezone adopted from the connected book (see the integration module) wins over the .env one.
        if (!empty($cache['timezone']) && in_array($cache['timezone'], timezone_identifiers_list(), true)) date_default_timezone_set($cache['timezone']);
    }
    return $GLOBALS['__settings_cache'];
}

function get_setting(string $key, ?string $default = null): ?string {
    $all = all_settings();
    return $all[$key] ?? $default;
}

function set_setting(string $key, string $value): void {
    db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
    if (isset($GLOBALS['__settings_cache'])) $GLOBALS['__settings_cache'][$key] = $value;
}

/** Setting if set (non-empty), otherwise the fallback (usually the env value). */
function setting_or(string $key, $fallback) {
    $v = get_setting($key, '');
    return ($v !== null && $v !== '') ? $v : $fallback;
}

const THEME_DEFAULTS = ['primary' => '#a97c34', 'secondary' => '#5f7d5b', 'dark' => '#20293b'];
const THEME_PAPER_LIGHT = '#efece2';
const THEME_PAPER_DARK = '#12161f';

/** Theme + seasonal-effect settings, with sane defaults if unset. */
function theme_settings(): array {
    $hex = fn (string $k, string $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) get_setting($k, $d)) ? strtolower(get_setting($k, $d)) : $d;
    return [
        'primary' => $hex('theme_primary', THEME_DEFAULTS['primary']),
        'secondary' => $hex('theme_secondary', THEME_DEFAULTS['secondary']),
        'dark' => $hex('theme_dark', THEME_DEFAULTS['dark']),
        'seasonal_enabled' => get_setting('seasonal_enabled', '0') === '1',
        'seasonal_effect' => get_setting('seasonal_effect', 'snow'),
    ];
}

/** Darken/lighten a #rrggbb hex color by a percentage (-100..100). */
function hex_shade(string $hex, float $percent): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '#' . str_pad($hex, 6, '0');
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $adjust = function (int $c) use ($percent): int {
        $target = $percent >= 0 ? 255 : 0;
        $c = (int) round($c + ($target - $c) * (abs($percent) / 100));
        return max(0, min(255, $c));
    };
    return sprintf('#%02x%02x%02x', $adjust($r), $adjust($g), $adjust($b));
}

/** WCAG relative luminance of a #rrggbb color. */
function color_luminance(string $hex): float {
    $hex = ltrim($hex, '#');
    $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    foreach ($c as &$v) { $v /= 255; $v = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function contrast_ratio(string $a, string $b): float {
    $la = color_luminance($a); $lb = color_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Text color (white or near-black) that reads best on the given background. */
function contrast_text(string $bg): string {
    return contrast_ratio($bg, '#ffffff') >= contrast_ratio($bg, '#111111') ? '#ffffff' : '#111111';
}

/** Nudges a color darker/lighter (toward what the background needs) until it reads at ≥ $min:1. */
function ensure_contrast(string $fg, string $bg, float $min = 4.5): string {
    $toward = color_luminance($bg) > 0.4 ? -1 : 1;
    for ($i = 0; $i < 40 && contrast_ratio($fg, $bg) < $min; $i++) {
        $fg = hex_shade($fg, $toward * 6);
    }
    return $fg;
}

/**
 * CSS custom properties for the admin-chosen theme. Emitted into <style> on
 * both storefront and admin so every button, accent and dark surface follows
 * the three theme colors, in light and dark mode alike.
 */
function theme_css(): string {
    $t = theme_settings();
    [$p, $s, $d] = [$t['primary'], $t['secondary'], $t['dark']];
    $light = fn (string $c) => ensure_contrast($c, THEME_PAPER_LIGHT);
    $dark = fn (string $c) => ensure_contrast($c, THEME_PAPER_DARK);
    return ":root{"
        . "--brass:$p;--brass-dark:" . hex_shade($p, -18) . ";--brass-tint:" . hex_shade($p, 55) . ";--on-brass:" . contrast_text($p) . ";"
        . "--accent-text:" . $light($p) . ";--sage:$s;--sage-text:" . $light($s) . ";"
        . "--surface-dark:$d;--on-dark:" . contrast_text($d) . ";}"
        . ":root[data-theme=\"dark\"]{--accent-text:" . $dark($p) . ";--sage-text:" . $dark($s) . ";}";
}

/** Announcement bar shown above the header: [enabled, text, link]. */
function topbar_settings(): array {
    $text = trim((string) get_setting('topbar_text', ''));
    return [
        'enabled' => get_setting('topbar_enabled', '0') === '1' && $text !== '',
        'text' => $text,
        'link' => trim((string) get_setting('topbar_link', '')),
        'raw_enabled' => get_setting('topbar_enabled', '0') === '1',
    ];
}

/** Effective SMTP configuration: admin-saved values win, env is the fallback. */
function smtp_settings(): array {
    return [
        'host' => (string) setting_or('smtp_host', SMTP_HOST),
        'port' => (int) setting_or('smtp_port', SMTP_PORT),
        'user' => (string) setting_or('smtp_user', SMTP_USER),
        'pass' => (string) setting_or('smtp_pass', SMTP_PASS),
        'secure' => get_setting('smtp_secure') !== null ? (string) get_setting('smtp_secure') : SMTP_SECURE, // tls | ssl | '' (none)
        // Sender defaults follow the store details, so renaming the store renames the sender too.
        'from_email' => (string) setting_or('smtp_from_email', env_val('SMTP_FROM_EMAIL') ?: (filter_var(setting_or('smtp_user', SMTP_USER), FILTER_VALIDATE_EMAIL) ?: store_info()['email'])),
        'from_name' => (string) setting_or('smtp_from_name', store_info()['name']),
    ];
}

/* --------------------------------------------------------------- tags -- */

/** Cleans a comma/newline separated tag string into a de-duplicated list. */
function parse_tags(?string $raw): array {
    $out = [];
    foreach (preg_split('/[,\n;]+/u', (string) $raw) as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        $t = mb_substr($t, 0, 40);
        if ($t === '') continue;
        $out[mb_strtolower($t)] ??= $t;
    }
    return array_slice(array_values($out), 0, 20);
}

function product_tags(array $product): array {
    return parse_tags($product['tags'] ?? '');
}

/* ------------------------------------------------------------- search -- */

function like_escape(string $s): string {
    return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $s);
}

/** Splits a query into up to 6 search words (each ≥1 char). */
function search_words(string $q): array {
    $words = preg_split('/[\s,]+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice(array_map(fn ($w) => mb_substr($w, 0, 40), $words), 0, 6);
}

/**
 * Partial-word product search. Every word the shopper typed has to appear
 * somewhere in the product (name, tags, SKU, descriptions, category, color /
 * size options) — but as a *fragment*, so "tita" finds "Titanium". Products
 * whose name/tags start a word with the fragment rank highest.
 *
 * @return array{where:string, where_params:array, rank:string, rank_params:array}
 */
function product_search_sql(string $q): array {
    $where = []; $wp = []; $rank = []; $rp = [];
    foreach (search_words($q) as $w) {
        $any = '%' . like_escape($w) . '%';
        $start = like_escape($w) . '%';
        $wordStart = '% ' . like_escape($w) . '%';
        $where[] = '(p.name LIKE ? ESCAPE \'|\' OR p.tags LIKE ? ESCAPE \'|\' OR p.sku LIKE ? ESCAPE \'|\' OR p.short_desc LIKE ? ESCAPE \'|\'
                    OR p.description LIKE ? ESCAPE \'|\' OR c.name LIKE ? ESCAPE \'|\' OR p.color LIKE ? ESCAPE \'|\'
                    OR EXISTS (SELECT 1 FROM product_variants sv WHERE sv.product_id = p.id AND sv.is_active = 1
                               AND (sv.color LIKE ? ESCAPE \'|\' OR sv.size LIKE ? ESCAPE \'|\')))';
        array_push($wp, $any, $any, $any, $any, $any, $any, $any, $any, $any);

        $rank[] = '(IF(p.name LIKE ? ESCAPE \'|\', 12, 0) + IF(p.name LIKE ? ESCAPE \'|\', 10, 0) + IF(p.name LIKE ? ESCAPE \'|\', 5, 0)
                  + IF(p.tags LIKE ? ESCAPE \'|\', 6, 0) + IF(p.sku LIKE ? ESCAPE \'|\', 5, 0)
                  + IF(p.short_desc LIKE ? ESCAPE \'|\', 2, 0) + IF(p.description LIKE ? ESCAPE \'|\', 1, 0) + IF(c.name LIKE ? ESCAPE \'|\', 2, 0))';
        array_push($rp, $start, $wordStart, $any, $any, $any, $any, $any, $any);
    }
    return [
        'where' => $where ? implode(' AND ', $where) : '1=1',
        'where_params' => $wp,
        'rank' => $rank ? implode(' + ', $rank) : '0',
        'rank_params' => $rp,
    ];
}

/* --------------------------------------------------------- variants ---- */

/** Extra SELECT columns product cards need to handle products that have variants. */
const PRODUCT_LIST_EXTRA = ', (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) AS variant_count,
    (SELECT COALESCE(SUM(pv.stock), 0) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) AS variant_stock,
    (SELECT COUNT(*) FROM favorites fw WHERE fw.product_id = p.id) AS wish_count,
    (SELECT COUNT(*) FROM product_reviews pr WHERE pr.product_id = p.id AND pr.status = \'published\') AS review_count,
    (SELECT AVG(pr2.rating) FROM product_reviews pr2 WHERE pr2.product_id = p.id AND pr2.status = \'published\') AS review_avg';

function product_variants_for(int $productId, bool $activeOnly = true): array {
    $sql = 'SELECT * FROM product_variants WHERE product_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id';
    $stmt = db()->prepare($sql);
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/**
 * Color and size options for a product: ['color' => [...], 'size' => [...]].
 * Names used by a variant but missing from product_options (e.g. data from
 * before options existed) are added as bare rows so nothing ever disappears.
 */
function product_options_for(int $productId): array {
    $stmt = db()->prepare('SELECT * FROM product_options WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$productId]);
    $out = ['color' => [], 'size' => []];
    foreach ($stmt->fetchAll() as $o) { $out[$o['kind']][$o['name']] = $o; }
    foreach (product_variants_for($productId, false) as $v) {
        foreach (['color', 'size'] as $kind) {
            $name = $v[$kind] ?? null;
            if ($name !== null && $name !== '' && !isset($out[$kind][$name])) {
                $out[$kind][$name] = ['id' => null, 'kind' => $kind, 'name' => $name, 'swatch' => null, 'image' => null,
                    'weight_grams' => null, 'height_mm' => null, 'width_mm' => null, 'depth_mm' => null];
            }
        }
    }
    return ['color' => array_values($out['color']), 'size' => array_values($out['size'])];
}

/** A product's customization choices (engraving, stitching…), in the admin's order. */
function product_customizations_for(int $productId, bool $activeOnly = true): array {
    $stmt = db()->prepare('SELECT * FROM product_customizations WHERE product_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id');
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/** Deletes an uploaded file, but only ever from inside the uploads folder. */
function delete_upload_file(?string $urlPath): void {
    if (!$urlPath || strpos($urlPath, UPLOAD_URL . '/') !== 0) return;
    $file = UPLOAD_DIR . '/' . basename($urlPath);
    if (is_file($file)) @unlink($file);
}

/**
 * Puts an order's items back into stock ($direction = +1, when it's cancelled)
 * or takes them out again ($direction = -1, when a cancelled order is revived).
 * Returns false — changing nothing — if there isn't enough stock to revive it.
 * Call inside a transaction.
 */
function order_stock_adjust(PDO $pdo, int $orderId, int $direction, string $origin = 'store'): bool {
    $items = $pdo->prepare('SELECT id, product_id, variant_id, quantity, is_preorder FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    // Units already put back on the shelf by a recorded return must not be put back (or taken out) a second time.
    $ret = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM order_return_items WHERE order_item_id = ? AND restock = 1');
    foreach ($items->fetchAll() as $it) {
        $table = $it['variant_id'] ? 'product_variants' : 'products';
        $rowId = $it['variant_id'] ?: $it['product_id'];
        if (!$rowId || !$it['product_id']) continue; // product was deleted since — nothing to adjust
        $ret->execute([$it['id']]);
        $qty = (int) $it['quantity'] - (int) $ret->fetchColumn();
        if ($qty < 1) continue;
        if ($direction > 0) {
            $pdo->prepare("UPDATE $table SET stock = stock + ? WHERE id = ?")->execute([$qty, $rowId]);
            if (function_exists('erp_stock_record')) erp_stock_record((int) $it['product_id'], $it['variant_id'] ? (int) $it['variant_id'] : null, $qty, 'sale_cancel', 'order', $orderId, $origin);
        } else {
            $st = $pdo->prepare("UPDATE $table SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $st->execute([$qty, $rowId, $qty]);
            if ($st->rowCount() < 1) return false;
            if (function_exists('erp_stock_record')) erp_stock_record((int) $it['product_id'], $it['variant_id'] ? (int) $it['variant_id'] : null, -$qty, 'sale', 'order', $orderId, $origin);
        }
        if ($it['variant_id'] && function_exists('stock_sync_product_total')) stock_sync_product_total((int) $it['product_id']);
    }
    return true;
}

/* --------------------------------------------------- order tracking - */

/**
 * Appends a status-history row (used at order creation and every admin status change).
 * $from is the status the order had before, and $admin (id + name) is who changed it —
 * both are recorded for the admin portal only; customers never see them.
 * @param array{id:int,name:string}|null $admin
 */
function order_status_add(int $orderId, string $status, ?string $note = null, ?string $from = null, ?array $admin = null): void {
    db()->prepare('INSERT INTO order_status_history (order_id, from_status, status, note, changed_by, changed_by_name) VALUES (?,?,?,?,?,?)')
        ->execute([$orderId, $from, $status, $note, $admin['id'] ?? null, isset($admin['name']) ? mb_substr((string) $admin['name'], 0, 120) : null]);
}

/**
 * An order's status timeline, oldest first. By default only the customer-safe columns are
 * returned; pass $forAdmin = true (admin portal only) to also get from_status / changed_by_name.
 */
function order_status_history(int $orderId, bool $forAdmin = false): array {
    $cols = $forAdmin ? 'id, order_id, from_status, status, note, changed_at, changed_by, changed_by_name' : 'id, order_id, status, note, changed_at';
    $stmt = db()->prepare("SELECT $cols FROM order_status_history WHERE order_id = ? ORDER BY changed_at ASC, id ASC");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/admin_log.php';
require_once __DIR__ . '/coupons.php';
require_once __DIR__ . '/reviews.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/erp/reconcile.php';
require_once __DIR__ . '/erp/invoices.php';

// The owner's time zone (Region & invoices) wins over the TZ default from .env.
try { $__tz = get_setting('timezone', ''); if ($__tz && in_array($__tz, timezone_identifiers_list(), true)) date_default_timezone_set($__tz); } catch (Throwable $e) { /* settings table not there yet */ }

// Maintenance mode (Admin → Store settings → Maintenance): shows the "Under Maintenance" page to visitors.
require_once __DIR__ . '/maintenance.php';
maintenance_gate();
