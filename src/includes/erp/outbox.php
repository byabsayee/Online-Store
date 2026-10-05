<?php
/**
 * Identity links, the outbox (events written in the same DB transaction as the business change),
 * and batched, retried, signed delivery to the book.
 */
require_once __DIR__ . '/transport.php';

const ERP_BACKOFF = [30, 120, 600, 3600, 21600]; // seconds: 30s, 2m, 10m, 1h, 6h, then dead-letter
const ERP_RETRYABLE_CODES = ['unknown_entity', 'dependency_missing', 'busy', 'rate_limited', 'temporarily_unavailable'];

/** entity => mapper class (defined in mappers.php). */
function erp_mapper(string $entity): string {
    static $map = [
        'category' => 'ErpMapCategory', 'product' => 'ErpMapProduct', 'variant' => 'ErpMapVariant', 'customer' => 'ErpMapCustomer',
        'order' => 'ErpMapOrder', 'payment' => 'ErpMapPayment', 'payment_method' => 'ErpMapPaymentMethod', 'stock_movement' => 'ErpMapStock',
        'coupon' => 'ErpMapCoupon', 'tax' => 'ErpMapTax', 'delivery_charge' => 'ErpMapDelivery', 'return' => 'ErpMapReturn', 'staff' => 'ErpMapStaff',
    ];
    if (!isset($map[$entity])) throw new InvalidArgumentException('Unknown entity ' . $entity);
    return $map[$entity];
}

/* --------------------------------------------------------------- links --- */

function erp_link_get(string $entity, string $uuid): ?array {
    $st = db()->prepare('SELECT * FROM sync_links WHERE connection_id = ? AND entity = ? AND entity_uuid = ?');
    $st->execute([erp_connection_id(), $entity, $uuid]);
    return $st->fetch() ?: null;
}

function erp_link_by_local(string $entity, int $localId): ?array {
    $st = db()->prepare('SELECT * FROM sync_links WHERE connection_id = ? AND entity = ? AND local_id = ?');
    $st->execute([erp_connection_id(), $entity, $localId]);
    return $st->fetch() ?: null;
}

function erp_link_create(string $entity, ?int $localId, ?string $uuid = null): array {
    $uuid = $uuid ?: erp_uuid4();
    db()->prepare('INSERT INTO sync_links (connection_id, entity, entity_uuid, local_id) VALUES (?,?,?,?)')->execute([erp_connection_id(), $entity, $uuid, $localId]);
    return erp_link_get($entity, $uuid);
}

/** The shared uuid of a local row, creating the link when it doesn't exist yet (without emitting anything). */
function erp_uuid_for(string $entity, ?int $localId): ?string {
    if (!$localId) return null;
    $l = erp_link_by_local($entity, $localId) ?? erp_link_create($entity, $localId);
    return $l['entity_uuid'];
}

/** Local id for a shared uuid, or null when unknown. */
function erp_local_for(string $entity, ?string $uuid): ?int {
    if (!$uuid) return null;
    $l = erp_link_get($entity, $uuid);
    return $l && $l['local_id'] ? (int) $l['local_id'] : null;
}

