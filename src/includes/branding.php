<?php
/**
 * Store identity, branding (logo / favicon / share banner) and page metadata.
 *
 * Everything a visitor sees about "who we are" — the name, phone, email,
 * address, social links, logo, description — is read from HERE, and here it
 * comes from the admin-editable `settings` table (with the .env values as the
 * fallback for a fresh install). Templates must never print SITE_NAME /
 * CONTACT_EMAIL / … directly: use store_info(), store_socials(),
 * brand_inner() etc. so that one edit in Admin changes the whole site.
 */

const DEFAULT_SITE_DESCRIPTION = 'Quality products, simple checkout and friendly service.';
const DEFAULT_TAGLINE = '';
/** Shown as "Last updated" on the legal pages. Change it when you actually edit their wording. */
const LEGAL_LAST_UPDATED = '2026-09-28';

function legal_updated_label(): string {
    return date('d F Y', (int) strtotime(LEGAL_LAST_UPDATED));
}

/* ==========================================================================
   Store identity
   ========================================================================== */

/**
 * The store's public details. Saved values win; .env is the fallback.
 * (An empty saved value falls back too, so the name/email are never blank.)
 */
function store_info(): array {
    return [
        'name' => trim((string) setting_or('store_name', SITE_NAME)) ?: SITE_NAME,
        'tagline' => trim((string) (get_setting('store_tagline') ?? DEFAULT_TAGLINE)),
        'description' => trim((string) setting_or('site_description', DEFAULT_SITE_DESCRIPTION)),
        'phone' => trim((string) setting_or('store_phone', CONTACT_PHONE)),
        'phone2' => trim((string) setting_or('store_phone2', CONTACT_PHONE_2)),
        'email' => trim((string) setting_or('store_email', CONTACT_EMAIL)),
        'address' => trim((string) setting_or('store_address', STORE_ADDRESS)),
    ];
}

function store_name(): string { return store_info()['name']; }

/** The store's phone numbers that are set — the main one, then the optional second one. @return string[] */
function store_phones(): array {
    $i = store_info();
    return array_values(array_filter([$i['phone'], $i['phone2']], fn ($p) => $p !== ''));
}

/**
 * Turns what an admin typed for the WhatsApp field into a clean https link, or null when it
 * isn't one. Accepts a wa.me / api.whatsapp.com / chat.whatsapp.com / whatsapp.com link, or just a
 * phone number (a Bangladeshi 01XXXXXXXXX is converted to the international form wa.me needs).
 * Empty input returns '' (= hide the icon).
 */
function whatsapp_link_normalize(string $input): ?string {
    $input = trim($input);
    if ($input === '') return '';
    if (preg_match('/^\+?[\d\s().-]{7,25}$/', $input)) {
        $d = preg_replace('/\D/', '', $input);
        if (str_starts_with($d, '00')) $d = substr($d, 2);
        elseif (strlen($d) === 11 && str_starts_with($d, '01')) $d = '880' . substr($d, 1);
        return (strlen($d) >= 8 && strlen($d) <= 15) ? 'https://wa.me/' . $d : null;
    }
    if (!preg_match('~^https?://~i', $input)) $input = 'https://' . $input;
    $parts = parse_url($input);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host);
    if (!in_array($host, ['wa.me', 'api.whatsapp.com', 'chat.whatsapp.com', 'whatsapp.com'], true)) return null;
    if (!filter_var($input, FILTER_VALIDATE_URL) || preg_match('/\s/', $input)) return null;
    return preg_replace('~^http://~i', 'https://', $input);
}

/**
 * Turns what an admin typed for the Signal field into a clean https link, or null when it isn't
 * one. Accepts a signal.me link, or just a phone number (a Bangladeshi 01XXXXXXXXX is converted to
 * the international form signal.me needs). Empty input returns '' (= hide the icon).
 */
function signal_link_normalize(string $input): ?string {
    $input = trim($input);
    if ($input === '') return '';
    if (preg_match('/^\+?[\d\s().-]{7,25}$/', $input)) {
        $d = preg_replace('/\D/', '', $input);
        if (str_starts_with($d, '00')) $d = substr($d, 2);
        elseif (strlen($d) === 11 && str_starts_with($d, '01')) $d = '880' . substr($d, 1);
        return (strlen($d) >= 8 && strlen($d) <= 15) ? 'https://signal.me/#p/+' . $d : null;
    }
    if (!preg_match('~^https?://~i', $input)) $input = 'https://' . $input;
    $parts = parse_url($input);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host);
    if ($host !== 'signal.me') return null;
    if (!filter_var($input, FILTER_VALIDATE_URL) || preg_match('/\s/', $input)) return null;
    return preg_replace('~^http://~i', 'https://', $input);
}

/**
 * Social profile links, only the ones that are set. Here an empty saved value
 * means "hide it" (unlike the store name), so a link can be removed from Admin
 * even when the .env still has one.
 * @return array<string, array{label:string, url:string}>
 */
