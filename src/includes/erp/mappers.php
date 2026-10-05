<?php
/**
 * Entity mappers. Each class knows how to
 *   build($localId)      -> the shared field set for a local row (null when the row is gone),
 *   dependencies($id)    -> parents that must exist at the book first,
 *   apply(...)           -> apply an inbound change from the book to the local tables.
 *
 * Field ownership (who wins when both sides could edit): see docs/INTEGRATION.md §Field ownership.
 * Money is always a 2-decimal string. Timestamps are UTC ISO-8601.
 */
require_once __DIR__ . '/hooks.php';

/** Rejects an inbound event with a stable code. $retry tells the sender the same event may succeed later. */
class ErpReject extends RuntimeException {
    public string $errCode; public bool $retry;
    public function __construct(string $code, string $message, bool $retry = false) { parent::__construct($message); $this->errCode = $code; $this->retry = $retry; }
}
/** Sends an inbound event to the conflict queue instead of applying it. */
class ErpConflictResult extends RuntimeException {
    public string $kind; public $local; public $remote;
    public function __construct(string $kind, string $note, $local = null, $remote = null) { parent::__construct($note); $this->kind = $kind; $this->local = $local; $this->remote = $remote; }
}

abstract class ErpMap {
    public static function dependencies(int $localId): array { return []; }
    public static function cancelFields(): array { return ['is_active' => false]; }
    public static function voidFields(): array { return ['is_active' => false]; }
    /** Which fields the book may change on our side (everything else in an inbound payload is ignored). */
    public static function accepts(): array { return []; }
    protected static function pick(array $f, array $keys): array { return array_intersect_key($f, array_flip($keys)); }
    protected static function bool($v): int { return in_array($v, [true, 1, '1', 'true'], true) ? 1 : 0; }
    protected static function decimal($v): float { if (!is_numeric($v)) throw new ErpReject('invalid_payload', 'A money value is not a number.'); return round((float) $v, 2); }
    protected static function str($v, int $max): ?string { $v = $v === null ? null : trim((string) $v); return ($v === null || $v === '') ? null : mb_substr($v, 0, $max); }
    protected static function req(array $f, string $k): void { if (!array_key_exists($k, $f) || $f[$k] === null || $f[$k] === '') throw new ErpReject('invalid_payload', "Missing required field '$k'."); }
    protected static function uniqueSlug(string $table, string $base, int $exceptId = 0): string {
        $slug = slugify($base); $n = 1; $orig = $slug;
        while (true) {
            $st = db()->prepare("SELECT id FROM `$table` WHERE slug = ? AND id != ?");
            $st->execute([$slug, $exceptId]);
            if (!$st->fetch()) return $slug;
            $slug = $orig . '-' . (++$n);
        }
    }
    abstract public static function build(int $localId): ?array;
    abstract public static function apply(string $op, ?int $localId, array $f, array $ctx): ?int;
}

/* ------------------------------------------------------------ category -- */

