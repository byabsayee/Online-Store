<?php
/** /robots.txt — served through nginx (see docker/nginx/default.conf). */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\n";
foreach (['/admin/', '/api/', '/cart', '/checkout', '/login', '/register', '/account', '/addresses', '/orders',
          '/order/', '/order-success', '/wishlist', '/invoice/', '/search', '/verify-email', '/resend-verification'] as $p) {
    echo "Disallow: $p\n";
}
echo "\nSitemap: " . abs_url('/sitemap.xml') . "\n";