function store_socials(): array {
    $defs = [
        'facebook' => ['Facebook', 'social_facebook', SOCIAL_FACEBOOK],
        'messenger' => ['Messenger', 'social_messenger', SOCIAL_FACEBOOK_MESSENGER],
        'instagram' => ['Instagram', 'social_instagram', SOCIAL_INSTAGRAM],
        'youtube' => ['YouTube', 'social_youtube', SOCIAL_YOUTUBE],
        'tiktok' => ['TikTok', 'social_tiktok', SOCIAL_TIKTOK],
        'signal' => ['Signal', 'social_signal', SOCIAL_SIGNAL],
        'whatsapp' => ['WhatsApp', 'social_whatsapp', SOCIAL_WHATSAPP],
    ];
    $out = [];
    foreach ($defs as $k => [$label, $key, $env]) {
        $v = get_setting($key);
        $url = trim((string) ($v !== null ? $v : $env));
        if (preg_match('~^https?://~i', $url)) $out[$k] = ['label' => $label, 'url' => $url];
    }
    return $out;
}

/** A phone number reduced to what a tel: link needs. */
function tel_href(string $phone): string {
    return 'tel:' . preg_replace('/[^\d+]/', '', $phone);
}

/** The letter shown in the round mark when no logo is uploaded (the Arabic letter if the name has one). */
function brand_initial(): string {
    $name = store_name();
    if (preg_match('/\p{Arabic}/u', $name, $m)) return $m[0];
    $letters = preg_replace('/[^\p{L}\p{N}]/u', '', $name);
    return mb_strtoupper(mb_substr($letters, 0, 1)) ?: 'K';
}

/* ==========================================================================
   Small UI helpers shared by header, drawer, footer and pages
   ========================================================================== */