class ErpMapCategory extends ErpMap {
    public static function accepts(): array { return ['name', 'description', 'sort_order', 'is_active', 'parent_uuid']; }
    public static function dependencies(int $id): array {
        $st = db()->prepare('SELECT parent_id FROM categories WHERE id = ?'); $st->execute([$id]);
        $p = $st->fetchColumn(); return $p ? [['category', (int) $p]] : [];
    }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM categories WHERE id = ?'); $st->execute([$id]);
        $c = $st->fetch(); if (!$c) return null;
        return ['name' => $c['name'], 'slug' => $c['slug'], 'description' => $c['description'], 'sort_order' => (int) $c['sort_order'],
            'is_active' => (bool) $c['is_active'], 'parent_uuid' => $c['parent_id'] ? erp_uuid_for('category', (int) $c['parent_id']) : null];
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $pdo = db();
        if ($op === 'archive' || $op === 'restore') { if ($id) $pdo->prepare('UPDATE categories SET is_active = ? WHERE id = ?')->execute([$op === 'restore' ? 1 : 0, $id]); return $id; }
        $f = self::pick($f, self::accepts());
        $parent = null;
        if (array_key_exists('parent_uuid', $f)) {
            if ($f['parent_uuid'] !== null) { $parent = erp_local_for('category', $f['parent_uuid']); if (!$parent) throw new ErpReject('dependency_missing', 'The parent category is not known yet.', true); }
            if ($parent && $id && (int) $parent === $id) throw new ErpReject('invalid_payload', 'A category cannot be its own parent.');
        }
        if (!$id) {
            self::req($f, 'name');
            $pdo->prepare('INSERT INTO categories (parent_id, name, slug, description, sort_order, is_active) VALUES (?,?,?,?,?,?)')
                ->execute([$parent, self::str($f['name'], 100), self::uniqueSlug('categories', (string) $f['name']), self::str($f['description'] ?? null, 5000), (int) ($f['sort_order'] ?? 0), self::bool($f['is_active'] ?? true)]);
            return (int) $pdo->lastInsertId();
        }
        $set = []; $v = [];
        if (isset($f['name'])) { $set[] = 'name = ?'; $v[] = self::str($f['name'], 100) ?? 'Category'; }
        if (array_key_exists('description', $f)) { $set[] = 'description = ?'; $v[] = self::str($f['description'], 5000); }
        if (isset($f['sort_order'])) { $set[] = 'sort_order = ?'; $v[] = (int) $f['sort_order']; }
        if (isset($f['is_active'])) { $set[] = 'is_active = ?'; $v[] = self::bool($f['is_active']); }
        if (array_key_exists('parent_uuid', $f)) { $set[] = 'parent_id = ?'; $v[] = $parent; }
        if ($set) { $v[] = $id; $pdo->prepare('UPDATE categories SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($v); }
        return $id;
    }
}

/* ------------------------------------------------------------- variant -- */
/** Variants only need a stable shared id (orders and stock refer to them); they travel inside their product. */
class ErpMapVariant extends ErpMap {
    public static function build(int $id): ?array { return null; }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int { return $id; }
}

/* ------------------------------------------------------------- product -- */

class ErpMapProduct extends ErpMap {
    /** Shared with the book. Store-only (slug, descriptions, photos, dimensions, tags, warranty, pre-order) are never overwritten from the book. */
    public static function accepts(): array { return ['name', 'sku', 'price', 'compare_price', 'weight_grams', 'is_active', 'category_uuid']; }
    public static function dependencies(int $id): array {
        $st = db()->prepare('SELECT category_id FROM products WHERE id = ?'); $st->execute([$id]);
        $c = $st->fetchColumn(); return $c ? [['category', (int) $c]] : [];
    }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM products WHERE id = ?'); $st->execute([$id]);
        $p = $st->fetch(); if (!$p) return null;
        $variants = [];
        foreach (product_variants_for($id, false) as $v) {
            $variants[] = ['variant_uuid' => erp_uuid_for('variant', (int) $v['id']), 'color' => $v['color'], 'size' => $v['size'], 'sku' => $v['sku'],
                'price_delta' => erp_money($v['price_delta']), 'is_active' => (bool) $v['is_active']];
        }
        return ['sku' => $p['sku'], 'name' => $p['name'], 'slug' => $p['slug'], 'short_desc' => $p['short_desc'], 'description' => $p['description'],
            'price' => erp_money($p['price']), 'compare_price' => $p['compare_price'] !== null ? erp_money($p['compare_price']) : null, 'weight_grams' => (int) $p['weight_grams'],
            'is_active' => (bool) $p['is_active'] && empty($p['archived_at']), 'category_uuid' => $p['category_id'] ? erp_uuid_for('category', (int) $p['category_id']) : null,
            'variants' => $variants];
    }
    /** Opening quantities travel once, on create, so the book can record an opening balance (never sent again: later changes are movements). */
    public static function extra(int $id): array {
        $st = db()->prepare('SELECT stock FROM products WHERE id = ?'); $st->execute([$id]);
        $vs = [];
        foreach (product_variants_for($id, false) as $v) $vs[erp_uuid_for('variant', (int) $v['id'])] = (int) $v['stock'];
        return ['opening_stock' => ['product' => (int) $st->fetchColumn(), 'variants' => (object) $vs]];
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $pdo = db();
        if ($op === 'archive') { if ($id) $pdo->prepare('UPDATE products SET is_active = 0, archived_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]); return $id; }
        if ($op === 'restore') { if ($id) $pdo->prepare('UPDATE products SET is_active = 1, archived_at = NULL WHERE id = ?')->execute([$id]); return $id; }
        $f = self::pick($f, self::accepts());
        $cat = null;
        if (array_key_exists('category_uuid', $f)) {
            if ($f['category_uuid'] !== null) { $cat = erp_local_for('category', $f['category_uuid']); if (!$cat) throw new ErpReject('dependency_missing', 'The product category is not known yet.', true); }
        }
        if (isset($f['sku']) && $f['sku'] !== null && $f['sku'] !== '') {
            $dup = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND id != ?'); $dup->execute([$f['sku'], $id ?: 0]);
            if ($dup->fetch()) throw new ErpConflictResult('other', 'Another local product already uses the SKU ' . $f['sku'] . '.', null, $f);
        }
        if (!$id) {
            self::req($f, 'name'); self::req($f, 'price');
            $pdo->prepare('INSERT INTO products (category_id, name, slug, sku, price, compare_price, weight_grams, stock, is_active) VALUES (?,?,?,?,?,?,?,0,?)')
                ->execute([$cat, self::str($f['name'], 180), self::uniqueSlug('products', (string) $f['name']), self::str($f['sku'] ?? null, 60), self::decimal($f['price']),
                    isset($f['compare_price']) ? self::decimal($f['compare_price']) : null, max(1, (int) ($f['weight_grams'] ?? 300)), self::bool($f['is_active'] ?? true)]);
            $id = (int) $pdo->lastInsertId();
            $os = $ctx['event']['payload']['opening_stock']['product'] ?? null;
            if (is_numeric($os) && (int) $os > 0) erp_stock_change($id, null, (int) $os, 'purchase', 'sync', null, 'book', 'Opening balance from the book');
            return $id;
        }
        $set = []; $v = [];
        foreach (['name' => 180, 'sku' => 60] as $k => $max) if (array_key_exists($k, $f)) { $set[] = "$k = ?"; $v[] = self::str($f[$k], $max) ?? ($k === 'name' ? 'Product' : null); }
        if (isset($f['price'])) { $set[] = 'price = ?'; $v[] = self::decimal($f['price']); }
        if (array_key_exists('compare_price', $f)) { $set[] = 'compare_price = ?'; $v[] = $f['compare_price'] === null ? null : self::decimal($f['compare_price']); }
        if (isset($f['weight_grams'])) { $set[] = 'weight_grams = ?'; $v[] = max(1, (int) $f['weight_grams']); }
        if (isset($f['is_active'])) { $set[] = 'is_active = ?'; $v[] = self::bool($f['is_active']); }
        if (array_key_exists('category_uuid', $f)) { $set[] = 'category_id = ?'; $v[] = $cat; }
        if ($set) { $v[] = $id; $pdo->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($v); }
        return $id;
    }
}

/* ------------------------------------------------------------ customer -- */

class ErpMapCustomer extends ErpMap {
    public static function accepts(): array { return ['name', 'email', 'phone', 'line1', 'city', 'state', 'zip', 'is_archived']; }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM sync_customers WHERE id = ?'); $st->execute([$id]);
        $c = $st->fetch(); if (!$c) return null;
        return ['name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'line1' => $c['line1'], 'city' => $c['city'], 'state' => $c['state'], 'zip' => $c['zip'], 'is_archived' => (bool) $c['is_archived']];
    }
    public static function cancelFields(): array { return ['is_archived' => true]; }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $pdo = db();
        if ($op === 'archive' || $op === 'restore') { if ($id) $pdo->prepare('UPDATE sync_customers SET is_archived = ? WHERE id = ?')->execute([$op === 'archive' ? 1 : 0, $id]); return $id; }
        $f = self::pick($f, self::accepts());
        if (!$id) {
            // D14: a phone/email that already belongs to a local customer is never merged automatically.
            $norm = erp_phone_norm($f['phone'] ?? null); $email = isset($f['email']) ? strtolower(trim((string) $f['email'])) : null;
            $match = null;
            if ($norm) { $st = $pdo->prepare('SELECT * FROM sync_customers WHERE phone_norm = ? LIMIT 1'); $st->execute([$norm]); $match = $st->fetch() ?: null; }
            if (!$match && $email) { $st = $pdo->prepare('SELECT * FROM sync_customers WHERE email = ? LIMIT 1'); $st->execute([$email]); $match = $st->fetch() ?: null; }
            if ($match && empty($ctx['force_new'])) throw new ErpConflictResult('customer_match', 'A customer with the same phone/email already exists here — confirm whether they are the same person.', ['id' => (int) $match['id'], 'name' => $match['name'], 'email' => $match['email'], 'phone' => $match['phone']], $f);
            self::req($f, 'name');
            $pdo->prepare('INSERT INTO sync_customers (name, email, phone, phone_norm, line1, city, state, zip, origin) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([self::str($f['name'], 120), $email ?: null, self::str($f['phone'] ?? null, 30), $norm, self::str($f['line1'] ?? null, 200), self::str($f['city'] ?? null, 100), self::str($f['state'] ?? null, 100), self::str($f['zip'] ?? null, 20), 'book']);
            return (int) $pdo->lastInsertId();
        }
        $set = []; $v = [];
        if (isset($f['name'])) { $set[] = 'name = ?'; $v[] = self::str($f['name'], 120) ?? 'Customer'; }
        if (array_key_exists('email', $f)) { $set[] = 'email = ?'; $v[] = $f['email'] ? strtolower(trim((string) $f['email'])) : null; }
        if (array_key_exists('phone', $f)) { $set[] = 'phone = ?, phone_norm = ?'; $v[] = self::str($f['phone'], 30); $v[] = erp_phone_norm($f['phone']); }
        foreach (['line1' => 200, 'city' => 100, 'state' => 100, 'zip' => 20] as $k => $max) if (array_key_exists($k, $f)) { $set[] = "$k = ?"; $v[] = self::str($f[$k], $max); }
        if (isset($f['is_archived'])) { $set[] = 'is_archived = ?'; $v[] = self::bool($f['is_archived']); }
        if ($set) { $v[] = $id; $pdo->prepare('UPDATE sync_customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($v); }
        return $id;
    }
}

/* ------------------------------------------------------ payment method -- */

class ErpMapPaymentMethod extends ErpMap {
    public static function accepts(): array { return ['name', 'is_active', 'sort_order', 'fund_name']; }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM payment_methods WHERE id = ?'); $st->execute([$id]);
        $m = $st->fetch(); if (!$m) return null;
        return ['code' => $m['code'], 'name' => $m['name'], 'kind' => $m['kind'], 'is_active' => (bool) $m['is_active'], 'sort_order' => (int) $m['sort_order']];
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $pdo = db();
        if ($op === 'archive' || $op === 'restore') { if ($id) $pdo->prepare('UPDATE payment_methods SET is_active = ? WHERE id = ?')->execute([$op === 'restore' ? 1 : 0, $id]); return $id; }
        if (!$id) {
            self::req($f, 'name'); self::req($f, 'code');
            $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $f['code'])) ?: 'method';
            $dup = $pdo->prepare('SELECT id FROM payment_methods WHERE code = ?'); $dup->execute([$code]);
            if ($row = $dup->fetch()) return (int) $row['id']; // same code = same method: link instead of duplicating
            $kind = in_array($f['kind'] ?? '', ['cod', 'manual', 'gateway'], true) ? $f['kind'] : 'manual';
            $pdo->prepare('INSERT INTO payment_methods (code, name, kind, fund_name, is_active, sort_order) VALUES (?,?,?,?,?,?)')
                ->execute([$code, self::str($f['name'], 80), $kind === 'gateway' ? 'manual' : $kind, self::str($f['fund_name'] ?? null, 120), self::bool($f['is_active'] ?? true), (int) ($f['sort_order'] ?? 0)]);
            return (int) $pdo->lastInsertId();
        }
        $f = self::pick($f, self::accepts());
        $set = []; $v = [];
        if (isset($f['name'])) { $set[] = 'name = ?'; $v[] = self::str($f['name'], 80) ?? 'Payment'; }
        if (array_key_exists('fund_name', $f)) { $set[] = 'fund_name = ?'; $v[] = self::str($f['fund_name'], 120); }
        if (isset($f['is_active'])) { $set[] = 'is_active = ?'; $v[] = self::bool($f['is_active']); }
        if (isset($f['sort_order'])) { $set[] = 'sort_order = ?'; $v[] = (int) $f['sort_order']; }
        if ($set) { $v[] = $id; $pdo->prepare('UPDATE payment_methods SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($v); }
        return $id;
    }
}

/* -------------------------------------------------------------- coupon -- */

class ErpMapCoupon extends ErpMap {
    public static function accepts(): array { return ['code', 'type', 'value', 'max_discount', 'min_subtotal', 'starts_at', 'expires_at', 'usage_limit', 'per_customer_limit', 'is_active', 'note']; }
    public static function build(int $id): ?array {
        $c = coupon_find($id); if (!$c) return null;
        return ['code' => $c['code'], 'type' => $c['type'], 'value' => erp_money($c['value']), 'max_discount' => $c['max_discount'] !== null ? erp_money($c['max_discount']) : null,
            'min_subtotal' => erp_money($c['min_subtotal']), 'starts_at' => erp_iso_from_db($c['starts_at']), 'expires_at' => erp_iso_from_db($c['expires_at']),
            'usage_limit' => $c['usage_limit'] !== null ? (int) $c['usage_limit'] : null, 'per_customer_limit' => $c['per_customer_limit'] !== null ? (int) $c['per_customer_limit'] : null,
            'is_active' => (bool) $c['is_active'], 'note' => $c['note']];
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $pdo = db();
        if ($op === 'archive' || $op === 'restore') { if ($id) $pdo->prepare('UPDATE coupons SET is_active = ? WHERE id = ?')->execute([$op === 'restore' ? 1 : 0, $id]); return $id; }
        $f = self::pick($f, self::accepts());
        if (isset($f['type']) && !in_array($f['type'], ['percent', 'fixed'], true)) throw new ErpReject('invalid_payload', 'Coupon type must be percent or fixed.');
        if (isset($f['code'])) {
            $f['code'] = coupon_normalize_code((string) $f['code']);
            $dup = $pdo->prepare('SELECT id FROM coupons WHERE code = ? AND id != ?'); $dup->execute([$f['code'], $id ?: 0]);
            if ($row = $dup->fetch()) { if (!$id) return (int) $row['id']; throw new ErpConflictResult('other', 'Another coupon here already uses the code ' . $f['code'] . '.', null, $f); }
        }
        $cols = ['code' => fn ($v) => self::str($v, 40), 'type' => fn ($v) => $v, 'value' => fn ($v) => self::decimal($v),
            'max_discount' => fn ($v) => $v === null ? null : self::decimal($v), 'min_subtotal' => fn ($v) => $v === null ? 0 : self::decimal($v),
            'starts_at' => fn ($v) => erp_db_from_iso($v), 'expires_at' => fn ($v) => erp_db_from_iso($v),
            'usage_limit' => fn ($v) => $v === null ? null : max(0, (int) $v), 'per_customer_limit' => fn ($v) => $v === null ? null : max(0, (int) $v),
            'is_active' => fn ($v) => self::bool($v), 'note' => fn ($v) => self::str($v, 255)];
        if (!$id) {
            self::req($f, 'code'); self::req($f, 'type'); self::req($f, 'value');
            $names = []; $vals = [];
            foreach ($cols as $k => $fn) if (array_key_exists($k, $f)) { $names[] = $k; $vals[] = $fn($f[$k]); }
            $pdo->prepare('INSERT INTO coupons (' . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')')->execute($vals);
            return (int) $pdo->lastInsertId();
        }
        $set = []; $vals = [];
        foreach ($cols as $k => $fn) if (array_key_exists($k, $f)) { $set[] = "$k = ?"; $vals[] = $fn($f[$k]); }
        if ($set) { $vals[] = $id; $pdo->prepare('UPDATE coupons SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals); }
        return $id;
    }
}

/* ----------------------------------------------------------------- tax -- */
/** One shared tax setting. Its uuid is derived from the connection id so both sides agree without a handshake. */
class ErpMapTax extends ErpMap {
    public static function uuid(): string { return erp_uuid5(erp_connection_id() ?: '00000000-0000-4000-8000-000000000000', 'tax'); }
    public static function uuidFor(int $id): ?string { return $id === 1 ? self::uuid() : null; }
    public static function build(int $id): ?array {
        $t = tax_settings();
        return ['enabled' => $t['enabled'], 'rate' => number_format($t['rate'], 3, '.', ''), 'inclusive' => $t['inclusive'], 'label' => $t['label']];
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        if (isset($f['enabled'])) set_setting('tax_enabled', self::bool($f['enabled']) ? '1' : '0');
        if (isset($f['rate'])) { if (!is_numeric($f['rate']) || $f['rate'] < 0 || $f['rate'] > 100) throw new ErpReject('invalid_payload', 'Tax rate must be between 0 and 100.'); set_setting('tax_rate', (string) round((float) $f['rate'], 3)); }
        if (isset($f['inclusive'])) set_setting('tax_inclusive', self::bool($f['inclusive']) ? '1' : '0');
        if (isset($f['label'])) set_setting('tax_label', (string) self::str($f['label'], 40));
        return 1;
    }
}

/* ------------------------------------------------------------ delivery -- */

class ErpMapDelivery extends ErpMap {
    const ZONES = [1 => 'inside_dhaka', 2 => 'suburbs', 3 => 'outside_dhaka'];
    const KEYS = ['inside_dhaka' => 'inside', 'suburbs' => 'suburbs', 'outside_dhaka' => 'outside'];
    public static function uuid(int $i): string { return erp_uuid5(erp_connection_id() ?: '00000000-0000-4000-8000-000000000000', 'delivery:' . self::ZONES[$i]); }
    public static function uuidFor(int $id): ?string { return isset(self::ZONES[$id]) ? self::uuid($id) : null; }
    public static function build(int $id): ?array {
        $z = self::ZONES[$id] ?? null; if (!$z) return null;
        return ['zone' => $z, 'label' => delivery_area_label($z), 'base_fee' => erp_money(shipcfg(self::KEYS[$z])), 'free_weight_kg' => erp_money(shipcfg('free_kg')), 'extra_per_kg' => erp_money(shipcfg('extra_kg'))];
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $z = self::ZONES[$id] ?? null; if (!$z) throw new ErpReject('unknown_entity', 'Unknown delivery zone.');
        foreach (['base_fee' => 'ship_' . self::KEYS[$z], 'free_weight_kg' => 'ship_free_kg', 'extra_per_kg' => 'ship_extra_kg'] as $k => $setting) {
            if (!isset($f[$k])) continue;
            if (!is_numeric($f[$k]) || $f[$k] < 0) throw new ErpReject('invalid_payload', "$k must be a non-negative number.");
            set_setting($setting, (string) round((float) $f[$k], 2));
        }
        return $id;
    }
}

/* --------------------------------------------------------------- stock -- */

class ErpMapStock extends ErpMap {
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM stock_movements WHERE id = ?'); $st->execute([$id]);
        $m = $st->fetch(); if (!$m) return null;
        return ['product_uuid' => erp_uuid_for('product', (int) $m['product_id']), 'variant_uuid' => $m['variant_id'] ? erp_uuid_for('variant', (int) $m['variant_id']) : null,
            'delta' => (int) $m['delta'], 'reason' => $m['reason'], 'note' => $m['note']];
    }
    public static function dependencies(int $id): array {
        $st = db()->prepare('SELECT product_id FROM stock_movements WHERE id = ?'); $st->execute([$id]);
        $p = $st->fetchColumn(); return $p ? [['product', (int) $p]] : [];
    }
    public static function cancelFields(): array { return []; }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        if ($id) return $id; // a movement never changes once recorded
        self::req($f, 'product_uuid'); self::req($f, 'delta');
        $reason = (string) ($f['reason'] ?? 'manual_adjustment');
        if (!in_array($reason, ['sale', 'sale_cancel', 'return', 'manual_adjustment', 'purchase', 'reconciliation_adjustment'], true)) throw new ErpReject('invalid_payload', 'Unknown stock reason.');
        $pid = erp_local_for('product', $f['product_uuid']);
        if (!$pid) throw new ErpReject('dependency_missing', 'The product is not known yet.', true);
        $vid = null;
        if (!empty($f['variant_uuid'])) { $vid = erp_local_for('variant', $f['variant_uuid']); if (!$vid) throw new ErpReject('dependency_missing', 'The variant is not known yet.', true); }
        $delta = (int) $f['delta'];
        if ($delta === 0) throw new ErpReject('invalid_payload', 'A stock movement needs a non-zero delta.');
        $tbl = $vid ? 'product_variants' : 'products'; $rowId = $vid ?: $pid;
        $cur = db()->prepare("SELECT stock FROM $tbl WHERE id = ? FOR UPDATE"); $cur->execute([$rowId]);
        $have = $cur->fetchColumn();
        if ($have === false) throw new ErpReject('unknown_entity', 'The stock row no longer exists.');
        if ($delta < 0 && $reason === 'sale' && (int) $have + $delta < 0) throw new ErpReject('insufficient_stock', 'Only ' . (int) $have . ' in stock here.');
        return erp_stock_change($pid, $vid, $delta, $reason, 'sync', null, 'book', self::str($f['note'] ?? null, 255));
    }
}

/* --------------------------------------------------------------- order -- */

class ErpMapOrder extends ErpMap {
    public static function cancelFields(): array { return ['fulfilment_status' => 'cancelled']; }
    public static function dependencies(int $id): array {
        $deps = [];
        $st = db()->prepare('SELECT product_id FROM order_items WHERE order_id = ? AND product_id IS NOT NULL'); $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $deps[] = ['product', (int) $p];
        $o = db()->prepare('SELECT customer_sync_id, payment_method_id, coupon_id FROM orders WHERE id = ?'); $o->execute([$id]);
        if ($r = $o->fetch()) {
            if ($r['customer_sync_id']) $deps[] = ['customer', (int) $r['customer_sync_id']];
            if ($r['payment_method_id']) $deps[] = ['payment_method', (int) $r['payment_method_id']];
            if ($r['coupon_id']) $deps[] = ['coupon', (int) $r['coupon_id']];
        }
        return $deps;
    }
    private static function addr(array $o, string $p): array {
        return ['name' => $o[$p . '_name'], 'phone' => $o[$p . '_phone'], 'line1' => $o[$p . '_line1'], 'city' => $o[$p . '_city'], 'state' => $o[$p . '_state'], 'zip' => $o[$p . '_zip']];
    }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$id]);
        $o = $st->fetch(); if (!$o) return null;
        $items = [];
        $it = db()->prepare('SELECT oi.*, p.sku AS p_sku FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id');
        $it->execute([$id]);
        foreach ($it->fetchAll() as $i) {
            $sku = $i['p_sku'];
            if ($i['variant_id']) { $vs = db()->prepare('SELECT sku FROM product_variants WHERE id = ?'); $vs->execute([$i['variant_id']]); $vsku = $vs->fetchColumn(); if ($vsku) $sku = $vsku; }
            $items[] = ['line' => (int) $i['id'], 'product_uuid' => $i['product_id'] ? erp_uuid_for('product', (int) $i['product_id']) : null,
                'variant_uuid' => $i['variant_id'] ? erp_uuid_for('variant', (int) $i['variant_id']) : null, 'sku' => $sku, 'name' => $i['product_name'], 'variant_label' => $i['variant_label'],
                'price' => erp_money($i['price']), 'quantity' => (int) $i['quantity'], 'subtotal' => erp_money($i['subtotal']), 'is_preorder' => (bool) $i['is_preorder']];
        }
        $pm = $o['payment_method_id'] ? erp_uuid_for('payment_method', (int) $o['payment_method_id']) : null;
        return ['number' => $o['order_number'], 'source' => $o['source'], 'fulfilment_status' => ERP_STATUS_TO_FULFILMENT[$o['status']] ?? 'placed',
            'customer_uuid' => $o['customer_sync_id'] ? erp_uuid_for('customer', (int) $o['customer_sync_id']) : null,
            'contact' => ['name' => $o['shipping_name'], 'phone' => $o['shipping_phone'], 'email' => $o['customer_email']],
            'shipping' => self::addr($o, 'shipping'), 'billing' => $o['billing_same_as_shipping'] ? null : self::addr($o, 'billing'),
            'currency' => store_currency_code(), 'subtotal' => erp_money($o['subtotal']), 'discount' => erp_money($o['discount']), 'tax' => erp_money($o['tax']), 'tax_inclusive' => (bool) $o['tax_inclusive'],
            'delivery_charge' => erp_money($o['shipping_fee']), 'total' => erp_money($o['total']), 'coupon_code' => $o['coupon_code'],
            'coupon_uuid' => $o['coupon_id'] ? erp_uuid_for('coupon', (int) $o['coupon_id']) : null, 'delivery_area' => $o['delivery_area'], 'payment_method_uuid' => $pm,
            'notes' => $o['notes'], 'placed_at' => erp_iso_from_db($o['created_at']), 'items' => $items];
    }

