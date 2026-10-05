<?php
/**
 * Admin activity log — an append-only record of what every admin did, when, and from where.
 *
 * Every state-changing admin action calls admin_log() once. There is deliberately no screen
 * (and no code path) that edits or deletes log rows: the Activity log page is read-only.
 * Logging never breaks the action being logged: a failure is written to the PHP error log
 * and the admin carries on.
 *
 * Action names are "group.verb" (e.g. "order.status", "product.update"); the group is what the
 * Activity log page filters on. Labels for the page live in ADMIN_LOG_ACTIONS below — add a new
 * action there when you add a new kind of admin action.
 */

/** action => [label, tone]. Tone is a CSS pill class suffix: sage (created/enabled), ink (changed), rust (removed/blocked), brass (sign-in). */
const ADMIN_LOG_ACTIONS = [
    'auth.login'           => ['Signed in', 'brass'],
    'auth.login_failed'    => ['Failed sign-in', 'rust'],
    'auth.logout'          => ['Signed out', 'brass'],
    'auth.password'        => ['Changed own password', 'brass'],
    'order.status'         => ['Order status changed', 'ink'],
    'order.payment'        => ['Payment recorded', 'sage'],
    'order.payment_void'   => ['Payment voided', 'rust'],
    'order.return'         => ['Return recorded', 'ink'],
    'order.attention_clear' => ['Order flag dismissed', 'ink'],
    'product.archive'      => ['Product archived', 'rust'],
    'product.restore'      => ['Product restored', 'sage'],
    'category.archive'     => ['Category hidden (kept)', 'rust'],
    'coupon.archive'       => ['Coupon switched off (kept)', 'rust'],
    'payment_method.create' => ['Payment method added', 'sage'],
    'payment_method.update' => ['Payment method edited', 'ink'],
    'payment_method.archive' => ['Payment method switched off', 'rust'],
    'payment_method.delete' => ['Payment method deleted', 'rust'],
    'settings.delivery_tax' => ['Delivery & tax changed', 'ink'],
    'erp.connect'          => ['Accounting link connected', 'sage'],
    'erp.disconnect'       => ['Accounting link disconnected', 'rust'],
    'erp.state'            => ['Accounting sync paused/resumed', 'ink'],
    'erp.setup'            => ['Accounting setup decision', 'ink'],
    'erp.conflict'         => ['Sync conflict resolved', 'ink'],
    'erp.import'           => ['Accounting import action', 'ink'],
    'erp.rotate'           => ['Accounting credentials rotated', 'ink'],
    'order.invoice'        => ['Opened an invoice', 'ink'],
    'product.create'       => ['Product created', 'sage'],
    'product.update'       => ['Product edited', 'ink'],
    'product.delete'       => ['Product deleted', 'rust'],
    'product.photo_delete' => ['Product photo removed', 'rust'],
    'product.photo_main'   => ['Main photo changed', 'ink'],
    'category.create'      => ['Category created', 'sage'],
    'category.update'      => ['Category edited', 'ink'],
    'category.delete'      => ['Category deleted', 'rust'],
    'customer.disable'     => ['Customer disabled', 'rust'],
    'customer.enable'      => ['Customer re-enabled', 'sage'],
    'staff.create'         => ['Staff member added', 'sage'],
    'staff.update'         => ['Staff details edited', 'ink'],
    'staff.delete'         => ['Staff member deleted', 'rust'],
    'staff.enable'         => ['Staff account re-enabled', 'sage'],
    'staff.disable'        => ['Staff account disabled', 'rust'],
    'staff.password_reset' => ['Reset a staff password', 'ink'],
    'staff.document_view'  => ['Opened a staff document', 'ink'],
    'coupon.create'        => ['Coupon created', 'sage'],
    'coupon.update'        => ['Coupon edited', 'ink'],
    'coupon.enable'        => ['Coupon switched on', 'sage'],
    'coupon.disable'       => ['Coupon switched off', 'ink'],
    'coupon.delete'        => ['Coupon deleted', 'rust'],
    'review.hide'          => ['Review hidden', 'ink'],
    'review.show'          => ['Review published', 'sage'],
    'review.delete'        => ['Review deleted', 'rust'],
    'settings.announcement' => ['Announcement bar edited', 'ink'],
    'settings.maintenance' => ['Maintenance mode changed', 'brass'],
    'settings.store'       => ['Store details edited', 'ink'],
    'settings.email'       => ['Email settings edited', 'ink'],
    'settings.test_email'  => ['Sent a test email', 'ink'],
    'settings.google'      => ['Google sign-in settings edited', 'ink'],
    'settings.site_url'    => ['Public site address edited', 'ink'],
    'promo.send'           => ['Promotional email sent', 'brass'],
    'promo.test'           => ['Promotional test email', 'ink'],
    'promo.cancel'         => ['Promotional campaign stopped', 'rust'],
    'branding.update'      => ['Branding edited', 'ink'],
    'theme.update'         => ['Theme edited', 'ink'],
    'theme.reset'          => ['Theme reset', 'ink'],
];

