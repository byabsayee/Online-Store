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

/**
 * Amount for the PDF, honouring the store's currency position and decimals. The Taka sign (৳) is not in the
 * PDF fonts, so it is drawn from a small bundled image; every other symbol is plain text.
 * $light = white version for use on a coloured background.
 */
function invoice_money(float $amount, bool $light = false, int $px = 10): string {
    $num = e(number_format($amount, currency_decimals()));
    $sym = store_currency_symbol();
    $after = currency_position() === 'after';
    if ($sym === "\u{09F3}") {
        $f = realpath(__DIR__ . '/../assets/img/' . ($light ? 'taka-light.png' : 'taka.png'));
        $sym = ($f && is_readable($f))
            ? '<img src="' . e($f) . '" style="height:' . $px . 'px;" alt="">'
            : e(store_currency_code());
    } else {
        $sym = e($sym);
    }
    return $after ? $num . '&nbsp;' . $sym : $sym . '&nbsp;' . $num;
}

/** One "Label: value" line inside a details card. Empty values show a dash. */
function invoice_detail_line(string $label, string $valueHtml): string {
    $valueHtml = trim($valueHtml) !== '' ? $valueHtml : '<span class="muted">&mdash;</span>';
    // A two-cell row so a wrapped address lines up under itself, not under the label.
    return '<table class="dl"><tr><td class="lbl">' . e($label) . '</td><td class="val">' . $valueHtml . '</td></tr></table>';
}

