<?php
/**
 * Generates an order invoice as a PDF using Dompdf (LGPL-2.1, AGPL-compatible). Nothing is cached to
 * disk — the PDF is rendered fresh from the database on every request, so
 * it always reflects the order's current status.
 *
 * Layout: header (shop + invoice number) → customer details on the left and
 * the shop's own details beside them → items → totals.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/order_mail.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/** Amount for the PDF. The currency sign is set in regular weight — the Taka glyph has no bold variant in DejaVu. */
function invoice_money(float $amount): string {
    return '<span class="cur">' . e(store_currency_symbol()) . '</span>' . e(number_format($amount, 2));
}

/** One "Label: value" line inside a details card. Empty values show a dash. */
function invoice_detail_line(string $label, string $valueHtml): string {
    $valueHtml = trim($valueHtml) !== '' ? $valueHtml : '<span class="muted">—</span>';
    // A two-cell row so a wrapped address lines up under itself, not under the label.
    return '<table class="dl"><tr><td class="lbl">' . e($label) . '</td><td class="val">' . $valueHtml . '</td></tr></table>';
}

/** Builds the invoice HTML (separate from PDF output so it can be tested/previewed). */
function build_invoice_html(array $order, array $items): string {
    $store = store_info();
    $dark = theme_settings()['dark'];
    $accent = theme_settings()['primary'];

    $rowsHtml = '';
    foreach ($items as $it) {
        $label = e($it['product_name']);
        if (!empty($it['is_preorder'])) {
            $label .= ' <span class="muted small">(Pre-order)</span>';
        }
        if (!empty($it['variant_label'])) {
            $label .= '<br><span class="muted small">' . e($it['variant_label']) . '</span>';
        }
        if ($warranty = warranty_label($it['warranty_days'] ?? null)) {
            $label .= '<br><span class="muted small">' . e($warranty) . '</span>';
        }
        $rowsHtml .= '<tr>'
            . '<td>' . $label . '</td>'
            . '<td style="text-align:right;">' . invoice_money((float) $it['price']) . '</td>'
            . '<td style="text-align:center;">' . (int) $it['quantity'] . '</td>'
            . '<td style="text-align:right;">' . invoice_money((float) $it['subtotal']) . '</td>'
            . '</tr>';
    }

    $statusLabels = ORDER_STATUS_LABELS;

    // --- customer card
    $addr = e($order['shipping_line1']) . '<br>'
        . e($order['shipping_city']) . ($order['shipping_state'] ? ', ' . e($order['shipping_state']) : '') . ($order['shipping_zip'] ? ' ' . e($order['shipping_zip']) : '');
    $customerHtml = '<div class="card-title">Ship to</div>'
        . '<div class="name">' . e($order['shipping_name']) . '</div>'
        . invoice_detail_line('Phone', e($order['shipping_phone']))
        . invoice_detail_line('Email', e((string) order_customer_email($order)))
        . invoice_detail_line('Address', $addr);

    // --- billing card (only rendered when it's actually different from shipping)
    $billingHtml = '';
    if (empty($order['billing_same_as_shipping'])) {
        $billAddr = e($order['billing_line1']) . '<br>'
            . e($order['billing_city']) . ($order['billing_state'] ? ', ' . e($order['billing_state']) : '') . ($order['billing_zip'] ? ' ' . e($order['billing_zip']) : '');
        $billingHtml = '<div class="card-title">Billed to</div>'
            . '<div class="name">' . e($order['billing_name']) . '</div>'
            . invoice_detail_line('Phone', e($order['billing_phone']))
            . invoice_detail_line('Address', $billAddr);
    }

    // --- shop card
    $storeHtml = '<div class="card-title">From</div>'
        . '<div class="name">' . e($store['name']) . '</div>'
        . invoice_detail_line('Phone', e(implode(' / ', store_phones())))
        . invoice_detail_line('Email', e($store['email']))
        . invoice_detail_line('Address', nl2br(e(trim($store['address']))))
        . (trim((string) get_setting('invoice_tax_number', '')) !== '' ? invoice_detail_line('Tax no.', e(trim((string) get_setting('invoice_tax_number', '')))) : '');

    // Logo above the shop name (PDF can embed png/jpg/gif/svg; skipped if the file is missing or an unsupported type).
    $logoHtml = '';
    $logoUrl = brand_logo();
    $logoFile = $logoUrl ? upload_local_path($logoUrl) : null;
    $logoFile = $logoFile ? (realpath($logoFile) ?: null) : null; // Dompdf only reads files inside its chroot, so give it the resolved path
    if ($logoFile && is_readable($logoFile) && preg_match('/\.(png|jpe?g|gif|svg)$/i', $logoFile)) {
        $logoHtml = '<img src="' . e($logoFile) . '" style="height:42px;margin-bottom:6px;"><br>';
    }
    // The logo usually contains the name already; only print the name as text if there's no logo or the admin asked for both.
    $nameHtml = ($logoHtml === '' || get_setting('brand_logo_show_name', '0') === '1') ? '<h1>' . e($store['name']) . '</h1>' : '';

    $shipLabel = 'Shipping (' . delivery_area_label($order['delivery_area']) . ')';
    // Coupon discount, only when the order used one.
    $discountRow = ((float) ($order['discount'] ?? 0)) > 0
        ? '<tr><td>Discount' . (!empty($order['coupon_code']) ? ' (' . e($order['coupon_code']) . ')' : '') . '</td><td style="text-align:right;">&minus;' . invoice_money((float) $order['discount']) . '</td></tr>'
        : '';
    $notes = !empty($order['notes']) ? '<div class="notes"><span class="muted">Order note:</span> ' . e($order['notes']) . '</div>' : '';

    return '<html><head><meta charset="UTF-8"><style>
        @page { margin: 16mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11.5px; color: #20293b; }
        .cur { font-weight: normal; }
        .muted { color: #8791a6; } .small { font-size: 9px; }
        h1 { font-size: 22px; margin: 0; color: ' . e($dark) . '; }
        .doc-label { font-size: 11px; letter-spacing: 3px; text-transform: uppercase; color: ' . e($accent) . '; }
        table { width: 100%; border-collapse: collapse; }
        .status-badge { background-color: ' . e($accent) . '; color: ' . e(contrast_text($accent)) . '; font-weight: bold; font-size: 9px; padding: 3px 9px; }
        .card { background-color: #f6f3ea; border: 1px solid #e3dfd0; padding: 11px 13px; vertical-align: top; }
        .card-title { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; color: ' . e($accent) . '; margin-bottom: 5px; font-weight: bold; }
        .card .name { font-size: 13px; font-weight: bold; margin-bottom: 5px; }
        table.dl { width: 100%; margin-top: 3px; }
        table.dl td { vertical-align: top; line-height: 1.45; padding: 0; }
        .lbl { color: #8791a6; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.5px; width: 50px; }
        .items th { text-align: left; background-color: ' . e($dark) . '; color: ' . e(contrast_text($dark)) . '; padding: 7px 9px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
        .items td { padding: 8px 9px; border-bottom: 1px solid #e6e2d3; }
        .totals td { padding: 4px 9px; }
        .grand td { border-top: 2px solid ' . e($dark) . '; font-size: 13px; padding-top: 7px; }
        .notes { margin-top: 14px; font-size: 10px; }
    </style></head><body>

    <table><tr>
        <td style="width:58%;vertical-align:top;">' . $logoHtml . $nameHtml . '<div class="doc-label">Invoice</div>' . (trim((string) get_setting('invoice_header', '')) !== '' ? '<div class="muted small">' . e(trim((string) get_setting('invoice_header', ''))) . '</div>' : '') . '</td>
        <td style="text-align:right;vertical-align:top;">
            <div style="font-size:13px;font-weight:bold;">#' . e($order['order_number']) . '</div>
            <div class="muted">Date: ' . e(fmt_dt($order['created_at'], 'd M Y')) . '</div>
            <div class="muted">Payment: ' . e(order_payment_name($order)) . '</div>
            <div style="margin-top:6px;"><span class="status-badge">&nbsp;' . e(strtoupper($statusLabels[$order['status']] ?? $order['status'])) . '&nbsp;</span></div>
        </td>
    </tr></table>

    <table style="margin-top:20px;"><tr>
        <td class="card" style="width:48.5%;">' . $customerHtml . '</td>
        <td style="width:3%;"></td>
        <td class="card" style="width:48.5%;">' . $storeHtml . '</td>
    </tr></table>' . ($billingHtml !== '' ? '

    <table style="margin-top:10px;"><tr>
        <td class="card" style="width:48.5%;">' . $billingHtml . '</td>
        <td style="width:3%;"></td>
        <td style="width:48.5%;"></td>
    </tr></table>' : '') . '

    <table class="items" style="margin-top:22px;">
        <tr><th>Item</th><th style="text-align:right;">Price</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Subtotal</th></tr>
        ' . $rowsHtml . '
    </table>

    <table style="margin-top:8px;"><tr><td></td><td style="width:270px;">
    <table class="totals">
        <tr><td>Subtotal</td><td style="text-align:right;">' . invoice_money((float) $order['subtotal']) . '</td></tr>
        ' . $discountRow . '
        <tr><td>' . e($shipLabel) . '</td><td style="text-align:right;">' . ($order['shipping_fee'] > 0 ? invoice_money((float) $order['shipping_fee']) : 'Free') . '</td></tr>
        <tr class="grand"><td><strong>Total</strong></td><td style="text-align:right;"><strong>' . invoice_money((float) $order['total']) . '</strong></td></tr>
    </table>
    </td></tr></table>
    ' . $notes . '

    <div class="muted small" style="margin-top:34px;text-align:center;">Thank you for shopping with ' . e($store['name']) . '.' . (trim((string) get_setting('invoice_footer', '')) !== '' ? '<br>' . nl2br(e(trim((string) get_setting('invoice_footer', '')))) : '') . '</div>
    </body></html>';
}

/**
 * @param array  $order Row from `orders`.
 * @param array  $items Rows from `order_items`.
 * @param string $mode  'I' = stream inline in the browser, 'D' = force download.
 */
function output_order_invoice(array $order, array $items, string $mode = 'I', ?string $src = null): void {
    // The invoice customers see is the one the owner picked (Admin → Accounting link). $src lets staff force one or the other.
    $src = $src ?? invoice_source();
    if ($src === 'book' && ($pdf = erp_book_invoice_pdf($order)) !== null) {
        $fname = 'invoice-' . preg_replace('/[^A-Za-z0-9._-]/', '_', order_invoice_id($order, 'book')) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($mode === 'D' ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        echo $pdf;
        return;
    }
    $html = build_invoice_html($order, $items);
    $fname = 'invoice-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $order['order_number']) . '.pdf';
    $pdf = null;
    if (class_exists(Dompdf::class)) {
        try {
            $opt = new Options();
            $opt->set('isRemoteEnabled', false);                 // never fetch anything over the network while rendering
            $opt->set('isPhpEnabled', false);
            $opt->set('chroot', [realpath(dirname(__DIR__)) ?: dirname(__DIR__)]); // the web root: logo and product photos live under it
            $opt->set('tempDir', sys_get_temp_dir());
            $opt->set('defaultFont', 'DejaVu Sans');             // has the Taka sign and most currency symbols
            $dompdf = new Dompdf($opt);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->addInfo('Title', 'Invoice ' . $order['order_number']);
            $pdf = $dompdf->output();
        } catch (Throwable $e) {
            error_log('[invoice] PDF render failed: ' . $e->getMessage());
            $pdf = null;
        }
    }
    if ($pdf !== null && $pdf !== '') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($mode === 'D' ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        echo $pdf;
        return;
    }
    // No PDF library (or it failed): show the same invoice as a printable page — "Print → Save as PDF" in any browser.
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store');
    $bar = '<style>@media print{.noprint{display:none!important}} body{max-width:820px;margin:18px auto;padding:0 14px}</style>';
    $btn = '<p class="noprint" style="text-align:right"><button onclick="window.print()" style="padding:8px 16px;font:600 14px sans-serif;cursor:pointer">Print / Save as PDF</button></p>';
    echo str_replace(['</head>', '<body>'], [$bar . '</head>', '<body>' . $btn], $html);
}
