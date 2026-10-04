<?php
/**
 * Business operations that both the store's own screens and inbound sync use, so accounting side
 * effects (stock, payments, returns, status history) follow one code path. None of these emit
 * events by themselves except where stated: callers decide, so applying a change that came FROM
 * the book never echoes back (loop prevention).
 */
require_once __DIR__ . '/outbox.php';

/* ---------------------------------------------------------------- stock -- */

/** Keeps products.stock equal to the sum of the active variant stocks (products with variants only). */
function stock_sync_product_total(int $productId): void {
    $has = db()->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = ?');
    $has->execute([$productId]);
    if ((int) $has->fetchColumn() < 1) return;
    db()->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ? AND is_active = 1) WHERE id = ?')->execute([$productId, $productId]);
}

/** Append a row to the stock ledger (the quantity itself must already be changed). Returns the movement id. */
function erp_stock_record(int $productId, ?int $variantId, int $delta, string $reason, ?string $refType = null, ?int $refId = null, string $origin = 'store', ?string $note = null): int {
    if ($delta === 0) return 0;
    db()->prepare('INSERT INTO stock_movements (product_id, variant_id, delta, reason, ref_type, ref_id, note, origin) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$productId, $variantId, $delta, $reason, $refType, $refId, $note !== null ? mb_substr($note, 0, 255) : null, $origin]);
    return (int) db()->lastInsertId();
}

/** Change a stock quantity and record it. Not emitted; use erp_stock_adjust_emit() for admin-made adjustments. */
function erp_stock_change(int $productId, ?int $variantId, int $delta, string $reason, ?string $refType = null, ?int $refId = null, string $origin = 'store', ?string $note = null): int {
    if ($delta === 0) return 0;
    if ($variantId) {
        db()->prepare('UPDATE product_variants SET stock = stock + ? WHERE id = ? AND product_id = ?')->execute([$delta, $variantId, $productId]);
        stock_sync_product_total($productId);
    } else {
        db()->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$delta, $productId]);
    }
    return erp_stock_record($productId, $variantId, $delta, $reason, $refType, $refId, $origin, $note);
}

/** A manual quantity change made by an admin: recorded, then sent to the book as a delta (never an absolute overwrite). */
function erp_stock_adjust_emit(int $productId, ?int $variantId, int $delta, ?string $note = null): void {
    $id = erp_stock_record($productId, $variantId, $delta, 'manual_adjustment', 'admin', null, 'store', $note);
    if ($id && erp_linked()) erp_emit('stock_movement', $id, 'create');
}

/* ------------------------------------------------------------ customers -- */

/**
 * Finds (phone first, then email — D14) or creates the customer record for a checkout/registration.
 * Returns null when there is neither a phone nor an email to identify the person by.
 * Only maintained once the module has been switched on. Call inside the caller's transaction.
 */
