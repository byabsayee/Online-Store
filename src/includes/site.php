<?php
/**
 * Online Store — "site layer": everything an owner can customise without touching code.
 *
 *   currency / order prefix · footer links · credit + source links · partners page · editable pages
 *   fonts (upload or Google) · ads · new-order notification email · first-run setup state
 *
 * Every value lives in the `settings` table (or its own small table), with a sensible default here.
 */

/** Address of this software's public source code (AGPL-3.0 §13). Set it once to your repository; owners can change it in Admin → Footer & credits. */
const SOURCE_CODE_URL_DEFAULT = '';
const CREDIT_TEXT_DEFAULT = 'Made using Byabsayee';
const CREDIT_URL_DEFAULT = 'https://byabsayee.com';

/* ============================================================ setup state */

function setup_done(): bool { return get_setting('setup_done', '1') !== '0'; }

/** [min, max] delivery days: the admin's numbers win over the .env defaults. */
function delivery_days_range(): array {
    $a = (int) setting_or('delivery_days_min', DELIVERY_DAYS_MIN); $b = (int) setting_or('delivery_days_max', DELIVERY_DAYS_MAX);
    if ($a < 0) $a = 0; if ($b < $a) $b = $a;
    return [$a, $b];
}

/** Order-number prefix, e.g. "ORD" -> ORD-260928-AB12C. Letters/digits only, 2-6 chars. */
function order_prefix(): string {
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) get_setting('order_prefix', 'ORD')));
    return strlen($p) >= 2 ? substr($p, 0, 6) : 'ORD';
}

/* ============================================================ getting-started checklist */

/** Steps for the dashboard card: [ [done(bool), label, link], … ]. Cheap checks only. */
function setup_checklist(): array {
    $smtp = smtp_settings();
    $pmOn = (int) db()->query("SELECT COUNT(*) FROM payment_methods WHERE is_active = 1 AND kind <> 'cod'")->fetchColumn();
    $prod = (int) db()->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
    return [
        [!admin_uses_default_password(), 'Choose your own admin password', '/admin/account.php'],
        [brand_logo() !== null, 'Upload your logo', '/admin/branding.php'],
        [$prod > 0, 'Add your first product', '/admin/product_form.php'],
        [$pmOn > 0, 'Add a payment method besides cash on delivery', '/admin/payment_methods.php'],
        [trim($smtp['host']) !== '', 'Set up outgoing email so customers get order emails', '/admin/settings.php'],
        [order_notify_recipients() !== [], 'Get an email whenever a new order arrives', '/admin/notifications.php'],
        [source_link() !== null, 'Set the source-code address shown in the footer (AGPL requirement)', '/admin/footer_links.php'],
    ];
}

/** True while the signed-in admin still has a shipped default password. */
function admin_uses_default_password(): bool {
    $me = function_exists('current_admin') ? current_admin() : null;
    if (!$me) return false;
    $st = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $st->execute([$me['id']]);
    $hash = (string) $st->fetchColumn();
    foreach (default_admin_passwords() as $p) { if (password_verify($p, $hash)) return true; }
    return false;
}

/* ============================================================ footer + credits */

/** The footer's link columns, in order: [ 'Support' => [ ['label'=>..,'url'=>..,'new_tab'=>bool], … ], … ] */
function footer_groups(): array {
    $rows = db()->query('SELECT * FROM footer_links WHERE is_active = 1 ORDER BY group_order, group_title, sort_order, id')->fetchAll();
    $hidden = site_pages_disabled_urls();
    $out = [];
    foreach ($rows as $r) {
        if (in_array(rtrim($r['url'], '/'), $hidden, true)) continue;
        $out[$r['group_title']][] = ['label' => $r['label'], 'url' => $r['url'], 'new_tab' => (bool) $r['new_tab']];
    }
    return $out;
}

/** Local URLs whose page is switched off, so a footer link to them simply disappears. */
function site_pages_disabled_urls(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    foreach (site_page_defs() as $slug => $d) {
        if (!site_page_enabled($slug)) $c[] = $d['url'];
    }
    if (!partners_enabled()) $c[] = '/partners';
    return $c;
}

function site_credit(): ?array {
    if (get_setting('credit_enabled', '1') !== '1') return null;
    $t = trim((string) get_setting('credit_text', CREDIT_TEXT_DEFAULT));
    if ($t === '') return null;
    $u = trim((string) get_setting('credit_url', CREDIT_URL_DEFAULT));
    return ['text' => $t, 'url' => preg_match('~^https?://~i', $u) ? $u : ''];
}

