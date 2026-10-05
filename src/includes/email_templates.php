<?php
/**
 * Editable emails. Every email the store sends on its own (sign-up, password reset, order emails, alerts to the
 * owner, contact-form messages) has a built-in default here; the owner can change the subject, heading, wording and
 * button text in Admin → Email templates. Saved wording lives in `settings` (email_tpl_<key>_<field>); an empty value
 * means "use the default".
 *
 * Placeholders such as {first_name} are filled in when the email is sent. Values passed in are already HTML-safe.
 * The owner's wording is cleaned with the same sanitiser as the public pages (no scripts, no styles).
 */

/** @return array<string, array{label:string, to:string, when:string, subject:string, heading:string, button:string, body:string, vars:array<string,string>}> */
function email_template_defs(): array {
    $common = ['store_name' => 'Your store name'];
    return [
        'verify' => [
            'label' => 'Confirm your email', 'to' => 'Customer', 'when' => 'Right after someone creates an account',
            'subject' => 'Verify your email — {store_name}', 'heading' => 'Confirm your email address', 'button' => 'Verify my email',
            'body' => '<p>Hi {first_name},</p><p>Welcome to {store_name}! Please confirm your email address to activate your account. Any orders you placed earlier as a guest with this email will be added to your account once it is confirmed.</p>{button}<p>Or paste this link into your browser:<br>{link}</p><p>The link works for 7 days. If you did not create this account you can ignore this email.</p>',
            'vars' => $common + ['first_name' => 'Customer\'s first name', 'button' => 'The button (uses the button text above)', 'link' => 'The confirmation link as text'],
        ],
        'password_reset' => [
            'label' => 'Password reset', 'to' => 'Customer', 'when' => 'When someone uses "Forgot password"',
            'subject' => 'Reset your password — {store_name}', 'heading' => 'Reset your password', 'button' => 'Choose a new password',
            'body' => '<p>Hi {first_name},</p><p>We got a request to reset the password for your {store_name} account. The link below works for one hour.</p>{button}<p>Or paste this link into your browser:<br>{link}</p><p>If you did not ask for this, ignore this email — your password stays the same.</p>',
            'vars' => $common + ['first_name' => 'Customer\'s first name', 'button' => 'The button (uses the button text above)', 'link' => 'The reset link as text'],
        ],
        'order_confirmation' => [
            'label' => 'Order confirmation', 'to' => 'Customer', 'when' => 'When an order is placed',
            'subject' => 'Order #{order_number} confirmed', 'heading' => 'Thanks for your order', 'button' => 'View your order',
            'body' => '<p>Hi {first_name},</p><p>Thank you for your order! We have received it and will get it ready. Your order number is <strong>#{order_number}</strong>{invoice_note}.</p>{items_table}{totals}{payment_note}<p>Delivering to: {delivery_to}</p>{billing_line}{button}',
            'vars' => $common + ['first_name' => 'Customer\'s first name', 'order_number' => 'Order number', 'invoice_note' => 'Invoice number sentence (only when it differs)', 'items_table' => 'Table of items', 'totals' => 'Discount, shipping and total', 'payment_note' => 'How the customer pays', 'delivery_to' => 'Delivery name and address', 'billing_line' => 'Billing address (only when different)', 'button' => 'View-order button (guests get a sign-up prompt instead)'],
        ],
        'order_status' => [
            'label' => 'Order status update', 'to' => 'Customer', 'when' => 'Whenever an order moves to a new status (processing, shipped, completed, cancelled)',
            'subject' => 'Order #{order_number} — {status}', 'heading' => 'Order update', 'button' => 'Track your order',
            'body' => '<p>Hi {first_name},</p><p>Your order <strong>#{order_number}</strong>{invoice_note} is now <strong>{status}</strong>.</p>{note}{button}',
            'vars' => $common + ['first_name' => 'Customer\'s first name', 'order_number' => 'Order number', 'invoice_note' => 'Invoice number in brackets (only when it differs)', 'status' => 'New status, e.g. Shipped', 'note' => 'The note you typed when changing the status', 'button' => 'Track-order button (guests get a sign-up prompt instead)'],
        ],
        'new_order_admin' => [
            'label' => 'New order alert', 'to' => 'You (shop owner)', 'when' => 'When a customer places an order (needs Order alerts switched on)',
            'subject' => 'New order {order_number} — {total}', 'heading' => 'New order', 'button' => 'Open the order in admin',
            'body' => '<p>A new order just came in.</p><p><strong>{order_number}</strong> — total <strong>{total}</strong><br>Payment: {payment}{payment_proof}</p>{items_table}<p>{customer}</p>{button}',
            'vars' => $common + ['order_number' => 'Order number', 'total' => 'Order total', 'payment' => 'Payment method', 'payment_proof' => 'Sender number and transaction ID (manual payments)', 'items_table' => 'Table of items', 'customer' => 'Customer name, phone and address', 'button' => 'Button that opens the order in admin'],
        ],
        'order_cancelled_admin' => [
            'label' => 'Customer cancelled an order', 'to' => 'You (shop owner)', 'when' => 'When a customer cancels their own order on the website',
            'subject' => 'Order {order_number} cancelled by customer', 'heading' => 'Order cancelled', 'button' => 'Open the order in admin',
            'body' => '<p>A customer cancelled order <strong>{order_number}</strong> ({total}).</p>{reason_line}{refund_line}<p>The items are back in stock.</p>{button}',
            'vars' => $common + ['order_number' => 'Order number', 'total' => 'Order total', 'reason_line' => 'The reason the customer gave (if any)', 'refund_line' => 'Reminder to refund (only when a payment was recorded)', 'button' => 'Button that opens the order in admin'],
        ],
        'contact_message' => [
            'label' => 'Contact-form message', 'to' => 'You (shop owner)', 'when' => 'When a visitor sends a message from the Contact page',
            'subject' => 'New contact message from {sender_name}', 'heading' => 'New contact message', 'button' => '',
            'body' => '<p><strong>{sender_name}</strong> ({sender_email}) sent a message from the contact form:</p>{message}',
            'vars' => $common + ['sender_name' => 'Name the visitor typed', 'sender_email' => 'Their email address', 'message' => 'Their message'],
        ],
    ];
}

