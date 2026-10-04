<?php
/**
 * Which invoice the store shows its customers.
 *
 *   'store' — the store's own invoice (ID = the order number, e.g. ORD-260928-AB12C).
 *   'book'   — the invoice the connected Byabsayee book generated for the order (ID = the book's invoice number, e.g. INV-000123).
 *
 * The owner chooses under Admin → Accounting link. Default: the book's invoice while the store is linked, the store's own otherwise.
 * The choice only changes what THIS website shows (order pages, invoice PDF, emails); it never changes the book.
 * Per order, if the book's invoice is not available (not synced yet, book unreachable, older book), the store's own invoice is
 * used instead — a customer never meets an error page.
 */
require_once __DIR__ . '/core.php';

/** The owner's explicit choice ('store' | 'book'), or '' when they never chose (= default). */
function invoice_source_pref(): string {
    $v = (string) get_setting('invoice_source', '');
    return in_array($v, ['store', 'book'], true) ? $v : '';
}

/** What customers get right now. */
function invoice_source(): string {
    if (!function_exists('erp_linked') || !erp_linked()) return 'store';
    return invoice_source_pref() === 'store' ? 'store' : 'book';
}

function order_book_invoice_no(array $o): ?string {
    $n = trim((string) ($o['book_invoice_no'] ?? ''));
    return $n !== '' ? $n : null;
}

/** The "Invoice ID" shown for an order under the chosen source (falls back to the order number). */
function order_invoice_id(array $o, ?string $src = null): string {
    $src = $src ?? invoice_source();
    if ($src === 'book' && ($n = order_book_invoice_no($o))) return $n;
    return (string) $o['order_number'];
}

/** True when the ID shown differs from the order number (so pages can print the order number as a second, smaller reference). */
function order_invoice_differs(array $o): bool {
    return order_invoice_id($o) !== (string) $o['order_number'];
}

/** Remember the book's invoice for an order (from an event answer or the lookup endpoint). */
function erp_order_save_invoice(?string $orderUuid, array $inv): void {
    $id = erp_local_for('order', $orderUuid);
    if (!$id) return;
    $no = mb_substr(trim((string) ($inv['invoice_no'] ?? '')), 0, 60);
    if ($no === '') return;
    $path = null;
    if (!empty($inv['book_id']) && !empty($inv['invoice_id'])) {
        $p = '/books/' . (int) $inv['book_id'] . '/invoices/' . (int) $inv['invoice_id'];
        if (preg_match('#^/books/\d+/invoices/\d+$#', $p)) $path = $p;
    }
    db()->prepare('UPDATE orders SET book_invoice_no = ?, book_invoice_path = COALESCE(?, book_invoice_path), book_invoice_checked_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$no, $path, $id]);
}

/** Link to the invoice inside the book's own screens (for staff), or null. */
function order_book_invoice_admin_url(array $o): ?string {
    $path = (string) ($o['book_invoice_path'] ?? '');
    $base = rtrim((string) (erp_conn()['book_base_url'] ?? ''), '/');
    return ($path !== '' && $base !== '' && preg_match('#^/books/\d+/invoices/\d+$#', $path)) ? $base . $path : null;
}

/** Asks the book for the invoice of an order. @return array{0:?array,1:string} [info, 'ok'|'none'|'error'] */
function erp_fetch_book_invoice_info(string $orderUuid): array {
    $r = erp_http_book('GET', 'invoice/' . rawurlencode($orderUuid), null, ['timeout' => 10]);
    if ($r['ok'] && !empty($r['json']['invoice']['invoice_no'])) return [$r['json']['invoice'], 'ok'];
    if ($r['status'] === 404) return [null, 'none'];
    return [null, 'error'];
}

/** The book's invoice as PDF bytes, or null (not linked, no invoice yet, book unreachable). Never throws. */
function erp_book_invoice_pdf(array $order): ?string {
    try {
        if (!erp_linked()) return null;
        $link = erp_link_by_local('order', (int) $order['id']);
        if (!$link) return null;
        $r = erp_http_book('GET', 'invoice/' . rawurlencode($link['entity_uuid']) . '/pdf', null, ['timeout' => 15]);
        if (!$r['ok'] || strncmp($r['body'], '%PDF', 4) !== 0) {
            erp_log('out', 'invoice', 'Could not get the book invoice for order ' . $order['order_number'] . ' (HTTP ' . $r['status'] . '); showing the store invoice instead.', false, null, $r['status'] ?: null);
            return null;
        }
        return $r['body'];
    } catch (Throwable $e) {
        error_log('[erp invoice pdf] ' . $e->getMessage());
        return null;
    }
}

/**
 * Worker task: asks the book for the invoice number/path of synced orders that don't have them yet (history imports, orders placed
 * before this feature, a lost answer). Each order is checked at most once an hour. @return int how many were filled in
 */
function erp_backfill_invoices(int $limit = 20): int {
    if (!erp_active()) return 0;
    $st = db()->prepare("SELECT o.id, l.entity_uuid FROM orders o JOIN sync_links l ON l.connection_id = ? AND l.entity = 'order' AND l.local_id = o.id AND l.last_payload IS NOT NULL
        WHERE o.book_invoice_path IS NULL AND (o.book_invoice_checked_at IS NULL OR o.book_invoice_checked_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR)
        ORDER BY o.id DESC LIMIT " . (int) $limit);
    $st->execute([erp_connection_id()]);
    $done = 0;
    foreach ($st->fetchAll() as $row) {
        [$info, $state] = erp_fetch_book_invoice_info($row['entity_uuid']);
        if ($state === 'ok') { erp_order_save_invoice($row['entity_uuid'], $info); $done++; }
        elseif ($state === 'none') db()->prepare('UPDATE orders SET book_invoice_checked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
        else break; // book unreachable: don't hammer it, try again next pass
    }
    return $done;
}