/** AGPL §13: a visible link to the source code of the running version. */
function source_link(): ?string {
    if (get_setting('source_link_enabled', '1') !== '1') return null;
    $u = trim((string) get_setting('source_url', (string) env_val('SOURCE_CODE_URL', SOURCE_CODE_URL_DEFAULT)));
    return preg_match('~^https?://~i', $u) ? $u : null;
}

function footer_extra_text(): string { return trim((string) get_setting('footer_text', '')); }

/* ============================================================ partners */

function partners_enabled(): bool { return get_setting('partners_enabled', '0') === '1'; }

function partners_list(bool $onlyActive = true): array {
    $rows = db()->query('SELECT * FROM partners' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id')->fetchAll();
    foreach ($rows as &$r) $r['links'] = partner_links($r);
    return $rows;
}

/** A partner's main link first, then its extra links: [ ['title'=>..,'url'=>..], … ]. */
function partner_links(array $p): array {
    $out = [];
    if (!empty($p['link_url'])) $out[] = ['title' => 'Visit website', 'url' => $p['link_url']];
    $extra = json_decode((string) ($p['extra_links'] ?? ''), true);
    if (is_array($extra)) foreach ($extra as $l) {
        if (!empty($l['url']) && preg_match('~^https?://~i', $l['url'])) $out[] = ['title' => (string) ($l['title'] ?? '') ?: 'Link', 'url' => $l['url']];
    }
    return $out;
}

/* ============================================================ uploads (logos, fonts) */

/** Saves a small image upload (partner / payment-method logo) in the branding folder. Returns the URL or null. @throws RuntimeException */
function site_upload_image(string $field): ?string {
    if (empty($_FILES[$field]) || is_array($_FILES[$field]['error'] ?? null) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The upload did not finish. Please try again.');
    if ($f['size'] > 2 * 1024 * 1024) throw new RuntimeException('That image is too large (max 2 MB).');
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('The upload could not be verified.');
    $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'text/plain' => 'svg', 'text/xml' => 'svg'][$mime] ?? null;
    if ($ext === null) throw new RuntimeException('Use a PNG, JPG, WebP or SVG image.');
    if ($ext === 'svg') {
        $svg = (string) file_get_contents($f['tmp_name']);
        if (!preg_match('~<svg\b~i', $svg)) throw new RuntimeException('Use a PNG, JPG, WebP or SVG image.');
        if ($why = svg_unsafe_reason($svg)) throw new RuntimeException($why);
    }
    [$dir, $url] = brand_dir();
    $name = 'brand-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save the image (is the uploads folder writable?).');
    return $url . '/' . $name;
}

function fonts_dir(): array {
    $dir = dirname(UPLOAD_DIR) . '/fonts';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return [$dir, dirname(UPLOAD_URL) . '/fonts'];
}

/** Saves an uploaded font (ttf, otf, woff, woff2). @return ?array{url:string,name:string} @throws RuntimeException */
function site_upload_font(string $field): ?array {
    if (empty($_FILES[$field]) || is_array($_FILES[$field]['error'] ?? null) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The font upload did not finish. Please try again.');
    if ($f['size'] > 4 * 1024 * 1024) throw new RuntimeException('That font file is too large (max 4 MB).');
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('The upload could not be verified.');
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'], true)) throw new RuntimeException('Fonts must be .ttf, .otf, .woff or .woff2 files.');
    $magic = (string) file_get_contents($f['tmp_name'], false, null, 0, 4);
    $ok = ['ttf' => ["\x00\x01\x00\x00", 'true'], 'otf' => ['OTTO', "\x00\x01\x00\x00"], 'woff' => ['wOFF'], 'woff2' => ['wOF2']][$ext];
    if (!in_array($magic, $ok, true)) throw new RuntimeException('That file does not look like a real ' . strtoupper($ext) . ' font.');
    [$dir, $url] = fonts_dir();
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('The uploads folder is not writable.');
    $name = 'font-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save the font.');
    $label = trim(preg_replace('/[^A-Za-z0-9 _-]+/', ' ', pathinfo((string) $f['name'], PATHINFO_FILENAME))) ?: 'Custom font';
    return ['url' => $url . '/' . $name, 'name' => mb_substr($label, 0, 40)];
}

