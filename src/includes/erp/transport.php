<?php
/**
 * Request signing, inbound authentication and the SSRF-safe outbound HTTPS client.
 *
 * Signature: HMAC-SHA256(secret, METHOD "\n" path "\n" timestamp "\n" nonce "\n" sha256(body))
 * where "path" is the request-target exactly as sent (path plus "?query" when present) and the
 * timestamp is Unix seconds. Both directions use the same rule; each direction has its own secret.
 */
require_once __DIR__ . '/core.php';

function erp_sign(string $secret, string $method, string $pathWithQuery, string $timestamp, string $nonce, string $body): string {
    return hash_hmac('sha256', strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
}

/** The signature proof for the domain-ownership callback. */
function erp_verify_proof(string $secret, string $token): string {
    return hash_hmac('sha256', "erp-verify\n" . $token, $secret);
}

/* ---------------------------------------------------- rate limiting ------ */

/** Fixed-window counter. Returns true when the request is within the limit. */
function erp_rate_ok(string $bucket, int $limit, int $windowSeconds = 60): bool {
    $win = intdiv(time(), $windowSeconds);
    $pdo = db();
    $pdo->prepare('INSERT INTO sync_rate (bucket, win, cnt) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1')->execute([mb_substr($bucket, 0, 90), $win]);
    $st = $pdo->prepare('SELECT cnt FROM sync_rate WHERE bucket = ? AND win = ?');
    $st->execute([mb_substr($bucket, 0, 90), $win]);
    if (mt_rand(1, 50) === 1) $pdo->prepare('DELETE FROM sync_rate WHERE win < ?')->execute([$win - 5]);
    return (int) $st->fetchColumn() <= $limit;
}

/* ----------------------------------------------------- inbound auth ------ */

class ErpApiError extends RuntimeException {
    public string $errCode; public int $httpStatus; public array $extra;
    public function __construct(string $code, string $message, int $http = 400, array $extra = []) {
        parent::__construct($message);
        $this->errCode = $code; $this->httpStatus = $http; $this->extra = $extra;
    }
}

/**
 * Authenticates a request from the book: HTTPS, known connection, API key (Bearer) and a valid,
 * fresh, unreplayed signature made with the book_to_site secret. Throws ErpApiError otherwise.
 * Returns the raw body so the caller does not have to read php://input a second time.
 */
function erp_authenticate_inbound(): string {
    $ip = client_ip();
    // Failed attempts are throttled per IP so a scanner can't grind on the signature check.
    if (!erp_rate_ok('ip:' . $ip, 240)) throw new ErpApiError('rate_limited', 'Too many requests.', 429, ['retry_after' => 60]);
    if (!erp_test_mode() && !erp_request_is_https()) throw new ErpApiError('https_required', 'This endpoint only accepts HTTPS.', 400);

    $conn = erp_conn(true);
    $status = $conn['status'] ?? 'disabled';
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > ERP_MAX_BODY) throw new ErpApiError('payload_too_large', 'Request body too large.', 413);
    $body = (string) file_get_contents('php://input', false, null, 0, ERP_MAX_BODY + 1);
    if (strlen($body) > ERP_MAX_BODY) throw new ErpApiError('payload_too_large', 'Request body too large.', 413);

    $fail = function (string $code, string $msg, int $http = 401) use ($ip): never {
        erp_rate_ok('bad:' . $ip, 30);
        erp_log('in', 'auth', 'Rejected request: ' . $code, false, null, $http, ['ip' => $ip, 'path' => $_SERVER['REQUEST_URI'] ?? '']);
        throw new ErpApiError($code, $msg, $http);
    };

    $cid = (string) ($_SERVER['HTTP_X_CONNECTION_ID'] ?? '');
    if (!in_array($status, ['active', 'paused', 'verifying'], true) || $cid === '' || !hash_equals((string) ($conn['connection_id'] ?? ''), $cid)) {
        $fail('unknown_connection', 'Unknown or inactive connection.');
    }
    if ($status === 'paused') throw new ErpApiError('connection_paused', 'This connection is paused.', 503, ['retry_after' => 300]);

    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    $key = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
    if ($key === '' || empty($conn['site_api_key_hash']) || !hash_equals((string) $conn['site_api_key_hash'], hash('sha256', $key))) $fail('bad_api_key', 'Invalid API key.');

    $ts = (string) ($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
    $nonce = (string) ($_SERVER['HTTP_X_NONCE'] ?? '');
    $sig = strtolower((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
    if (!ctype_digit($ts) || abs(time() - (int) $ts) > ERP_TS_WINDOW) $fail('timestamp_out_of_range', 'Timestamp is outside the allowed window (±5 minutes).');
    if (!preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $nonce)) $fail('bad_nonce', 'Missing or malformed nonce.');
    $secret = erp_secret_in();
    if (!$secret) $fail('not_configured', 'The signing secret is not configured.', 503);

    $expected = erp_sign($secret, $_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', $ts, $nonce, $body);
    if (!hash_equals($expected, $sig)) $fail('bad_signature', 'Signature does not match.');

    // Replay guard: a nonce is accepted once within the window (checked after the signature so junk can't fill the table).
    $pdo = db();
    $pdo->prepare('DELETE FROM sync_nonces WHERE expires_at < UTC_TIMESTAMP()')->execute();
    try {
        $pdo->prepare('INSERT INTO sync_nonces (nonce, expires_at) VALUES (?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))')->execute([hash('sha256', $cid . $nonce), ERP_TS_WINDOW * 2]);
    } catch (PDOException $e) {
        $fail('replayed', 'This request was already received.');
    }
    if (!erp_rate_ok('conn:' . $cid, 600)) throw new ErpApiError('rate_limited', 'Too many requests for this connection.', 429, ['retry_after' => 60]);
    return $body;
}

/* --------------------------------------------------- outbound client ----- */

/**
 * Signed request to the book. Only ever talks to the registered book host over HTTPS on port 443:
 * DNS is resolved here, every address must be public, and the connection is pinned to the checked
 * address (so DNS can't change between the check and the connect). No redirects are followed.
 *
 * @return array{ok:bool,status:int,json:?array,error:?string,body:string}
 */
function erp_http_book(string $method, string $path, ?array $json = null, array $opts = []): array {
    $conn = erp_conn();
    $base = (string) ($conn['book_base_url'] ?? '');
    $parsed = erp_parse_base_url($base, $err);
    $testMode = erp_test_mode();
    if (!$parsed && $testMode && $base !== '') { $parsed = ['host' => (string) parse_url($base, PHP_URL_HOST), 'base' => rtrim($base, '/')]; }
    if (!$parsed) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'The book address is not valid: ' . $err, 'body' => ''];
    $url = $parsed['base'] . '/api/v1/integrations/' . ltrim($path, '/');
    $body = $json === null ? '' : erp_json($json);
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $headers = ['Accept: application/json', 'User-Agent: StoreERP/' . ERP_MODULE_VERSION, 'X-Connection-Id: ' . ($opts['connection_id'] ?? erp_connection_id())];
    if ($json !== null) $headers[] = 'Content-Type: application/json';
    if (!empty($opts['batch_id'])) $headers[] = 'X-Event-Batch-Id: ' . $opts['batch_id'];
    if (empty($opts['unsigned'])) {
        $secret = $opts['secret'] ?? erp_secret_out();
        $key = $opts['api_key'] ?? erp_api_key_out();
        if (!$secret || !$key) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'The connection credentials are missing.', 'body' => ''];
        $reqTarget = (string) parse_url($url, PHP_URL_PATH) . (($q = parse_url($url, PHP_URL_QUERY)) ? '?' . $q : '');
        $headers[] = 'Authorization: Bearer ' . $key;
        $headers[] = 'X-Timestamp: ' . $ts;
        $headers[] = 'X-Nonce: ' . $nonce;
        $headers[] = 'X-Signature: ' . erp_sign($secret, $method, $reqTarget, $ts, $nonce, $body);
    }
    return erp_http_request($method, $url, $body, $headers, (int) ($opts['timeout'] ?? 20));
}

/** Low-level guarded request (shared with the domain-verification tests). */
function erp_http_request(string $method, string $url, string $body, array $headers, int $timeout = 20): array {
    $fail = fn (string $m) => ['ok' => false, 'status' => 0, 'json' => null, 'error' => $m, 'body' => ''];
    $p = parse_url($url);
    $test = erp_test_mode();
    if (!$p || empty($p['host'])) return $fail('Invalid URL.');
    $host = strtolower($p['host']);
    $scheme = strtolower($p['scheme'] ?? '');
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!$test) {
        if ($scheme !== 'https' || $port !== 443) return $fail('Only https on port 443 is allowed.');
        if ($e = erp_host_error($host)) return $fail($e);
    }
    // Resolve and vet every address; pin the connection to one of them.
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) { $ips[] = $host; }
    else {
        foreach ((array) @dns_get_record($host, DNS_A) as $r) if (!empty($r['ip'])) $ips[] = $r['ip'];
        foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        if (!$ips) { $g = @gethostbynamel($host); if ($g) $ips = $g; }
    }
    if (!$ips) return $fail('Could not resolve ' . $host . '.');
    if (!$test) foreach ($ips as $ip) if (!erp_ip_is_public($ip)) return $fail($host . ' resolves to a non-public address, which is not allowed.');
    $pin = $ips[0];

    $ch = curl_init($url);
    $got = '';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => !$test,
        CURLOPT_SSL_VERIFYHOST => $test ? 0 : 2,
        CURLOPT_PROTOCOLS => $test ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($pin, ':') !== false ? '[' . $pin . ']' : $pin)],
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$got) {
            $got .= $chunk;
            return strlen($got) > 4 * 1024 * 1024 ? 0 : strlen($chunk); // response size cap
        },
    ]);
    if ($body !== '' || in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ok === false && $status === 0) return $fail($err !== '' ? $err : 'The request failed.');
    $json = json_decode($got, true);
    if ($status >= 300 && $status < 400) return ['ok' => false, 'status' => $status, 'json' => null, 'error' => 'Redirects are not followed (the book answered with HTTP ' . $status . ').', 'body' => ''];
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'json' => is_array($json) ? $json : null,
        'error' => $status >= 200 && $status < 300 ? null : (is_array($json) ? ($json['error']['message'] ?? ($json['message'] ?? 'HTTP ' . $status)) : 'HTTP ' . $status), 'body' => $got];
}
