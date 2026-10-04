<?php
/**
 * Central config. All values come from environment variables so the same
 * code works unmodified inside the docker container. See docker-compose.yml
 * and .env.example for how these are supplied.
 */

function env_val(string $key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/**
 * Guarded with defined() checks (rather than plain define()) because PHP's
 * built-in `php -S` dev server can keep a worker process alive across
 * keep-alive requests, and this file is requested very early via
 * require_once from several entry points. The guards make repeated
 * evaluation harmless in any environment (dev server, php-fpm, CLI tests).
 */
if (!defined('DB_HOST')) {
    define('DB_HOST', env_val('DB_HOST', 'db'));
    define('DB_NAME', env_val('DB_NAME', 'store_db'));
    define('DB_USER', env_val('DB_USER', 'admin'));
    define('DB_PASS', env_val('DB_PASS', 'admin'));

    define('SITE_NAME', env_val('SITE_NAME', 'Online Store'));
    define('SITE_URL', rtrim(env_val('SITE_URL', ''), '/')); // e.g. https://shop.example.com, blank = relative
    // Named STORE_CURRENCY_SYMBOL (not CURRENCY_SYMBOL) because PHP's
    // standard extension already defines a built-in CURRENCY_SYMBOL
    // constant (used by nl_langinfo()) — defining our own under that name
    // silently collides with it.
    define('STORE_CURRENCY_SYMBOL', env_val('CURRENCY_SYMBOL', '৳'));
    define('STORE_CURRENCY_CODE', strtoupper(env_val('CURRENCY_CODE', 'BDT'))); // ISO 4217, used in link-preview / search-engine product data

    // --- Shipping (flat zone fee + per-kg surcharge over the free weight) ---
    define('SHIPPING_INSIDE_DHAKA_FEE', (float) env_val('SHIPPING_INSIDE_DHAKA_FEE', 80));
    define('SHIPPING_SUBURBS_FEE', (float) env_val('SHIPPING_SUBURBS_FEE', 100));
    define('SHIPPING_OUTSIDE_DHAKA_FEE', (float) env_val('SHIPPING_OUTSIDE_DHAKA_FEE', 130));
    define('SHIPPING_FREE_WEIGHT_KG', (float) env_val('SHIPPING_FREE_WEIGHT_KG', 1));
    define('SHIPPING_EXTRA_PER_KG', (float) env_val('SHIPPING_EXTRA_PER_KG', 20));
    define('DELIVERY_DAYS_MIN', (int) env_val('DELIVERY_DAYS_MIN', 3));
    define('DELIVERY_DAYS_MAX', (int) env_val('DELIVERY_DAYS_MAX', 5));

    // --- Outbound email (PHPMailer). Leave SMTP_HOST blank to fall back to
    // PHP's built-in mail() — fine for testing, but most hosts need real SMTP
    // creds to actually deliver mail. ---
    define('SMTP_HOST', env_val('SMTP_HOST', ''));
    define('SMTP_PORT', (int) env_val('SMTP_PORT', 587));
    define('SMTP_USER', env_val('SMTP_USER', ''));
    define('SMTP_PASS', env_val('SMTP_PASS', ''));
    define('SMTP_SECURE', env_val('SMTP_SECURE', 'tls')); // tls | ssl | '' (none)
    define('SMTP_FROM_EMAIL', env_val('SMTP_FROM_EMAIL', env_val('CONTACT_EMAIL', '')));
    define('SMTP_FROM_NAME', env_val('SMTP_FROM_NAME', env_val('SITE_NAME', 'Online Store')));

    // --- Contact / social links ---
    define('CONTACT_EMAIL', env_val('CONTACT_EMAIL', ''));
    define('CONTACT_PHONE', env_val('CONTACT_PHONE', ''));
    define('CONTACT_PHONE_2', env_val('CONTACT_PHONE_2', '')); // optional second number
    define('STORE_ADDRESS', env_val('STORE_ADDRESS', ''));
    define('SOCIAL_FACEBOOK', env_val('SOCIAL_FACEBOOK', ''));
    define('SOCIAL_FACEBOOK_MESSENGER', env_val('SOCIAL_FACEBOOK_MESSENGER', ''));
    define('SOCIAL_INSTAGRAM', env_val('SOCIAL_INSTAGRAM', ''));
    define('SOCIAL_YOUTUBE', env_val('SOCIAL_YOUTUBE', ''));
    define('SOCIAL_WHATSAPP', env_val('SOCIAL_WHATSAPP', '')); // a wa.me link, or just the number
    define('SOCIAL_TIKTOK', env_val('SOCIAL_TIKTOK', ''));
    define('SOCIAL_SIGNAL', env_val('SOCIAL_SIGNAL', '')); // a signal.me link, or just the number

    define('UPLOAD_DIR', __DIR__ . '/../uploads/products');
    define('UPLOAD_URL', '/uploads/products');
    define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5MB — product photos are shown near their original size, no resize step
    // Branding assets (logo/favicon/banner) get scaled down server-side on upload (see brand_upload()),
    // so the raw file people pick — often a straight-from-camera/phone photo — can be much bigger than
    // what's actually stored. Kept just under the nginx/php upload ceilings (see docker/nginx/default.conf
    // client_max_body_size and docker/php/uploads.ini upload_max_filesize) so this is always the limit
    // that's actually hit, with a clear message, instead of a generic transport-level failure.
    define('BRAND_MAX_UPLOAD_BYTES', 15 * 1024 * 1024); // 15MB
}

// Session cookie hardening - must run before session_start()
if (session_status() === PHP_SESSION_NONE && !defined('ERP_NO_SESSION')) {
    // Detect HTTPS directly or via a reverse proxy (nginx/Portainer setups
    // commonly terminate TLS in front of this container), so the cookie
    // only gets the `secure` flag when it's actually safe to require it.
    $__isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $__isHttps,
    ]);
    session_start();
}

date_default_timezone_set(env_val('TZ', 'Asia/Dhaka'));
error_reporting(E_ALL);
ini_set('display_errors', env_val('APP_DEBUG', '0') === '1' ? '1' : '0');