/** Stroke-style UI icons (Feather-like). */
function ui_icon(string $name, int $size = 20): string {
    static $paths = [
        'home' => '<path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/>',
        'cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'box' => '<path d="M21 8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'snow' => '<path d="M12 2v20M4.9 7l14.2 10M19.1 7 4.9 17"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
        'truck' => '<rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
        'star' => '<path fill="currentColor" stroke="none" d="M12 2.5l2.9 6.4 7 .7-5.3 4.7 1.6 6.9L12 17.6l-6.2 3.6 1.6-6.9-5.3-4.7 7-.7z"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'alert' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'paperclip' => '<path d="M21.44 11.05 12.25 20.24a5.5 5.5 0 0 1-7.78-7.78l9.19-9.19a3.5 3.5 0 0 1 5.5 4.95l-9.2 9.19a1.5 1.5 0 0 1-2.12-2.12l8.49-8.48"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82A1.65 1.65 0 0 0 3 9.09V9a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 4.6a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'address' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/><path d="M9 21h6"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="i i-' . $name . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

/** Filled brand glyphs for the social links. */
function social_icon(string $key, int $size = 18): string {

    static $paths = [

        'facebook' => '<path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.89h2.78l-.45 2.91h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z"/>',

        'instagram' => '<path fill-rule="evenodd" d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9a5.5 5.5 0 0 1-5.5 5.5h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2zm0 2A3.5 3.5 0 0 0 4 7.5v9A3.5 3.5 0 0 0 7.5 20h9a3.5 3.5 0 0 0 3.5-3.5v-9A3.5 3.5 0 0 0 16.5 4h-9zM12 7.3a4.7 4.7 0 1 1 0 9.4 4.7 4.7 0 0 1 0-9.4zm0 2a2.7 2.7 0 1 0 0 5.4 2.7 2.7 0 0 0 0-5.4zm5.1-3.6a1.1 1.1 0 1 1 0 2.2 1.1 1.1 0 0 1 0-2.2z"/>',

        'youtube' => '<path fill-rule="evenodd" d="M23 12s0-3.6-.46-5.3a3 3 0 0 0-2.1-2.1C18.6 4 12 4 12 4s-6.6 0-8.44.6a3 3 0 0 0-2.1 2.1C1 8.4 1 12 1 12s0 3.6.46 5.3a3 3 0 0 0 2.1 2.1C5.4 20 12 20 12 20s6.6 0 8.44-.6a3 3 0 0 0 2.1-2.1C23 15.6 23 12 23 12zM9.8 8.6v6.8L15.8 12z"/>',

        'signal' => '<g fill="currentColor">
            <path d="m80 0c4.1505 0 8.2271.31607 12.2072.925452l-1.1444 7.413248c-3.6069-.55226-7.3014-.8387-11.0628-.8387-3.7612 0-7.4555.28641-11.0623.83862l-1.1444-7.413245c3.9799-.609332 8.0564-.925375 12.2067-.925375z"/>
            <path d="m98.9849 2.26619-1.7798 7.28755c7.3099 1.77896 14.1849 4.66606 20.4389 8.47306l3.895-6.411c-6.901-4.20091-14.488-7.38658-22.5541-9.34961z"/>
            <path d="m127.279 15.4591-4.432 6.0507c5.977 4.3861 11.257 9.6664 15.643 15.6437l6.051-4.4324c-4.84-6.5957-10.666-12.4222-17.262-17.262z"/>
            <path d="m148.384 38.4618-6.411 3.8942c3.807 6.2541 6.694 13.1299 8.473 20.4395l7.288-1.7798c-1.963-8.0657-5.149-15.6528-9.35-22.5539z"/>
            <path d="m159.075 67.7934-7.414 1.1444c.553 3.6067.839 7.301.839 11.0622 0 3.7614-.286 7.4559-.839 11.0628l7.414 1.1444c.609-3.9801.925-8.0567.925-12.2072 0-4.1503-.316-8.2267-.925-12.2066z"/>
            <path d="m141.973 117.645c3.807-6.255 6.694-13.13 8.473-20.44l7.288 1.7798c-1.963 8.0662-5.149 15.6532-9.35 22.5542z"/>
            <path d="m138.49 122.847 6.051 4.432c-4.84 6.596-10.666 12.422-17.262 17.262l-4.433-6.051c5.978-4.386 11.258-9.666 15.644-15.643z"/>
            <path d="m117.644 141.973 3.894 6.411c-6.901 4.201-14.488 7.387-22.5537 9.35l-1.7798-7.288c7.3095-1.779 14.1855-4.666 20.4395-8.473z"/>
            <path d="m91.0622 151.661 1.1445 7.414c-3.9799.609-8.0564.925-12.2067.925-4.1505 0-8.2272-.316-12.2073-.925l1.1442-7.413c3.6054.552 7.2997.838 11.0631.838 3.7612 0 7.4555-.286 11.0622-.839z"/>
            <path d="m62.7945 150.448-1.7794 7.286c-6.0589-1.475-11.8477-3.639-17.2785-6.406l-7.5927 1.772-1.7042-7.304 10.2604-2.394 2.4408 1.243c4.9187 2.506 10.1623 4.467 15.6536 5.803z"/>
            <path d="m28.1097 147.273 1.7042 7.304-13.0145 3.036c-8.66079 2.021-16.433718-5.752-14.41286-14.412l3.03673-13.015 7.30383 1.704-3.03675 13.015c-.75782 3.248 2.15705 6.162 5.40485 5.405z"/>
            <path d="m14.2041 125.56-7.30383-1.704 1.77163-7.593c-2.76664-5.431-4.93123-11.22-6.40585-17.2787l7.28586-1.7794c1.33599 5.4911 3.29709 10.7351 5.80279 15.6541l1.2435 2.441z"/>
            <path d="m8.33759 91.0624-7.412228 1.1442c-.609324-3.9799-.925362-8.0563-.925362-12.2066 0-4.1505.316067-8.2271.925446-12.2072l7.413244 1.1444c-.55225 3.607-.83869 7.3014-.83869 11.0628 0 3.7631.28613 7.4572.83759 11.0624z"/>
            <path d="m9.55373 62.795-7.28755-1.7798c1.96302-8.0657 5.1487-15.6528 9.34962-22.5539l6.411 3.8942c-3.807 6.2541-6.694 13.1299-8.47307 20.4395z"/>
            <path d="m21.5098 37.1531-6.0507-4.4324c4.8398-6.5957 10.6663-12.4221 17.262-17.2619l4.4324 6.0507c-5.9773 4.3861-11.2576 9.6663-15.6437 15.6436z"/>
            <path d="m42.356 18.0266-3.8943-6.4111c6.9011-4.20082 14.4882-7.38645 22.554-9.34944l1.7798 7.28755c-7.3096 1.77899-14.1854 4.66589-20.4395 8.47299z"/>
            <path d="m145 80c0 35.899-29.101 65-65 65-11.3866 0-22.0893-2.928-31.3965-8.072-.8961-.495-1.9417-.658-2.9389-.426l-28.9134 6.747 6.7465-28.914c.2326-.997.0692-2.043-.426-2.939-5.1439-9.307-8.0717-20.0095-8.0717-31.396 0-35.8985 29.1015-65 65-65 35.899 0 65 29.1015 65 65z"/>
        </g>',

        'whatsapp' => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>',

        'tiktok' => '<path d="M16.6 5.8A4.3 4.3 0 0 1 15.5 3h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.77.12V9.7a5.7 5.7 0 1 0 4.93 5.64V9.2a7.4 7.4 0 0 0 4.3 1.37V7.5a4.3 4.3 0 0 1-3.2-1.7z"/>',

        'messenger' => '<path fill-rule="evenodd" d="M12 2C6.4 2 2 6.2 2 11.4c0 2.9 1.4 5.5 3.6 7.2V22l3.3-1.8c.9.2 2 .4 3.1.4 5.6 0 10-4.2 10-9.4S17.6 2 12 2zm1 12.6-2.5-2.6-4.9 2.7 5.4-5.7 2.6 2.6 4.8-2.6-5.4 5.6z"/>',

    ];

    $viewBox = $key === 'signal' ? '0 0 160 160' : '0 0 24 24';

    return '<svg class="i i-' . $key . '" viewBox="' . $viewBox . '" width="' . $size . '" height="' . $size . '" fill="currentColor" aria-hidden="true" focusable="false">' . ($paths[$key] ?? '') . '</svg>';
}

/** The round social-link buttons (facebook / instagram / youtube / whatsapp / signal). Empty string if none are set. */
function social_row_html(string $class = 'social-row'): string {
    $links = array_intersect_key(store_socials(), array_flip(['facebook', 'instagram', 'youtube', 'tiktok', 'signal', 'whatsapp']));
    if (!$links) return '';
    $h = '<div class="' . e($class) . '">';
    foreach ($links as $k => $l) {
        $h .= '<a href="' . e($l['url']) . '" target="_blank" rel="noopener" aria-label="' . e($l['label']) . '" title="' . e($l['label']) . '">' . social_icon($k) . '</a>';
    }
    return $h . '</div>';
}

/* ==========================================================================
   Absolute URLs (link previews, sitemap and emails need full addresses)
   ========================================================================== */

function abs_url(string $path): string {
    if (preg_match('~^https?://~i', $path)) return $path;
    return rtrim(base_url(), '/') . '/' . ltrim($path, '/');
}

/** Public address the site is being served from — for the admin "is this right?" hint. */
function public_url_status(): array {
    $url = base_url();
    $host = (string) parse_url($url, PHP_URL_HOST);
    $local = in_array($host, ['', 'localhost', '127.0.0.1', '0.0.0.0'], true) || preg_match('~^(10|192\.168|172\.(1[6-9]|2\d|3[01]))\.~', $host) === 1;
    return ['url' => $url, 'https' => str_starts_with($url, 'https://'), 'local' => $local, 'from_env' => SITE_URL !== ''];
}

/* ==========================================================================
   Uploaded branding files
   ========================================================================== */

/** [absolute folder, url prefix] for logo/favicon/banner files. Falls back to the products folder if /uploads isn't writable. */
function brand_dir(): array {
    static $r = null;
    if ($r !== null) return $r;
    $sub = dirname(UPLOAD_DIR) . '/branding';
    if ((is_dir($sub) && is_writable($sub)) || (!is_dir($sub) && @mkdir($sub, 0775, true))) {
        return $r = [$sub, dirname(UPLOAD_URL) . '/branding'];
    }
    return $r = [UPLOAD_DIR, UPLOAD_URL];
}

/** Maps one of our upload URLs (/uploads/branding/x.png) to its file on disk, or null. */
function upload_local_path(?string $url): ?string {
    if (!preg_match('~^/uploads/(products|branding)/([A-Za-z0-9._-]+)$~', (string) $url, $m)) return null;
    return ($m[1] === 'products' ? UPLOAD_DIR : dirname(UPLOAD_DIR) . '/branding') . '/' . $m[2];
}

/** Deletes a branding file — and only ever a file whose name we generated (brand-*). */
function brand_delete_file(?string $url): void {
    $local = upload_local_path($url);
    if ($local && str_starts_with(basename($local), 'brand-') && is_file($local)) @unlink($local);
}

/** URL of a saved branding image if its file still exists. */
function brand_asset_url(string $key): ?string {
    $u = trim((string) get_setting($key, ''));
    if ($u === '') return null;
    $local = upload_local_path($u);
    return ($local && is_file($local)) ? $u : null;
}

function brand_logo(): ?string { return brand_asset_url('brand_logo'); }
/** The light-on-dark variant. Ignored when there is no main logo. */
function brand_logo_dark(): ?string { return brand_logo() ? brand_asset_url('brand_logo_dark') : null; }
function brand_favicon(): ?string { return brand_asset_url('brand_favicon'); }
function brand_banner(): ?string { return brand_asset_url('brand_banner'); }

function is_svg_url(?string $u): bool { return $u !== null && strtolower(pathinfo($u, PATHINFO_EXTENSION)) === 'svg'; }

/** [w, h] of a raster file we host, else null. */
function image_dims(?string $url): ?array {
    $local = upload_local_path($url) ?? ($url && str_starts_with($url, '/assets/') ? dirname(__DIR__) . $url : null);
    if (!$local || !is_file($local) || is_svg_url($url)) return null;
    $i = @getimagesize($local);
    return $i ? [(int) $i[0], (int) $i[1]] : null;
}

/** Generated files (app icons, share card): ['i32' => url, …] as saved by brand_rebuild_derived(). */
function brand_generated(string $key): ?string {
    $all = json_decode((string) get_setting('brand_generated', ''), true);
    $u = is_array($all) ? ($all[$key] ?? null) : null;
    $local = $u ? upload_local_path($u) : null;
    return ($local && is_file($local)) ? $u : null;
}

/* ---- SVG safety --------------------------------------------------------- */

/** Why an SVG upload must be refused, or null if it looks safe. SVGs are only ever used through <img>, but we refuse anything active anyway. */
function svg_unsafe_reason(string $svg): ?string {
    if (strlen($svg) > 2 * 1024 * 1024) return 'The SVG file is too large (max 2 MB).';
    $bad = [
        '~<\s*script~i', '~<\s*foreignObject~i', '~<\s*(iframe|embed|object|audio|video|canvas)\b~i', '~<!ENTITY~i',
        '~[\s"\']on[a-z]+\s*=~i', '~javascript\s*:~i', '~data\s*:\s*(text|application)/~i',
        '~(xlink:)?href\s*=\s*["\']\s*(?!#|data:image/)~i', '~@import~i', '~url\(\s*["\']?\s*(https?:)?//~i',
    ];
    foreach ($bad as $re) {
        if (preg_match($re, $svg)) return 'That SVG contains scripts or external links, which are not allowed. Export a plain SVG, or use a PNG.';
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($svg, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$xml || strtolower($xml->getName()) !== 'svg') return 'That file is not a valid SVG image.';
    return null;
}

/* ---- upload handling ---------------------------------------------------- */

/**
 * Validates and stores one branding image from $_FILES[$field].
 *
 * @param string   $kind      short label used in the file name (logo, favicon, banner…)
 * @param string[] $formats   allowed types: jpg png gif webp svg
 * @param int      $maxSide   if > 0, JPG/PNG larger than this (longest side, px) are scaled down
 * @return string|null URL of the stored file, or null when nothing was uploaded
 * @throws RuntimeException with a message that is fine to show the admin
 */
function brand_upload(string $field, string $kind, array $formats, int $maxSide = 0): ?string {
    if (empty($_FILES[$field]) || is_array($_FILES[$field]['error'] ?? null)) return null;
    $f = $_FILES[$field];
    if ($f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('That file is too large (max ' . (int) (BRAND_MAX_UPLOAD_BYTES / 1048576) . ' MB).');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The upload did not finish (error ' . (int) $f['error'] . '). Please try again.');
    // Branding images are resized down to $maxSide right below, so the raw file (often a
    // straight-from-camera/phone photo) is allowed to be considerably bigger than the product-photo
    // cap (MAX_UPLOAD_BYTES) — it never ends up that large once it's actually saved.
    if ($f['size'] > BRAND_MAX_UPLOAD_BYTES) throw new RuntimeException('That file is too large (max ' . (int) (BRAND_MAX_UPLOAD_BYTES / 1048576) . ' MB — try a smaller photo or crop/compress it first).');
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('The upload could not be verified. Please try again.');

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);

    $raster = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = null; $info = null; $labels = strtoupper(implode(', ', $formats));
    if (isset($raster[$mime]) && in_array($raster[$mime], $formats, true)) {
        $ext = $raster[$mime];
        $info = @getimagesize($f['tmp_name']);
        if (!$info) throw new RuntimeException('That image could not be read. Try saving it again as ' . $labels . '.');
        if ($info[0] * $info[1] > 30000000) throw new RuntimeException('That image has too many pixels (' . $info[0] . '×' . $info[1] . '). Please resize it below 5000 px wide.');
    } elseif (in_array('svg', $formats, true) && in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true)
              && preg_match('~<svg[\s>]~i', (string) file_get_contents($f['tmp_name'], false, null, 0, 8192))) {
        $svg = (string) file_get_contents($f['tmp_name']);
        if ($why = svg_unsafe_reason($svg)) throw new RuntimeException($why);
        $ext = 'svg';
    } else {
        throw new RuntimeException('Unsupported file type. Please upload ' . $labels . '.');
    }

    [$dir, $prefix] = brand_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('The uploads folder is not writable.');
    $name = 'brand-' . $kind . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    $done = false;
    if ($maxSide > 0 && $info && in_array($ext, ['jpg', 'png'], true) && max($info[0], $info[1]) > $maxSide && function_exists('imagecreatefromstring')) {
        $img = gd_load($f['tmp_name']);
        if ($img) {
            $scale = $maxSide / max($info[0], $info[1]);
            $out = gd_canvas((int) round($info[0] * $scale), (int) round($info[1] * $scale), $ext === 'png' ? null : [255, 255, 255]);
            imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($img), imagesy($img));
            $done = $ext === 'png' ? imagepng($out, $dest, 6) : imagejpeg($out, $dest, 88);
        }
    }
    if (!$done && !move_uploaded_file($f['tmp_name'], $dest)) throw new RuntimeException('Could not save the uploaded file.');
    @chmod($dest, 0644);
    return $prefix . '/' . $name;
}

/* ---- image drawing (GD) ------------------------------------------------- */

function gd_available(): bool { return function_exists('imagecreatetruecolor') && function_exists('imagecreatefromstring'); }

/** Loads a JPG/PNG/GIF/WebP as a true-colour image (alpha kept), or null. */
function gd_load(string $path): ?GdImage {
    $data = @file_get_contents($path);
    $img = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$img) return null;
    if (!imageistruecolor($img)) imagepalettetotruecolor($img);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    return $img;
}

