<?php
/** Serves a staff member's profile picture to owners and to that person only. ?id=<admin id>[&v=<version>] */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$me = current_admin();
$id = (int) ($_GET['id'] ?? 0);
if (!admin_is_owner() && $id !== (int) $me['id']) {
    http_response_code(403);
    exit('Not allowed.');
}
$s = db()->prepare('SELECT mime, data, UNIX_TIMESTAMP(updated_at) AS v FROM admin_photos WHERE admin_id = ?');
$s->execute([$id]);
$ph = $s->fetch();
if (!$ph) {
    http_response_code(404);
    exit('No picture.');
}
$etag = 'W/"p' . $id . '-' . (int) $ph['v'] . '"';
header('ETag: ' . $etag);
header('Cache-Control: private, no-cache');                        // may be reused by the browser, but only after it re-checks the ETag
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $ph['mime']);
header('Content-Length: ' . strlen($ph['data']));
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
echo $ph['data'];
