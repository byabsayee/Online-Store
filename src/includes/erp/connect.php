<?php
/**
 * Connection lifecycle (disabled → pending → verifying → active ⇄ paused → revoked), the initial
 * matching review (D13/D14), credential rotation, and disconnect.
 */
require_once __DIR__ . '/inbound.php';

/* ---------------------------------------------------------- connect ------ */

/** The store's own address must be a dedicated public HTTPS domain (D1) — the book will call it back over the internet. */
function erp_site_public_error(): ?string {
    if (erp_test_mode()) return null;
    $url = site_url();
    if ($url === '') return 'Set the public site address first (Settings & email → Public site address), e.g. https://shop.example.com.';
    $p = erp_parse_base_url($url, $err);
    if (!$p) return 'The public site address is not usable for the integration: ' . $err;
    if ($p['base'] !== 'https://' . $p['host']) return 'Use a domain (or subdomain) dedicated to this store — no path after the domain.';
    return null;
}

/**
 * Starts pairing. Either a pairing code (the book returns the credentials), or manual credentials
 * ['connection_id', 'api_key', 'secret_site_to_book', 'secret_book_to_site'].
 * @return array{0:bool,1:string}
 */
function erp_connect(string $bookUrl, string $pairingCode = '', array $manual = []): array {
    $st = erp_status();
    if (in_array($st, ['active', 'paused', 'verifying'], true)) return [false, 'Disconnect the current link first.'];
    if ($e = erp_site_public_error()) return [false, $e];
    $parsed = erp_parse_base_url($bookUrl, $err);
    if (!$parsed && erp_test_mode() && preg_match('#^https?://[^/]+#', $bookUrl, $tm)) $parsed = ['host' => (string) parse_url($bookUrl, PHP_URL_HOST), 'base' => rtrim($bookUrl, '/')];
    if (!$parsed) return [false, $err];
    $siteHost = erp_test_mode() ? (string) (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost') : (string) parse_url(site_url(), PHP_URL_HOST);

    $req = ['site' => erp_site_info(), 'module_version' => ERP_MODULE_VERSION, 'api_version' => ERP_API_VERSION, 'capabilities' => ERP_CAPABILITIES];
    erp_conn_update(['status' => 'pending', 'book_base_url' => $parsed['base'], 'site_domain' => $siteHost, 'last_error' => null]);

    if ($pairingCode !== '') {
        $req['pairing_code'] = trim($pairingCode);
        $res = erp_http_book('POST', 'connect/handshake', $req, ['unsigned' => true, 'connection_id' => '-']);
        $j = $res['json'];
        if (!$res['ok'] || !is_array($j) || empty($j['connection_id']) || empty($j['api_key']) || empty($j['secrets']['site_to_book']) || empty($j['secrets']['book_to_site'])) {
            $m = $res['error'] ?: 'The book did not return credentials.';
            erp_conn_update(['status' => 'disabled', 'last_error' => mb_substr($m, 0, 500)]);
            erp_log('system', 'connect', 'Pairing failed: ' . $m, false, null, $res['status'] ?: null);
            return [false, 'The book refused the pairing: ' . $m];
        }
        $cred = ['connection_id' => (string) $j['connection_id'], 'api_key' => (string) $j['api_key'], 'secret_site_to_book' => (string) $j['secrets']['site_to_book'], 'secret_book_to_site' => (string) $j['secrets']['book_to_site']];
        $info = $j;
    } else {
        foreach (['connection_id', 'api_key', 'secret_site_to_book', 'secret_book_to_site'] as $k) if (trim((string) ($manual[$k] ?? '')) === '') { erp_conn_update(['status' => 'disabled']); return [false, 'Fill in every credential field.']; }
        $cred = array_map('trim', array_intersect_key($manual, array_flip(['connection_id', 'api_key', 'secret_site_to_book', 'secret_book_to_site'])));
        if (!erp_is_uuid($cred['connection_id'])) { erp_conn_update(['status' => 'disabled']); return [false, 'The connection ID is not a valid UUID.']; }
        $res = erp_http_book('POST', 'connect/handshake', $req, ['connection_id' => $cred['connection_id'], 'api_key' => $cred['api_key'], 'secret' => $cred['secret_site_to_book']]);
        $info = $res['json'];
        if (!$res['ok'] || !is_array($info)) {
            $m = $res['error'] ?: 'No answer.';
            erp_conn_update(['status' => 'disabled', 'last_error' => mb_substr($m, 0, 500)]);
            erp_log('system', 'connect', 'Handshake failed: ' . $m, false, null, $res['status'] ?: null);
            return [false, 'The handshake failed: ' . $m];
        }
    }
    if (!empty($info['api_version']) && $info['api_version'] !== ERP_API_VERSION) {
        erp_conn_update(['status' => 'disabled', 'last_error' => 'Unsupported API version ' . $info['api_version']]);
        return [false, 'The book speaks API ' . $info['api_version'] . ' but this store only supports ' . ERP_API_VERSION . '.'];
    }
    $caps = array_values(array_filter((array) ($info['capabilities'] ?? []), 'is_string'));
    erp_conn_update([
        'status' => 'verifying', 'connection_id' => $cred['connection_id'], 'api_key_enc' => erp_enc($cred['api_key']), 'site_api_key_hash' => hash('sha256', $cred['api_key']),
        'secret_site_to_book_enc' => erp_enc($cred['secret_site_to_book']), 'secret_book_to_site_enc' => erp_enc($cred['secret_book_to_site']),
        'api_version' => ERP_API_VERSION, 'peer_module_version' => isset($info['module_version']) ? mb_substr((string) $info['module_version'], 0, 40) : null,
        'peer_capabilities' => erp_json($caps), 'book_info' => isset($info['book']) ? erp_json($info['book']) : null,
        'scopes' => erp_json(erp_valid_scopes($info['scopes'] ?? array_keys(ERP_SCOPE_ENTITIES))), 'verify_token' => null, 'verified_at' => null, 'last_error' => null,
    ]);
    erp_conn(true);
    erp_log('system', 'connect', 'Handshake accepted. Waiting for the book to verify this domain and finish linking.', true, null, $res['status'] ?? 200);
    return [true, 'Handshake accepted. The book will now check that you own this domain and finish linking — refresh in a moment.'];
}

function erp_valid_scopes($scopes): array {
    $out = [];
    foreach ((array) $scopes as $s) if (is_string($s) && isset(ERP_SCOPE_ENTITIES[$s])) $out[$s] = $s;
    return array_values($out) ?: array_keys(ERP_SCOPE_ENTITIES);
}

/**
 * The book finishes linking (POST connect/complete): it has chosen who is authoritative for
 * currency/timezone/tax (D17). When the book is, this store adopts its values — unless that would
 * change the currency under existing orders. Then the store goes active (setup review pending).
 */
function erp_connect_complete(array $body): array {
    $c = erp_conn(true);
    if (!in_array($c['status'], ['verifying', 'active'], true)) throw new ErpApiError('invalid_state', 'This store is not waiting to be linked.', 409);
    if (empty($c['verified_at'])) throw new ErpApiError('not_verified', 'The domain has not been verified yet — call GET /.well-known/erp-verify first.', 409);
    $authority = $body['authority'] ?? null;
    if (!in_array($authority, ['book', 'site'], true)) throw new ErpApiError('invalid_request', "authority must be 'book' or 'site'.", 400);
    $adopted = [];
    if ($authority === 'book') {
        $b = is_array($body['book'] ?? null) ? $body['book'] : [];
        $code = strtoupper(trim((string) ($b['currency_code'] ?? '')));
        if ($code !== '' && !preg_match('/^[A-Z]{3}$/', $code)) throw new ErpApiError('invalid_request', 'currency_code must be a 3-letter ISO code.', 400);
        $orders = (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        if ($code !== '' && $code !== store_currency_code() && $orders > 0) {
            throw new ErpApiError('currency_conflict', 'This store already has ' . $orders . ' order(s) in ' . store_currency_code() . '; the book uses ' . $code . '. No automatic conversion is done — make the website authoritative or start from a store without orders.', 409);
        }
        if ($code !== '') { set_setting('currency_code', $code); $adopted[] = 'currency'; }
        if (!empty($b['currency_symbol'])) set_setting('currency_symbol', mb_substr((string) $b['currency_symbol'], 0, 8));
        if (!empty($b['timezone']) && in_array($b['timezone'], timezone_identifiers_list(), true)) { set_setting('timezone', (string) $b['timezone']); date_default_timezone_set((string) $b['timezone']); $adopted[] = 'timezone'; }
        if (is_array($b['tax'] ?? null)) {
            $t = $b['tax'];
            set_setting('tax_enabled', !empty($t['enabled']) ? '1' : '0');
            if (isset($t['rate']) && is_numeric($t['rate'])) set_setting('tax_rate', (string) max(0, min(100, (float) $t['rate'])));
            set_setting('tax_inclusive', !empty($t['inclusive']) ? '1' : '0');
            if (!empty($t['label'])) set_setting('tax_label', mb_substr((string) $t['label'], 0, 40));
            $adopted[] = 'tax';
        }
    }
    erp_conn_update(['status' => 'active', 'authority' => $authority, 'scopes' => erp_json(erp_valid_scopes($body['scopes'] ?? erp_scopes())),
        'peer_capabilities' => isset($body['capabilities']) ? erp_json(array_values(array_filter((array) $body['capabilities'], 'is_string'))) : $c['peer_capabilities'],
        'book_info' => isset($body['book']) ? erp_json($body['book']) : $c['book_info'], 'last_error' => null]);
    erp_backfill_customers();
    erp_log('system', 'connect', 'Linked. Authority: ' . $authority . ($adopted ? ' (adopted ' . implode(', ', $adopted) . ')' : ''), true);
    return ['ok' => true, 'status' => 'active', 'adopted' => $adopted, 'setup_required' => !erp_setup_done(), 'site' => erp_site_info()];
}

/* ------------------------------------------------------ pause / stop ---- */

function erp_pause(): void { if (erp_status() === 'active') { erp_conn_update(['status' => 'paused']); erp_log('system', 'state', 'Sync paused by an admin.'); } }
function erp_resume(): void { if (erp_status() === 'paused') { erp_conn_update(['status' => 'active']); erp_log('system', 'state', 'Sync resumed by an admin.'); erp_flush(2); } }

/**
 * Unlink. Stops events, keeps every record and the links table so the same pair can resume later.
 * Credentials are wiped. Tells the book when it can be reached.
 */
function erp_disconnect(bool $notify = true): string {
    $msg = 'Disconnected.';
    if ($notify && in_array(erp_status(), ['active', 'paused', 'verifying'], true)) {
        $res = erp_http_book('POST', 'disconnect', ['reason' => 'store_disconnect']);
        if (!$res['ok']) $msg = 'Disconnected here, but the book could not be reached to tell it (' . ($res['error'] ?: 'no answer') . '). Revoke the connection there too.';
    }
    erp_conn_update(['status' => 'revoked', 'api_key_enc' => null, 'site_api_key_hash' => null, 'secret_site_to_book_enc' => null, 'secret_book_to_site_enc' => null, 'verify_token' => null]);
    erp_log('system', 'state', 'Disconnected.');
    return $msg;
}

/** The book asked to unlink (signed POST disconnect). */
function erp_disconnect_inbound(): array { erp_disconnect(false); return ['ok' => true, 'status' => 'revoked']; }

/* ----------------------------------------------------------- rotation ---- */

/** Store-initiated: ask the book for a fresh API key and secrets and swap them in. */
function erp_rotate_credentials(): array {
    if (!erp_linked()) return [false, 'Nothing to rotate — not linked.'];
    $res = erp_http_book('POST', 'connect/rotate', ['requested_by' => 'site']);
    $j = $res['json'];
    if (!$res['ok'] || !is_array($j) || empty($j['api_key']) || empty($j['secrets']['site_to_book']) || empty($j['secrets']['book_to_site'])) return [false, 'The book did not rotate: ' . ($res['error'] ?: 'no credentials returned')];
    erp_apply_credentials((string) $j['api_key'], (string) $j['secrets']['site_to_book'], (string) $j['secrets']['book_to_site']);
    erp_log('system', 'rotate', 'Credentials rotated (requested by the store).');
    return [true, 'Credentials rotated.'];
}

function erp_apply_credentials(string $apiKey, string $siteToBook, string $bookToSite): void {
    erp_conn_update(['api_key_enc' => erp_enc($apiKey), 'site_api_key_hash' => hash('sha256', $apiKey), 'secret_site_to_book_enc' => erp_enc($siteToBook), 'secret_book_to_site_enc' => erp_enc($bookToSite)]);
}

/** Book-initiated rotation. The request itself was authenticated with the OLD credentials. */
function erp_rotate_inbound(array $body): array {
    $key = (string) ($body['api_key'] ?? ''); $s1 = (string) ($body['secrets']['site_to_book'] ?? ''); $s2 = (string) ($body['secrets']['book_to_site'] ?? '');
    if (strlen($key) < 32 || strlen($s1) < 32 || strlen($s2) < 32) throw new ErpApiError('invalid_request', 'api_key and both secrets are required (32+ characters).', 400);
    erp_apply_credentials($key, $s1, $s2);
    erp_log('system', 'rotate', 'Credentials rotated (requested by the book).');
    return ['ok' => true];
}

/* ------------------------------------------------- initial matching ------ */

const ERP_MATCH_ENTITIES = ['category', 'product', 'customer'];

function erp_match_key(string $entity, array $f): array {
    if ($entity === 'product') return ['sku' => strtolower(trim((string) ($f['sku'] ?? '')))];
    if ($entity === 'category') return ['name' => mb_strtolower(trim((string) ($f['name'] ?? '')))];
    return ['phone' => erp_phone_norm($f['phone'] ?? null), 'email' => strtolower(trim((string) ($f['email'] ?? '')))];
}

/** Pulls the book's catalog and customers and prepares the review list. Nothing is changed on either side. */
function erp_initial_scan(): array {
    if (!erp_active()) return [false, 'Link the store first.'];
    if (erp_setup_done()) return [false, 'Initial setup is already finished.'];
    $pdo = db();
    $remote = [];
    foreach (ERP_MATCH_ENTITIES as $entity) {
        if (!erp_scope_allows($entity)) continue;
        $cursor = '';
        for ($page = 0; $page < 200; $page++) {
            $res = erp_http_book('GET', 'snapshot/' . $entity . '?limit=200' . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : ''));
            if (!$res['ok'] || !is_array($res['json']['items'] ?? null)) return [false, 'Could not read the book\'s ' . $entity . ' list: ' . ($res['error'] ?: 'unexpected answer')];
            foreach ($res['json']['items'] as $it) if (!empty($it['entity_uuid']) && is_array($it['fields'] ?? null) && empty($it['archived'])) $remote[$entity][] = $it;
            if (empty($res['json']['has_more'])) break;
            $cursor = (string) ($res['json']['cursor'] ?? '');
            if ($cursor === '') break;
        }
    }
    $pdo->exec("DELETE FROM sync_match_items WHERE status = 'pending'");
    $ins = $pdo->prepare('INSERT INTO sync_match_items (entity, kind, remote_uuid, remote_data, local_id, status) VALUES (?,?,?,?,?,?)');
    $counts = ['matches' => 0, 'remote_only' => 0, 'local_only' => 0];
    $tables = ['category' => 'categories', 'product' => 'products', 'customer' => 'sync_customers'];
    foreach (ERP_MATCH_ENTITIES as $entity) {
        $locals = $pdo->query('SELECT * FROM ' . $tables[$entity] . ($entity === 'customer' ? ' WHERE is_archived = 0' : ''))->fetchAll();
        $used = [];
        $already = $pdo->prepare('SELECT 1 FROM sync_links WHERE connection_id = ? AND entity = ? AND local_id = ? AND last_payload IS NOT NULL');
        $isLinkedLocal = function (int $id) use ($already, $entity) { $already->execute([erp_connection_id(), $entity, $id]); return (bool) $already->fetchColumn(); };
        foreach ($remote[$entity] ?? [] as $it) {
            if (erp_link_get($entity, $it['entity_uuid'])) continue; // already linked
            $k = erp_match_key($entity, $it['fields']);
            $hit = null;
            foreach ($locals as $l) {
                if (isset($used[$l['id']]) || $isLinkedLocal((int) $l['id'])) continue;
                $lk = erp_match_key($entity, $l);
                if ($entity === 'customer') { if (($k['phone'] && $k['phone'] === $lk['phone']) || ($k['email'] && $k['email'] === $lk['email'])) { $hit = $l; break; } }
                elseif ($k[array_key_first($k)] !== '' && $k === $lk) { $hit = $l; break; }
            }
            if ($hit) { $used[$hit['id']] = true; $ins->execute([$entity, 'sku_match', $it['entity_uuid'], erp_json($it['fields']), $hit['id'], 'pending']); $counts['matches']++; }
            else { $ins->execute([$entity, 'remote_only', $it['entity_uuid'], erp_json($it['fields']), null, 'pending']); $counts['remote_only']++; }
        }
        foreach ($locals as $l) {
            if (isset($used[$l['id']]) || $isLinkedLocal((int) $l['id'])) continue;
            if ($entity === 'product' && !empty($l['archived_at'])) continue;
            $ins->execute([$entity, 'local_only', null, null, $l['id'], 'pending']); $counts['local_only']++;
        }
    }
    erp_log('system', 'setup', 'Initial scan: ' . $counts['matches'] . ' match(es), ' . $counts['remote_only'] . ' only at the book, ' . $counts['local_only'] . ' only here.');
    return [true, 'Scan finished: ' . $counts['matches'] . ' possible match(es), ' . $counts['remote_only'] . ' item(s) only at the book, ' . $counts['local_only'] . ' only here.'];
}

/** Applies the owner's decision for one review row. */
function erp_match_resolve(int $itemId, string $action): string {
    $st = db()->prepare("SELECT * FROM sync_match_items WHERE id = ? AND status = 'pending'"); $st->execute([$itemId]);
    $it = $st->fetch(); if (!$it) return 'That item was already handled.';
    $entity = $it['entity']; $remote = $it['remote_data'] ? json_decode($it['remote_data'], true) : [];
    $set = fn (string $s) => db()->prepare('UPDATE sync_match_items SET status = ? WHERE id = ?')->execute([$s, $itemId]);
    if ($action === 'ignore') { $set('ignored'); return 'Left out of the sync.'; }
    $cls = erp_mapper($entity);
    if ($it['kind'] === 'sku_match' && $action === 'link') {
        $localId = (int) $it['local_id'];
        $link = erp_link_by_local($entity, $localId);
        if ($link) db()->prepare('DELETE FROM sync_links WHERE id = ?')->execute([$link['id']]);
        erp_link_create($entity, $localId, $it['remote_uuid']);
        $l = erp_link_get($entity, $it['remote_uuid']);
        $built = $cls::build($localId);
        erp_link_update((int) $l['id'], ['last_payload' => $built !== null ? erp_json($built) : null, 'content_hash' => $built !== null ? sha1(erp_json($built)) : null, 'last_synced_at' => gmdate('Y-m-d H:i:s')]);
        $set('linked'); return 'Linked.';
    }
    if ($it['kind'] === 'remote_only' && $action === 'create') {
        $f = $remote;
        if (!empty($f['category_uuid']) && !erp_local_for('category', $f['category_uuid'])) $f['category_uuid'] = null; // its category was left out
        try {
            $id = erp_applying(fn () => $cls::apply('create', null, $f, ['uuid' => $it['remote_uuid'], 'event' => ['payload' => []], 'force_new' => true]));
        } catch (ErpReject | ErpConflictResult $e) { return 'Could not create it here: ' . $e->getMessage(); }
        erp_link_create($entity, $id, $it['remote_uuid']);
        $l = erp_link_get($entity, $it['remote_uuid']);
        $built = $cls::build((int) $id);
        erp_link_update((int) $l['id'], ['last_payload' => erp_json($built), 'content_hash' => sha1(erp_json($built)), 'last_synced_at' => gmdate('Y-m-d H:i:s')]);
        $set('created_here'); return 'Created here.';
    }
    if ($it['kind'] === 'local_only' && $action === 'push') { $set('pushed'); return 'Will be sent to the book when setup finishes.'; }
    return 'That choice does not apply to this item.';
}

function erp_match_bulk(string $entity, string $kind, string $action): int {
    $st = db()->prepare("SELECT id FROM sync_match_items WHERE status = 'pending' AND kind = ? AND (? = '' OR entity = ?) ORDER BY FIELD(entity, 'category','product','customer'), id");
    $st->execute([$kind, $entity, $entity]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $r = erp_match_resolve((int) $id, $action); if (in_array($r, ['Linked.', 'Created here.', 'Left out of the sync.', 'Will be sent to the book when setup finishes.'], true)) $n++; }
    return $n;
}

function erp_match_pending_count(): int { return (int) db()->query("SELECT COUNT(*) FROM sync_match_items WHERE status = 'pending'")->fetchColumn(); }

/**
 * Ends the setup review: from now on changes flow both ways. Everything the owner chose to push,
 * plus payment methods, coupons, tax and delivery charges, is queued for the book.
 */
function erp_finish_setup(): array {
    if (!erp_active()) return [false, 'Link the store first.'];
    if (erp_setup_done()) return [true, 'Already finished.'];
    if ($n = erp_match_pending_count()) return [false, $n . ' item(s) still need a decision — link, create, push or ignore them (or use the bulk buttons).'];
    set_setting('erp_setup_done', '1');
    $pdo = db();
    $queued = 0;
    $pdo->beginTransaction();
    try {
        foreach (['category', 'product', 'customer'] as $entity) {
            $st = $pdo->prepare("SELECT local_id FROM sync_match_items WHERE entity = ? AND status = 'pushed' AND local_id IS NOT NULL ORDER BY id");
            $st->execute([$entity]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lid) if (erp_emit($entity, (int) $lid, 'auto')) $queued++;
        }
        foreach ($pdo->query('SELECT id FROM payment_methods ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) if (erp_emit('payment_method', (int) $id, 'auto')) $queued++;
        foreach ($pdo->query('SELECT id FROM coupons ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) if (erp_emit('coupon', (int) $id, 'auto')) $queued++;
        if (erp_emit('tax', 1, 'auto')) $queued++;
        foreach ([1, 2, 3] as $z) if (erp_emit('delivery_charge', $z, 'auto')) $queued++;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_setting('erp_setup_done', '0');
        error_log('[erp finish] ' . $e->getMessage());
        return [false, 'Could not finish setup: ' . $e->getMessage()];
    }
    erp_log('system', 'setup', 'Setup finished; ' . $queued . ' event(s) queued for the book.');
    erp_flush(3);
    return [true, 'Setup finished. ' . $queued . ' item(s) are being sent to the book.'];
}

/** Resolves a customer-match conflict: 'link' the incoming customer to the existing one, or keep them as 'separate' people. */
function erp_resolve_customer_match(int $conflictId, string $action, string $by): string {
    $st = db()->prepare("SELECT * FROM sync_conflicts WHERE id = ? AND kind = 'customer_match' AND status = 'open'"); $st->execute([$conflictId]);
    $c = $st->fetch(); if (!$c) return 'That conflict was already resolved.';
    $ev = json_decode((string) $c['remote_data'], true) ?: []; $local = json_decode((string) $c['local_data'], true) ?: [];
    $uuid = $c['entity_uuid'];
    if ($action === 'link' && !empty($local['id'])) {
        // The local customer may already have its own shared id; the book's id replaces it.
        if ($old = erp_link_by_local('customer', (int) $local['id'])) db()->prepare('DELETE FROM sync_links WHERE id = ?')->execute([$old['id']]);
        if (!erp_link_get('customer', $uuid)) erp_link_create('customer', (int) $local['id'], $uuid);
        $l = erp_link_get('customer', $uuid);
        $built = ErpMapCustomer::build((int) $local['id']);
        erp_link_update((int) $l['id'], ['local_id' => (int) $local['id'], 'last_payload' => erp_json($built), 'content_hash' => sha1(erp_json($built)), 'remote_version' => (int) ($ev['version'] ?? 0)]);
        $res = 'linked';
    } elseif ($action === 'separate') {
        $f = $ev['payload']['fields'] ?? [];
        $id = erp_applying(fn () => ErpMapCustomer::apply('create', null, $f, ['uuid' => $uuid, 'event' => $ev, 'force_new' => true]));
        $l = erp_link_get('customer', $uuid) ?? erp_link_create('customer', $id, $uuid);
        $built = ErpMapCustomer::build((int) $id);
        erp_link_update((int) $l['id'], ['local_id' => $id, 'last_payload' => erp_json($built), 'content_hash' => sha1(erp_json($built)), 'remote_version' => (int) ($ev['version'] ?? 0)]);
        $res = 'kept_separate';
    } else return 'Choose link or keep separate.';
    db()->prepare("UPDATE sync_conflicts SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$res, mb_substr($by, 0, 120), $conflictId]);
    return $res === 'linked' ? 'Linked to the existing customer.' : 'Kept as a separate customer.';
}