/** New blank canvas: filled with $bg [r,g,b], or fully transparent when $bg is null. */
function gd_canvas(int $w, int $h, ?array $bg): GdImage {
    $c = imagecreatetruecolor($w, $h);
    if ($bg === null) {
        imagealphablending($c, false);
        imagesavealpha($c, true);
        imagefill($c, 0, 0, imagecolorallocatealpha($c, 0, 0, 0, 127));
    } else {
        imagefill($c, 0, 0, imagecolorallocate($c, $bg[0], $bg[1], $bg[2]));
    }
    return $c;
}

/** Draws $src centred on a new $w×$h canvas, scaled to fit inside a box of ($boxW × $boxH) px. */
function gd_place(GdImage $src, int $w, int $h, ?array $bg, float $boxW, float $boxH): GdImage {
    $canvas = gd_canvas($w, $h, $bg);
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = min($boxW / $sw, $boxH / $sh);
    $dw = max(1, (int) round($sw * $scale)); $dh = max(1, (int) round($sh * $scale));
    if ($bg !== null) imagealphablending($canvas, true);
    else imagealphablending($canvas, false);
    imagecopyresampled($canvas, $src, (int) (($w - $dw) / 2), (int) (($h - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
    return $canvas;
}

/** First existing raster file among the given setting keys (as a local path), or null. */
function brand_raster_source(array $keys): ?string {
    foreach ($keys as $k) {
        $u = brand_asset_url($k);
        if ($u && !is_svg_url($u)) return upload_local_path($u);
    }
    return null;
}

/**
 * Rebuilds everything derived from the uploaded images: square app icons
 * (browser tab, iPhone/Android home screen) and, when no banner was uploaded,
 * a 1200×630 link-preview card made from the logo. Old generated files are
 * removed. Safe to call any time; does nothing useful (and breaks nothing)
 * when there is no raster image or PHP has no GD.
 */
function brand_rebuild_derived(): void {
    $old = json_decode((string) get_setting('brand_generated', ''), true);
    if (is_array($old)) foreach ($old as $u) brand_delete_file($u);
    $new = [];

    if (gd_available()) {
        [$dir, $prefix] = brand_dir();
        $save = function (GdImage $img, string $key, string $label) use (&$new, $dir, $prefix): void {
            $name = 'brand-' . $label . '-' . bin2hex(random_bytes(4)) . '.png';
            if (@imagepng($img, $dir . '/' . $name, 6)) $new[$key] = $prefix . '/' . $name;
        };

        // Square icons from the favicon, else the logo. iOS/Android fill transparency with black, so those get a solid backdrop.
        if ($src = brand_raster_source(['brand_favicon', 'brand_logo'])) {
            if ($img = gd_load($src)) {
                $save(gd_place($img, 32, 32, null, 32, 32), 'i32', 'icon-32');
                foreach ([180 => 'i180', 192 => 'i192', 512 => 'i512'] as $px => $key) {
                    $save(gd_place($img, $px, $px, [255, 255, 255], $px * 0.72, $px * 0.72), $key, 'icon-' . $px);
                }
            }
        }

        // Link-preview card, only needed when there's no banner: the logo centred on a clean 1200×630 field.
        if (!brand_banner()) {
            $dark = brand_raster_source(['brand_logo_dark']);
            $srcPath = $dark ?: brand_raster_source(['brand_logo']);
            if ($srcPath && ($img = gd_load($srcPath))) {
                $bg = $dark ? [32, 41, 59] : [246, 243, 234];
                $save(gd_place($img, 1200, 630, $bg, 620, 300), 'share', 'share');
            }
        }
    }
    set_setting('brand_generated', json_encode($new));
}

/* ==========================================================================
   What the browser / link previews / phones pick up
   ========================================================================== */

/** Icon for a size, generated from the logo/favicon when there is one, else the built-in default. */
function brand_icon_url(int $size): string {
    if ($g = brand_generated('i' . $size)) return $g;
    return match ($size) { 32 => '/assets/img/favicon-32.png', 180 => '/assets/img/apple-touch-icon.png', default => '/assets/img/icon-' . $size . '.png' };
}

/** <link> tags: favicon, iPhone icon, web-app manifest. */
function brand_head_icons(): string {
    $fav = brand_favicon() ?: brand_logo();
    $h = '';
    if ($fav && is_svg_url($fav)) {
        $h .= '<link rel="icon" type="image/svg+xml" href="' . e($fav) . '">' . "\n";
        $h .= '<link rel="icon" type="image/png" sizes="32x32" href="' . e(brand_icon_url(32)) . '">' . "\n";
    } elseif ($fav) {
        $h .= '<link rel="icon" type="image/png" sizes="32x32" href="' . e(brand_generated('i32') ?: $fav) . '">' . "\n";
    } else {
        $h .= '<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">' . "\n";
        $h .= '<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">' . "\n";
    }
    $h .= '<link rel="apple-touch-icon" href="' . e(brand_icon_url(180)) . '">' . "\n";
    $h .= '<link rel="manifest" href="/manifest.webmanifest">' . "\n";
    return $h;
}

/**
 * The image used when a link is shared: the uploaded banner, else a card made
 * from the logo, else the logo itself, else the built-in default.
 * @return array{url:string, w:?int, h:?int, alt:string}
 */
function share_image(): array {
    $u = brand_banner() ?: brand_generated('share');
    if (!$u) { $logo = brand_logo(); $u = ($logo && !is_svg_url($logo)) ? $logo : '/assets/img/og-default.png'; }
    $d = image_dims($u);
    return ['url' => abs_url($u), 'w' => $d[0] ?? null, 'h' => $d[1] ?? null, 'alt' => store_name()];
}

/** Logo (raster only — mail apps don't draw SVG) as an absolute URL for emails, or null. Also null on a private/local address. */
function brand_logo_email_url(): ?string {
    $l = brand_logo();
    if (!$l || is_svg_url($l) || public_url_status()['local']) return null;
    return abs_url($l);
}

/** Cuts text to $n characters at a word boundary, for meta descriptions. */
function meta_trim(string $s, int $n = 200): string {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    if (mb_strlen($s) <= $n) return $s;
    $cut = mb_substr($s, 0, $n - 1);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp !== false && $sp > $n * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,;:-—") . '…';
}

/**
 * Inner markup of the brand link: the uploaded logo (or the round mark + name).
 * $ctx: 'header' sits on the page colour (adapts to day/night); 'footer',
 * 'drawer' and 'admin' sit on the dark theme colour.
 */
function brand_inner(string $ctx = 'header'): string {
    $name = store_name();
    $light = brand_logo();
    if (!$light) {
        return '<span class="mark" aria-hidden="true">' . e(brand_initial()) . '</span><span class="brand-name">' . e($name) . '</span>';
    }
    $dark = brand_logo_dark();
    $showName = get_setting('brand_logo_show_name', '0') === '1';
    $alt = $showName ? '' : $name;
    $img = function (string $src, string $cls) use ($alt): string {
        $d = image_dims($src);
        return '<img class="brand-logo ' . $cls . '" src="' . e($src) . '" alt="' . e($alt) . '"' . ($d ? ' width="' . $d[0] . '" height="' . $d[1] . '"' : '') . ' decoding="async">';
    };
    if ($ctx === 'header') {
        $h = $dark ? $img($light, 'logo-l') . $img($dark, 'logo-d') : $img($light, 'logo-l plate-in-dark');
    } else {
        $h = $img($dark ?: $light, $dark ? 'logo-d' : 'logo-l on-plate');
    }
    return $h . ($showName ? '<span class="brand-name">' . e($name) . '</span>' : '');
}

/* ==========================================================================
   <head> metadata: title, description, canonical, robots, Open Graph, Twitter,
   structured data. Pages set $pageTitle; product.php also sets $seo (see there).
   ========================================================================== */

const NOINDEX_PAGES = ['cart.php', 'checkout.php', 'login.php', 'register.php', 'account.php', 'addresses.php', 'orders.php', 'order-detail.php',
    'order-success.php', 'wishlist.php', 'verify-email.php', 'resend-verification.php', 'invoice.php', '404.php'];

/** The page's own address without tracking junk (only the params that change what the page shows). */
function canonical_url(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path === '/index.php') $path = '/';
    // Product/category slugs are part of the pretty path itself (see product_url()/category_url()) —
    // $_GET['slug'] is still set internally (nginx passes it to the script as a query string), but it
    // must NOT be re-appended here or the canonical URL would show both, e.g. /product/x?slug=x.
    $keep = [];
    if ((int) ($_GET['page'] ?? 1) > 1) $keep['page'] = (int) $_GET['page'];
    return abs_url($path . ($keep ? '?' . http_build_query($keep) : ''));
}

function render_head_meta(): string {
    $s = store_info();
    $seo = $GLOBALS['seo'] ?? [];
    $pageTitle = (string) ($GLOBALS['pageTitle'] ?? '');
    $isProduct = ($seo['type'] ?? '') === 'product';
    $isHome = !empty($seo['home']);
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');

    // Browser tab / search-result title.
    if ($isHome) $title = $s['tagline'] !== '' ? $s['name'] . ' — ' . $s['tagline'] : $s['name'];
    elseif ($pageTitle !== '') $title = $pageTitle . ' — ' . $s['name'];
    else $title = $s['name'];

    // What a shared link shows: product pages describe the product, every other page describes the site.
    $share = share_image();
    if ($isProduct) {
        $ogTitle = (string) $seo['title'];
        $desc = (string) $seo['description'];
        if (!empty($seo['image'])) {
            $d = image_dims($seo['image']);
            $share = ['url' => abs_url($seo['image']), 'w' => $d[0] ?? null, 'h' => $d[1] ?? null, 'alt' => $ogTitle];
        }
    } else {
        $ogTitle = $s['name'];
        $desc = $s['description'];
    }
    $desc = meta_trim($desc, 300);
    $url = canonical_url();

    $status = http_response_code();
    $robots = null;
    if (($status !== false && $status >= 400) || in_array($script, NOINDEX_PAGES, true) || !empty($seo['noindex'])) $robots = 'noindex, nofollow';
    elseif ($script === 'search.php') $robots = 'noindex, follow';

    $m = fn (string $attr, string $name, string $content) => '<meta ' . $attr . '="' . e($name) . '" content="' . e($content) . '">' . "\n";
    $h = '<title>' . e($title) . "</title>\n";
    $h .= $m('name', 'description', $desc);
    if ($robots) $h .= $m('name', 'robots', $robots);
    $h .= '<link rel="canonical" href="' . e($url) . '">' . "\n";

    // Open Graph (Facebook, WhatsApp, Messenger, LinkedIn, Telegram…)
    $h .= $m('property', 'og:type', $isProduct ? 'product' : 'website');
    $h .= $m('property', 'og:site_name', $s['name']);
    $h .= $m('property', 'og:locale', 'en_US');
    $h .= $m('property', 'og:title', $ogTitle);
    $h .= $m('property', 'og:description', $desc);
    $h .= $m('property', 'og:url', $url);
    $h .= $m('property', 'og:image', $share['url']);
    if (str_starts_with($share['url'], 'https://')) $h .= $m('property', 'og:image:secure_url', $share['url']);
    if ($share['w'] && $share['h']) { $h .= $m('property', 'og:image:width', (string) $share['w']) . $m('property', 'og:image:height', (string) $share['h']); }
    $h .= $m('property', 'og:image:alt', $share['alt']);
    if ($isProduct) {
        $h .= $m('property', 'product:price:amount', number_format((float) $seo['price'], 2, '.', ''));
        $h .= $m('property', 'product:price:currency', store_currency_code());
        $h .= $m('property', 'product:availability', !empty($seo['in_stock']) ? 'in stock' : 'out of stock');
    }

    // Twitter / X card
    $h .= $m('name', 'twitter:card', 'summary_large_image');
    $h .= $m('name', 'twitter:title', $ogTitle);
    $h .= $m('name', 'twitter:description', $desc);
    $h .= $m('name', 'twitter:image', $share['url']);
    $h .= $m('name', 'twitter:image:alt', $share['alt']);

    // Structured data for search engines
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
    $ld = [];
    if ($isProduct) {
        $imgs = array_map('abs_url', array_values(array_filter((array) ($seo['images'] ?? []))));
        $ld[] = array_filter([
            '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $seo['title'], 'description' => $desc,
            'image' => $imgs ?: [$share['url']], 'sku' => $seo['sku'] ?? null, 'category' => $seo['category'] ?? null,
            'brand' => ['@type' => 'Brand', 'name' => $s['name']],
            'aggregateRating' => (!empty($seo['review_count']) && !empty($seo['rating']))
                ? ['@type' => 'AggregateRating', 'ratingValue' => number_format((float) $seo['rating'], 1, '.', ''), 'reviewCount' => (int) $seo['review_count'], 'bestRating' => 5, 'worstRating' => 1] : null,
            'offers' => ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => store_currency_code(), 'price' => number_format((float) $seo['price'], 2, '.', ''),
                'availability' => 'https://schema.org/' . (!empty($seo['in_stock']) ? 'InStock' : 'OutOfStock'), 'itemCondition' => 'https://schema.org/NewCondition'],
        ], fn ($v) => $v !== null && $v !== '');
    }
    if ($isHome) {
        $logo = brand_logo();
        $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $s['name'], 'url' => abs_url('/'), 'description' => $desc];
        if ($logo && !is_svg_url($logo)) $org['logo'] = abs_url($logo);
        if ($s['email'] !== '') $org['email'] = $s['email'];
        if ($ph = store_phones()) $org['telephone'] = count($ph) === 1 ? $ph[0] : $ph;
        if ($s['address'] !== '') $org['address'] = str_replace("\n", ', ', $s['address']);
        if ($same = array_values(array_map(fn ($x) => $x['url'], array_diff_key(store_socials(), ['messenger' => 1, 'whatsapp' => 1, 'signal' => 1])))) $org['sameAs'] = $same;
        $ld[] = $org;
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $s['name'], 'url' => abs_url('/'),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => abs_url('/search?q={search_term_string}'), 'query-input' => 'required name=search_term_string']];
    }
    foreach ($ld as $obj) $h .= '<script type="application/ld+json">' . json_encode($obj, $flags) . "</script>\n";
    return $h;
}

/** "Phone: … · Address: …" for the Contact section of legal pages. Empty when neither is set. */
function store_contact_extra_html(): string {
    $s = store_info();
    $bits = [];
    if ($ph = store_phones()) $bits[] = 'Phone: ' . implode(' / ', array_map(fn ($p) => '<a href="' . e(tel_href($p)) . '">' . e($p) . '</a>', $ph));
    if ($s['address'] !== '') $bits[] = 'Address: ' . e(str_replace("\n", ', ', $s['address']));
    return $bits ? '<p>' . implode(' · ', $bits) . '</p>' : '';
}

/** What a shared product link says: its short description, else the start of the long one, else its name. */
function product_share_description(array $p): string {
    foreach (['short_desc', 'description'] as $k) {
        $d = meta_trim((string) ($p[$k] ?? ''), 200);
        if ($d !== '') return $d;
    }
    return (string) $p['name'];
}
