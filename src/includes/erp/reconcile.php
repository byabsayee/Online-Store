<?php
/**
 * Reliability tools: hourly/manual reconciliation (drift report + stock correction), recovery
 * (re-send / pull what one side is missing), and the optional historical import wizard
 * (dry run → batches with pause/resume → rollback of exactly one batch).
 */
require_once __DIR__ . '/connect.php';

/* ------------------------------------------------------ book snapshots ---- */

/** Pages through the book's snapshot for one entity. Returns [items, errorOrNull]. */
function erp_book_snapshot(string $entity, int $maxPages = 100): array {
    $items = []; $cursor = '';
    for ($p = 0; $p < $maxPages; $p++) {
        $res = erp_http_book('GET', 'snapshot/' . $entity . '?limit=200' . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : ''));
        if (!$res['ok'] || !is_array($res['json']['items'] ?? null)) return [$items, 'Could not read the book\'s ' . $entity . ' list: ' . ($res['error'] ?: 'unexpected answer')];
        foreach ($res['json']['items'] as $it) if (!empty($it['entity_uuid'])) $items[] = $it;
        if (empty($res['json']['has_more'])) break;
        $cursor = (string) ($res['json']['cursor'] ?? ''); if ($cursor === '') break;
    }
    return [$items, null];
}

/* ------------------------------------------------------- reconcile ------- */

const ERP_RECONCILE_ENTITIES = ['category', 'product', 'customer', 'payment_method', 'coupon', 'order'];

/**
 * Compares this store with the book: per-entity presence and version drift, plus the cached stock
 * of every product. Stock drift is corrected with a flagged reconciliation_adjustment movement
 * (the book's ledger is the record) — never a silent overwrite. Everything else is reported.
 */