function erp_customer_touch(string $name, ?string $email, ?string $phone, array $addr = [], ?int $userId = null): ?int {
    if (erp_status() === 'disabled') return null;
    $email = $email !== null ? strtolower(trim($email)) : null;
    $email = $email !== '' ? $email : null;
    $norm = erp_phone_norm($phone);
    if (!$norm && !$email) return null;
    $pdo = db();
    $row = null;
    if ($norm) { $st = $pdo->prepare('SELECT * FROM sync_customers WHERE phone_norm = ? ORDER BY id LIMIT 1'); $st->execute([$norm]); $row = $st->fetch() ?: null; }
    if (!$row && $email) { $st = $pdo->prepare('SELECT * FROM sync_customers WHERE email = ? ORDER BY id LIMIT 1'); $st->execute([$email]); $row = $st->fetch() ?: null; }
    if ($row) {
        $set = []; $vals = [];
        if ($userId && !$row['user_id']) { $set[] = 'user_id = ?'; $vals[] = $userId; }
        if ($email && !$row['email']) { $set[] = 'email = ?'; $vals[] = $email; }
        if ($norm && !$row['phone_norm']) { $set[] = 'phone = ?, phone_norm = ?'; $vals[] = $phone; $vals[] = $norm; }
        foreach (['line1', 'city', 'state', 'zip'] as $k) if (!empty($addr[$k]) && empty($row[$k])) { $set[] = "$k = ?"; $vals[] = $addr[$k]; }
        if ($set) { $vals[] = $row['id']; $pdo->prepare('UPDATE sync_customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals); }
        $id = (int) $row['id'];
    } else {
        $pdo->prepare('INSERT INTO sync_customers (user_id, name, email, phone, phone_norm, line1, city, state, zip, origin) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$userId, mb_substr($name, 0, 120), $email, $phone, $norm, $addr['line1'] ?? null, $addr['city'] ?? null, $addr['state'] ?? null, $addr['zip'] ?? null, 'store']);
        $id = (int) $pdo->lastInsertId();
    }
    if (erp_linked()) erp_emit('customer', $id, 'auto');
    return $id;
}

/** Existing users and past guest orders become customer records (used when a store is first linked). Returns how many were added. */
function erp_backfill_customers(): int {
    $n = 0; $pdo = db();
    foreach ($pdo->query("SELECT id, name, email, phone FROM users")->fetchAll() as $u) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM sync_customers')->fetchColumn();
        erp_customer_touch_quiet($u['name'], $u['email'], $u['phone'], [], (int) $u['id']);
        $n += (int) $pdo->query('SELECT COUNT(*) FROM sync_customers')->fetchColumn() - $before;
    }
    foreach ($pdo->query("SELECT shipping_name, customer_email, shipping_phone, shipping_line1, shipping_city, shipping_state, shipping_zip, user_id FROM orders ORDER BY id")->fetchAll() as $o) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM sync_customers')->fetchColumn();
        erp_customer_touch_quiet($o['shipping_name'], $o['customer_email'], $o['shipping_phone'], ['line1' => $o['shipping_line1'], 'city' => $o['shipping_city'], 'state' => $o['shipping_state'], 'zip' => $o['shipping_zip']], $o['user_id'] ? (int) $o['user_id'] : null);
        $n += (int) $pdo->query('SELECT COUNT(*) FROM sync_customers')->fetchColumn() - $before;
    }
    return $n;
}

/** Same as erp_customer_touch() but never emits (the initial review decides what gets sent). */
function erp_customer_touch_quiet(string $name, ?string $email, ?string $phone, array $addr = [], ?int $userId = null): ?int {
    $GLOBALS['erp_applying'] = ($GLOBALS['erp_applying'] ?? 0) + 1;
    try { return erp_customer_touch($name, $email, $phone, $addr, $userId); }
    finally { $GLOBALS['erp_applying']--; if ($GLOBALS['erp_applying'] < 1) unset($GLOBALS['erp_applying']); }
}

/** Run $fn with outbound emitting switched off (used while applying changes that came from the book). */
function erp_applying(callable $fn) {
    $GLOBALS['erp_applying'] = ($GLOBALS['erp_applying'] ?? 0) + 1;
    try { return $fn(); }
    finally { $GLOBALS['erp_applying']--; if ($GLOBALS['erp_applying'] < 1) unset($GLOBALS['erp_applying']); }
}

/* ------------------------------------------------------------- payments -- */

function erp_order_paid_total(int $orderId): float {
    $st = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = ? AND status = 'recorded'");
    $st->execute([$orderId]);
    return round((float) $st->fetchColumn(), 2);
}

/** unpaid | partial | paid */
function erp_order_payment_status(array $order): string {
    $paid = erp_order_paid_total((int) $order['id']);
    if ($paid <= 0) return 'unpaid';
    return $paid + 0.004 >= (float) $order['total'] ? 'paid' : 'partial';
}

/** A locked order (payments recorded or already delivered) keeps its money and line items: edits go to the conflict queue. */
function erp_order_locked(array $order): bool {
    return $order['status'] === 'completed' || erp_order_paid_total((int) $order['id']) > 0;
}

function erp_payment_record(int $orderId, ?int $methodId, float $amount, ?string $paidAtUtc = null, ?string $reference = null, ?string $note = null, ?string $by = null, string $origin = 'store'): int {
    $amount = round($amount, 2);
    if ($amount <= 0) throw new RuntimeException('The payment amount must be greater than zero.');
    $st = db()->prepare('SELECT id, total, status FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) throw new RuntimeException('Order not found.');
    if ($order['status'] === 'cancelled') throw new RuntimeException('A cancelled order cannot take payments.');
    $due = round((float) $order['total'] - erp_order_paid_total($orderId), 2);
    if ($amount > $due + 0.004) throw new RuntimeException('That is more than the ' . money($due) . ' still due on this order.');
    db()->prepare('INSERT INTO order_payments (order_id, method_id, amount, paid_at, reference, note, recorded_by, origin) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$orderId, $methodId, $amount, $paidAtUtc ?: gmdate('Y-m-d H:i:s'), $reference ? mb_substr($reference, 0, 120) : null, $note ? mb_substr($note, 0, 255) : null, $by, $origin]);
    return (int) db()->lastInsertId();
}

function erp_payment_void(int $paymentId): bool {
    $st = db()->prepare("UPDATE order_payments SET status = 'void' WHERE id = ? AND status = 'recorded'");
    $st->execute([$paymentId]);
    return $st->rowCount() > 0;
}

/* ---------------------------------------------------------- order state -- */

const ERP_STATUS_TO_FULFILMENT = ['pending' => 'placed', 'processing' => 'processing', 'shipped' => 'shipped', 'completed' => 'delivered', 'cancelled' => 'cancelled'];
const ERP_FULFILMENT_TO_STATUS = ['placed' => 'pending', 'processing' => 'processing', 'shipped' => 'shipped', 'delivered' => 'completed', 'cancelled' => 'cancelled'];

/**
 * Moves an order to a new status inside its own transaction: locks the row, puts stock back on
 * cancel (or takes it out again when a cancelled order is revived), voids recorded payments on
 * cancel, appends the status history. Returns the previous status, or null when it was already
 * at $new. Throws RuntimeException with a message that is safe to show to an admin.
 * Does NOT emit and does NOT send email; callers do (they know who the actor is).
 */
function erp_order_set_status(int $orderId, string $new, ?string $note, ?array $admin, string $origin = 'store'): ?string {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $from = $st->fetchColumn();
        if ($from === false) throw new RuntimeException('Order not found.');
        if ($from === $new) { if ($own) $pdo->rollBack(); return null; }
        $wasCancelled = $from === 'cancelled'; $nowCancelled = $new === 'cancelled';
        if ($nowCancelled && !$wasCancelled) {
            order_stock_adjust($pdo, $orderId, +1, $origin);
            $pdo->prepare("UPDATE order_payments SET status = 'void' WHERE order_id = ? AND status = 'recorded'")->execute([$orderId]);
        } elseif ($wasCancelled && !$nowCancelled && !order_stock_adjust($pdo, $orderId, -1, $origin)) {
            throw new RuntimeException('Not enough stock left to reactivate this order.');
        }
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$new, $orderId]);
        order_status_add($orderId, $new, $note, (string) $from, $admin);
        if ($own) $pdo->commit();
        return (string) $from;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* -------------------------------------------------------------- returns -- */

/**
 * Records a return (goods coming back / refund) against an order.
 * $items: [['order_item_id' => int, 'quantity' => int, 'restock' => bool], ...]
 * Restocked quantities go back on the shelf. Returns the new return id. Not emitted here.
 */
function erp_return_create(int $orderId, array $items, ?string $reason, float $refund, ?int $refundMethodId, ?string $by, string $origin = 'store', ?string $returnedAtUtc = null): int {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $o = $pdo->prepare('SELECT id, status, total FROM orders WHERE id = ? FOR UPDATE');
        $o->execute([$orderId]);
        $order = $o->fetch();
        if (!$order) throw new RuntimeException('Order not found.');
        if ($order['status'] === 'cancelled') throw new RuntimeException('A cancelled order has nothing to return — its items are already back in stock.');
        $lines = [];
        foreach ($items as $it) {
            $qty = (int) ($it['quantity'] ?? 0);
            if ($qty < 1) continue;
            $st = $pdo->prepare('SELECT * FROM order_items WHERE id = ? AND order_id = ?');
            $st->execute([(int) $it['order_item_id'], $orderId]);
            $line = $st->fetch();
            if (!$line) throw new RuntimeException('One of the returned items is not on this order.');
            $already = $pdo->prepare('SELECT COALESCE(SUM(ri.quantity),0) FROM order_return_items ri JOIN order_returns r ON r.id = ri.return_id WHERE ri.order_item_id = ?');
            $already->execute([$line['id']]);
            if ($qty + (int) $already->fetchColumn() > (int) $line['quantity']) throw new RuntimeException('You cannot return more of "' . $line['product_name'] . '" than was ordered.');
            $lines[] = [$line, $qty, !empty($it['restock'])];
        }
        if (!$lines) throw new RuntimeException('Choose at least one item to return.');
        $refund = round(max(0, $refund), 2);
        if ($refund > (float) $order['total'] + 0.004) throw new RuntimeException('The refund cannot be more than the order total.');
        $pdo->prepare('INSERT INTO order_returns (order_id, reason, refund_amount, refund_method_id, returned_at, created_by, origin) VALUES (?,?,?,?,?,?,?)')
            ->execute([$orderId, $reason ? mb_substr($reason, 0, 255) : null, $refund, $refundMethodId, $returnedAtUtc ?: gmdate('Y-m-d H:i:s'), $by, $origin]);
        $rid = (int) $pdo->lastInsertId();
        foreach ($lines as [$line, $qty, $restock]) {
            $pdo->prepare('INSERT INTO order_return_items (return_id, order_item_id, product_id, variant_id, quantity, restock) VALUES (?,?,?,?,?,?)')
                ->execute([$rid, $line['id'], $line['product_id'], $line['variant_id'], $qty, $restock ? 1 : 0]);
            if ($restock && $line['product_id'] && !$line['is_preorder']) erp_stock_change((int) $line['product_id'], $line['variant_id'] ? (int) $line['variant_id'] : null, $qty, 'return', 'return', $rid, $origin);
        }
        if ($own) $pdo->commit();
        return $rid;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ------------------------------------------------------------- settings -- */

function store_currency_symbol(): string { return (string) setting_or('currency_symbol', STORE_CURRENCY_SYMBOL); }
function store_currency_code(): string { return strtoupper((string) setting_or('currency_code', STORE_CURRENCY_CODE)); }

/** Shipping numbers: admin-edited (Delivery & tax) values win over the .env defaults. k: inside|suburbs|outside|free_kg|extra_kg */
function shipcfg(string $k): float {
    $defaults = ['inside' => SHIPPING_INSIDE_DHAKA_FEE, 'suburbs' => SHIPPING_SUBURBS_FEE, 'outside' => SHIPPING_OUTSIDE_DHAKA_FEE, 'free_kg' => SHIPPING_FREE_WEIGHT_KG, 'extra_kg' => SHIPPING_EXTRA_PER_KG];
    $v = get_setting('ship_' . $k, '');
    return ($v !== null && $v !== '' && is_numeric($v)) ? (float) $v : (float) $defaults[$k];
}

/** @return array{enabled:bool,rate:float,inclusive:bool,label:string} */
function tax_settings(): array {
    return ['enabled' => get_setting('tax_enabled', '0') === '1', 'rate' => max(0.0, min(100.0, (float) get_setting('tax_rate', '0'))),
        'inclusive' => get_setting('tax_inclusive', '0') === '1', 'label' => trim((string) get_setting('tax_label', '')) ?: 'Tax'];
}

/**
 * Tax on an amount (already net of discount, before delivery). Inclusive tax is a part OF the
 * price, exclusive tax is added on top. Returns [tax, addedToTotal].
 */
function tax_for(float $base, ?array $t = null): array {
    $t = $t ?? tax_settings();
    if (!$t['enabled'] || $t['rate'] <= 0 || $base <= 0) return [0.0, 0.0];
    if ($t['inclusive']) return [round($base - $base / (1 + $t['rate'] / 100), 2), 0.0];
    $tax = round($base * $t['rate'] / 100, 2);
    return [$tax, $tax];
}

/** Active payment methods for checkout, cash on delivery first. Never empty. */
function checkout_payment_methods(): array {
    $rows = db()->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY (kind = 'cod') DESC, sort_order, id")->fetchAll();
    if (!$rows) $rows = db()->query("SELECT * FROM payment_methods WHERE code = 'cod' LIMIT 1")->fetchAll();
    if (!$rows) $rows = [['id' => 0, 'code' => 'cod', 'name' => 'Cash on delivery', 'kind' => 'cod', 'instructions' => null]];
    return $rows;
}

/** A registered customer edited their profile: keep the linked customer record in step (and tell the book). */
function erp_customer_profile_updated(int $userId, string $name, ?string $phone): void {
    if (erp_status() === 'disabled') return;
    $st = db()->prepare('SELECT id FROM sync_customers WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $id = $st->fetchColumn();
    if (!$id) return;
    db()->prepare('UPDATE sync_customers SET name = ?, phone = ?, phone_norm = ? WHERE id = ?')->execute([mb_substr($name, 0, 120), $phone, erp_phone_norm($phone), $id]);
    if (erp_linked()) erp_emit('customer', (int) $id, 'auto');
}

/** Things in the integration that need a person: open conflicts, dead events, orders flagged as oversold. */
function erp_attention_count(): int {
    static $n = null;
    if ($n === null) {
        if (erp_status() === 'disabled') return $n = 0;
        try {
            $n = (int) db()->query("SELECT (SELECT COUNT(*) FROM sync_conflicts WHERE status = 'open') + (SELECT COUNT(*) FROM sync_outbox WHERE status = 'dead')")->fetchColumn();
        } catch (Throwable $e) { $n = 0; }
    }
    return $n;
}
