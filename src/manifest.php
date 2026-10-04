<?php
/** /manifest.webmanifest — lets phones "Add to Home screen" with the store's name and icon. Served through nginx. */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$s = store_info();
$short = trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $s['name'])) ?: $s['name'];
$icon = function (int $px): array {
    $u = brand_icon_url($px);
    return ['src' => $u, 'sizes' => $px . 'x' . $px, 'type' => 'image/png', 'purpose' => 'any'];
};
echo json_encode([
    'name' => $s['name'],
    'short_name' => mb_substr($short, 0, 14),
    'description' => meta_trim($s['description'], 150),
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',
    'background_color' => '#efece2',
    'theme_color' => '#f8f6ee',
    'icons' => [$icon(192), $icon(512)],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
