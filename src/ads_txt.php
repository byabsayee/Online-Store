<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$t = trim((string) get_setting('ads_txt', ''));
if ($t === '') { http_response_code(404); echo "Not found\n"; exit; }
echo $t . "\n";