function font_delete_file(?string $url): void {
    if ($url && preg_match('~^/uploads/fonts/(font-[a-f0-9]{12}\.(ttf|otf|woff2?))$~', $url, $m)) @unlink(fonts_dir()[0] . '/' . $m[1]);
}

/* ============================================================ fonts */

/** The three font roles: key => [css variable, label, what it styles]. */
function font_roles(): array {
    return [
        'title' => ['--font-title', 'Title font', 'The store name in the header and the large page titles'],
        'primary' => ['--font-body', 'Primary font', 'Body text, buttons and forms'],
        'secondary' => ['--font-display', 'Secondary font', 'Section headings and accents'],
    ];
}

/** Saved choice for one role: ['src' => default|google|upload, 'name' => family, 'file' => url]. */
function font_choice(string $role): array {
    $src = get_setting('font_' . $role . '_src', 'default');
    if (!in_array($src, ['default', 'google', 'upload'], true)) $src = 'default';
    return ['src' => $src, 'name' => trim((string) get_setting('font_' . $role . '_name', '')), 'file' => trim((string) get_setting('font_' . $role . '_file', ''))];
}

/** Valid font-family names only (they end up inside CSS and a Google Fonts URL). */
function font_name_ok(string $n): bool { return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9 ]{1,38}$/', $n); }

/** @return array{links: string[], css: string} what <head> needs for the chosen fonts (empty when everything is default). */
function font_head(): array {
    $links = []; $css = ''; $vars = '';
    foreach (font_roles() as $role => [$var]) {
        $c = font_choice($role);
        if ($c['src'] === 'google' && font_name_ok($c['name'])) {
            // The classic CSS endpoint skips weights a family doesn't have. The newer css2 one answers "400 Bad Request"
            // for the whole stylesheet instead (e.g. a single-weight display font asked for 500/600/700), which left
            // phones — that have no copy of the font installed — on the fallback font.
            $links[] = 'https://fonts.googleapis.com/css?family=' . str_replace('%20', '+', rawurlencode($c['name'])) . ':400,500,600,700&display=swap';
            $vars .= $var . ':"' . $c['name'] . '",' . font_fallback($role) . ';';
        } elseif ($c['src'] === 'upload' && $c['file'] !== '' && preg_match('~^/uploads/fonts/font-[a-f0-9]{12}\.(ttf|otf|woff2?)$~', $c['file'], $m)) {
            $fam = 'Custom ' . ucfirst($role);
            $fmt = ['ttf' => 'truetype', 'otf' => 'opentype', 'woff' => 'woff', 'woff2' => 'woff2'][$m[1]];
            $css .= '@font-face{font-family:"' . $fam . '";src:url("' . $c['file'] . '") format("' . $fmt . '");font-weight:100 900;font-style:normal;font-display:swap}';
            $vars .= $var . ':"' . $fam . '",' . font_fallback($role) . ';';
        }
    }
    if ($vars !== '') $css .= ':root{' . $vars . '}';
    return ['links' => $links, 'css' => $css];
}

function font_fallback(string $role): string {
    return $role === 'primary' ? '-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif' : 'Georgia,"Times New Roman",serif';
}

/** Ready-to-print <link>/<style> tags for the chosen fonts. */
function font_head_html(): string {
    $h = font_head(); $o = '';
    foreach ($h['links'] as $l) $o .= '<link rel="stylesheet" href="' . e($l) . '">' . "\n";
    if ($h['css'] !== '') $o .= '<style>' . $h['css'] . '</style>' . "\n";
    return $o;
}

/* ============================================================ ads */

/** Where ads can go: slot key => [label, where]. */
function ad_slots(): array {
    return [
        'home_top' => ['Home — below the hero', 'Full-width banner under the main banner on the home page'],
        'home_mid' => ['Home — between sections', 'Full-width banner between product sections on the home page'],
        'category_top' => ['Category & search — above the products', 'Banner above the product grid'],
        'product_below' => ['Product page — below the details', 'Banner under the product description'],
        'cart_below' => ['Cart — below the items', 'Banner under the cart'],
        'footer_above' => ['Every page — above the footer', 'Banner at the bottom of every page'],
    ];
}

