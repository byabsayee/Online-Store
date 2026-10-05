<?php
/** /sitemap.xml — every live page, product and category, for search engines. Served through nginx. */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$urls = [];
$add = function (string $path, ?string $lastmod = null, string $freq = 'weekly', string $prio = '0.5') use (&$urls) {
    $urls[] = ['loc' => abs_url($path), 'lastmod' => $lastmod ? gmdate('c', strtotime($lastmod . ' UTC')) : null, 'freq' => $freq, 'prio' => $prio];
};
$add('/', null, 'daily', '1.0');
$__off = site_pages_disabled_urls();
foreach (['/about', '/faq', '/contact', '/partners'] as $p) if (!in_array($p, $__off, true)) $add($p, null, 'monthly', '0.4');
foreach (['/privacy-policy', '/cookie-policy', '/terms', '/refund-policy', '/shipping-policy'] as $p) if (!in_array($p, $__off, true)) $add($p, LEGAL_LAST_UPDATED, 'yearly', '0.2');

foreach (db()->query('SELECT slug FROM categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll() as $c) {
    $add(category_url($c), null, 'weekly', '0.7');
}
foreach (db()->query('SELECT slug, updated_at FROM products WHERE is_active = 1 ORDER BY id')->fetchAll() as $p) {
    $add(product_url($p), $p['updated_at'], 'weekly', '0.8');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url><loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>"
        . ($u['lastmod'] ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '')
        . '<changefreq>' . $u['freq'] . '</changefreq><priority>' . $u['prio'] . "</priority></url>\n";
}
echo "</urlset>\n";
