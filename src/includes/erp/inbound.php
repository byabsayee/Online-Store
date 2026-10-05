<?php
/**
 * Inbound side: applies signed events from the book exactly once, resolves per-field conflicts
 * (newest wins, the book wins exact ties), and serves the recovery/reconciliation feeds.
 */
require_once __DIR__ . '/mappers.php';

/** Newest-wins tie rule (D10): when both timestamps are identical, the BOOK wins. Documented in docs/INTEGRATION.md. */
const ERP_TIE_WINS = 'book';

function erp_ts_float(?string $iso): ?float {
    if (!$iso) return null;
    try { $d = new DateTimeImmutable($iso); return (float) $d->format('U.u'); } catch (Throwable $e) { return null; }
}

/** @return array{0:array,1:array} [accepted fields, accepted field timestamps] */
function erp_lww_filter(?array $link, array $fields, array $fieldTs, string $occurredAt, string $entity, string $uuid): array {
    $localTs = $link ? (json_decode((string) $link['field_ts'], true) ?: []) : [];
    $prev = $link ? (json_decode((string) $link['last_payload'], true) ?: []) : [];
    $accepted = []; $acceptedTs = [];
    foreach ($fields as $k => $v) {
        $remote = erp_ts_float($fieldTs[$k] ?? $occurredAt) ?? 0.0;
        $mine = erp_ts_float($localTs[$k] ?? null);
        if ($mine !== null && $mine > $remote) {
            erp_log('in', 'conflict', "Kept the newer local value of $entity.$k (local change is newer than the book's)", true, null, null, ['entity' => $entity, 'uuid' => $uuid, 'field' => $k]);
            continue;
        }
        if ($mine !== null && array_key_exists($k, $prev) && erp_json($prev[$k]) !== erp_json($v)) {
            erp_log('in', 'overwrite', "The book's value replaced the local one for $entity.$k", true, null, null, ['entity' => $entity, 'uuid' => $uuid, 'field' => $k, 'old' => $prev[$k] ?? null, 'new' => $v]);
        }
        $accepted[$k] = $v; $acceptedTs[$k] = $fieldTs[$k] ?? $occurredAt;
    }
    return [$accepted, $acceptedTs];
}

