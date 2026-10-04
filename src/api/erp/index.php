<?php
/**
 * ERP integration API, protocol v1: /api/erp/v1/<route> (nginx rewrites it to this file).
 * Machine-to-machine only: no session, no CSRF, JSON in and JSON out — every failure, including
 * an unexpected PHP error, is a JSON body with a stable error code, never a blank page or HTML.
 */
define('ERP_NO_SESSION', true);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function erp_api_out(int $status, array $body, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $h) header($h);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
set_exception_handler(function (Throwable $e) {
    error_log('[erp api] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) erp_api_out(500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The store could not process this request.']]);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        error_log('[erp api fatal] ' . $e['message']);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The store could not process this request.']]);
    }
});

require_once __DIR__ . '/../../includes/functions.php';

try {
    $route = trim((string) ($_GET['route'] ?? ''), '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $routes = [
        'POST events' => 'events', 'GET changes' => 'changes', 'GET status' => 'status', 'POST disconnect' => 'disconnect',
        'POST connect/complete' => 'complete', 'POST connect/rotate' => 'rotate',
    ];
    $key = $method . ' ' . $route;
    $snapshot = $method === 'GET' && preg_match('#^snapshot/([a-z_]+)$#', $route, $m) ? $m[1] : null;
    if (!isset($routes[$key]) && !$snapshot) {
        $known = array_filter(array_keys($routes), fn ($k) => substr($k, strpos($k, ' ') + 1) === $route);
        if ($known) erp_api_out(405, ['ok' => false, 'error' => ['code' => 'method_not_allowed', 'message' => 'Use ' . implode(' or ', array_map(fn ($k) => strtok($k, ' '), $known)) . ' for this route.']]);
        erp_api_out(404, ['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Unknown route.']]);
    }
    $body = erp_authenticate_inbound();
    $json = [];
    if ($method === 'POST') {
        $json = $body === '' ? [] : json_decode($body, true);
        if (!is_array($json)) throw new ErpApiError('invalid_json', 'The request body is not valid JSON.', 400);
    }
    $limit = (int) ($_GET['limit'] ?? 100);
    if ($snapshot) erp_api_out(200, erp_snapshot($snapshot, (int) ($_GET['cursor'] ?? 0), $limit));
    switch ($routes[$key]) {
        case 'events': erp_api_out(200, erp_inbound_batch($json));
        case 'changes': erp_api_out(200, erp_changes((int) ($_GET['cursor'] ?? 0), $limit));
        case 'status': erp_api_out(200, erp_status_body());
        case 'disconnect': erp_api_out(200, erp_disconnect_inbound());
        case 'complete': erp_api_out(200, erp_connect_complete($json));
        case 'rotate': erp_api_out(200, erp_rotate_inbound($json));
    }
} catch (ErpApiError $e) {
    $hdr = isset($e->extra['retry_after']) ? ['Retry-After: ' . (int) $e->extra['retry_after']] : [];
    erp_api_out($e->httpStatus, ['ok' => false, 'error' => array_merge(['code' => $e->errCode, 'message' => $e->getMessage()], $e->extra)], $hdr);
}