    /** Marks an order "needs attention" (e.g. oversold) so it stands out in the admin. */
    public static function flag_attention(string $orderUuid, string $kind, string $note): void {
        $id = erp_local_for('order', $orderUuid); if (!$id) return;
        db()->prepare('UPDATE orders SET attention = ?, attention_note = ? WHERE id = ?')->execute([$kind, mb_substr($note, 0, 255), $id]);
        erp_conflict_add('oversold', 'order', $orderUuid, $id, null, null, null, $note);
    }

    private static function statusFrom($v): string {
        $s = ERP_FULFILMENT_TO_STATUS[(string) $v] ?? null;
        if (!$s) throw new ErpReject('invalid_payload', 'Unknown fulfilment_status.');
        return $s;
    }

    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $pdo = db();
        if ($op === 'cancel' || $op === 'void') {
            if (!$id) return null;
            try { $from = erp_order_set_status($id, 'cancelled', 'Cancelled from the book', null, 'book'); } catch (RuntimeException $e) { throw new ErpReject('invalid_state', $e->getMessage()); }
            if ($from !== null) self::notify($id, 'cancelled');
            return $id;
        }
        if (!$id) return self::create($f, $ctx);

        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE'); $st->execute([$id]);
        $o = $st->fetch(); if (!$o) throw new ErpReject('unknown_entity', 'The order no longer exists.');
        $locked = erp_order_locked($o);
        $moneyKeys = ['items', 'subtotal', 'discount', 'tax', 'tax_inclusive', 'delivery_charge', 'total', 'coupon_code', 'coupon_uuid'];
        $blocked = array_intersect_key($f, array_flip($moneyKeys));
        if ($blocked && $locked) {
            erp_conflict_add('locked', 'order', $ctx['uuid'], $id, $ctx['event']['event_id'] ?? null, self::build($id), $blocked, 'The book changed the amounts or items of an order that already has payments or is delivered. Not applied — review it.');
        } elseif ($blocked) {
            self::applyMoney($id, $o, $f);
        }
        $set = []; $v = [];
        if (isset($f['shipping']) && is_array($f['shipping'])) {
            foreach (['name' => 120, 'phone' => 30, 'line1' => 200, 'city' => 100, 'state' => 100, 'zip' => 20] as $k => $max) if (array_key_exists($k, $f['shipping'])) { $set[] = "shipping_$k = ?"; $v[] = self::str($f['shipping'][$k], $max) ?? ($k === 'state' || $k === 'zip' ? null : ''); }
        }
        if (isset($f['contact']['email'])) { $set[] = 'customer_email = ?'; $v[] = self::str($f['contact']['email'], 160); }
        if (array_key_exists('notes', $f)) { $set[] = 'notes = ?'; $v[] = self::str($f['notes'], 255); }
        if ($set) { $v[] = $id; $pdo->prepare('UPDATE orders SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($v); }
        if (isset($f['fulfilment_status'])) {
            $new = self::statusFrom($f['fulfilment_status']);
            try { $from = erp_order_set_status($id, $new, 'Updated from the book', null, 'book'); } catch (RuntimeException $e) { throw new ErpReject('invalid_state', $e->getMessage()); }
            if ($from !== null) self::notify($id, $new);
        }
        return $id;
    }

    private static function notify(int $id, string $status): void {
        $st = db()->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$id]);
        $o = $st->fetch();
        if ($o && PHP_SAPI !== 'cli') { require_once __DIR__ . '/../order_mail.php'; defer_job(fn () => send_order_status_email($o, $status, null)); }
    }

    /** Validates that the numbers add up to the paisa — otherwise nothing is applied (totals integrity rule). */
    public static function checkTotals(array $f, ?array $orderFallback = null): array {
        $get = fn ($k) => array_key_exists($k, $f) ? $f[$k] : ($orderFallback[$k] ?? null);
        $items = $f['items'] ?? null;
        $sum = 0.0;
        if (is_array($items)) {
            foreach ($items as $i) {
                if (!isset($i['price'], $i['quantity']) || (int) $i['quantity'] < 1) throw new ErpReject('invalid_payload', 'An order line is missing price or quantity.');
                $line = round((float) $i['price'] * (int) $i['quantity'], 2);
                if (isset($i['subtotal']) && abs((float) $i['subtotal'] - $line) > 0.004) throw new ErpConflictResult('totals', 'A line subtotal does not equal price × quantity.', null, $f);
                $sum += $line;
            }
        }
        $subtotal = round((float) $get('subtotal'), 2); $discount = round((float) ($get('discount') ?? 0), 2); $tax = round((float) ($get('tax') ?? 0), 2);
        $delivery = round((float) ($get('delivery_charge') ?? 0), 2); $total = round((float) $get('total'), 2); $incl = !empty($get('tax_inclusive'));
        if (is_array($items) && abs($sum - $subtotal) > 0.004) throw new ErpConflictResult('totals', 'Line items add up to ' . erp_money($sum) . ' but the subtotal says ' . erp_money($subtotal) . '.', null, $f);
        $expected = round($subtotal - $discount + ($incl ? 0 : $tax) + $delivery, 2);
        if (abs($expected - $total) > 0.004) throw new ErpConflictResult('totals', 'Subtotal, discount, tax and delivery give ' . erp_money($expected) . ' but the total says ' . erp_money($total) . '. Totals must match to the paisa.', null, $f);
        return [$subtotal, $discount, $tax, $delivery, $total, $incl];
    }

    private static function resolveLines(array $items): array {
        $out = [];
        foreach ($items as $i) {
            $pid = !empty($i['product_uuid']) ? erp_local_for('product', $i['product_uuid']) : null;
            if (!$pid && !empty($i['product_uuid'])) throw new ErpReject('dependency_missing', 'A product on this order is not known here yet.', true);
            $vid = !empty($i['variant_uuid']) ? erp_local_for('variant', $i['variant_uuid']) : null;
            if (!empty($i['variant_uuid']) && !$vid) throw new ErpReject('dependency_missing', 'A variant on this order is not known here yet.', true);
            $out[] = [$pid, $vid, $i];
        }
        return $out;
    }

    private static function applyMoney(int $id, array $o, array $f): void {
        $pdo = db();
        [$subtotal, $discount, $tax, $delivery, $total, $incl] = self::checkTotals(array_merge(self::build($id) ?? [], $f));
        $set = 'subtotal = ?, discount = ?, tax = ?, tax_inclusive = ?, shipping_fee = ?, total = ?'; $v = [$subtotal, $discount, $tax, $incl ? 1 : 0, $delivery, $total];
        if (array_key_exists('coupon_code', $f)) { $set .= ', coupon_code = ?, coupon_id = ?'; $v[] = self::str($f['coupon_code'], 40); $cid = !empty($f['coupon_uuid']) ? erp_local_for('coupon', $f['coupon_uuid']) : null; $v[] = $cid; }
        $v[] = $id;
        if (isset($f['items']) && is_array($f['items'])) {
            $lines = self::resolveLines($f['items']);
            if ($o['status'] !== 'cancelled') order_stock_adjust($pdo, $id, +1, 'book');
            $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
            self::insertLines($id, $lines);
            if ($o['status'] !== 'cancelled') self::deduct($id, 'book');
        }
        $pdo->prepare("UPDATE orders SET $set WHERE id = ?")->execute($v);
    }

    private static function insertLines(int $orderId, array $lines): void {
        $ins = db()->prepare('INSERT INTO order_items (order_id, product_id, variant_id, variant_label, product_name, price, quantity, subtotal, warranty_days, is_preorder) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($lines as [$pid, $vid, $i]) {
            $w = null;
            if ($pid) { $ws = db()->prepare('SELECT warranty_days FROM products WHERE id = ?'); $ws->execute([$pid]); $w = $ws->fetchColumn() ?: null; }
            $ins->execute([$orderId, $pid, $vid, self::str($i['variant_label'] ?? null, 150), self::str($i['name'] ?? 'Item', 180) ?? 'Item', round((float) $i['price'], 2), (int) $i['quantity'], round((float) $i['price'] * (int) $i['quantity'], 2), $w, !empty($i['is_preorder']) ? 1 : 0]);
        }
    }

    /** Take the order's items out of stock (locally recorded, never emitted). Rejects when stock is short. */
    private static function deduct(int $orderId, string $origin): void {
        if (!order_stock_adjust(db(), $orderId, -1, $origin)) throw new ErpReject('insufficient_stock', 'Not enough stock here for one of the items.');
    }

    private static function create(array $f, array $ctx): int {
        $pdo = db();
        foreach (['number', 'total', 'subtotal', 'items'] as $k) self::req($f, $k);
        if (!is_array($f['items']) || !$f['items']) throw new ErpReject('invalid_payload', 'An order needs at least one item.');
        $sh = $f['shipping'] ?? ($f['contact'] ?? []);
        $name = self::str($sh['name'] ?? ($f['contact']['name'] ?? null), 120); $phone = self::str($sh['phone'] ?? ($f['contact']['phone'] ?? null), 30);
        if (!$name || !$phone) throw new ErpReject('invalid_payload', 'The order needs a recipient name and phone.');
        [$subtotal, $discount, $tax, $delivery, $total, $incl] = self::checkTotals($f);
        $number = self::str($f['number'], 30);
        $dup = $pdo->prepare('SELECT id FROM orders WHERE order_number = ?'); $dup->execute([$number]);
        if ($dup->fetch()) throw new ErpReject('duplicate_number', 'An order numbered ' . $number . ' already exists here.');
        $lines = self::resolveLines($f['items']);
        $custId = null;
        if (!empty($f['customer_uuid'])) $custId = erp_local_for('customer', $f['customer_uuid']);
        $pm = !empty($f['payment_method_uuid']) ? erp_local_for('payment_method', $f['payment_method_uuid']) : null;
        $pmKind = 'cod';
        if ($pm) { $k = $pdo->prepare('SELECT kind FROM payment_methods WHERE id = ?'); $k->execute([$pm]); $pmKind = $k->fetchColumn() === 'cod' ? 'cod' : 'bank_transfer'; }
        $area = in_array($f['delivery_area'] ?? '', ['inside_dhaka', 'suburbs', 'outside_dhaka'], true) ? $f['delivery_area'] : 'inside_dhaka';
        $status = isset($f['fulfilment_status']) ? self::statusFrom($f['fulfilment_status']) : 'pending';
        $bill = is_array($f['billing'] ?? null) ? $f['billing'] : null;
        $cid = !empty($f['coupon_uuid']) ? erp_local_for('coupon', $f['coupon_uuid']) : null;
        $email = self::str($f['contact']['email'] ?? null, 160);
        $imp = $ctx['event']['payload']['import'] ?? ($f['import'] ?? null);
        $imported = !empty($imp);
        $placed = erp_db_from_iso($f['placed_at'] ?? null) ?: gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO orders (order_number, user_id, status, payment_method, payment_method_id, delivery_area, subtotal, discount, tax, tax_inclusive, coupon_id, coupon_code, shipping_fee, total,
                shipping_name, shipping_phone, customer_email, shipping_line1, shipping_city, shipping_state, shipping_zip, billing_same_as_shipping, billing_name, billing_phone, billing_line1, billing_city, billing_state, billing_zip,
                notes, created_at, source, customer_sync_id, import_batch) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$number, null, 'pending', $pmKind, $pm, $area, $subtotal, $discount, $tax, $incl ? 1 : 0, $cid, self::str($f['coupon_code'] ?? null, 40), $delivery, $total,
                $name, $phone, $email, self::str($sh['line1'] ?? null, 200) ?? '—', self::str($sh['city'] ?? null, 100) ?? '—', self::str($sh['state'] ?? null, 100), self::str($sh['zip'] ?? null, 20),
                $bill ? 0 : 1, $bill ? self::str($bill['name'] ?? null, 120) : null, $bill ? self::str($bill['phone'] ?? null, 30) : null, $bill ? self::str($bill['line1'] ?? null, 200) : null,
                $bill ? self::str($bill['city'] ?? null, 100) : null, $bill ? self::str($bill['state'] ?? null, 100) : null, $bill ? self::str($bill['zip'] ?? null, 20) : null,
                self::str($f['notes'] ?? null, 255), $placed, 'book', $custId, $imported ? (int) ($imp['batch'] ?? 0) ?: null : null]);
        $id = (int) $pdo->lastInsertId();
        // An order that was created in the book carries the book's invoice number as its number.
        if (($f['source'] ?? '') === 'book') $pdo->prepare('UPDATE orders SET book_invoice_no = ? WHERE id = ?')->execute([$number, $id]);
        self::insertLines($id, $lines);
        order_status_add($id, 'pending', $imported ? 'Imported from the book' : 'Placed at the book');
        // Imported history never touches current stock (unless the owner picks opening balance elsewhere).
        if (!$imported && $status !== 'cancelled') self::deduct($id, 'book');
        if ($status !== 'pending') {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
            order_status_add($id, $status, 'Status at the book', 'pending');
        }
        return $id;
    }
}