function erp_link_update(int $linkId, array $cols): void {
    $set = []; $vals = [];
    foreach ($cols as $k => $v) { $set[] = "`$k` = ?"; $vals[] = $v; }
    $vals[] = $linkId;
    db()->prepare('UPDATE sync_links SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
}

/* ---------------------------------------------------------------- diff --- */

/** Top-level fields of $new that differ from $old (arrays compare as a whole). */
function erp_diff(array $old, array $new): array {
    $out = [];
    foreach ($new as $k => $v) {
        if (!array_key_exists($k, $old) || erp_json($old[$k]) !== erp_json($v)) $out[$k] = $v;
    }
    return $out;
}

/* ---------------------------------------------------------------- emit --- */

/**
 * Queue an event for a local row. Call it inside the same DB transaction as the change, right
 * after the change. Does nothing when the module isn't linked, when the change came FROM the
 * book (loop prevention) or when the entity isn't in the connection's scopes.
 *
 * $op: 'auto' (create the first time, update afterwards; skipped when nothing changed), or one of
 * archive|restore|cancel|void, which always send.
 * $extra: merged into the payload (outside the diffed fields), e.g. a stock snapshot on create.
 */
function erp_emit(string $entity, int $localId, string $op = 'auto', array $extra = []): ?string {
    // A fault in the sync module must never break a sale or an admin save: log it and carry on.
    try { return erp_emit_raw($entity, $localId, $op, $extra); }
    catch (Throwable $e) {
        error_log('[erp emit] ' . $entity . '#' . $localId . ' ' . $op . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        erp_log('system', 'emit', 'Could not queue ' . $entity . ' ' . $op . ': ' . $e->getMessage(), false);
        return null;
    }
}

function erp_emit_raw(string $entity, int $localId, string $op = 'auto', array $extra = []): ?string {
    if (!erp_linked() || !empty($GLOBALS['erp_applying']) || !erp_scope_allows($entity)) return null;
    $cls = erp_mapper($entity);
    if (erp_setup_pending() || erp_match_ignored($entity, $localId)) return null;
    $full = $cls::build($localId);
    $link = erp_link_by_local($entity, $localId);

    if ($full === null) {
        // The row is gone. The only event that still makes sense is an archive/void (never a hard delete).
        if (!$link || !in_array($op, ['archive', 'void', 'cancel'], true)) return null;
        $full = json_decode((string) $link['last_payload'], true) ?: [];
    }
    // A link can exist without ever having been sent (created just to hand out a uuid): that still counts as a first send.
    $isNew = $link === null || empty($link['last_payload']);
    if ($isNew) {
        // Parents first (a product references its category; a payment its order, ...).
        foreach ($cls::dependencies($localId) as [$depEntity, $depId]) erp_ensure_emitted($depEntity, (int) $depId);
        if ($link === null) $link = erp_link_create($entity, $localId, method_exists($cls, 'uuidFor') ? $cls::uuidFor($localId) : null);
    }
    if ($op === 'auto') $op = $isNew ? 'create' : 'update';
    $prev = json_decode((string) $link['last_payload'], true) ?: [];
    $fields = $op === 'create' ? $full : erp_diff($prev, $full);
    if ($op === 'archive' || $op === 'restore') $fields = ['is_active' => $op === 'restore'];
    if ($op === 'cancel') $fields = $cls::cancelFields();
    if ($op === 'void') $fields = $cls::voidFields();
    if ($op === 'update' && !$fields) return null; // nothing the book cares about changed
    if ($op === 'create' && method_exists($cls, 'extra')) $extra = array_merge($cls::extra($localId), $extra);

    $now = erp_now_iso();
    $ts = json_decode((string) $link['field_ts'], true) ?: [];
    $sendTs = [];
    foreach (array_keys($fields) as $f) { $ts[$f] = $now; $sendTs[$f] = $now; }
    $version = (int) $link['local_version'] + 1;
    $eventId = erp_uuid4();
    $env = [
        'event_id' => $eventId, 'connection_id' => erp_connection_id(), 'origin' => 'site', 'entity' => $entity, 'entity_uuid' => $link['entity_uuid'],
        'op' => $op, 'version' => $version, 'base_version' => (int) $link['remote_version'], 'occurred_at' => $now,
        'payload' => array_merge(['fields' => $fields, 'field_ts' => $sendTs], $extra),
    ];
    erp_outbox_insert($env);
    $archived = $op === 'archive' ? 1 : ($op === 'restore' ? 0 : (int) $link['archived']);
    erp_link_update((int) $link['id'], [
        'local_version' => $version, 'last_payload' => erp_json($full), 'field_ts' => erp_json($ts), 'content_hash' => sha1(erp_json($full)),
        'archived' => $archived, 'local_id' => in_array($op, ['archive', 'void'], true) && $cls::build($localId) === null ? null : $localId,
    ]);
    erp_flush_soon();
    return $eventId;
}

/** Initial matching (D13) not finished yet: nothing is sent, so nothing can merge unreviewed. */
function erp_setup_pending(): bool { return get_setting('erp_setup_done', '0') !== '1'; }

/** Items the owner chose to leave out of the sync during the initial review stay unlinked. */
function erp_match_ignored(string $entity, int $localId): bool {
    static $cache = [];
    $k = $entity . ':' . $localId;
    if (!isset($cache[$k])) {
        $st = db()->prepare("SELECT 1 FROM sync_match_items WHERE entity = ? AND local_id = ? AND status = 'ignored' LIMIT 1");
        $st->execute([$entity, $localId]);
        $cache[$k] = (bool) $st->fetchColumn();
    }
    return $cache[$k];
}

/** Make sure a dependency (e.g. the category of a product) exists at the book before the row that points at it. */
function erp_ensure_emitted(string $entity, int $localId): void {
    $l = $localId ? erp_link_by_local($entity, $localId) : null;
    if (!$localId || ($l && !empty($l['last_payload']))) return;
    erp_emit($entity, $localId, 'auto');
}

/** Low-level: put an already-built envelope into the outbox. */
function erp_outbox_insert(array $env): void {
    db()->prepare('INSERT INTO sync_outbox (connection_id, event_id, entity, entity_uuid, op, version, envelope, status, next_attempt_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())')
        ->execute([$env['connection_id'], $env['event_id'], $env['entity'], $env['entity_uuid'], $env['op'], $env['version'], erp_json($env), 'pending']);
}

/** Registers one opportunistic flush for the end of this request (after the visitor has their page). */
function erp_flush_soon(): void {
    static $done = false;
    if ($done || !erp_active() || PHP_SAPI === 'cli') return;
    $done = true;
    defer_job(function () { erp_flush(2); });
}

/* --------------------------------------------------------------- flush --- */

function erp_backoff_seconds(int $attempts): int {
    return ERP_BACKOFF[min(max($attempts - 1, 0), count(ERP_BACKOFF) - 1)];
}

/**
 * Sends due events to the book. Safe to call from anywhere: a lock keeps two callers from
 * delivering the same batch. Returns counts for the admin screen.
 */
function erp_flush(int $maxBatches = 5): array {
    $stats = ['sent' => 0, 'failed' => 0, 'dead' => 0, 'batches' => 0];
    if (!erp_active()) return $stats;
    if (!erp_lock('flush', 0)) return $stats;
    try {
        // Anything left in 'sending' by a crashed run goes back in the queue.
        db()->exec("UPDATE sync_outbox SET status = 'pending' WHERE status = 'sending' AND next_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
        for ($i = 0; $i < $maxBatches; $i++) {
            $rows = db()->query("SELECT * FROM sync_outbox WHERE status = 'pending' AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 200")->fetchAll();
            if (!$rows) break;
            $batch = []; $bytes = 0;
            $dueIds = array_flip(array_map('intval', array_column($rows, 'id')));
            $earlierStmt = db()->prepare("SELECT id FROM sync_outbox WHERE entity = ? AND entity_uuid = ? AND id < ? AND status IN ('pending','sending')");
            foreach ($rows as $r) {
                // Per-entity ordering: never send an event while an earlier one for the same entity is still
                // waiting (backing off or in flight). Earlier events that are due now are in this same selection.
                $earlierStmt->execute([$r['entity'], $r['entity_uuid'], $r['id']]);
                $wait = false;
                foreach ($earlierStmt->fetchAll(PDO::FETCH_COLUMN) as $eid) if (!isset($dueIds[(int) $eid])) { $wait = true; break; }
                if ($wait) continue;
                $bytes += strlen($r['envelope']);
                if (count($batch) >= ERP_BATCH_MAX || ($batch && $bytes > 900000)) break;
                $batch[] = $r;
            }
            if (!$batch) break;
            $in = implode(',', array_map('intval', array_column($batch, 'id')));
            db()->exec("UPDATE sync_outbox SET status = 'sending', next_attempt_at = UTC_TIMESTAMP() WHERE id IN ($in)");
            $stats['batches']++;
            $ok = erp_send_batch($batch, $stats);
            if (!$ok) break; // the book is unreachable/refusing: stop and let backoff work
        }
    } finally {
        erp_unlock('flush');
    }
    return $stats;
}

function erp_send_batch(array $batch, array &$stats): bool {
    $batchId = erp_uuid4();
    $envs = array_map(fn ($r) => json_decode($r['envelope'], true), $batch);
    $res = erp_http_book('POST', 'events', ['batch_id' => $batchId, 'events' => $envs], ['batch_id' => $batchId]);
    $byEvent = [];
    foreach ((array) ($res['json']['results'] ?? []) as $r) if (!empty($r['event_id'])) $byEvent[$r['event_id']] = $r;

    if (!$res['ok'] || !isset($res['json']['results'])) {
        $msg = $res['error'] ?: 'HTTP ' . $res['status'];
        $code = (string) ($res['json']['error']['code'] ?? '');
        foreach ($batch as $r) erp_outbox_fail($r, $msg, $stats, $res['status'] === 429 || $res['status'] >= 500 || $res['status'] === 0 ? 'transient' : 'auth');
        erp_log('out', 'events', 'Batch of ' . count($batch) . ' failed: ' . $msg, false, null, $res['status'] ?: null, ['batch_id' => $batchId, 'code' => $code]);
        erp_conn_update(['last_error' => mb_substr($msg, 0, 500)]);
        if ($res['status'] === 410 || $code === 'connection_revoked') erp_conn_update(['status' => 'revoked', 'last_error' => 'The book revoked this connection.']);
        return false;
    }
    foreach ($batch as $r) {
        $x = $byEvent[$r['event_id']] ?? null;
        $result = (string) ($x['result'] ?? '');
        if (in_array($result, ['applied', 'duplicate'], true)) {
            db()->prepare("UPDATE sync_outbox SET status = 'done', sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?")->execute([$r['id']]);
            // The book answers an applied order with its invoice number: keep it so customers can be shown the book's invoice.
            if ($r['entity'] === 'order' && !empty($x['invoice']) && is_array($x['invoice'])) { try { erp_order_save_invoice($r['entity_uuid'], $x['invoice']); } catch (Throwable $e) { error_log('[erp invoice save] ' . $e->getMessage()); } }
            $stats['sent']++;
        } elseif ($result === 'conflict') {
            db()->prepare("UPDATE sync_outbox SET status = 'conflict', sent_at = UTC_TIMESTAMP(), last_error = ? WHERE id = ?")->execute([mb_substr((string) ($x['message'] ?? 'Queued as a conflict at the book.'), 0, 500), $r['id']]);
            erp_conflict_add('other', $r['entity'], $r['entity_uuid'], null, $r['event_id'], null, json_decode($r['envelope'], true)['payload'] ?? null, 'The book queued this change as a conflict: ' . ($x['message'] ?? 'needs review there.'));
            $stats['sent']++;
        } elseif ($result === 'rejected') {
            $code = (string) ($x['code'] ?? 'rejected');
            $msg = $code . ': ' . ($x['message'] ?? 'Rejected by the book.');
            if ($code === 'insufficient_stock' && $r['entity'] === 'order') {
                ErpMapOrder::flag_attention($r['entity_uuid'], 'oversold', 'The book has less stock than was sold — ' . ($x['message'] ?? 'insufficient stock') . '.');
                db()->prepare("UPDATE sync_outbox SET status = 'done', sent_at = UTC_TIMESTAMP(), last_error = ? WHERE id = ?")->execute([mb_substr($msg, 0, 500), $r['id']]);
            } elseif (!empty($x['retry']) || in_array($code, ERP_RETRYABLE_CODES, true)) {
                erp_outbox_fail($r, $msg, $stats, 'transient');
            } else {
                erp_outbox_fail($r, $msg, $stats, 'permanent');
            }
        } else {
            erp_outbox_fail($r, 'The book did not report a result for this event.', $stats, 'transient');
        }
    }
    db()->prepare("UPDATE sync_connection SET last_sync_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = 1")->execute();
    erp_conn(true);
    erp_log('out', 'events', 'Sent ' . count($batch) . ' event(s)', true, null, $res['status'], ['batch_id' => $batchId, 'results' => array_count_values(array_map(fn ($r) => $r['result'] ?? '?', (array) $res['json']['results']))]);
    return true;
}

function erp_outbox_fail(array $row, string $msg, array &$stats, string $kind): void {
    $attempts = (int) $row['attempts'] + 1;
    if ($kind === 'permanent' || $attempts > count(ERP_BACKOFF)) {
        db()->prepare("UPDATE sync_outbox SET status = 'dead', attempts = ?, last_error = ? WHERE id = ?")->execute([$attempts, mb_substr($msg, 0, 500), $row['id']]);
        $stats['dead']++;
        return;
    }
    db()->prepare("UPDATE sync_outbox SET status = 'pending', attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ?")
        ->execute([$attempts, mb_substr($msg, 0, 500), erp_backoff_seconds($attempts), $row['id']]);
    $stats['failed']++;
}

function erp_outbox_retry(int $id): void {
    db()->prepare("UPDATE sync_outbox SET status = 'pending', attempts = 0, next_attempt_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'dead'")->execute([$id]);
}
function erp_outbox_retry_all_dead(): int {
    $st = db()->prepare("UPDATE sync_outbox SET status = 'pending', attempts = 0, next_attempt_at = UTC_TIMESTAMP() WHERE status = 'dead'");
    $st->execute();
    return $st->rowCount();
}
/** "Discard" keeps the row (never dropped silently) but takes it out of the queue for good. */
function erp_outbox_discard(int $id): void {
    db()->prepare("UPDATE sync_outbox SET status = 'done', last_error = CONCAT('[discarded by admin] ', COALESCE(last_error, '')) WHERE id = ? AND status = 'dead'")->execute([$id]);
}

function erp_queue_stats(): array {
    $r = db()->query("SELECT status, COUNT(*) c FROM sync_outbox GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['pending' => (int) ($r['pending'] ?? 0) + (int) ($r['sending'] ?? 0), 'dead' => (int) ($r['dead'] ?? 0), 'done' => (int) ($r['done'] ?? 0), 'conflict' => (int) ($r['conflict'] ?? 0)];
}

/* ------------------------------------------------------------ conflicts -- */

function erp_conflict_add(string $kind, string $entity, ?string $uuid, ?int $localId, ?string $eventId, $local, $remote, string $note): int {
    db()->prepare('INSERT INTO sync_conflicts (kind, entity, entity_uuid, local_id, event_id, local_data, remote_data, note) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$kind, $entity, $uuid, $localId, $eventId, $local === null ? null : erp_json($local), $remote === null ? null : erp_json($remote), mb_substr($note, 0, 500)]);
    return (int) db()->lastInsertId();
}

/* ------------------------------------------------------- housekeeping ---- */

/** Log retention: detail 30 days, summaries 90 days; inbox/outbox bookkeeping trimmed. */
function erp_prune(): void {
    $pdo = db();
    $pdo->exec("UPDATE sync_log SET detail = NULL WHERE detail IS NOT NULL AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)");
    $pdo->exec("DELETE FROM sync_log WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)");
    $pdo->exec("DELETE FROM sync_nonces WHERE expires_at < UTC_TIMESTAMP()");
    $pdo->exec("DELETE FROM sync_inbox WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)");
    $pdo->exec("DELETE FROM sync_outbox WHERE status = 'done' AND sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)");
}