function erp_reconcile(?array $only = null): array {
    $report = ['ran_at' => erp_now_iso(), 'ok' => true, 'error' => null, 'entities' => [], 'stock' => ['checked' => 0, 'drift' => [], 'corrected' => 0, 'deferred' => false]];
    if (!erp_active() || !erp_setup_done()) { $report['ok'] = false; $report['error'] = 'The store is not active yet.'; return $report; }
    if (!erp_lock('reconcile', 0)) { $report['ok'] = false; $report['error'] = 'A reconciliation is already running.'; return $report; }
    try {
        $inflight = (int) db()->query("SELECT COUNT(*) FROM sync_outbox WHERE status IN ('pending','sending') AND entity IN ('order','payment','return','stock_movement')")->fetchColumn();
        $report['stock']['deferred'] = $inflight > 0;
        foreach ($only ?? ERP_RECONCILE_ENTITIES as $entity) {
            if (!erp_scope_allows($entity)) continue;
            [$items, $err] = erp_book_snapshot($entity);
            if ($err) { $report['ok'] = false; $report['error'] = $err; break; }
            $remote = [];
            foreach ($items as $it) $remote[$it['entity_uuid']] = $it;
            $st = db()->prepare("SELECT entity_uuid, local_id, remote_version, local_version, archived FROM sync_links WHERE connection_id = ? AND entity = ? AND last_payload IS NOT NULL AND local_id IS NOT NULL");
            $st->execute([erp_connection_id(), $entity]);
            $localLinks = []; foreach ($st->fetchAll() as $l) $localLinks[$l['entity_uuid']] = $l;
            $missingAtBook = array_keys(array_diff_key($localLinks, $remote));
            $missingHere = array_keys(array_filter(array_diff_key($remote, $localLinks), fn ($r) => empty($r['archived'])));
            $behind = [];
            foreach ($localLinks as $u => $l) if (isset($remote[$u]) && (int) ($remote[$u]['version'] ?? 0) > (int) $l['remote_version']) $behind[] = $u;
            $report['entities'][$entity] = ['here' => count($localLinks), 'book' => count($remote), 'missing_at_book' => $missingAtBook, 'missing_here' => $missingHere, 'behind' => $behind];

            if ($entity === 'product') {
                foreach ($localLinks as $u => $l) {
                    if (!isset($remote[$u]['stock'])) continue;
                    $rs = $remote[$u]['stock'];
                    $pid = (int) $l['local_id'];
                    $cur = db()->prepare('SELECT stock, sku FROM products WHERE id = ?'); $cur->execute([$pid]);
                    $row = $cur->fetch(); if (!$row) continue;
                    $has = (int) db()->query('SELECT COUNT(*) FROM product_variants WHERE product_id = ' . $pid)->fetchColumn();
                    $pairs = [];
                    if ($has && is_array($rs['variants'] ?? null)) {
                        foreach ($rs['variants'] as $vu => $qty) { $vid = erp_local_for('variant', (string) $vu); if ($vid) $pairs[] = [$vid, (int) $qty]; }
                    } elseif (!$has && isset($rs['product'])) $pairs[] = [null, (int) $rs['product']];
                    foreach ($pairs as [$vid, $bookQty]) {
                        $report['stock']['checked']++;
                        $q = $vid ? db()->prepare('SELECT stock FROM product_variants WHERE id = ?') : db()->prepare('SELECT stock FROM products WHERE id = ?');
                        $q->execute([$vid ?: $pid]);
                        $mine = (int) $q->fetchColumn();
                        if ($mine === $bookQty) continue;
                        $d = ['sku' => $row['sku'], 'product_id' => $pid, 'variant_id' => $vid, 'here' => $mine, 'book' => $bookQty, 'delta' => $bookQty - $mine];
                        if (!$inflight) { erp_stock_change($pid, $vid, $bookQty - $mine, 'reconciliation_adjustment', 'sync', null, 'book', 'Reconciliation: matched the book (' . $mine . ' → ' . $bookQty . ')'); $d['corrected'] = true; $report['stock']['corrected']++; }
                        $report['stock']['drift'][] = $d;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $report['ok'] = false; $report['error'] = $e->getMessage();
        error_log('[erp reconcile] ' . $e->getMessage());
    } finally { erp_unlock('reconcile'); }
    $drift = 0; foreach ($report['entities'] as $r) $drift += count($r['missing_at_book']) + count($r['missing_here']) + count($r['behind']);
    $report['drift_total'] = $drift + count($report['stock']['drift']);
    set_setting('erp_last_reconcile', erp_json($report));
    erp_log('system', 'reconcile', $report['ok'] ? ('Reconciled: ' . $report['drift_total'] . ' difference(s), ' . $report['stock']['corrected'] . ' stock correction(s).') : ('Reconcile failed: ' . $report['error']), $report['ok'] && $report['drift_total'] === 0);
    return $report;
}

function erp_last_reconcile(): ?array { $j = json_decode((string) get_setting('erp_last_reconcile', ''), true); return is_array($j) ? $j : null; }

/** Recovery: queue a fresh 'create' for things the book is missing. */
function erp_resend_missing(string $entity, array $uuids): int {
    $n = 0;
    foreach ($uuids as $u) {
        $l = erp_link_get($entity, (string) $u);
        if (!$l || !$l['local_id']) continue;
        erp_link_update((int) $l['id'], ['last_payload' => null]); // forces a full create
        if (erp_emit($entity, (int) $l['local_id'], 'auto')) $n++;
    }
    return $n;
}

/** Recovery: bring in what only the book has, or what advanced there while events were lost. */
function erp_pull_from_book(string $entity, array $uuids): array {
    [$items, $err] = erp_book_snapshot($entity);
    if ($err) return [0, [$err]];
    $by = []; foreach ($items as $it) $by[$it['entity_uuid']] = $it;
    $n = 0; $errors = [];
    $cls = erp_mapper($entity);
    foreach ($uuids as $u) {
        $it = $by[$u] ?? null; if (!$it || !is_array($it['fields'] ?? null)) continue;
        $link = erp_link_get($entity, $u);
        try {
            db()->beginTransaction();
            $now = erp_now_iso();
            $ts = array_fill_keys(array_keys($it['fields']), $now);
            $localId = $link && $link['local_id'] ? (int) $link['local_id'] : null;
            $fake = ['event_id' => erp_uuid4(), 'entity' => $entity, 'entity_uuid' => $u, 'version' => (int) ($it['version'] ?? 1), 'occurred_at' => $now, 'payload' => ['fields' => $it['fields'], 'field_ts' => $ts]];
            // A product created from the book keeps the book's quantity (this used to arrive as 0). Existing products are corrected by the stock reconciliation instead.
            if ($entity === 'product' && !$localId && isset($it['stock']['product']) && is_numeric($it['stock']['product']) && (int) $it['stock']['product'] > 0) {
                $fake['payload']['opening_stock'] = ['product' => (int) $it['stock']['product'], 'variants' => (object) []];
            }
            $f = $it['fields'];
            if (!empty($f['category_uuid']) && !erp_local_for('category', $f['category_uuid'])) $f['category_uuid'] = null;
            $id = erp_applying(fn () => $cls::apply($localId ? 'update' : 'create', $localId, $f, ['uuid' => $u, 'event' => $fake, 'force_new' => true]));
            if (!$link) { erp_link_create($entity, $id, $u); $link = erp_link_get($entity, $u); }
            $built = $cls::build((int) $id);
            erp_link_update((int) $link['id'], ['local_id' => $id, 'remote_version' => (int) ($it['version'] ?? 0), 'last_payload' => $built !== null ? erp_json($built) : null, 'last_synced_at' => gmdate('Y-m-d H:i:s')]);
            db()->commit(); $n++;
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $errors[] = $u . ': ' . $e->getMessage(); }
    }
    return [$n, $errors];
}

/* ----------------------------------------------------------- self-healing -- */

const ERP_HEAL_INTERVAL = 180; // seconds between automatic catch-up passes

/**
 * Keeps the two sides in step without anyone pressing anything. Called by the worker; does nothing until the interval has passed.
 * Every few minutes it compares both sides and then fixes what it finds, following the agreed rules:
 *   - catalog data (categories, products, payment methods, coupons): the book wins, so missing or newer items are brought in
 *     (a product that arrives from the book keeps the book's stock) and stock differences are corrected;
 *   - customers: customers only the book has are brought in; ones only here are sent (newest edit wins stays with the live events);
 *   - orders: online orders the book is missing are re-sent (the store is where they originate);
 *   - events that gave up are tried again once an hour.
 * Items that already have an event waiting or failed are not queued twice. @return array{ran:bool,pulled:int,resent:int,retried:int,errors:array}
 */
function erp_auto_heal(bool $force = false): array {
    $out = ['ran' => false, 'pulled' => 0, 'resent' => 0, 'retried' => 0, 'errors' => []];
    if (!erp_active() || !erp_setup_done()) return $out;
    if (!$force && time() - (int) get_setting('erp_last_heal', '0') < ERP_HEAL_INTERVAL) return $out;
    set_setting('erp_last_heal', (string) time());
    $out['ran'] = true;

    $fullDue = $force || time() - (int) get_setting('erp_last_heal_full', '0') >= 3600;   // orders can be many: compare them hourly, the rest every pass
    if ($fullDue) set_setting('erp_last_heal_full', (string) time());
    $entities = $fullDue ? ERP_RECONCILE_ENTITIES : array_values(array_diff(ERP_RECONCILE_ENTITIES, ['order']));
    $report = erp_reconcile($entities);
    if (!$report['ok']) { $out['errors'][] = (string) $report['error']; return $out; }

    $busy = db()->prepare("SELECT 1 FROM sync_outbox WHERE entity_uuid = ? AND status IN ('pending','sending','dead') LIMIT 1");
    $catalog = ['category', 'product', 'payment_method', 'coupon'];
    foreach ($report['entities'] as $entity => $r) {
        $pull = [];
        if (in_array($entity, $catalog, true)) $pull = array_merge($r['missing_here'], $r['behind']);
        elseif ($entity === 'customer') $pull = $r['missing_here'];
        $pull = array_slice(array_values(array_unique($pull)), 0, 100);
        if ($pull) {
            [$n, $errs] = erp_pull_from_book($entity, $pull);
            $out['pulled'] += $n;
            foreach (array_slice($errs, 0, 3) as $e) $out['errors'][] = $entity . ': ' . $e;
        }
        if (in_array($entity, array_merge($catalog, ['customer', 'order']), true) && $r['missing_at_book']) {
            $send = [];
            foreach ($r['missing_at_book'] as $u) { $busy->execute([$u]); if (!$busy->fetchColumn()) $send[] = $u; if (count($send) >= 100) break; }
            if ($send) $out['resent'] += erp_resend_missing($entity, $send);
        }
    }
    if (time() - (int) get_setting('erp_last_dead_retry', '0') >= 3600) {
        set_setting('erp_last_dead_retry', (string) time());
        $out['retried'] = erp_outbox_retry_all_dead();
    }
    if ($out['pulled'] || $out['resent'] || $out['retried'] || $out['errors'] || $report['stock']['corrected']) {
        erp_log('system', 'heal', 'Automatic catch-up: brought in ' . $out['pulled'] . ', re-sent ' . $out['resent'] . ', retried ' . $out['retried'] . ', corrected stock for ' . (int) $report['stock']['corrected'] . '.' . ($out['errors'] ? ' Problems: ' . implode(' | ', array_slice($out['errors'], 0, 3)) : ''), !$out['errors']);
    }
    if ($out['resent']) erp_flush(3);
    return $out;
}

/* ----------------------------------------------------------- import ------ */

/** Import planning (dry run): reads, never writes (except the batch row that stores the plan). */
function erp_import_plan(string $direction, array $entities, ?string $from, ?string $to, string $stockMode, string $by): array {
    if (!erp_active() || !erp_setup_done()) return [false, 'Finish linking and the initial setup first.', 0];
    if (!in_array($direction, ['store_to_book', 'book_to_store'], true)) return [false, 'Choose a direction.', 0];
    $entities = array_values(array_intersect($entities, ['customers', 'orders']));
    if (!$entities) return [false, 'Choose what to import.', 0];
    foreach (['from' => $from, 'to' => $to] as $v) if ($v && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return [false, 'Dates must look like 2026-01-31.', 0];
    if ($stockMode === 'opening_balance' && $direction !== 'book_to_store') return [false, 'Opening-balance mode is only available when importing from the book.', 0];
    $plan = ['counts' => [], 'warnings' => [], 'samples' => []];
    $fromDb = $from ? $from . ' 00:00:00' : null;  // date range is interpreted in UTC, like everything stored
    $toDb = $to ? $to . ' 23:59:59' : null;

    if ($direction === 'store_to_book') {
        if (in_array('orders', $entities, true)) {
            $w = ["NOT EXISTS (SELECT 1 FROM sync_links l WHERE l.connection_id = ? AND l.entity = 'order' AND l.local_id = o.id AND l.last_payload IS NOT NULL)", "o.source = 'store'"]; $p = [erp_connection_id()];
            if ($fromDb) { $w[] = 'o.created_at >= ?'; $p[] = $fromDb; } if ($toDb) { $w[] = 'o.created_at <= ?'; $p[] = $toDb; }
            $st = db()->prepare('SELECT COUNT(*) FROM orders o WHERE ' . implode(' AND ', $w)); $st->execute($p);
            $plan['counts']['orders_to_send'] = (int) $st->fetchColumn();
            $st = db()->prepare('SELECT COUNT(*) FROM orders o WHERE ' . implode(' AND ', array_slice($w, 1)) . ' AND EXISTS (SELECT 1 FROM sync_links l WHERE l.connection_id = ? AND l.entity = \'order\' AND l.local_id = o.id AND l.last_payload IS NOT NULL)');
            $st->execute(array_merge(array_slice($p, 1), [erp_connection_id()]));
            $plan['counts']['orders_already_synced'] = (int) $st->fetchColumn();
            $st = db()->prepare('SELECT COUNT(DISTINCT oi.product_id) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE ' . implode(' AND ', array_slice($w, 0)) . ' AND oi.product_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sync_links l WHERE l.connection_id = ? AND l.entity = \'product\' AND l.local_id = oi.product_id AND l.last_payload IS NOT NULL)');
            $st->execute(array_merge($p, [erp_connection_id()]));
            if ($n = (int) $st->fetchColumn()) $plan['warnings'][] = $n . ' product(s) on these orders are not linked to the book yet; they will be created there automatically (review the initial matching first if that is not what you want).';
            $st = db()->prepare("SELECT COUNT(*) FROM orders o WHERE o.status <> 'cancelled' AND " . implode(' AND ', array_slice($w, 1)) . " AND NOT EXISTS (SELECT 1 FROM sync_links l WHERE l.connection_id = ? AND l.entity = 'order' AND l.local_id = o.id AND l.last_payload IS NOT NULL)");
            $st->execute(array_merge(array_slice($p, 1), [erp_connection_id()]));
            $plan['warnings'][] = 'Imported orders never change the book\'s stock.';
        }
        if (in_array('customers', $entities, true)) {
            $st = db()->prepare("SELECT COUNT(*) FROM sync_customers c WHERE c.is_archived = 0 AND NOT EXISTS (SELECT 1 FROM sync_links l WHERE l.connection_id = ? AND l.entity = 'customer' AND l.local_id = c.id AND l.last_payload IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM sync_match_items m WHERE m.entity = 'customer' AND m.local_id = c.id AND m.status = 'ignored')");
            $st->execute([erp_connection_id()]);
            $plan['counts']['customers_to_send'] = (int) $st->fetchColumn();
        }
    } else {
        if (in_array('orders', $entities, true)) {
            [$items, $err] = erp_book_snapshot('order');
            if ($err) return [false, $err, 0];
            $new = 0; $known = 0; $dupNumber = 0; $missingProducts = 0; $bad = 0;
            foreach ($items as $it) {
                $f = $it['fields'] ?? []; $placed = erp_db_from_iso($f['placed_at'] ?? null);
                if (($fromDb && $placed && $placed < $fromDb) || ($toDb && $placed && $placed > $toDb)) continue;
                if (erp_link_get('order', $it['entity_uuid'])) { $known++; continue; }
                $n = db()->prepare('SELECT 1 FROM orders WHERE order_number = ?'); $n->execute([(string) ($f['number'] ?? '')]);
                if ($n->fetchColumn()) { $dupNumber++; continue; }
                try { $probe = new ReflectionMethod('ErpMapOrder', 'checkTotals'); $probe->setAccessible(true); $probe->invoke(null, $f); } catch (Throwable $e) { $bad++; continue; }
                foreach ((array) ($f['items'] ?? []) as $li) if (!empty($li['product_uuid']) && !erp_local_for('product', $li['product_uuid'])) { $missingProducts++; break; }
                $new++;
            }
            $plan['counts'] = array_merge($plan['counts'], ['orders_to_create' => $new, 'orders_already_synced' => $known, 'orders_number_clash' => $dupNumber, 'orders_bad_totals' => $bad, 'orders_missing_products' => $missingProducts]);
            if ($dupNumber) $plan['warnings'][] = $dupNumber . ' order(s) use a number that already exists here and will be skipped.';
            if ($bad) $plan['warnings'][] = $bad . ' order(s) have totals that do not add up and will be skipped.';
            if ($missingProducts) $plan['warnings'][] = $missingProducts . ' order(s) refer to products not linked here and will be skipped — finish product matching first.';
            $plan['warnings'][] = $stockMode === 'opening_balance' ? 'Stock: after importing, quantities here are set to the book\'s (recorded as reconciliation adjustments).' : 'Imported orders will not change your current stock.';
        }
        if (in_array('customers', $entities, true)) {
            [$items, $err] = erp_book_snapshot('customer');
            if ($err) return [false, $err, 0];
            $new = 0; $matches = 0;
            foreach ($items as $it) { if (erp_link_get('customer', $it['entity_uuid'])) continue; $k = erp_match_key('customer', $it['fields'] ?? []);
                $m = false;
                if ($k['phone']) { $q = db()->prepare('SELECT 1 FROM sync_customers WHERE phone_norm = ?'); $q->execute([$k['phone']]); $m = (bool) $q->fetchColumn(); }
                if (!$m && $k['email']) { $q = db()->prepare('SELECT 1 FROM sync_customers WHERE email = ?'); $q->execute([$k['email']]); $m = (bool) $q->fetchColumn(); }
                $m ? $matches++ : $new++; }
            $plan['counts']['customers_to_create'] = $new; $plan['counts']['customers_need_review'] = $matches;
            if ($matches) $plan['warnings'][] = $matches . ' customer(s) share a phone/email with an existing one and will go to the review queue instead of being merged.';
        }
    }
    db()->prepare('INSERT INTO sync_import_batches (direction, entities, date_from, date_to, stock_mode, status, plan, created_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$direction, implode(',', $entities), $from ?: null, $to ?: null, $stockMode === 'opening_balance' ? 'opening_balance' : 'none', 'dry_run', erp_json($plan), mb_substr($by, 0, 120)]);
    $id = (int) db()->lastInsertId();
    erp_log('system', 'import', 'Dry run #' . $id . ' (' . $direction . '): ' . json_encode($plan['counts']));
    return [true, 'Dry run finished. Nothing was written.', $id];
}

function erp_import_get(int $id): ?array { $st = db()->prepare('SELECT * FROM sync_import_batches WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }

function erp_import_start(int $id): string {
    $b = erp_import_get($id);
    if (!$b || $b['status'] !== 'dry_run') return 'Only a fresh dry run can be started.';
    db()->prepare("UPDATE sync_import_batches SET status = 'running', progress = ?, cursor_json = ? WHERE id = ?")->execute([erp_json(['done' => 0, 'skipped' => 0, 'failed' => 0, 'ids' => [], 'errors' => []]), erp_json(['orders' => 0, 'customers' => 0, 'stage' => 'customers', 'book_cursor' => '', 'book_items' => null]), $id]);
    return 'Import started.';
}
function erp_import_pause(int $id): void { db()->prepare("UPDATE sync_import_batches SET status = 'paused' WHERE id = ? AND status = 'running'")->execute([$id]); }
function erp_import_resume(int $id): void { db()->prepare("UPDATE sync_import_batches SET status = 'running' WHERE id = ? AND status = 'paused'")->execute([$id]); }

/** Processes one chunk (default 20 records) of a running batch. Returns the batch row. */
function erp_import_step(int $id, int $chunk = 20): ?array {
    $b = erp_import_get($id);
    if (!$b || $b['status'] !== 'running') return $b;
    if (!erp_lock('import' . $id, 0)) return $b;
    try {
        $prog = json_decode((string) $b['progress'], true) ?: ['done' => 0, 'skipped' => 0, 'failed' => 0, 'ids' => [], 'errors' => []];
        $cur = json_decode((string) $b['cursor_json'], true) ?: [];
        $ents = explode(',', $b['entities']);
        $fromDb = $b['date_from'] ? $b['date_from'] . ' 00:00:00' : null; $toDb = $b['date_to'] ? $b['date_to'] . ' 23:59:59' : null;
        $imp = ['batch' => (int) $id, 'stock' => false];
        $left = $chunk;

        if ($b['direction'] === 'store_to_book') {
            if (($cur['stage'] ?? 'customers') === 'customers') {
                if (!in_array('customers', $ents, true)) $cur['stage'] = 'orders';
                else {
                    $st = db()->prepare("SELECT c.id FROM sync_customers c WHERE c.id > ? AND c.is_archived = 0 AND NOT EXISTS (SELECT 1 FROM sync_links l WHERE l.connection_id = ? AND l.entity = 'customer' AND l.local_id = c.id AND l.last_payload IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM sync_match_items m WHERE m.entity = 'customer' AND m.local_id = c.id AND m.status = 'ignored') ORDER BY c.id LIMIT " . $left);
                    $st->execute([(int) ($cur['customers'] ?? 0), erp_connection_id()]);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($ids as $cid) { erp_emit('customer', (int) $cid, 'create', ['import' => $imp]); $prog['done']++; $cur['customers'] = (int) $cid; $left--; }
                    if (count($ids) < $chunk) $cur['stage'] = 'orders';
                }
            }
            if ($left > 0 && ($cur['stage'] ?? '') === 'orders') {
                if (!in_array('orders', $ents, true)) $cur['stage'] = 'done';
                else {
                    $w = ["o.id > ?", "o.source = 'store'", "NOT EXISTS (SELECT 1 FROM sync_links l WHERE l.connection_id = ? AND l.entity = 'order' AND l.local_id = o.id AND l.last_payload IS NOT NULL)"]; $p = [(int) ($cur['orders'] ?? 0), erp_connection_id()];
                    if ($fromDb) { $w[] = 'o.created_at >= ?'; $p[] = $fromDb; } if ($toDb) { $w[] = 'o.created_at <= ?'; $p[] = $toDb; }
                    $st = db()->prepare('SELECT o.id FROM orders o WHERE ' . implode(' AND ', $w) . ' ORDER BY o.id LIMIT ' . $left);
                    $st->execute($p);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($ids as $oid) {
                        try {
                            db()->beginTransaction();
                            erp_emit('order', (int) $oid, 'create', ['import' => $imp]);
                            foreach (db()->query('SELECT id FROM order_payments WHERE order_id = ' . (int) $oid . " AND status = 'recorded'")->fetchAll(PDO::FETCH_COLUMN) as $pid) erp_emit('payment', (int) $pid, 'create', ['import' => $imp]);
                            foreach (db()->query('SELECT id FROM order_returns WHERE order_id = ' . (int) $oid)->fetchAll(PDO::FETCH_COLUMN) as $rid) erp_emit('return', (int) $rid, 'create', ['import' => $imp]);
                            db()->commit();
                            $prog['done']++; $prog['ids'][] = (int) $oid;
                        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $prog['failed']++; $prog['errors'][] = 'Order #' . $oid . ': ' . $e->getMessage(); }
                        $cur['orders'] = (int) $oid; $left--;
                    }
                    if (count($ids) < $chunk) $cur['stage'] = 'done';
                }
            }
            $finished = ($cur['stage'] ?? '') === 'done';
        } else {
            if (($cur['book_items'] ?? null) === null) {
                $queue = [];
                foreach (['customer' => 'customers', 'order' => 'orders'] as $entity => $ent) {
                    if (!in_array($ent, $ents, true)) continue;
                    [$items, $err] = erp_book_snapshot($entity);
                    if ($err) throw new RuntimeException($err);
                    foreach ($items as $it) {
                        if ($entity === 'order') { $placed = erp_db_from_iso($it['fields']['placed_at'] ?? null); if (($fromDb && $placed && $placed < $fromDb) || ($toDb && $placed && $placed > $toDb)) continue; }
                        $queue[] = [$entity, $it];
                    }
                }
                usort($queue, fn ($a, $c) => ($a[0] === 'customer' ? 0 : 1) <=> ($c[0] === 'customer' ? 0 : 1)); // customers first: orders refer to them
                $cur['book_items'] = $queue; $cur['pos'] = 0;
            }
            $queue = $cur['book_items']; $pos = (int) ($cur['pos'] ?? 0);
            while ($left > 0 && $pos < count($queue)) {
                [$entity, $it] = $queue[$pos]; $pos++; $left--;
                $u = $it['entity_uuid'];
                if (erp_link_get($entity, $u)) { $prog['skipped']++; continue; }
                $cls = erp_mapper($entity);
                try {
                    db()->beginTransaction();
                    $now = erp_now_iso();
                    $fake = ['event_id' => erp_uuid4(), 'entity' => $entity, 'entity_uuid' => $u, 'version' => (int) ($it['version'] ?? 1), 'occurred_at' => $now, 'payload' => ['fields' => $it['fields'], 'field_ts' => [], 'import' => $imp]];
                    $lid = erp_applying(fn () => $cls::apply('create', null, $it['fields'], ['uuid' => $u, 'event' => $fake]));
                    $l = erp_link_create($entity, $lid, $u);
                    $built = $cls::build((int) $lid);
                    erp_link_update((int) $l['id'], ['remote_version' => (int) ($it['version'] ?? 0), 'last_payload' => $built !== null ? erp_json($built) : null, 'last_synced_at' => gmdate('Y-m-d H:i:s')]);
                    db()->commit();
                    $prog['done']++; $prog['ids'][] = [$entity, (int) $lid, $u];
                } catch (ErpConflictResult $c) {
                    if (db()->inTransaction()) db()->rollBack();
                    erp_conflict_add($c->kind, $entity, $u, null, null, $c->local, $c->kind === 'customer_match' ? ['event_id' => $fake['event_id'], 'entity_uuid' => $u, 'version' => $fake['version'], 'payload' => ['fields' => $it['fields']]] : $c->remote, $c->getMessage());
                    $prog['skipped']++;
                } catch (Throwable $e) {
                    if (db()->inTransaction()) db()->rollBack();
                    $prog['failed']++; $prog['errors'][] = $entity . ' ' . $u . ': ' . $e->getMessage();
                }
            }
            $cur['pos'] = $pos;
            $finished = $pos >= count($queue);
            if ($finished) unset($cur['book_items']);
            if ($finished && $b['stock_mode'] === 'opening_balance') { db()->prepare("UPDATE sync_import_batches SET progress = ? WHERE id = ?")->execute([erp_json($prog), $id]); erp_reconcile(); }
        }
        $prog['errors'] = array_slice($prog['errors'], -30);
        $prog['ids'] = array_slice($prog['ids'], 0, 100000);
        db()->prepare('UPDATE sync_import_batches SET progress = ?, cursor_json = ?, status = ?, finished_at = ? WHERE id = ?')
            ->execute([erp_json($prog), erp_json($cur), $finished ? 'done' : 'running', $finished ? gmdate('Y-m-d H:i:s') : null, $id]);
        if ($finished) erp_log('system', 'import', 'Import #' . $id . ' finished: ' . $prog['done'] . ' done, ' . $prog['skipped'] . ' skipped, ' . $prog['failed'] . ' failed.');
    } catch (Throwable $e) {
        error_log('[erp import] ' . $e->getMessage());
        db()->prepare("UPDATE sync_import_batches SET status = 'failed', progress = JSON_SET(COALESCE(progress, '{}'), '$.fatal', ?) WHERE id = ?")->execute([$e->getMessage(), $id]);
    } finally { erp_unlock('import' . $id); }
    return erp_import_get($id);
}

/**
 * Rolls back exactly one batch. store→book: sends a void (cancel with reversals, D8) for each order
 * the batch sent. book→store: removes the orders/customers the batch created (imported history never
 * touched stock, so nothing else changes).
 */
function erp_import_rollback(int $id): string {
    $b = erp_import_get($id);
    if (!$b || !in_array($b['status'], ['done', 'paused', 'failed', 'running'], true)) return 'This batch cannot be rolled back.';
    $prog = json_decode((string) $b['progress'], true) ?: [];
    $n = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($b['direction'] === 'store_to_book') {
            foreach ($prog['ids'] ?? [] as $oid) { if (erp_emit('order', (int) $oid, 'void', ['import' => ['batch' => $id, 'stock' => false, 'rollback' => true]])) $n++; }
        } else {
            foreach (array_reverse($prog['ids'] ?? []) as $row) {
                [$entity, $lid, $u] = $row;
                if ($entity === 'order') $pdo->prepare('DELETE FROM orders WHERE id = ? AND import_batch = ?')->execute([$lid, $id]);
                elseif ($entity === 'customer') $pdo->prepare('DELETE FROM sync_customers WHERE id = ?')->execute([$lid]);
                $pdo->prepare('DELETE FROM sync_links WHERE connection_id = ? AND entity = ? AND entity_uuid = ?')->execute([erp_connection_id(), $entity, $u]);
                $n++;
            }
        }
        $pdo->prepare("UPDATE sync_import_batches SET status = 'rolled_back', finished_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return 'Rollback failed: ' . $e->getMessage(); }
    erp_log('system', 'import', 'Import #' . $id . ' rolled back (' . $n . ' record(s)).');
    return 'Rolled back ' . $n . ' record(s).';
}