function ads_settings(): array {
    $client = trim((string) get_setting('ads_client', ''));
    $s = ['enabled' => get_setting('ads_enabled', '0') === '1' && (bool) preg_match('/^ca-pub-\d{8,20}$/', $client), 'client' => $client, 'slots' => []];
    foreach (ad_slots() as $k => $_) {
        $s['slots'][$k] = ['on' => get_setting('ad_' . $k . '_on', '0') === '1', 'id' => trim((string) get_setting('ad_' . $k . '_id', ''))];
    }
    return $s;
}

/** HTML for one ad slot, or '' when ads/this slot are off or not configured. */
function ad_slot(string $key): string {
    $a = ads_settings();
    if (!$a['enabled'] || empty($a['slots'][$key]) || !$a['slots'][$key]['on'] || !preg_match('/^\d{6,20}$/', $a['slots'][$key]['id'])) return '';
    $GLOBALS['__ads_used'] = true;
    return '<div class="wrap ad-slot ad-' . e($key) . '" aria-label="Advertisement"><span class="ad-label">Advertisement</span>'
        . '<ins class="adsbygoogle" style="display:block" data-ad-client="' . e($a['client']) . '" data-ad-slot="' . e($a['slots'][$key]['id']) . '" data-ad-format="auto" data-full-width-responsive="true"></ins></div>';
}

/** Loader for the AdSense script — printed once before </body>, only when a slot was printed. Waits for consent when the cookie notice is on. */
function ads_script_html(): string {
    if (empty($GLOBALS['__ads_used'])) return '';
    $a = ads_settings();
    $needConsent = cookie_notice_on();
    return '<script>(function(){var go=function(){var s=document.createElement("script");s.async=true;s.crossOrigin="anonymous";'
        . 's.src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . e($a['client']) . '";'
        . 's.onload=function(){document.querySelectorAll("ins.adsbygoogle").forEach(function(){try{(adsbygoogle=window.adsbygoogle||[]).push({})}catch(e){}})};document.head.appendChild(s)};'
        . ($needConsent ? 'var ok=null;try{ok=localStorage.getItem("store-consent")}catch(e){}if(ok==="yes")go();else document.addEventListener("store-consent-yes",go,{once:true});' : 'go();')
        . '})();</script>';
}

function cookie_notice_on(): bool { return get_setting('cookie_notice', '0') === '1'; }

/* ============================================================ new-order notification */

/** Addresses that should be told about a new order (up to 3), or [] when switched off. */
function order_notify_recipients(): array {
    if (get_setting('notify_enabled', '0') !== '1') return [];
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string) get_setting('notify_email', '')) as $a) {
        if (filter_var($a, FILTER_VALIDATE_EMAIL)) $out[strtolower($a)] = $a;
    }
    return array_slice(array_values($out), 0, 3);
}

/** Emails the owner(s) about a freshly placed order. Never throws — a mail problem must not break checkout. */
function notify_new_order(int $orderId): void {
    try {
        $to = order_notify_recipients();
        if (!$to) return;
        $st = db()->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$orderId]);
        $o = $st->fetch(); if (!$o) return;
        $items = db()->prepare('SELECT product_name, variant_label, quantity, price FROM order_items WHERE order_id = ?'); $items->execute([$orderId]);
        $rows = '';
        foreach ($items->fetchAll() as $it) {
            $rows .= '<tr><td style="padding:4px 8px 4px 0">' . e($it['product_name']) . ($it['variant_label'] ? ' (' . e($it['variant_label']) . ')' : '') . ' × ' . (int) $it['quantity'] . '</td><td style="text-align:right">' . e(money((float) $it['price'] * (int) $it['quantity'])) . '</td></tr>';
        }
        $pay = payment_method_label((string) $o['payment_method']);
        if ($o['payment_method_id']) { $pm = db()->prepare('SELECT name FROM payment_methods WHERE id = ?'); $pm->execute([$o['payment_method_id']]); if ($n = $pm->fetchColumn()) $pay = $n; }
        require_once __DIR__ . '/mail.php';
        require_once __DIR__ . '/order_mail.php';
        [$subject, $html] = email_render('new_order_admin', [
            'order_number' => e($o['order_number']), 'total' => e(money((float) $o['total'])), 'payment' => e($pay),
            'payment_proof' => $o['pay_txn'] ? '<br>Sent from: ' . e((string) $o['pay_sender']) . ' · Transaction ID: <strong>' . e($o['pay_txn']) . '</strong>' : '',
            'items_table' => '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>',
            'customer' => '<strong>' . e($o['shipping_name']) . '</strong> · ' . e($o['shipping_phone']) . '<br>' . e(trim($o['shipping_line1'] . ', ' . $o['shipping_city'], ', ')),
            'button' => ['href' => mail_url('/admin/order_detail.php?id=' . (int) $orderId)],
        ]);
        foreach ($to as $addr) send_email($addr, store_name(), $subject, $html, null, null, 'order_notify');
    } catch (Throwable $e) { error_log('[notify_new_order] ' . $e->getMessage()); }
}