/** Builds the invoice HTML (separate from PDF output so it can be tested/previewed). */
function build_invoice_html(array $order, array $items): string {
    $store = store_info();
    $theme = theme_settings();
    $dark = $theme['dark'];
    $accent = $theme['primary'];
    $onAccent = contrast_text($accent);
    $onDark = contrast_text($dark);
    $light = strtolower($onAccent) === '#ffffff'; // white text on the accent band? then use the white Taka sign

    $statusLabels = ORDER_STATUS_LABELS;
    $statusColors = ['cancelled' => '#b3261e', 'completed' => '#2e7d4f', 'shipped' => '#1d6fb8', 'processing' => '#9a6a00', 'pending' => '#6b7280'];
    $statusBg = $statusColors[$order['status']] ?? $accent;

    // ----- items
    $rowsHtml = '';
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $label = '<strong>' . e($it['product_name']) . '</strong>';
        if (!empty($it['is_preorder']) && empty($it['is_backorder'])) $label .= ' <span class="muted small">(Pre-order)</span>';
        if (!empty($it['variant_label'])) $label .= '<br><span class="muted small">' . e($it['variant_label']) . '</span>';
        if ($warranty = warranty_label($it['warranty_days'] ?? null)) $label .= '<br><span class="muted small">' . e($warranty) . '</span>';
        $rowsHtml .= '<tr class="' . ($n % 2 ? 'odd' : 'even') . '">'
            . '<td class="c-no">' . $n . '</td>'
            . '<td>' . $label . '</td>'
            . '<td class="r">' . invoice_money((float) $it['price']) . '</td>'
            . '<td class="c">' . (int) $it['quantity'] . '</td>'
            . '<td class="r"><strong>' . invoice_money((float) $it['subtotal']) . '</strong></td>'
            . '</tr>';
    }

    // ----- ship-to / billed-to / from cards
    $addr = e($order['shipping_line1']) . '<br>'
        . e($order['shipping_city']) . ($order['shipping_state'] ? ', ' . e($order['shipping_state']) : '') . ($order['shipping_zip'] ? ' ' . e($order['shipping_zip']) : '');
    $shipHtml = '<div class="card-title">Ship to</div>'
        . '<div class="name">' . e($order['shipping_name']) . '</div>'
        . invoice_detail_line('Phone', e($order['shipping_phone']))
        . invoice_detail_line('Email', e((string) order_customer_email($order)))
        . invoice_detail_line('Address', $addr);

    $billingHtml = '';
    if (empty($order['billing_same_as_shipping'])) {
        $billAddr = e($order['billing_line1']) . '<br>'
            . e($order['billing_city']) . ($order['billing_state'] ? ', ' . e($order['billing_state']) : '') . ($order['billing_zip'] ? ' ' . e($order['billing_zip']) : '');
        $billingHtml = '<div class="card-title">Billed to</div>'
            . '<div class="name">' . e($order['billing_name']) . '</div>'
            . invoice_detail_line('Phone', e($order['billing_phone']))
            . invoice_detail_line('Address', $billAddr);
    }

    $addrs = array_map(fn ($a) => ($a['label'] !== '' ? '<strong>' . e($a['label']) . ':</strong> ' : '') . nl2br(e($a['text'])), store_addresses());
    $mails = array_map(fn ($m) => e($m['email']), store_emails());
    $taxNo = trim((string) get_setting('invoice_tax_number', ''));
    $storeHtml = '<div class="card-title">From</div>'
        . '<div class="name">' . e($store['name']) . '</div>'
        . invoice_detail_line('Phone', e(implode(' / ', store_phones())))
        . invoice_detail_line('Email', implode('<br>', $mails))
        . invoice_detail_line('Address', implode('<br>', $addrs))
        . ($taxNo !== '' ? invoice_detail_line('Tax no.', e($taxNo)) : '');

    // ----- logo / name
    $logoHtml = '';
    $logoUrl = brand_logo();
    $logoFile = $logoUrl ? upload_local_path($logoUrl) : null;
    $logoFile = $logoFile ? (realpath($logoFile) ?: null) : null; // Dompdf only reads files inside its chroot, so give it the resolved path
    if ($logoFile && is_readable($logoFile) && preg_match('/\.(png|jpe?g|gif|svg)$/i', $logoFile)) {
        $logoHtml = '<img src="' . e($logoFile) . '" style="height:44px;margin-bottom:8px;"><br>';
    }
    $nameHtml = ($logoHtml === '' || get_setting('brand_logo_show_name', '0') === '1') ? '<div class="store-name">' . e($store['name']) . '</div>' : '';
    $header = trim((string) get_setting('invoice_header', ''));

    // ----- payment + notes
    $payLine = e(order_payment_name($order));
    $proof = order_payment_proof($order);
    if ($proof !== '') $payLine .= '<br><span class="muted small">' . e($proof) . '</span>';
    $notes = !empty($order['notes']) ? '<div class="note"><strong>Order note</strong><br>' . e($order['notes']) . '</div>' : '';

    // ----- totals
    $shipLabel = 'Shipping (' . delivery_area_label($order['delivery_area']) . ')';
    $discountRow = ((float) ($order['discount'] ?? 0)) > 0
        ? '<tr><td>Discount' . (!empty($order['coupon_code']) ? ' <span class="muted small">(' . e($order['coupon_code']) . ')</span>' : '') . '</td><td class="r">&minus;&nbsp;' . invoice_money((float) $order['discount']) . '</td></tr>'
        : '';

    // ----- footer (fixed to the bottom of every page)
    $footMain = 'Thank you for shopping with ' . e($store['name']) . '.';
    $footExtra = trim((string) get_setting('invoice_footer', ''));
    $contactBits = array_filter([implode(' · ', $mails), implode(' · ', array_map('e', store_phones()))]);

    return '<html><head><meta charset="UTF-8"><style>
        @page { margin: 14mm 14mm 30mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #20293b; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #8791a6; } .small { font-size: 8.5px; } .r { text-align: right; } .c { text-align: center; }
        .store-name { font-size: 21px; font-weight: bold; color: ' . e($dark) . '; margin-bottom: 2px; }
        .hdr-note { color: #8791a6; font-size: 9px; margin-top: 3px; }
        .doc-title { font-size: 30px; font-weight: bold; letter-spacing: 6px; color: ' . e($accent) . '; text-align: right; }
        .meta td { padding: 1px 0; text-align: right; font-size: 10px; }
        .meta .k { color: #8791a6; padding-right: 8px; }
        .badge { background-color: ' . e($statusBg) . '; color: #ffffff; font-weight: bold; font-size: 8.5px; letter-spacing: 1px; padding: 3px 10px; border-radius: 9px; }
        .rule { height: 3px; background-color: ' . e($accent) . '; margin: 14px 0 16px 0; }
        .card { background-color: #f6f4ee; border: 1px solid #e6e2d4; border-radius: 6px; padding: 11px 13px; vertical-align: top; }
        .card-title { font-size: 8.5px; text-transform: uppercase; letter-spacing: 1.6px; color: ' . e($accent) . '; font-weight: bold; margin-bottom: 4px; }
        .card .name { font-size: 12.5px; font-weight: bold; margin-bottom: 4px; }
        table.dl { margin-top: 3px; }
        table.dl td { vertical-align: top; padding: 0; line-height: 1.45; }
        .lbl { color: #8791a6; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; width: 48px; padding-top: 1px; }
        .items th { background-color: ' . e($dark) . '; color: ' . e($onDark) . '; padding: 8px 9px; font-size: 8.5px; text-transform: uppercase; letter-spacing: .8px; text-align: left; }
        .items td { padding: 9px; border-bottom: 1px solid #ebe8dc; vertical-align: top; }
        .items tr.even td { background-color: #faf9f4; }
        .c-no { width: 22px; color: #8791a6; }
        .totals td { padding: 5px 10px; }
        .grand td { background-color: ' . e($accent) . '; color: ' . e($onAccent) . '; font-size: 13px; font-weight: bold; padding: 9px 10px; }
        .paybox { background-color: #f6f4ee; border: 1px solid #e6e2d4; border-radius: 6px; padding: 10px 13px; }
        .note { margin-top: 10px; font-size: 9.5px; color: #4a5670; }
        .inv-foot { position: fixed; left: 0; right: 0; bottom: -22mm; height: 16mm; border-top: 1px solid #e3dfd0; padding-top: 7px; text-align: center; color: #8791a6; font-size: 8.5px; }
        .inv-foot strong { color: #20293b; }
    </style></head><body>

    <div class="inv-foot"><strong>' . $footMain . '</strong>'
        . ($footExtra !== '' ? '<br>' . nl2br(e($footExtra)) : '')
        . ($contactBits ? '<br>' . implode(' &nbsp;|&nbsp; ', $contactBits) : '') . '</div>

    <table><tr>
        <td style="width:55%;vertical-align:top;">' . $logoHtml . $nameHtml . ($header !== '' ? '<div class="hdr-note">' . e($header) . '</div>' : '') . '</td>
        <td style="vertical-align:top;">
            <div class="doc-title">INVOICE</div>
            <table class="meta" style="margin-top:6px;">
                <tr><td class="k">Invoice no.</td><td><strong>#' . e($order['order_number']) . '</strong></td></tr>
                <tr><td class="k">Date</td><td>' . e(fmt_dt($order['created_at'], 'd M Y')) . '</td></tr>
                <tr><td class="k">Payment</td><td>' . e(order_payment_name($order)) . '</td></tr>
                <tr><td class="k">Status</td><td><span class="badge">&nbsp;' . e(strtoupper($statusLabels[$order['status']] ?? $order['status'])) . '&nbsp;</span></td></tr>
            </table>
        </td>
    </tr></table>

    <div class="rule"></div>

    <table><tr>
        <td class="card" style="width:48.5%;">' . $shipHtml . '</td>
        <td style="width:3%;"></td>
        <td class="card" style="width:48.5%;">' . $storeHtml . '</td>
    </tr></table>' . ($billingHtml !== '' ? '
    <table style="margin-top:10px;"><tr>
        <td class="card" style="width:48.5%;">' . $billingHtml . '</td>
        <td style="width:3%;"></td><td style="width:48.5%;"></td>
    </tr></table>' : '') . '

    <table class="items" style="margin-top:20px;">
        <tr><th style="width:22px;">#</th><th>Item</th><th class="r" style="text-align:right;">Price</th><th class="c" style="text-align:center;">Qty</th><th class="r" style="text-align:right;">Amount</th></tr>
        ' . $rowsHtml . '
    </table>

    <table style="margin-top:14px;"><tr>
        <td style="vertical-align:top;padding-right:18px;">
            <div class="paybox"><div class="card-title">Payment</div>' . $payLine . '</div>
            ' . $notes . '
        </td>
        <td style="width:250px;vertical-align:top;">
            <table class="totals">
                <tr><td>Subtotal</td><td class="r">' . invoice_money((float) $order['subtotal']) . '</td></tr>
                ' . $discountRow . '
                <tr><td>' . e($shipLabel) . '</td><td class="r">' . ($order['shipping_fee'] > 0 ? invoice_money((float) $order['shipping_fee']) : 'Free') . '</td></tr>
                <tr class="grand"><td>Total</td><td class="r">' . invoice_money((float) $order['total'], $light, 11) . '</td></tr>
            </table>
        </td>
    </tr></table>

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
    $bar = '<style>@media print{.noprint{display:none!important}} body{max-width:820px;margin:18px auto;padding:0 14px} .inv-foot{position:static!important;margin-top:30px;height:auto!important}</style>';
    $btn = '<p class="noprint" style="text-align:right"><button onclick="window.print()" style="padding:8px 16px;font:600 14px sans-serif;cursor:pointer">Print / Save as PDF</button></p>';
    echo str_replace(['</head>', '<body>'], [$bar . '</head>', '<body>' . $btn], $html);
}