/** Human names for the action groups (the filter drop-down on the Activity log page). */
const ADMIN_LOG_GROUPS = [
    'auth' => 'Sign-ins & passwords', 'order' => 'Orders', 'product' => 'Products', 'category' => 'Categories',
    'customer' => 'Customers', 'staff' => 'Staff', 'coupon' => 'Coupons', 'review' => 'Reviews', 'settings' => 'Settings & email', 'promo' => 'Promotions',
    'branding' => 'Branding', 'theme' => 'Theme',
];

/**
 * Records one admin action.
 *
 * @param string      $action     "group.verb", see ADMIN_LOG_ACTIONS
 * @param string      $summary    one readable line, e.g. 'Order ORD-260921-AB12C: Pending → Processing'
 * @param string|null $targetType 'order' | 'product' | 'category' | 'customer' | 'coupon' | 'review' | null
 * @param int|null    $targetId   id of the thing acted on (lets the page link to it)
 * @param array       $details    extra facts shown when the row is expanded (e.g. field-by-field changes)
 * @param array|null  $admin      ['id','name','username'] — only needed when nobody is signed in yet (login events)
 */
function admin_log(string $action, string $summary, ?string $targetType = null, ?int $targetId = null, array $details = [], ?array $admin = null): void {
    try {
        if ($admin === null && function_exists('current_admin')) {
            $admin = current_admin();
        }
        $name = trim((string) ($admin['name'] ?? '')) ?: (trim((string) ($admin['username'] ?? '')) ?: 'Unknown');
        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
        if ($json !== null && strlen($json) > 30000) $json = substr($json, 0, 30000);
        db()->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, admin_username, action, target_type, target_id, summary, details, ip)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            isset($admin['id']) ? (int) $admin['id'] : null,
            mb_substr($name, 0, 120),
            isset($admin['username']) ? mb_substr((string) $admin['username'], 0, 60) : null,
            mb_substr($action, 0, 60),
            $targetType,
            $targetId,
            mb_substr($summary, 0, 255),
            $json,
            mb_substr(client_ip(), 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('[admin_log] could not record "' . $action . '": ' . $e->getMessage());
    }
}

/** Shortens long text for the log, keeping it readable. */
function admin_log_clip($v, int $n = 160): string {
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
    return mb_strlen($v) > $n ? mb_substr($v, 0, $n - 1) . '…' : $v;
}

/**
 * Field-by-field comparison for edit forms: returns ['Price' => ['950.00', '990.00'], …] for the
 * fields whose value actually changed. $fields maps a column/key to the label shown in the log.
 * Numbers compare as numbers, so "950" vs "950.00" is not a change.
 */
function admin_log_diff(array $old, array $new, array $fields): array {
    $out = [];
    foreach ($fields as $key => $label) {
        $a = $old[$key] ?? null;
        $b = $new[$key] ?? null;
        $norm = fn ($v) => $v === null ? '' : (is_numeric($v) ? (string) (float) $v : trim((string) $v));
        if ($norm($a) === $norm($b)) continue;
        $out[$label] = [admin_log_clip($a ?? '', 120) ?: '—', admin_log_clip($b ?? '', 120) ?: '—'];
    }
    return $out;
}

/** Turns a diff into the short "Price 950 → 990, Stock 5 → 8" suffix used in summaries. */
function admin_log_diff_summary(array $diff, int $max = 4): string {
    $parts = [];
    foreach ($diff as $label => [$a, $b]) {
        $parts[] = $label . ' ' . admin_log_clip($a, 30) . ' → ' . admin_log_clip($b, 30);
        if (count($parts) >= $max) break;
    }
    $more = count($diff) - count($parts);
    return implode(', ', $parts) . ($more > 0 ? ' (+' . $more . ' more)' : '');
}

function admin_log_label(string $action): array {
    return ADMIN_LOG_ACTIONS[$action] ?? [ucfirst(str_replace(['.', '_'], ' ', $action)), 'ink'];
}

/** Link to the admin page of the thing an entry was about, or null (deleted things and sign-ins have none). */
function admin_log_target_url(?string $type, ?int $id): ?string {
    if (!$type || !$id) return null;
    return match ($type) {
        'order' => '/admin/order_detail.php?id=' . $id,
        'product' => '/admin/product_form.php?id=' . $id,
        'category' => '/admin/category_form.php?id=' . $id,
        'coupon' => '/admin/coupon_form.php?id=' . $id,
        'staff' => '/admin/staff_form.php?id=' . $id,
        default => null,
    };
}

/** '"Product name"' for log summaries (falls back to the id if it no longer exists). */
function admin_log_product_name(int $productId): string {
    $s = db()->prepare('SELECT name FROM products WHERE id = ?');
    $s->execute([$productId]);
    $n = $s->fetchColumn();
    return $n !== false ? '"' . admin_log_clip($n, 80) . '"' : 'product #' . $productId;
}