/* ============================================================ customer cancellation */

/** Customers may cancel their own order from the website (owner can switch this off). */
function customer_cancel_enabled(): bool { return get_setting('customer_cancel', '1') === '1'; }

/** Orders can be cancelled online until they are shipped. */
function customer_can_cancel(array $order): bool {
    return customer_cancel_enabled() && in_array($order['status'] ?? '', ['pending', 'processing'], true);
}

/**
 * Cancels the order on the customer's behalf: stock goes back on the shelf and recorded payments are voided
 * (same code path as the admin's Cancel), then the customer and the shop owner are emailed.
 * Returns null on success, or a message that is safe to show the customer.
 */
function customer_cancel_order(array $order, string $reason): ?string {
    $pdo = db();
    $reason = mb_substr(trim($reason), 0, 300);
    $note = 'Cancelled by the customer' . ($reason !== '' ? ': ' . $reason : '');
    $hadPayment = false;
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([(int) $order['id']]);
        $cur = (string) $st->fetchColumn();
        if ($cur === 'cancelled') { $pdo->rollBack(); return null; }
        if (!customer_cancel_enabled() || !in_array($cur, ['pending', 'processing'], true)) {
            $pdo->rollBack();
            return 'This order is already ' . strtolower($cur) . ' and can no longer be cancelled online. Please contact us and we will help.';
        }
        $vp = $pdo->prepare("SELECT id FROM order_payments WHERE order_id = ? AND status = 'recorded'");
        $vp->execute([(int) $order['id']]);
        $voiding = $vp->fetchAll(PDO::FETCH_COLUMN);
        $hadPayment = $voiding !== [] || !empty($order['pay_txn']);
        $from = erp_order_set_status((int) $order['id'], 'cancelled', $note, null);
        if ($from !== null) {
            foreach ($voiding as $pid) erp_emit('payment', (int) $pid, 'void');
            erp_emit('order', (int) $order['id'], 'cancel');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[customer_cancel] ' . $e->getMessage());
        return 'We could not cancel the order just now. Please try again or contact us.';
    }
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([(int) $order['id']]);
    $fresh = $st->fetch() ?: $order;
    defer_job(function () use ($fresh, $reason, $hadPayment) {
        try {
            require_once __DIR__ . '/mail.php';
            require_once __DIR__ . '/order_mail.php';
            send_order_status_email($fresh, 'cancelled', 'You cancelled this order.' . ($hadPayment ? ' If you already paid, we will contact you about your refund.' : ''));
            $to = order_notify_recipients() ?: array_filter([store_info()['email']]);
            [$subject, $html] = email_render('order_cancelled_admin', [
                'order_number' => e($fresh['order_number']), 'total' => e(money((float) $fresh['total'])),
                'reason_line' => $reason !== '' ? '<p>Reason: ' . e($reason) . '</p>' : '',
                'refund_line' => $hadPayment ? '<p><strong>A payment was recorded for this order — please arrange the refund.</strong></p>' : '',
                'button' => ['href' => mail_url('/admin/order_detail.php?id=' . (int) $fresh['id'])],
            ]);
            foreach ($to as $addr) send_email($addr, store_name(), $subject, $html, null, null, 'order_cancel_notify');
        } catch (Throwable $e) { error_log('[customer_cancel mail] ' . $e->getMessage()); }
    });
    return null;
}

/* ============================================================ editable pages */


/** The pages an owner can edit: slug => [title, public path, eyebrow]. */
function site_page_defs(): array {
    return [
        'about' => ['title' => 'About us', 'url' => '/about', 'eyebrow' => 'About'],
        'faq' => ['title' => 'Frequently asked questions', 'url' => '/faq', 'eyebrow' => 'Help'],
        'terms' => ['title' => 'Terms of Service', 'url' => '/terms', 'eyebrow' => 'Legal'],
        'privacy-policy' => ['title' => 'Privacy Policy', 'url' => '/privacy-policy', 'eyebrow' => 'Legal'],
        'cookie-policy' => ['title' => 'Cookie Policy', 'url' => '/cookie-policy', 'eyebrow' => 'Legal'],
        'refund-policy' => ['title' => 'Refund & Return Policy', 'url' => '/refund-policy', 'eyebrow' => 'Legal'],
        'shipping-policy' => ['title' => 'Shipping & Delivery Policy', 'url' => '/shipping-policy', 'eyebrow' => 'Legal'],
    ];
}