/* ------------------------------------------------------------- payment -- */

class ErpMapPayment extends ErpMap {
    public static function voidFields(): array { return ['status' => 'void']; }
    public static function dependencies(int $id): array {
        $st = db()->prepare('SELECT order_id, method_id FROM order_payments WHERE id = ?'); $st->execute([$id]);
        $r = $st->fetch(); if (!$r) return [];
        return array_filter([['order', (int) $r['order_id']], $r['method_id'] ? ['payment_method', (int) $r['method_id']] : null]);
    }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM order_payments WHERE id = ?'); $st->execute([$id]);
        $p = $st->fetch(); if (!$p) return null;
        return ['order_uuid' => erp_uuid_for('order', (int) $p['order_id']), 'method_uuid' => $p['method_id'] ? erp_uuid_for('payment_method', (int) $p['method_id']) : null,
            'amount' => erp_money($p['amount']), 'paid_at' => erp_iso_from_db($p['paid_at']), 'reference' => $p['reference'], 'note' => $p['note'], 'status' => $p['status']];
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        if ($op === 'void' || ($id && ($f['status'] ?? '') === 'void')) { if ($id) erp_payment_void($id); return $id; }
        if ($id) return $id; // recorded payments are never edited; corrections are void + new payment
        self::req($f, 'order_uuid'); self::req($f, 'amount');
        $oid = erp_local_for('order', $f['order_uuid']);
        if (!$oid) throw new ErpReject('dependency_missing', 'The order for this payment is not known yet.', true);
        $mid = !empty($f['method_uuid']) ? erp_local_for('payment_method', $f['method_uuid']) : null;
        try { return erp_payment_record($oid, $mid, self::decimal($f['amount']), erp_db_from_iso($f['paid_at'] ?? null), self::str($f['reference'] ?? null, 120), self::str($f['note'] ?? null, 255), 'Book', 'book'); }
        catch (RuntimeException $e) { throw new ErpConflictResult('locked', $e->getMessage(), null, $f); }
    }
}

