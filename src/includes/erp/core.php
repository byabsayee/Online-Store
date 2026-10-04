<?php
/**
 * ERP / accounting integration module — core helpers (protocol v1, see docs/INTEGRATION.md).
 *
 * This module is self-contained: its tables are prefixed sync_, its routes live under
 * /api/erp/v1/ and /.well-known/erp-verify, and its admin screens are admin/erp*.php.
 * It ships disabled (sync_connection.status = 'disabled') and every hook into the rest of
 * the store is a no-op until an owner links the store to a book. Nothing here depends on the
 * book living on the same server: everything goes over public HTTPS.
 */
require_once __DIR__ . '/../functions.php';

const ERP_MODULE_VERSION = '1.0.0';
const ERP_API_VERSION = 'v1';
/** What this store can do. The handshake exchanges these; unknown ones are ignored by the peer. */
const ERP_CAPABILITIES = ['categories', 'products', 'variants', 'stock', 'customers', 'orders', 'payments',
    'payment_methods', 'coupons', 'taxes', 'delivery_charges', 'returns', 'snapshot', 'changes', 'reconcile'];
const ERP_ENTITIES = ['category', 'product', 'customer', 'order', 'payment', 'payment_method', 'stock_movement', 'coupon', 'tax', 'delivery_charge', 'return'];
const ERP_OPS = ['create', 'update', 'archive', 'restore', 'cancel', 'void'];
/** scope => entities it covers (a connection can be limited to some of them). */
const ERP_SCOPE_ENTITIES = [
    'catalog' => ['category', 'product'], 'stock' => ['stock_movement'], 'customers' => ['customer'],
    'orders' => ['order', 'return'], 'payments' => ['payment', 'payment_method'], 'money' => ['coupon', 'tax', 'delivery_charge'],
];
const ERP_TS_WINDOW = 300; // seconds of allowed clock skew
const ERP_MAX_BODY = 2097152; // 2 MB request cap
const ERP_BATCH_MAX = 50;

/* ------------------------------------------------------------ ids/time -- */

function erp_uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/** Name-based (v5) UUID: how singleton entities (tax, delivery zones) get the same id on both sides. */
function erp_uuid5(string $namespaceUuid, string $name): string {
    $ns = hex2bin(str_replace('-', '', $namespaceUuid));
    $h = sha1($ns . $name);
    $h = substr($h, 0, 8) . substr($h, 8, 4) . substr($h, 12, 4) . substr($h, 16, 4) . substr($h, 20, 12);
    $b = hex2bin($h);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x50);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $x = bin2hex($b);
    return substr($x, 0, 8) . '-' . substr($x, 8, 4) . '-' . substr($x, 12, 4) . '-' . substr($x, 16, 4) . '-' . substr($x, 20, 12);
}

function erp_is_uuid($v): bool {
    return is_string($v) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v);
}

/** UTC ISO-8601 with milliseconds, e.g. 2026-09-30T10:15:00.123Z. */
function erp_now_iso(): string {
    $t = microtime(true);
    return gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', (int) (($t - floor($t)) * 1000));
}

/** MySQL UTC datetime ('Y-m-d H:i:s') -> ISO-8601 Z. */
function erp_iso_from_db(?string $utc): ?string {
    if (!$utc || $utc === '0000-00-00 00:00:00') return null;
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
    return $d ? $d->format('Y-m-d\TH:i:s') . '.000Z' : null;
}

/** ISO-8601 -> MySQL UTC datetime, or null when it can't be parsed. */
function erp_db_from_iso($iso): ?string {
    if (!is_string($iso) || $iso === '') return null;
    try { return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return null; }
}

/** Exact decimal string with 2 places — money never travels as a float. */
function erp_money($v): string {
    return number_format(round((float) $v, 2), 2, '.', '');
}

function erp_json($v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}

/* ------------------------------------------------------- connection ----- */