function site_page_row(string $slug): ?array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM site_pages')->fetchAll() as $r) $cache[$r['slug']] = $r;
    }
    return $cache[$slug] ?? null;
}

/** A page is on unless the owner switched it off. */
function site_page_enabled(string $slug): bool {
    $r = site_page_row($slug);
    return $r === null ? true : (bool) $r['is_enabled'];
}

/** Placeholders the owner can use inside page text; they always show the current store details. */
function page_tokens(): array {
    $i = store_info();
    $phones = store_phones();
    $zones = [];
    foreach (delivery_zones() as $c => $l) $zones[] = '<li><strong>' . e($l) . ':</strong> ' . e(money(shipcfg(DELIVERY_ZONE_KEYS[$c]))) . '</li>';
    return [
        '{store_name}' => e($i['name']), '{store_email}' => e($i['email'] ?: '[your email]'), '{store_phone}' => e($phones[0] ?? '[your phone]'),
        '{store_address}' => e($i['address'] ?: '[your address]'), '{store_url}' => e(site_url() ?: ''), '{updated}' => e(legal_updated_label()),
        '{currency}' => e(store_currency_code()), '{contact_extra}' => function_exists('store_contact_extra_html') ? store_contact_extra_html() : '',
        '{fee_zone1}' => e(money(shipcfg('inside'))), '{fee_zone2}' => e(money(shipcfg('suburbs'))), '{fee_zone3}' => e(money(shipcfg('outside'))),
        '{delivery_zones}' => '<ul>' . implode('', $zones) . '</ul>',
        '{delivery_days}' => implode('–', delivery_days_range()),
        '{extra_per_kg}' => e(money(shipcfg('extra_kg'))), '{free_kg}' => e(rtrim(rtrim(number_format(shipcfg('free_kg'), 2, '.', ''), '0'), '.')),
    ];
}

/** The page's HTML (owner's version, or the built-in starter text), placeholders filled in. */
function site_page_html(string $slug): string {
    $r = site_page_row($slug);
    $raw = ($r && trim((string) $r['body_html']) !== '') ? $r['body_html'] : site_page_starter($slug);
    return strtr($raw, page_tokens());
}

function site_page_title(string $slug): string {
    $r = site_page_row($slug);
    return ($r && trim($r['title']) !== '') ? $r['title'] : site_page_defs()[$slug]['title'];
}

/** Optional search-engine overrides for a page: ['title' => full <title>, 'description' => meta description]. */
function site_page_seo(string $slug): array {
    return [
        'title' => trim((string) get_setting('page_seo_title_' . $slug, '')),
        'description' => trim((string) get_setting('page_seo_desc_' . $slug, '')),
    ];
}

function site_page_starter(string $slug): string {
    static $all = null;
    if ($all === null) $all = require __DIR__ . '/pages_default.php';
    return $all[$slug] ?? '';
}

/** Prints a whole public page (header, title, text, footer) — or the 404 page when the owner switched it off. */
function render_site_page(string $slug): void {
    $defs = site_page_defs();
    if (!isset($defs[$slug]) || !site_page_enabled($slug)) {
        http_response_code(404);
        require __DIR__ . '/../404.php';
        exit;
    }
    $pageTitle = site_page_title($slug);
    $GLOBALS['pageTitle'] = $pageTitle; // header.php runs inside this function; render_head_meta() reads the global
    $GLOBALS['page_meta'] = site_page_seo($slug);
    $eyebrow = $defs[$slug]['eyebrow'];
    $body = site_page_html($slug);
    require __DIR__ . '/header.php';
    echo '<div class="page-header wrap"><span class="eyebrow">' . e($eyebrow) . '</span><h1>' . e($pageTitle) . '</h1></div>'
        . '<div class="wrap section" style="padding-top:8px;"><div class="prose">' . $body . '</div></div>';
    require __DIR__ . '/footer.php';
}