/* -------------------------------------------------------------- return -- */

class ErpMapReturn extends ErpMap {
    public static function dependencies(int $id): array {
        $st = db()->prepare('SELECT order_id, refund_method_id FROM order_returns WHERE id = ?'); $st->execute([$id]);
        $r = $st->fetch(); if (!$r) return [];
        return array_filter([['order', (int) $r['order_id']], $r['refund_method_id'] ? ['payment_method', (int) $r['refund_method_id']] : null]);
    }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT * FROM order_returns WHERE id = ?'); $st->execute([$id]);
        $r = $st->fetch(); if (!$r) return null;
        $items = [];
        $it = db()->prepare('SELECT * FROM order_return_items WHERE return_id = ? ORDER BY id'); $it->execute([$id]);
        foreach ($it->fetchAll() as $i) $items[] = ['line' => (int) $i['order_item_id'], 'product_uuid' => $i['product_id'] ? erp_uuid_for('product', (int) $i['product_id']) : null,
            'variant_uuid' => $i['variant_id'] ? erp_uuid_for('variant', (int) $i['variant_id']) : null, 'quantity' => (int) $i['quantity'], 'restock' => (bool) $i['restock']];
        return ['order_uuid' => erp_uuid_for('order', (int) $r['order_id']), 'reason' => $r['reason'], 'refund_amount' => erp_money($r['refund_amount']),
            'refund_method_uuid' => $r['refund_method_id'] ? erp_uuid_for('payment_method', (int) $r['refund_method_id']) : null, 'returned_at' => erp_iso_from_db($r['returned_at']), 'items' => $items];
    }
    public static function cancelFields(): array { return []; }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        if ($id) return $id; // a recorded return is final
        self::req($f, 'order_uuid'); self::req($f, 'items');
        $oid = erp_local_for('order', $f['order_uuid']);
        if (!$oid) throw new ErpReject('dependency_missing', 'The order for this return is not known yet.', true);
        $items = [];
        foreach ((array) $f['items'] as $i) {
            $pid = !empty($i['product_uuid']) ? erp_local_for('product', $i['product_uuid']) : null;
            $vid = !empty($i['variant_uuid']) ? erp_local_for('variant', $i['variant_uuid']) : null;
            $q = db()->prepare('SELECT id FROM order_items WHERE order_id = ? AND product_id <=> ? AND variant_id <=> ? ORDER BY id LIMIT 1');
            $q->execute([$oid, $pid, $vid]);
            $line = $q->fetchColumn();
            if (!$line) throw new ErpConflictResult('other', 'A returned item is not on the order here.', null, $f);
            $items[] = ['order_item_id' => (int) $line, 'quantity' => (int) ($i['quantity'] ?? 0), 'restock' => !empty($i['restock'])];
        }
        $mid = !empty($f['refund_method_uuid']) ? erp_local_for('payment_method', $f['refund_method_uuid']) : null;
        try { return erp_return_create($oid, $items, self::str($f['reason'] ?? null, 255), (float) ($f['refund_amount'] ?? 0), $mid, 'Book', 'book', erp_db_from_iso($f['returned_at'] ?? null)); }
        catch (RuntimeException $e) { throw new ErpConflictResult('other', $e->getMessage(), null, $f); }
    }
}