/** @return array{event_id:?string,result:string,code?:string,message?:string,retry?:bool} */
function erp_inbound_one($ev): array {
    $eid = is_array($ev) && isset($ev['event_id']) && is_string($ev['event_id']) ? $ev['event_id'] : null;
    $rej = fn (string $code, string $msg, bool $retry = false) => ['event_id' => $eid, 'result' => 'rejected', 'code' => $code, 'message' => $msg, 'retry' => $retry];
    if (!is_array($ev) || !erp_is_uuid($eid)) return $rej('invalid_envelope', 'event_id must be a UUID.');
    foreach (['connection_id', 'origin', 'entity', 'entity_uuid', 'op', 'version', 'occurred_at', 'payload'] as $k) if (!array_key_exists($k, $ev)) return $rej('invalid_envelope', "Missing '$k'.");
    if (!hash_equals(erp_connection_id(), (string) $ev['connection_id'])) return $rej('unknown_connection', 'connection_id does not match.');
    if ($ev['origin'] !== 'book') return $rej('invalid_envelope', "origin must be 'book'.");
    if (!in_array($ev['entity'], ERP_ENTITIES, true)) return $rej('unsupported_entity', 'Unknown entity ' . (is_string($ev['entity']) ? $ev['entity'] : '?') . '.');
    if (!in_array($ev['op'], ERP_OPS, true)) return $rej('invalid_envelope', 'Unknown op.');
    if (!erp_is_uuid($ev['entity_uuid'])) return $rej('invalid_envelope', 'entity_uuid must be a UUID.');
    if (!is_int($ev['version']) || $ev['version'] < 1) return $rej('invalid_envelope', 'version must be a positive integer.');
    if (!is_array($ev['payload']) || erp_ts_float((string) $ev['occurred_at']) === null) return $rej('invalid_envelope', 'payload/occurred_at invalid.');
    if (!erp_scope_allows($ev['entity'])) return $rej('scope_denied', 'The connection has no scope for ' . $ev['entity'] . '.');
    if (erp_setup_pending()) return $rej('temporarily_unavailable', 'Initial setup is not finished on this store yet.', true);
    if (!erp_active()) return $rej('connection_paused', 'This connection is not active.', true);

    $pdo = db();
    $seen = $pdo->prepare('SELECT result FROM sync_inbox WHERE event_id = ?');
    $seen->execute([$eid]);
    if ($seen->fetchColumn() !== false) return ['event_id' => $eid, 'result' => 'duplicate'];

    $entity = $ev['entity']; $uuid = $ev['entity_uuid']; $op = $ev['op'];
    $cls = erp_mapper($entity);
    $fields = is_array($ev['payload']['fields'] ?? null) ? $ev['payload']['fields'] : [];
    $fieldTs = is_array($ev['payload']['field_ts'] ?? null) ? $ev['payload']['field_ts'] : [];

    try {
        $pdo->beginTransaction();
        $GLOBALS['erp_applying'] = ($GLOBALS['erp_applying'] ?? 0) + 1;
        $link = erp_link_get($entity, $uuid);
        if (!$link && method_exists($cls, 'uuidFor')) {
            foreach ([1, 2, 3] as $i) if ($cls::uuidFor($i) === $uuid) { $link = erp_link_create($entity, $i, $uuid); break; }
        }
        if (!$link) {
            if ($op !== 'create') throw new ErpReject('unknown_entity', "This store has no $entity with that id yet (send it as a create first).", true);
            $link = erp_link_create($entity, null, $uuid);
        }
        $localId = $link['local_id'] ? (int) $link['local_id'] : null;
        if ($op === 'create' && $localId) $op = 'update'; // a second "create" for something we already have is an update

        // Locally gone but the book still talks about it: only a restore/create can bring it back.
        [$accepted, $acceptedTs] = in_array($op, ['create', 'update'], true) ? erp_lww_filter($link, $fields, $fieldTs, (string) $ev['occurred_at'], $entity, $uuid) : [$fields, []];
        $ctx = ['uuid' => $uuid, 'event' => $ev, 'link' => $link];
        $newId = $cls::apply($op, $localId, $accepted, $ctx);

        $built = $newId ? $cls::build((int) $newId) : null;
        $ts = json_decode((string) $link['field_ts'], true) ?: [];
        foreach ($acceptedTs as $k => $t) $ts[$k] = $t;
        erp_link_update((int) $link['id'], [
            'local_id' => $newId ?: $localId, 'remote_version' => max((int) $link['remote_version'], (int) $ev['version']),
            'last_payload' => $built !== null ? erp_json($built) : $link['last_payload'], 'content_hash' => $built !== null ? sha1(erp_json($built)) : $link['content_hash'],
            'field_ts' => erp_json($ts), 'archived' => $op === 'archive' ? 1 : ($op === 'restore' ? 0 : (int) $link['archived']), 'last_synced_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $pdo->prepare('INSERT INTO sync_inbox (event_id, connection_id, entity, entity_uuid, op, result) VALUES (?,?,?,?,?,?)')->execute([$eid, erp_connection_id(), $entity, $uuid, $ev['op'], 'applied']);
        $pdo->commit();
        return ['event_id' => $eid, 'result' => 'applied'];
    } catch (ErpConflictResult $c) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $cid = erp_conflict_add($c->kind, $entity, $uuid, null, $eid, $c->local, $c->kind === 'customer_match' ? $ev : $c->remote, $c->getMessage());
        $pdo->prepare('INSERT IGNORE INTO sync_inbox (event_id, connection_id, entity, entity_uuid, op, result, detail) VALUES (?,?,?,?,?,?,?)')
            ->execute([$eid, erp_connection_id(), $entity, $uuid, $ev['op'], 'conflict', 'conflict #' . $cid]);
        erp_log('in', 'conflict', 'Queued a ' . $c->kind . ' conflict for ' . $entity . ': ' . $c->getMessage(), false, $eid);
        return ['event_id' => $eid, 'result' => 'conflict', 'message' => $c->getMessage(), 'conflict_id' => $cid];
    } catch (ErpReject $r) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        erp_log('in', 'reject', "Rejected $entity/$op: " . $r->getMessage(), false, $eid, null, ['code' => $r->errCode]);
        return $rej($r->errCode, $r->getMessage(), $r->retry);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[erp inbound] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        erp_log('in', 'error', "Failed to apply $entity/$op: " . $e->getMessage(), false, $eid);
        return $rej('apply_failed', 'The store could not apply this event.', true);
    } finally {
        $GLOBALS['erp_applying'] = ($GLOBALS['erp_applying'] ?? 1) - 1;
        if ($GLOBALS['erp_applying'] < 1) unset($GLOBALS['erp_applying']);
    }
}