/** Keeps a safe subset of HTML (what the page editor can produce); everything else is reduced to its text. */
function sanitize_page_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $allowed = ['p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 'a', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr'];
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $dom->getElementById('root');
    if (!$root) return '';
    $clean = function (DOMNode $node) use (&$clean, $allowed): void {
        foreach (iterator_to_array($node->childNodes) as $ch) {
            if ($ch instanceof DOMElement) {
                $tag = strtolower($ch->nodeName);
                $clean($ch);
                if (!in_array($tag, $allowed, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'noscript'], true)) { $node->removeChild($ch); continue; }
                    while ($ch->firstChild) $node->insertBefore($ch->firstChild, $ch);
                    $node->removeChild($ch);
                    continue;
                }
                foreach (iterator_to_array($ch->attributes) as $at) {
                    $keep = $tag === 'a' && in_array($at->name, ['href', 'target', 'rel', 'data-cookie-reset'], true);
                    if (!$keep) $ch->removeAttribute($at->name);
                }
                if ($tag === 'a') {
                    $href = trim((string) $ch->getAttribute('href'));
                    if (!preg_match('~^(https?://|mailto:|tel:|/|#)~i', $href)) { $ch->removeAttribute('href'); }
                    elseif (preg_match('~^https?://~i', $href)) { $ch->setAttribute('rel', 'noopener'); $ch->setAttribute('target', '_blank'); }
                    else { $ch->removeAttribute('target'); $ch->removeAttribute('rel'); }
                }
            }
        }
    };
    $clean($root);
    $out = '';
    foreach ($root->childNodes as $ch) $out .= $dom->saveHTML($ch);
    return trim($out);
}

/* ============================================================ presets (export / import) */

/** Setting keys that belong in a preset: appearance, text and options — never passwords, keys or accounting-link data. */
function preset_setting_keys(): array {
    $seo = [];
    foreach (array_keys(site_page_defs()) as $sl) { $seo[] = 'page_seo_title_' . $sl; $seo[] = 'page_seo_desc_' . $sl; }
    require_once __DIR__ . '/email_templates.php';
    $seo = array_merge($seo, email_template_setting_keys());
    return array_merge($seo, ['store_name', 'store_tagline', 'site_description', 'store_phone', 'store_phone2', 'store_email', 'store_address', 'store_address_map', 'contact_addresses', 'contact_emails', 'contact_phones',
        'social_facebook', 'social_messenger', 'social_instagram', 'social_youtube', 'social_signal', 'social_whatsapp', 'social_tiktok',
        'theme_primary', 'theme_secondary', 'theme_dark', 'seasonal_enabled', 'seasonal_effect', 'topbar_enabled', 'topbar_text', 'topbar_link',
        'currency_symbol', 'currency_code', 'currency_pos', 'currency_decimals', 'timezone', 'order_prefix',
        'ship_inside', 'ship_suburbs', 'ship_outside', 'ship_free_kg', 'ship_extra_kg', 'zone_label_inside', 'zone_label_suburbs', 'zone_label_outside', 'zone_off_suburbs', 'zone_off_outside',
        'tax_enabled', 'tax_rate', 'tax_inclusive', 'tax_label',
        'credit_enabled', 'credit_text', 'credit_url', 'source_link_enabled', 'source_url', 'footer_text', 'partners_enabled', 'cookie_notice',
        'notify_enabled', 'notify_email', 'customer_cancel', 'font_title_src', 'font_title_name', 'font_primary_src', 'font_primary_name', 'font_secondary_src', 'font_secondary_name',
        'home_eyebrow', 'home_headline', 'home_lead', 'home_cta', 'home_card_title', 'home_stamp', 'home_points', 'home_why_tag', 'home_why_title',
        'home_c1_title', 'home_c1_text', 'home_c2_title', 'home_c2_text', 'home_c3_title', 'home_c3_text',
        'ads_enabled', 'ads_client', 'ads_txt', 'delivery_days_min', 'delivery_days_max', 'invoice_header', 'invoice_footer', 'invoice_tax_number']);
}

/* ============================================================ home page text */

