<?php
/**
 * GET /.well-known/erp-verify?token=...  — domain-ownership proof (step 4 of the connection lifecycle).
 * The book calls this over HTTPS and expects HMAC-SHA256("erp-verify\n" + token) made with the
 * site_to_book secret it issued, proving whoever controls this domain holds that secret.
 * Only answers while the store is waiting to be linked.
 */
define('ERP_NO_SESSION', true);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../includes/functions.php';
set_exception_handler(function (Throwable $e) { error_log('[erp verify] ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Verification failed.']]); });

$fail = function (int $s, string $code, string $msg): never { http_response_code($s); echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $msg]]); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') $fail(405, 'method_not_allowed', 'Use GET.');
if (!erp_rate_ok('verify:' . client_ip(), 30)) $fail(429, 'rate_limited', 'Too many requests.');
$token = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $token)) $fail(400, 'invalid_request', 'A token of 16-128 URL-safe characters is required.');
$c = erp_conn(true);
if (!in_array($c['status'], ['verifying', 'active'], true)) $fail(409, 'invalid_state', 'This store is not waiting to be verified.');
$secret = erp_secret_out();
if (!$secret) $fail(503, 'not_configured', 'No secret configured.');
erp_conn_update(['verify_token' => hash('sha256', $token), 'verified_at' => gmdate('Y-m-d H:i:s')]);
erp_log('in', 'verify', 'The book checked domain ownership.', true, null, 200);
echo json_encode(['ok' => true, 'connection_id' => $c['connection_id'], 'token' => $token, 'proof' => erp_verify_proof($secret, $token)]);