/** Handles POST events. Returns the response body. */
function erp_inbound_batch(array $body): array {
    $events = $body['events'] ?? null;
    if (!is_array($events) || !array_is_list($events)) throw new ErpApiError('invalid_request', "Body must contain an 'events' array.", 400);
    if (count($events) > ERP_BATCH_MAX) throw new ErpApiError('batch_too_large', 'At most ' . ERP_BATCH_MAX . ' events per request.', 413);
    $results = [];
    foreach ($events as $ev) $results[] = erp_inbound_one($ev);
    $applied = count(array_filter($results, fn ($r) => $r['result'] === 'applied'));
    if ($applied) erp_conn_update(['last_sync_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null]);
    erp_log('in', 'events', 'Received ' . count($events) . ' event(s): ' . json_encode(array_count_values(array_column($results, 'result'))), true, null, 200, ['batch_id' => $body['batch_id'] ?? null]);
    return ['ok' => true, 'batch_id' => $body['batch_id'] ?? null, 'results' => $results];
}

/* ------------------------------------------------ feeds for the book ----- */

/** entity => [table, extra WHERE] for the snapshot listing. */
const ERP_SNAPSHOT_TABLES = [
    'category' => ['categories', ''], 'product' => ['products', ''], 'customer' => ['sync_customers', ''], 'staff' => ['admins', ''], 'order' => ['orders', ''],
    'payment' => ['order_payments', ''], 'payment_method' => ['payment_methods', ''], 'coupon' => ['coupons', ''], 'return' => ['order_returns', ''],
];

function erp_snapshot(string $entity, int $cursor, int $limit): array {
    $limit = max(1, min(200, $limit));
    if ($entity === 'tax') return ['ok' => true, 'entity' => $entity, 'items' => [erp_snapshot_item('tax', 1)], 'cursor' => null, 'has_more' => false];
    if ($entity === 'delivery_charge') return ['ok' => true, 'entity' => $entity, 'items' => array_map(fn ($i) => erp_snapshot_item('delivery_charge', $i), [1, 2, 3]), 'cursor' => null, 'has_more' => false];
    if (!isset(ERP_SNAPSHOT_TABLES[$entity])) throw new ErpApiError('unsupported_entity', 'No snapshot for ' . $entity . '.', 404);
    [$table] = ERP_SNAPSHOT_TABLES[$entity];
    $st = db()->prepare("SELECT id FROM `$table` WHERE id > ? ORDER BY id ASC LIMIT " . ($limit + 1));
    $st->execute([$cursor]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $more = count($ids) > $limit; $ids = array_slice($ids, 0, $limit);
    $items = [];
    foreach ($ids as $id) { $it = erp_snapshot_item($entity, (int) $id); if ($it) $items[] = $it; }
    return ['ok' => true, 'entity' => $entity, 'items' => $items, 'cursor' => $ids ? (string) end($ids) : (string) $cursor, 'has_more' => $more];
}

function erp_snapshot_item(string $entity, int $id): ?array {
    $cls = erp_mapper($entity);
    $fields = $cls::build($id);
    if ($fields === null) return null;
    $uuid = $entity === 'tax' ? ErpMapTax::uuid() : ($entity === 'delivery_charge' ? ErpMapDelivery::uuid($id) : erp_uuid_for($entity, $id));
    $link = erp_link_get($entity, $uuid);
    $item = ['entity_uuid' => $uuid, 'version' => (int) ($link['local_version'] ?? 0), 'archived' => (bool) ($link['archived'] ?? false), 'content_hash' => sha1(erp_json($fields)), 'fields' => $fields];
    if ($entity === 'product') {
        $vs = [];
        foreach (product_variants_for($id, false) as $v) $vs[erp_uuid_for('variant', (int) $v['id'])] = (int) $v['stock'];
        $s = db()->prepare('SELECT stock FROM products WHERE id = ?'); $s->execute([$id]);
        $item['stock'] = ['product' => (int) $s->fetchColumn(), 'variants' => (object) $vs];
    }
    return $item;
}

/** Outbound events after a cursor (recovery only — the primary transport is the signed POST). */
function erp_changes(int $cursor, int $limit): array {
    $limit = max(1, min(200, $limit));
    $st = db()->prepare("SELECT id, envelope FROM sync_outbox WHERE id > ? ORDER BY id ASC LIMIT " . ($limit + 1));
    $st->execute([$cursor]);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
    return ['ok' => true, 'events' => array_map(fn ($r) => json_decode($r['envelope'], true), $rows), 'cursor' => $rows ? (string) end($rows)['id'] : (string) $cursor, 'has_more' => $more];
}

function erp_site_info(): array {
    $t = tax_settings();
    return ['name' => store_name(), 'url' => site_url(), 'currency_code' => store_currency_code(), 'currency_symbol' => store_currency_symbol(), 'timezone' => date_default_timezone_get(),
        'tax' => ['enabled' => $t['enabled'], 'rate' => number_format($t['rate'], 3, '.', ''), 'inclusive' => $t['inclusive'], 'label' => $t['label']],
        'delivery' => ['inside_dhaka' => erp_money(shipcfg('inside')), 'suburbs' => erp_money(shipcfg('suburbs')), 'outside_dhaka' => erp_money(shipcfg('outside')), 'free_weight_kg' => erp_money(shipcfg('free_kg')), 'extra_per_kg' => erp_money(shipcfg('extra_kg'))]];
}

function erp_status_body(): array {
    $c = erp_conn(true);
    return ['ok' => true, 'api_version' => ERP_API_VERSION, 'module_version' => ERP_MODULE_VERSION, 'capabilities' => ERP_CAPABILITIES, 'connection_status' => $c['status'],
        'setup_done' => erp_setup_done(), 'queue' => erp_queue_stats(), 'last_sync_at' => erp_iso_from_db($c['last_sync_at'] ?? null), 'server_time' => erp_now_iso(), 'site' => erp_site_info()];
}