/** The home page's editable words, with generic defaults. */
function home_content(): array {
    $g = fn (string $k, string $d) => trim((string) get_setting($k, $d));
    $name = store_info()['name'];
    $pointsRaw = get_setting('home_points', null);
    if ($pointsRaw === null) $pointsRaw = "Carefully chosen products\nSecure, simple checkout\nFast delivery\nFriendly support";
    $points = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $pointsRaw)), fn ($x) => $x !== ''));
    $stamp = $g('home_stamp', '');
    return [
        'eyebrow' => $g('home_eyebrow', ''),
        'headline' => $g('home_headline', 'Welcome to ' . $name),
        'lead' => $g('home_lead', store_info()['description']),
        'cta' => $g('home_cta', 'Shop all products') ?: 'Shop all products',
        'card_title' => $g('home_card_title', 'Why people shop with us'),
        'points' => array_slice($points, 0, 8),
        'stamp' => $stamp, 'stamp_html' => nl2br(e($stamp)),
        'why_tag' => $g('home_why_tag', 'The ' . $name . ' promise'),
        'why_title' => $g('home_why_title', 'Why choose ' . $name),
        'cards' => [
            [$g('home_c1_title', 'Quality you can trust'), $g('home_c1_text', 'Every product is chosen with care, so you can buy with confidence.')],
            [$g('home_c2_title', 'Pay your way'), $g('home_c2_text', 'Choose the payment method that suits you at checkout.')],
            [$g('home_c3_title', 'Delivered with care'), $g('home_c3_text', 'Orders are carefully packed and sent to you as quickly as we can.')],
        ],
    ];
}

/** The payment method's own name for an order ("bKash"), or the generic label for older orders. */
function order_payment_name(array $order): string {
    static $names = null;
    if ($names === null) { $names = []; foreach (db()->query('SELECT id, name FROM payment_methods')->fetchAll() as $r) $names[(int) $r['id']] = $r['name']; }
    $id = (int) ($order['payment_method_id'] ?? 0);
    return $names[$id] ?? payment_method_label((string) ($order['payment_method'] ?? 'cod'));
}

/** "Sent from 017… · Transaction ID ABC123" for orders paid by a manual method, else ''. */
function order_payment_proof(array $order): string {
    if (empty($order['pay_txn'])) return '';
    return 'Sent from ' . $order['pay_sender'] . ' · Transaction ID ' . $order['pay_txn'];
}

/* ============================================================ first-run helpers */

/** A few example products so a new owner can see what the store looks like. Safe to call once; skips if products exist. */
function load_sample_catalog(): int {
    $pdo = db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) return 0;
    $cats = [['Featured', 'featured', 'Our picks.', 1], ['New arrivals', 'new-arrivals', 'Fresh in the store.', 2]];
    $ids = [];
    $ci = $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order) VALUES (?,?,?,?)');
    foreach ($cats as $c) { $ci->execute($c); $ids[] = (int) $pdo->lastInsertId(); }
    $items = [
        [0, 'Sample product one', 'sample-product-one', 'SMP-001', 'A short line about this product.', 'Replace this with a real description. Edit or delete sample products in Admin → Products.', 1200.00, 1500.00, 25, 1],
        [0, 'Sample product two', 'sample-product-two', 'SMP-002', 'A short line about this product.', 'Replace this with a real description.', 850.00, null, 40, 0],
        [1, 'Sample product three', 'sample-product-three', 'SMP-003', 'A short line about this product.', 'Replace this with a real description.', 2400.00, 2800.00, 15, 1],
        [1, 'Sample product four', 'sample-product-four', 'SMP-004', 'A short line about this product.', 'Replace this with a real description.', 560.00, null, 60, 0],
    ];
    $pi = $pdo->prepare('INSERT INTO products (category_id, name, slug, sku, short_desc, description, price, compare_price, stock, is_active, is_featured) VALUES (?,?,?,?,?,?,?,?,?,1,?)');
    foreach ($items as $it) $pi->execute([$ids[$it[0]], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6], $it[7], $it[8], $it[9]]);
    return count($items);
}

/** Writes the built-in starter text of the chosen pages into the database (so the owner can edit them). */
function create_starter_pages(array $slugs): void {
    $st = db()->prepare('INSERT INTO site_pages (slug, title, body_html, is_enabled) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE is_enabled = 1');
    foreach (site_page_defs() as $slug => $d) {
        if (!in_array($slug, $slugs, true)) {
            db()->prepare('INSERT INTO site_pages (slug, title, is_enabled) VALUES (?,?,0) ON DUPLICATE KEY UPDATE is_enabled = 0')->execute([$slug, $d['title']]);
            continue;
        }
        $st->execute([$slug, $d['title'], site_page_starter($slug)]);
    }
}