/* -------------------------------------------------------------- staff --- */

/**
 * Staff accounts (table admins) <-> the book's employees. Profile only: name, email, phone, address, active.
 * Passwords, roles, ID numbers, photos and documents never travel. A person the book announces who has no account
 * here gets one that is DISABLED with an unknown random password: an owner enables it and sets a password
 * (Admin > Staff) when that person should really sign in. Owners are never created, demoted or disabled by sync.
 */
class ErpMapStaff extends ErpMap {
    public static function accepts(): array { return ['name', 'email', 'phone', 'address', 'is_active']; }
    public static function cancelFields(): array { return ['is_active' => false]; }
    public static function build(int $id): ?array {
        $st = db()->prepare('SELECT name, email, phone, address, status FROM admins WHERE id = ?'); $st->execute([$id]);
        $a = $st->fetch(); if (!$a) return null;
        return ['name' => $a['name'], 'email' => $a['email'] ? strtolower(trim((string) $a['email'])) : null, 'phone' => $a['phone'], 'address' => $a['address'], 'is_active' => $a['status'] === 'active'];
    }
    /** The same person already has an account here? Email first, then phone (digits compared, so formatting does not matter). */
    public static function findMatch(?string $phone, ?string $email): ?array {
        $pdo = db();
        if ($email) { $st = $pdo->prepare('SELECT * FROM admins WHERE LOWER(email) = ? LIMIT 1'); $st->execute([strtolower(trim($email))]); if ($r = $st->fetch()) return $r; }
        $norm = erp_phone_norm($phone);
        if ($norm) foreach ($pdo->query("SELECT * FROM admins WHERE phone IS NOT NULL AND phone <> ''")->fetchAll() as $r) if (erp_phone_norm($r['phone']) === $norm) return $r;
        return null;
    }
    private static function newUsername(string $email, string $name): string {
        $base = substr((string) slugify($email !== '' ? (string) strstr($email, '@', true) : $name), 0, 40);
        if ($base === '') $base = 'staff';
        $pdo = db(); $u = $base; $n = 1;
        while (true) { $st = $pdo->prepare('SELECT 1 FROM admins WHERE username = ?'); $st->execute([$u]); if (!$st->fetchColumn()) return $u; $u = $base . '-' . (++$n); }
    }
    public static function apply(string $op, ?int $id, array $f, array $ctx): ?int {
        $pdo = db();
        if ($op === 'archive' || $op === 'restore') {
            if ($id) $pdo->prepare("UPDATE admins SET status = ? WHERE id = ? AND role <> 'owner'")->execute([$op === 'archive' ? 'disabled' : 'active', $id]);
            return $id;
        }
        $f = self::pick($f, self::accepts());
        if (!$id) {
            $email = isset($f['email']) && $f['email'] !== '' ? strtolower(trim((string) $f['email'])) : null;
            if ($m = self::findMatch($f['phone'] ?? null, $email)) { $id = (int) $m['id']; }   // same person: link, never duplicate
            else {
                self::req($f, 'name');
                $pdo->prepare("INSERT INTO admins (username, name, password_hash, role, phone, email, address, status, must_change_password) VALUES (?,?,?,'staff',?,?,?,'disabled',1)")
                    ->execute([self::newUsername((string) $email, (string) $f['name']), self::str($f['name'], 120), password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                        self::str($f['phone'] ?? null, 40), $email, self::str($f['address'] ?? null, 500)]);
                return (int) $pdo->lastInsertId();
            }
        }
        $set = []; $v = [];
        if (isset($f['name'])) { $set[] = 'name = ?'; $v[] = self::str($f['name'], 120) ?? 'Staff'; }
        if (array_key_exists('email', $f)) {
            $email = $f['email'] ? strtolower(trim((string) $f['email'])) : null;
            $dup = $pdo->prepare('SELECT 1 FROM admins WHERE LOWER(email) = ? AND id <> ?'); $dup->execute([(string) $email, $id]);
            if ($email === null || !$dup->fetchColumn()) { $set[] = 'email = ?'; $v[] = $email; }   // never break the unique email rule
        }
        if (array_key_exists('phone', $f)) { $set[] = 'phone = ?'; $v[] = self::str($f['phone'], 40); }
        if (array_key_exists('address', $f)) { $set[] = 'address = ?'; $v[] = self::str($f['address'], 500); }
        if (array_key_exists('is_active', $f)) { $set[] = "status = IF(role = 'owner', status, ?)"; $v[] = self::bool($f['is_active']) ? 'active' : 'disabled'; }
        if ($set) { $v[] = $id; $pdo->prepare('UPDATE admins SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($v); }
        return $id;
    }
}
