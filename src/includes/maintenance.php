<?php
/**
 * Maintenance mode. While it is on, every visitor sees a friendly "Under Maintenance" page (HTTP 503, so search
 * engines know it is temporary). Still reachable: the admin portal (so you can switch it off again), the signed
 * accounting-link API, and — for convenience — the storefront for anyone signed in to the admin portal.
 * Settings (Admin → Store settings → Maintenance): maintenance_enabled, maintenance_title, maintenance_message,
 * maintenance_eta.
 */

const MAINTENANCE_DEFAULT_TITLE = 'Under Maintenance';
const MAINTENANCE_DEFAULT_MESSAGE = "We're making a few improvements to the store. We'll be back very soon — thank you for your patience.";

function maintenance_settings(): array {
    return [
        'enabled' => get_setting('maintenance_enabled', '0') === '1',
        'title' => trim((string) get_setting('maintenance_title', '')) ?: MAINTENANCE_DEFAULT_TITLE,
        'message' => trim((string) get_setting('maintenance_message', '')) ?: MAINTENANCE_DEFAULT_MESSAGE,
        'eta' => trim((string) get_setting('maintenance_eta', '')),
    ];
}

function maintenance_enabled(): bool {
    try { return get_setting('maintenance_enabled', '0') === '1'; } catch (Throwable $e) { return false; }
}

/** Scripts that must keep working during maintenance. */
function maintenance_path_exempt(): bool {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    return strpos($script, '/admin/') === 0 || strpos($script, '/api/erp/') === 0 || $script === '/erp_worker.php';
}

/** Draws the page (200 for a preview, 503 for real visitors). Does not exit. */
function maintenance_render(array $m, bool $preview = false): void {
    if (!$preview) {
        http_response_code(503);
        header('Retry-After: 3600');
    }
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Type: text/html; charset=UTF-8');
    $t = theme_settings();
    $primary = $t['primary'];
    $onPrimary = function_exists('contrast_text') ? contrast_text($primary) : '#ffffff';
    $logoLight = function_exists('brand_logo') ? brand_logo() : null;
    $logoDark = $logoLight ? brand_logo_dark() : null;
    $name = store_name();
    $signal = '';
    try { $socials = store_socials(); $signal = $socials['signal']['url'] ?? ''; } catch (Throwable $e) { /* optional */ }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($m['title']) ?> — <?= e($name) ?></title>
<?= function_exists('brand_head_icons') ? brand_head_icons() : '' ?>
<?= function_exists('font_head_html') ? font_head_html() : '' ?>
<style>
:root { --bg:#f1f0ea; --card:#ffffff; --ink:#20293b; --ink-soft:#4a5670; --line:#dedbd0; --accent:<?= e($primary) ?>; --on-accent:<?= e($onPrimary) ?>; }
@media (prefers-color-scheme: dark) { :root { --bg:#161b27; --card:#1f2636; --ink:#f2f3f7; --ink-soft:#aab2c5; --line:#303a52; } }
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px; background: var(--bg); color: var(--ink); font-family: var(--font-body, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif); line-height: 1.55; }
.card { width: 100%; max-width: 520px; text-align: center; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 40px 28px 34px; box-shadow: 0 10px 40px rgba(0,0,0,.07); }
.logo { max-height: 52px; max-width: 220px; margin: 0 auto 22px; display: block; object-fit: contain; }
.logo-d { display: none; }
@media (prefers-color-scheme: dark) { .logo-d { display: block; } .logo-l.has-dark { display: none; } .logo-l.plate { background: #fff; padding: 8px 12px; border-radius: 10px; } }
.store { font-family: var(--font-title, Georgia, "Times New Roman", serif); font-size: 1.3rem; font-weight: 700; margin: 0 0 22px; }
.icon { width: 76px; height: 76px; margin: 0 auto 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--accent); color: var(--on-accent); }
.icon svg { width: 38px; height: 38px; }
h1 { font-family: var(--font-display, Georgia, "Times New Roman", serif); font-size: clamp(1.6rem, 6vw, 2.1rem); line-height: 1.15; margin: 0 0 12px; }
p { margin: 0 0 16px; color: var(--ink-soft); font-size: 1.02rem; white-space: pre-line; }
.eta { display: inline-block; margin: 2px 0 6px; padding: 7px 16px; border-radius: 999px; border: 1px solid var(--line); font-weight: 600; font-size: .92rem; color: var(--ink); }
.contact { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--line); font-size: .9rem; color: var(--ink-soft); }
.contact a { color: var(--ink); font-weight: 600; }
.preview { position: fixed; top: 0; left: 0; right: 0; padding: 8px 14px; text-align: center; font-size: .82rem; background: #20293b; color: #fff; }
</style>
</head>
<body>
<?php if ($preview): ?><div class="preview">Preview only — visitors will see this page while maintenance mode is on. <a href="/admin/maintenance.php" style="color:#fff;text-decoration:underline;">Back to settings</a></div><?php endif; ?>
<main class="card">
  <?php if ($logoLight): ?><img class="logo logo-l <?= $logoDark ? 'has-dark' : 'plate' ?>" src="<?= e($logoLight) ?>" alt="<?= e($name) ?>"><?php if ($logoDark): ?><img class="logo logo-d" src="<?= e($logoDark) ?>" alt="<?= e($name) ?>"><?php endif; ?><?php else: ?><div class="store"><?= e($name) ?></div><?php endif; ?>
  <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
  <h1><?= e($m['title']) ?></h1>
  <p><?= e($m['message']) ?></p>
  <?php if ($m['eta'] !== ''): ?><div class="eta"><?= e($m['eta']) ?></div><?php endif; ?>
  <?php if ($signal !== ''): ?><div class="contact">Need something urgent? <a href="<?= e($signal) ?>" rel="noopener">Message us on Signal</a></div><?php endif; ?>
</main>
</body>
</html>
<?php
}

/** Called once, at the end of includes/functions.php, on every storefront request. */
function maintenance_gate(): void {
    if (PHP_SAPI === 'cli' || maintenance_path_exempt()) return;
    try {
        $isAdmin = !empty($_SESSION['admin_id']);
        // Admins can look at the page itself: /?__maint_preview=1
        if ($isAdmin && !empty($_GET['__maint_preview'])) { maintenance_render(maintenance_settings(), true); exit; }
        if (!maintenance_enabled() || $isAdmin) return;

        $m = maintenance_settings();
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $wantsJson = strpos($script, '/api/') === 0 || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        if ($wantsJson) {
            http_response_code(503);
            header('Retry-After: 3600');
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'The store is under maintenance. Please try again soon.']);
            exit;
        }
        maintenance_render($m);
        exit;
    } catch (Throwable $e) {
        error_log('[maintenance] ' . $e->getMessage());
    }
}