/** The single connection row (id = 1). Cached per request; pass true to reload. */
function erp_conn(bool $fresh = false): array {
    static $c = null;
    if ($c === null || $fresh) {
        $c = db()->query('SELECT * FROM sync_connection WHERE id = 1')->fetch() ?: [];
        if (!$c) {
            db()->exec("INSERT IGNORE INTO sync_connection (id, status) VALUES (1, 'disabled')");
            $c = db()->query('SELECT * FROM sync_connection WHERE id = 1')->fetch() ?: ['status' => 'disabled'];
        }
    }
    return $c;
}

function erp_conn_update(array $cols): void {
    if (!$cols) return;
    $set = []; $vals = [];
    foreach ($cols as $k => $v) { $set[] = "`$k` = ?"; $vals[] = $v; }
    db()->prepare('UPDATE sync_connection SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($vals);
    erp_conn(true);
}

function erp_status(): string { return (string) (erp_conn()['status'] ?? 'disabled'); }
/** Events are queued while linked (active or paused). */
function erp_linked(): bool { return in_array(erp_status(), ['active', 'paused'], true); }
function erp_setup_done(): bool { return get_setting('erp_setup_done', '0') === '1'; }
function erp_active(): bool { return erp_status() === 'active'; }
function erp_connection_id(): string { return (string) (erp_conn()['connection_id'] ?? ''); }

function erp_caps_peer(): array {
    $c = json_decode((string) (erp_conn()['peer_capabilities'] ?? ''), true);
    return is_array($c) ? $c : [];
}
function erp_peer_can(string $cap): bool { return in_array($cap, erp_caps_peer(), true); }

function erp_scopes(): array {
    $s = json_decode((string) (erp_conn()['scopes'] ?? ''), true);
    return is_array($s) ? $s : array_keys(ERP_SCOPE_ENTITIES);
}
function erp_scope_allows(string $entity): bool {
    $have = erp_scopes();
    foreach (ERP_SCOPE_ENTITIES as $scope => $ents) if (in_array($entity, $ents, true)) return in_array($scope, $have, true);
    return false;
}

/* -------------------------------------------------------------- crypto -- */

/** Secrets are encrypted at rest. ERP_SECRET_KEY (env) is preferred; the app secret is the fallback. */
function erp_key(): string {
    $env = (string) env_val('ERP_SECRET_KEY', '');
    return hash('sha256', 'erp-v1|' . ($env !== '' ? $env : app_secret()), true);
}
function erp_key_is_external(): bool { return (string) env_val('ERP_SECRET_KEY', '') !== ''; }

function erp_enc(string $plain): string {
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', erp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function erp_dec(?string $blob): ?string {
    if (!$blob || strncmp($blob, 'v1:', 3) !== 0) return null;
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', erp_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? null : $pt;
}

function erp_secret_out(): ?string { return erp_dec(erp_conn()['secret_site_to_book_enc'] ?? null); } // we sign with this
function erp_secret_in(): ?string { return erp_dec(erp_conn()['secret_book_to_site_enc'] ?? null); } // the book signs with this
function erp_api_key_out(): ?string { return erp_dec(erp_conn()['api_key_enc'] ?? null); }

/* ------------------------------------------------------------ logging --- */

const ERP_REDACT_KEYS = ['email', 'customer_email', 'phone', 'shipping_phone', 'billing_phone', 'name', 'shipping_name', 'billing_name', 'line1', 'shipping_line1', 'billing_line1',
    'city', 'shipping_city', 'billing_city', 'state', 'zip', 'address', 'secret', 'api_key', 'site_api_key', 'signature', 'authorization', 'password', 'token', 'pairing_code', 'secrets', 'notes', 'reference'];

function erp_redact($v) {
    if (!is_array($v)) return $v;
    $out = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && in_array(strtolower($k), ERP_REDACT_KEYS, true)) { $out[$k] = '[redacted]'; continue; }
        $out[$k] = erp_redact($x);
    }
    return $out;
}