function email_first_name(string $name): string {
    $f = explode(' ', trim($name))[0] ?? '';
    return $f !== '' ? e($f) : 'there';
}

/** A call-to-action button in the theme colour. */
function email_button_html(string $href, string $label): string {
    $bg = theme_settings()['primary'];
    return '<p style="margin:22px 0;"><a href="' . e($href) . '" style="background:' . e($bg) . ';color:' . e(contrast_text($bg))
        . ';padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">' . e($label) . '</a></p>';
}

/** The wording in use for one template: saved values over the defaults. */
function email_template(string $key): array {
    $defs = email_template_defs();
    $d = $defs[$key] ?? null;
    if ($d === null) throw new InvalidArgumentException('Unknown email template ' . $key);
    $out = ['subject' => $d['subject'], 'heading' => $d['heading'], 'button' => $d['button'], 'body' => $d['body']];
    foreach (array_keys($out) as $f) {
        $v = trim((string) get_setting('email_tpl_' . $key . '_' . $f, ''));
        if ($v !== '') $out[$f] = $v;
    }
    return $out;
}

function email_template_customised(string $key): bool {
    foreach (['subject', 'heading', 'button', 'body'] as $f) {
        if (trim((string) get_setting('email_tpl_' . $key . '_' . $f, '')) !== '') return true;
    }
    return false;
}

/** Plain text for subjects/headings: placeholders filled with the text of their values. */
function email_plain(string $text, array $vars): string {
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = trim(html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = strtr($text, $map);
    $text = preg_replace('/\{[a-z_]+\}/', '', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Builds one email. $vars: placeholder => HTML-safe value. A 'button' var may be given as ['href' => ..., 'label' => ...]
 * (the template's own button text is used when label is omitted) or as ready-made HTML.
 * @return array{0:string,1:string} [subject, full html]
 */
function email_render(string $key, array $vars, ?array $override = null): array {
    $t = $override ? array_merge(email_template($key), $override) : email_template($key);
    $vars += ['store_name' => e(store_name())];
    if (isset($vars['button']) && is_array($vars['button'])) {
        $b = $vars['button'];
        $label = trim((string) ($b['label'] ?? '')) !== '' ? $b['label'] : $t['button'];
        $vars['button'] = ($b['href'] ?? '') !== '' && $label !== '' ? email_button_html($b['href'], $label) : '';
    }
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string) $v;
    $subject = str_replace(["\r", "\n"], ' ', email_plain($t['subject'], $vars));
    $heading = email_plain($t['heading'], $vars);
    $body = strtr($t['body'], $map);
    $body = preg_replace('/\{[a-z_]+\}/', '', $body);
    return [$subject, email_wrap($heading, $body)];
}

/** Example values for the preview and the "send me a test" button. */
function email_sample_vars(string $key): array {
    $items = '<table style="width:100%;border-collapse:collapse;font-size:14px;"><tr><td style="padding:6px 0;border-bottom:1px solid #eee;">Sample product<br><span style="color:#8791a6;font-size:12px;">Blue</span></td><td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;">× 2</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right;">' . e(money(2400)) . '</td></tr></table>';
    $btn = ['href' => mail_url('/'), 'label' => ''];
    $v = [
        'first_name' => 'Alex', 'link' => e(mail_url('/verify-email?uid=1&token=example')), 'button' => $btn,
        'order_number' => 'ORD-261005-AB12C', 'invoice_note' => '', 'items_table' => $items,
        'totals' => '<p style="text-align:right;margin:10px 0 0;">Shipping (Inside city): ' . e(money(80)) . '<br><strong>Total: ' . e(money(2480)) . '</strong></p>',
        'payment_note' => '<p>Payment: Cash on delivery — please have ' . e(money(2480)) . ' ready when your order arrives.</p>',
        'delivery_to' => 'Alex Rahman, 12 Example Road, Dhaka', 'billing_line' => '',
        'status' => 'Shipped', 'note' => '<p>Your parcel is on its way.</p>',
        'total' => e(money(2480)), 'payment' => 'bKash', 'payment_proof' => '<br>Sent from: 01700000000 · Transaction ID: <strong>8N7A6D5C</strong>',
        'customer' => '<strong>Alex Rahman</strong> · 01700000000<br>12 Example Road, Dhaka',
        'reason_line' => '<p>Reason: Ordered by mistake</p>', 'refund_line' => '<p><strong>A payment was recorded for this order — please arrange the refund.</strong></p>',
        'sender_name' => 'Alex Rahman', 'sender_email' => 'alex@example.com',
        'message' => '<p style="white-space:pre-wrap;background:#f8f6ee;padding:14px;border-radius:6px;">Hello, do you have this in black?</p>',
    ];
    return $v;
}

/** Setting keys for presets. */
function email_template_setting_keys(): array {
    $k = [];
    foreach (array_keys(email_template_defs()) as $key) foreach (['subject', 'heading', 'button', 'body'] as $f) $k[] = 'email_tpl_' . $key . '_' . $f;
    return $k;
}
