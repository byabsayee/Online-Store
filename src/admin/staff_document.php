<?php
/**
 * Serves a staff member's private document. Only owners, and the person it belongs to, get it.
 * (It is deliberately NOT stored in the public uploads folder.)  ?id=<admin id>[&download=1]
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$me = current_admin();
$id = (int) ($_GET['id'] ?? 0);

if (!admin_is_owner() && $id !== (int) $me['id']) {
    http_response_code(403);
    exit('Not allowed.');
}
$s = db()->prepare('SELECT d.original_name, d.mime, d.size, d.data, a.name FROM admin_documents d JOIN admins a ON a.id = d.admin_id WHERE d.admin_id = ?');
$s->execute([$id]);
$doc = $s->fetch();
if (!$doc) {
    http_response_code(404);
    exit('No document.');
}
// Opening someone else's ID document is recorded; looking at your own is not.
if ($id !== (int) $me['id']) {
    admin_log('staff.document_view', 'Opened the document of ' . $doc['name'] . ' (' . $doc['original_name'] . ')', 'staff', $id);
}
$disp = (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($doc['original_name']);
header('Content-Type: ' . $doc['mime']);
header('Content-Length: ' . strlen($doc['data']));
header('Content-Disposition: ' . $disp);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
echo $doc['data'];