function erp_log(string $direction, string $kind, string $summary, bool $ok = true, ?string $eventId = null, ?int $http = null, $detail = null): void {
    try {
        $d = $detail === null ? null : (is_string($detail) ? $detail : json_encode(erp_redact($detail), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        db()->prepare('INSERT INTO sync_log (direction, kind, event_id, http_status, ok, summary, detail) VALUES (?,?,?,?,?,?,?)')
            ->execute([$direction, $kind, $eventId, $http, $ok ? 1 : 0, mb_substr($summary, 0, 500), $d !== null ? mb_substr($d, 0, 4000) : null]);
    } catch (Throwable $e) { error_log('[erp_log] ' . $e->getMessage()); }
}

/* ----------------------------------------------------- host validation --- */

/** D1: only a dedicated public HTTPS host. Returns an error message, or null when fine. */
function erp_host_error(string $host): ?string {
    $host = strtolower(trim($host));
    if ($host === '') return 'The address is empty.';
    if (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^\[.*\]$/', $host) || preg_match('/^\d+$/', $host)) return 'IP addresses are not allowed — use a domain name.';
    if ($host === 'localhost' || preg_match('/\.(local|localhost|internal|lan|home|corp|test|invalid|example)$/', $host)) return 'Local and internal hostnames are not allowed.';
    if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) return 'That is not a valid public domain name.';
    return null;
}

/** Parse a base URL, enforcing https://, no credentials, port 443, no IP literal. */
function erp_parse_base_url(string $url, ?string &$error = null): ?array {
    $url = trim($url);
    $p = parse_url($url);
    if (!$p || empty($p['host'])) { $error = 'Enter a full address such as https://byabsayee.example.com'; return null; }
    if (strtolower($p['scheme'] ?? '') !== 'https') { $error = 'Only https:// addresses are allowed.'; return null; }
    if (isset($p['user']) || isset($p['pass'])) { $error = 'The address must not contain a username or password.'; return null; }
    if (isset($p['port']) && (int) $p['port'] !== 443) { $error = 'Only the standard HTTPS port (443) is allowed.'; return null; }
    if ($e = erp_host_error($p['host'])) { $error = $e; return null; }
    $path = rtrim($p['path'] ?? '', '/');
    return ['host' => strtolower($p['host']), 'base' => 'https://' . strtolower($p['host']) . $path];
}

/** Is this IP publicly routable? Blocks private, loopback, link-local, CGNAT, metadata and mapped-IPv6 tricks. */
function erp_ip_is_public(string $ip): bool {
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = substr($ip, 7);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $l = ip2long($ip);
        foreach (['100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '198.18.0.0/15', '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'] as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if ((($l & $mask) === (ip2long($net) & $mask))) return false;
        }
        return true;
    }
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    $b0 = ord($bin[0]); $b1 = ord($bin[1]);
    if (($b0 & 0xfe) === 0xfc) return false;          // fc00::/7 unique local
    if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return false; // fe80::/10 link-local
    if ($b0 === 0xff) return false;                    // multicast
    return true;
}

/** Test hook: only ever honoured on the command line, never for a web request. */
function erp_test_mode(): bool { return PHP_SAPI === 'cli' && defined('ERP_TEST_MODE') && ERP_TEST_MODE === true; }

/* -------------------------------------------------------- misc helpers -- */

/** Normalises a phone number to digits, turning +880/880 prefixes into the local 0-prefixed form. */
function erp_phone_norm(?string $phone): ?string {
    $d = preg_replace('/\D+/', '', (string) $phone);
    if ($d === '') return null;
    if (strpos($d, '00880') === 0) $d = substr($d, 4);
    if (strpos($d, '880') === 0 && strlen($d) >= 12) $d = '0' . substr($d, 3);
    if (strlen($d) === 10 && $d[0] === '1') $d = '0' . $d;
    return substr($d, 0, 20);
}

function erp_request_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/** A tiny advisory lock so the worker and an opportunistic flush never send the same batch twice. */
function erp_lock(string $name, int $wait = 0): bool {
    $st = db()->prepare('SELECT GET_LOCK(?, ?)');
    $st->execute(['kafeel_erp_' . $name, $wait]);
    return (int) $st->fetchColumn() === 1;
}
function erp_unlock(string $name): void {
    db()->prepare('SELECT RELEASE_LOCK(?)')->execute(['kafeel_erp_' . $name]);
}
