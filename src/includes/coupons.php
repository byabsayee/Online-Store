<?php
/**
 * Coupons: percentage or fixed-amount discount codes.
 *
 * Rules the whole store relies on:
 *  - A coupon discounts the ITEMS total only. Shipping is never discounted, and the discount can
 *    never exceed the items total (an order can't go negative).
 *  - The customer's browser is never trusted for money: the discount is recomputed on the server
 *    every time it matters — when the code is applied, when checkout is shown, and again inside
 *    the transaction that places the order (coupon_claim_for_order).
 *  - Usage is counted from real orders (cancelled orders don't count), so cancelling an order
 *    automatically gives the coupon use back — there is no counter to drift out of sync.
 *  - Dates are stored in UTC like every other timestamp in the database.
 */

const COUPON_SESSION_KEY = 'coupon_code';

/** Thrown when a coupon can't be used any more at the moment the order is placed. */
class CouponException extends RuntimeException {}

/** Uppercase, no spaces, only letters / digits / dash / underscore. Empty string if nothing usable is left. */
function coupon_normalize_code(string $code): string {
    $code = strtoupper(preg_replace('/\s+/', '', trim($code)));
    return preg_replace('/[^A-Z0-9_-]/', '', $code);
}

function coupon_find_by_code(string $code, bool $forUpdate = false): ?array {
    $code = coupon_normalize_code($code);
    if ($code === '') return null;
    $stmt = db()->prepare('SELECT * FROM coupons WHERE code = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function coupon_find(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM coupons WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** The discount this coupon gives on an items total (0.00 – subtotal). */
function coupon_discount_for(array $coupon, float $subtotal): float {
    if ($subtotal <= 0) return 0.0;
    $value = (float) $coupon['value'];
    if ($coupon['type'] === 'percent') {
        $d = round($subtotal * $value / 100, 2);
        if ($coupon['max_discount'] !== null && (float) $coupon['max_discount'] > 0) $d = min($d, (float) $coupon['max_discount']);
    } else {
        $d = $value;
    }
    return round(max(0.0, min($d, $subtotal)), 2);
}

/** "10% off" / "৳200 off" (+ "up to ৳500" for capped percentage coupons). */
function coupon_describe(array $c): string {
    if ($c['type'] === 'percent') {
        $v = rtrim(rtrim(number_format((float) $c['value'], 2, '.', ''), '0'), '.');
        $s = $v . '% off';
        if ($c['max_discount'] !== null && (float) $c['max_discount'] > 0) $s .= ' (up to ' . money((float) $c['max_discount']) . ')';
        return $s;
    }
    return money((float) $c['value']) . ' off';
}

/** Orders that used this coupon and are still standing (a cancelled order gives its use back). */
function coupon_usage_count(int $couponId): int {
    $s = db()->prepare("SELECT COUNT(*) FROM orders WHERE coupon_id = ? AND status <> 'cancelled'");
    $s->execute([$couponId]);
    return (int) $s->fetchColumn();
}

/**
 * How many times this shopper has used the coupon. A shopper is recognised by account, by the
 * email on the order, or by the phone number on the order — so a guest can't dodge a
 * once-per-customer coupon just by not logging in.
 * @param array{user_id?:?int,email?:?string,phone?:?string} $who
 */
function coupon_customer_usage(int $couponId, array $who): int {
    $or = []; $params = [$couponId];
    if (!empty($who['user_id'])) { $or[] = 'user_id = ?'; $params[] = (int) $who['user_id']; }
    if (!empty($who['email'])) { $or[] = 'LOWER(customer_email) = ?'; $params[] = strtolower(trim((string) $who['email'])); }
    if (!empty($who['phone'])) { $or[] = 'shipping_phone = ?'; $params[] = trim((string) $who['phone']); }
    if (!$or) return 0;
    $s = db()->prepare("SELECT COUNT(*) FROM orders WHERE coupon_id = ? AND status <> 'cancelled' AND (" . implode(' OR ', $or) . ')');
    $s->execute($params);
    return (int) $s->fetchColumn();
}

/** UTC "Y-m-d H:i:s" string from the database → unix time (null when unset). */
function coupon_ts(?string $utc): ?int {
    if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) return null;
    $t = strtotime($utc . ' UTC');
    return $t === false ? null : $t;
}

/**
 * Where a coupon stands right now: [key, label]. Keys: disabled | scheduled | expired | used_up | active.
 * Used by the admin list, and by coupon_validate() so both always agree.
 */
function coupon_state(array $c): array {
    $now = time();
    if (!(int) $c['is_active']) return ['disabled', 'Switched off'];
    $start = coupon_ts($c['starts_at'] ?? null);
    $end = coupon_ts($c['expires_at'] ?? null);
    if ($start !== null && $now < $start) return ['scheduled', 'Scheduled'];
    if ($end !== null && $now >= $end) return ['expired', 'Expired'];
    if ($c['usage_limit'] !== null && (int) $c['usage_limit'] > 0 && (isset($c['_used']) ? (int) $c['_used'] : coupon_usage_count((int) $c['id'])) >= (int) $c['usage_limit']) return ['used_up', 'Used up'];
    return ['active', 'Active'];
}

/**
 * Can this coupon be used on an items total of $subtotal by this shopper?
 * @param array{user_id?:?int,email?:?string,phone?:?string} $who what we know about the shopper (may be partial while browsing)
 * @return array{ok:bool, message:string, discount:float}
 */
function coupon_validate(?array $coupon, float $subtotal, array $who = []): array {
    $fail = fn (string $m) => ['ok' => false, 'message' => $m, 'discount' => 0.0];
    if (!$coupon) return $fail('That coupon code isn\'t valid.');
    [$state] = coupon_state($coupon);
    if ($state === 'disabled') return $fail('That coupon code isn\'t valid.');
    if ($state === 'scheduled') return $fail('That coupon isn\'t active yet.');
    if ($state === 'expired') return $fail('That coupon has expired.');
    if ($state === 'used_up') return $fail('That coupon has reached its usage limit.');
    if ((float) $coupon['min_subtotal'] > 0 && $subtotal < (float) $coupon['min_subtotal']) {
        return $fail('This coupon needs an order of at least ' . money((float) $coupon['min_subtotal']) . ' (your items come to ' . money($subtotal) . ').');
    }
    if ($coupon['per_customer_limit'] !== null && (int) $coupon['per_customer_limit'] > 0
        && coupon_customer_usage((int) $coupon['id'], $who) >= (int) $coupon['per_customer_limit']) {
        return $fail((int) $coupon['per_customer_limit'] === 1 ? 'You\'ve already used this coupon.' : 'You\'ve reached the limit for this coupon.');
    }
    $discount = coupon_discount_for($coupon, $subtotal);
    if ($discount <= 0) return $fail('That coupon doesn\'t apply to this order.');
    return ['ok' => true, 'message' => 'Coupon applied.', 'discount' => $discount];
}

/** What we know about the current shopper, for the once-per-customer check. */
function coupon_shopper(?array $user = null, ?string $email = null, ?string $phone = null): array {
    return ['user_id' => $user['id'] ?? null, 'email' => $email ?: ($user['email'] ?? null), 'phone' => $phone];
}

/** The coupon code saved for this visit (normalised), or ''. */
function coupon_session_code(): string {
    return coupon_normalize_code((string) ($_SESSION[COUPON_SESSION_KEY] ?? ''));
}

function coupon_session_clear(): void {
    unset($_SESSION[COUPON_SESSION_KEY]);
}

/**
 * The coupon saved for this visit, re-checked against the current cart. Returns
 * ['coupon' => row, 'discount' => float] when it still applies, or null. A code that has stopped
 * working (expired, cart shrank below the minimum, …) is dropped, and $dropReason says why.
 */
function coupon_session_current(float $subtotal, array $who = [], ?string &$dropReason = null): ?array {
    $code = coupon_session_code();
    if ($code === '') return null;
    $coupon = coupon_find_by_code($code);
    $v = coupon_validate($coupon, $subtotal, $who);
    if (!$v['ok']) {
        coupon_session_clear();
        $dropReason = 'Coupon ' . $code . ' was removed: ' . lcfirst($v['message']);
        return null;
    }
    return ['coupon' => $coupon, 'discount' => $v['discount']];
}

/**
 * Final, race-safe check used inside the checkout transaction. Locks the coupon row so two
 * shoppers can't both take the last use, re-validates with everything now known about the buyer,
 * and returns the coupon and the discount to store on the order.
 *
 * @throws RuntimeException with a customer-friendly message when the coupon can no longer be used
 * @return array{0:array,1:float}
 */
function coupon_claim_for_order(PDO $pdo, string $code, float $subtotal, array $who): array {
    $stmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ? FOR UPDATE');
    $stmt->execute([coupon_normalize_code($code)]);
    $coupon = $stmt->fetch() ?: null;
    $v = coupon_validate($coupon, $subtotal, $who);
    if (!$v['ok']) throw new CouponException('Coupon ' . coupon_normalize_code($code) . ' could not be used: ' . lcfirst($v['message']) . ' It has been removed from your order — please check the total and place the order again.');
    return [$coupon, $v['discount']];
}

/** Converts a "2026-09-21T14:30" value typed in the store's timezone into a UTC database datetime (null for blank/invalid). */
function coupon_local_to_utc(?string $local): ?string {
    $local = trim((string) $local);
    if ($local === '') return null;
    try {
        $d = new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable $e) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** UTC database datetime → the "Y-m-d\TH:i" a datetime-local input wants, in the store's timezone. */
function coupon_utc_to_local_input(?string $utc): string {
    $d = db_time($utc);
    return $d ? $d->format('Y-m-d\TH:i') : '';
}
