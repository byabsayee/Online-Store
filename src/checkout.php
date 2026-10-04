<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$totals = cart_totals();
if (!$totals['items']) {
    redirect('/cart');
}

$shippingInside = shipping_fee_for_area('inside_dhaka', $totals['weight_grams']);
$shippingSuburbs = shipping_fee_for_area('suburbs', $totals['weight_grams']);
$shippingOutside = shipping_fee_for_area('outside_dhaka', $totals['weight_grams']);

$__user = current_user();
$errors = [];

// A coupon saved earlier in this visit is re-checked against the cart as it is now.
$__couponDrop = null;
$appliedCoupon = coupon_session_current($totals['subtotal'], coupon_shopper($__user), $__couponDrop);
// While browsing, a coupon that stopped working is dropped with a notice. But when the customer is
// pressing "Place order" it must NOT be dropped silently and the order placed at a higher price than
// the one they saw: the order is held back (see the POST handler) so they can confirm the new total.
if ($__couponDrop && $_SERVER['REQUEST_METHOD'] !== 'POST') flash_set('info', $__couponDrop);

$defaultAddress = null;
$savedAddresses = [];
if ($__user) {
    $stmt = db()->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC');
    $stmt->execute([$__user['id']]);
    $savedAddresses = $stmt->fetchAll();
    $defaultAddress = $savedAddresses[0] ?? null;
}

/** Does $fields (name/phone/line1/city/state/zip) match a saved address exactly? Avoids re-saving a duplicate. */
function address_matches(array $addr, string $name, string $phone, string $line1, string $city, string $state, string $zip): bool {
    return $addr['full_name'] === $name && $addr['phone'] === $phone && $addr['line1'] === $line1
        && $addr['city'] === $city && (string) $addr['state'] === $state && (string) $addr['zip'] === $zip;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($__couponDrop) $errors[] = $__couponDrop . ' Your total has changed — please check it and place the order again.';

    $name = trim($_POST['shipping_name'] ?? '');
    $phone = trim($_POST['shipping_phone'] ?? '');
    $email = strtolower(trim($_POST['customer_email'] ?? ''));
    $line1 = trim($_POST['shipping_line1'] ?? '');
    $city = trim($_POST['shipping_city'] ?? '');
    $state = trim($_POST['shipping_state'] ?? '');
    $zip = trim($_POST['shipping_zip'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $shipAddressId = (int) ($_POST['shipping_address_id'] ?? 0);

    $billingSame = !empty($_POST['billing_same']);
    $billName = $billingSame ? $name : trim($_POST['billing_name'] ?? '');
    $billPhone = $billingSame ? $phone : trim($_POST['billing_phone'] ?? '');
    $billLine1 = $billingSame ? $line1 : trim($_POST['billing_line1'] ?? '');
    $billCity = $billingSame ? $city : trim($_POST['billing_city'] ?? '');
    $billState = $billingSame ? $state : trim($_POST['billing_state'] ?? '');
    $billZip = $billingSame ? $zip : trim($_POST['billing_zip'] ?? '');

    // Payment methods are managed by the admin (Payment methods). Cash on delivery always exists.
    $pmRows = checkout_payment_methods();
    $pmChosen = null;
    foreach ($pmRows as $pm) if ((string) $pm['id'] === (string) ($_POST['payment_method'] ?? '')) $pmChosen = $pm;
    $pmChosen = $pmChosen ?: $pmRows[0];
    $payment = $pmChosen['kind'] === 'cod' ? 'cod' : 'bank_transfer';
    $deliveryArea = in_array($_POST['delivery_area'] ?? '', ['inside_dhaka', 'suburbs', 'outside_dhaka'], true) ? $_POST['delivery_area'] : 'inside_dhaka';
    $saveAddress = !empty($_POST['save_address']);

    if ($name === '' || strlen($name) < 2) $errors[] = 'Please enter the recipient\'s full name.';
    if (!preg_match('/^\+?[0-9][0-9 ()\-]{5,20}$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 7 || strlen(preg_replace('/\D/', '', $phone)) > 15) $errors[] = 'Please enter a valid phone number, e.g. 01XXXXXXXXX.';
    // Required: the confirmation and status emails go here, and it's what lets a later account pick this order up.
    if ($email === '') $errors[] = 'Please enter your email address — we send your order confirmation and updates there.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) $errors[] = 'That email address doesn\'t look right.';
    if ($line1 === '') $errors[] = 'Please enter your street address.';
    if ($city === '') $errors[] = 'Please enter your city.';

    if (!$billingSame) {
        if ($billName === '' || strlen($billName) < 2) $errors[] = 'Please enter the billing full name.';
        if (!preg_match('/^\+?[0-9][0-9 ()\-]{5,20}$/', $billPhone) || strlen(preg_replace('/\D/', '', $billPhone)) < 7 || strlen(preg_replace('/\D/', '', $billPhone)) > 15) $errors[] = 'Please enter a valid billing phone number.';
        if ($billLine1 === '') $errors[] = 'Please enter the billing street address.';
        if ($billCity === '') $errors[] = 'Please enter the billing city.';
    }

    // Re-verify current cart & stock right before committing the order.
    $freshTotals = cart_totals();
    if (!$freshTotals['items']) {
        $errors[] = 'Your cart is empty.';
    }
    foreach ($freshTotals['items'] as $it) {
        $label = $it['name'] . ($it['variant_label'] ? ' (' . $it['variant_label'] . ')' : '');
        if (!$it['available']) {
            $errors[] = $label . ' is no longer available — please remove it from your cart.';
        } elseif ($it['quantity'] > $it['stock'] && !$it['is_preorder']) {
            $errors[] = $label . ' only has ' . (int) $it['stock'] . ' left in stock.';
        }
    }

    if (!$errors) {
        $shippingFee = shipping_fee_for_area($deliveryArea, $freshTotals['weight_grams']);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            // Coupon: locked, re-validated with everything we now know about the buyer, and priced
            // here on the server. Nothing the browser sent decides the discount.
            $coupon = null; $discount = 0.0;
            if (coupon_session_code() !== '') {
                [$coupon, $discount] = coupon_claim_for_order($pdo, coupon_session_code(), (float) $freshTotals['subtotal'], coupon_shopper($__user, $email, $phone));
            }
            $taxCfg = tax_settings();
            [$orderTax, $taxAdded] = tax_for(round((float) $freshTotals['subtotal'] - $discount, 2), $taxCfg);
            $orderTotal = round((float) $freshTotals['subtotal'] - $discount + $taxAdded + $shippingFee, 2);
            $custSyncId = erp_customer_touch($name, $email ?: ($__user['email'] ?? null), $phone, ['line1' => $line1, 'city' => $city, 'state' => $state ?: null, 'zip' => $zip ?: null], $__user['id'] ?? null);
            $orderNumber = 'RA-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $ins = $pdo->prepare(
                'INSERT INTO orders (order_number, user_id, status, payment_method, payment_method_id, delivery_area, subtotal, discount, tax, tax_inclusive, coupon_id, coupon_code, shipping_fee, total,
                 shipping_name, shipping_phone, customer_email, shipping_line1, shipping_city, shipping_state, shipping_zip,
                 billing_same_as_shipping, billing_name, billing_phone, billing_line1, billing_city, billing_state, billing_zip, notes, customer_sync_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                $orderNumber, $__user['id'] ?? null, 'pending', $payment, (int) $pmChosen['id'] ?: null, $deliveryArea,
                $freshTotals['subtotal'], $discount, $orderTax, $taxCfg['inclusive'] ? 1 : 0, $coupon['id'] ?? null, $coupon['code'] ?? null, $shippingFee, $orderTotal,
                $name, $phone, ($email ?: ($__user['email'] ?? null)) ?: null, $line1, $city, $state ?: null, $zip ?: null,
                $billingSame ? 1 : 0, $billName, $billPhone, $billLine1, $billCity, $billState ?: null, $billZip ?: null, $notes ?: null, $custSyncId,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, variant_id, variant_label, product_name, price, quantity, subtotal, warranty_days, is_preorder) VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $productStockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            $variantStockStmt = $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?');
            foreach ($freshTotals['items'] as $it) {
                $itemStmt->execute([$orderId, $it['product_id'], $it['variant_id'], $it['variant_label'], $it['name'], $it['price'], $it['quantity'], $it['price'] * $it['quantity'], $it['warranty_days'] ?? null, $it['is_preorder'] ? 1 : 0]);
                // Pre-order lines have no stock to deduct yet — skip straight past the
                // conditional UPDATE below, which would otherwise always fail on stock >= ? here.
                if ($it['is_preorder']) continue;
                // Conditional UPDATE: if someone else bought the last units a moment ago
                // it matches no row, and we abort instead of overselling.
                $stmtStock = $it['variant_id'] ? $variantStockStmt : $productStockStmt;
                $stmtStock->execute([$it['quantity'], $it['variant_id'] ?: $it['product_id'], $it['quantity']]);
                if ($stmtStock->rowCount() < 1) {
                    throw new RuntimeException($it['name'] . ' just sold out — please review your cart.');
                }
                erp_stock_record((int) $it['product_id'], $it['variant_id'] ? (int) $it['variant_id'] : null, -(int) $it['quantity'], 'sale', 'order', $orderId);
                if ($it['variant_id']) stock_sync_product_total((int) $it['product_id']);
            }
            order_status_add($orderId, 'pending');

            if ($__user && $saveAddress) {
                // Only insert a new address when this one isn't already saved — picking a saved
                // address from the list and pressing "place order" shouldn't clone it every time.
                $alreadySaved = false;
                foreach ($savedAddresses as $a) {
                    if ((int) $a['id'] === $shipAddressId && address_matches($a, $name, $phone, $line1, $city, $state, $zip)) { $alreadySaved = true; break; }
                }
                if ($alreadySaved) {
                    $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$__user['id']]);
                    $pdo->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?')->execute([$shipAddressId, $__user['id']]);
                } else {
                    $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$__user['id']]);
                    $pdo->prepare(
                        'INSERT INTO addresses (user_id, label, full_name, phone, line1, city, state, zip, is_default) VALUES (?,?,?,?,?,?,?,?,1)'
                    )->execute([$__user['id'], 'Home', $name, $phone, $line1, $city, $state ?: null, $zip ?: null]);
                }
            }

            erp_emit('order', $orderId, 'create'); // queued in the same transaction as the sale
            $pdo->commit();
            cart_clear();
            coupon_session_clear();
            $_SESSION['last_order_number'] = $orderNumber;
            if (!$__user) guest_order_grant($orderNumber);
            $placedOrderId = (int) $orderId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // A coupon that just stopped working is dropped, so the shopper isn't stuck re-submitting it.
            if ($e instanceof CouponException) { coupon_session_clear(); $appliedCoupon = null; }
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong placing your order. Please try again.';
            if (!($e instanceof RuntimeException)) error_log('[checkout] ' . $e->getMessage());
        }

        if (!empty($placedOrderId)) {
            // Email after the shopper has been sent on to the confirmation page (see defer_job), so a slow
            // mail server never delays them.
            require_once __DIR__ . '/includes/order_mail.php';
            defer_job(fn () => send_order_confirmation_synced($placedOrderId)); // waits a few seconds for the book's invoice number when that invoice is shown
            redirect('/order-success');
        }
    }
}

$discount = $appliedCoupon ? (float) $appliedCoupon['discount'] : 0.0;
$billingSamePosted = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['billing_same']) : true;
$pageTitle = 'Checkout';
$bodyClass = 'has-action-bar';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header wrap">
  <span class="eyebrow">Checkout</span>
  <h1>Shipping &amp; billing</h1>
</div>

<div class="wrap cart-layout">
  <div class="form-card">
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <?php if (!$__user): ?>
      <div class="alert alert-info">Checking out as a guest. <a href="/login">Log in</a> to save this address and track your order later.</div>
    <?php endif; ?>

    <form method="post" id="checkoutForm">
      <?= csrf_field() ?>
      <h2 class="checkout-section-title">Shipping details</h2>

      <?php if ($savedAddresses): ?>
        <div class="field">
          <label for="shipping_address_select">Use a saved address</label>
          <select id="shipping_address_select" class="address-picker" data-target="shipping">
            <option value="">Enter a new address…</option>
            <?php foreach ($savedAddresses as $a): ?>
              <option value="<?= (int) $a['id'] ?>"
                data-name="<?= e($a['full_name']) ?>" data-phone="<?= e($a['phone']) ?>" data-line1="<?= e($a['line1']) ?>"
                data-city="<?= e($a['city']) ?>" data-state="<?= e((string) $a['state']) ?>" data-zip="<?= e((string) $a['zip']) ?>"
                <?= (!isset($_POST['shipping_address_id']) && $a['is_default']) || (int) ($_POST['shipping_address_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                <?= e($a['label']) ?> — <?= e($a['line1']) ?>, <?= e($a['city']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <input type="hidden" name="shipping_address_id" id="shipping_address_id" value="<?= e($_POST['shipping_address_id'] ?? ($defaultAddress['id'] ?? '')) ?>">

      <div class="field-row">
        <div class="field">
          <label for="shipping_name">Full name</label>
          <input id="shipping_name" name="shipping_name" autocomplete="name" required value="<?= e($_POST['shipping_name'] ?? ($defaultAddress['full_name'] ?? ($__user['name'] ?? ''))) ?>">
        </div>
        <div class="field">
          <label for="shipping_phone">Phone number</label>
          <input id="shipping_phone" name="shipping_phone" type="tel" inputmode="tel" autocomplete="tel" required value="<?= e($_POST['shipping_phone'] ?? ($defaultAddress['phone'] ?? ($__user['phone'] ?? ''))) ?>">
        </div>
      </div>
      <div class="field">
        <label for="customer_email">Email <span style="font-weight:400;color:var(--ink-faint);">(for your order confirmation &amp; invoice)</span></label>
        <input type="email" id="customer_email" name="customer_email" autocomplete="email" required value="<?= e($_POST['customer_email'] ?? ($__user['email'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="shipping_line1">Street address</label>
        <input id="shipping_line1" name="shipping_line1" autocomplete="address-line1" required value="<?= e($_POST['shipping_line1'] ?? ($defaultAddress['line1'] ?? '')) ?>">
      </div>
      <div class="field-row">
        <div class="field">
          <label for="shipping_city">City</label>
          <input id="shipping_city" name="shipping_city" autocomplete="address-level2" required value="<?= e($_POST['shipping_city'] ?? ($defaultAddress['city'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="shipping_state">State / Division</label>
          <input id="shipping_state" name="shipping_state" autocomplete="address-level1" value="<?= e($_POST['shipping_state'] ?? ($defaultAddress['state'] ?? '')) ?>">
        </div>
      </div>
      <div class="field">
        <label for="shipping_zip">ZIP / postal code</label>
        <input id="shipping_zip" name="shipping_zip" inputmode="numeric" autocomplete="postal-code" value="<?= e($_POST['shipping_zip'] ?? ($defaultAddress['zip'] ?? '')) ?>">
      </div>

      <div class="field">
        <label>Delivery area</label>
        <label class="radio-option">
          <input type="radio" name="delivery_area" value="inside_dhaka" id="da_inside" data-fee="<?= e((string)$shippingInside) ?>" <?= ($_POST['delivery_area'] ?? 'inside_dhaka') === 'inside_dhaka' ? 'checked' : '' ?>>
          <span class="radio-option-label">Inside Dhaka — <?= money($shippingInside) ?></span>
        </label>
        <label class="radio-option">
          <input type="radio" name="delivery_area" value="suburbs" id="da_suburbs" data-fee="<?= e((string)$shippingSuburbs) ?>" <?= ($_POST['delivery_area'] ?? '') === 'suburbs' ? 'checked' : '' ?>>
          <span class="radio-option-label">Dhaka Suburbs — <?= money($shippingSuburbs) ?></span>
        </label>
        <label class="radio-option">
          <input type="radio" name="delivery_area" value="outside_dhaka" id="da_outside" data-fee="<?= e((string)$shippingOutside) ?>" <?= ($_POST['delivery_area'] ?? '') === 'outside_dhaka' ? 'checked' : '' ?>>
          <span class="radio-option-label">Outside Dhaka — <?= money($shippingOutside) ?></span>
        </label>
        <div class="hint">+<?= money(shipcfg('extra_kg')) ?> added per additional kg once your parcel passes <?= (int)shipcfg('free_kg') ?>kg.</div>
      </div>

      <?php if ($__user): ?>
        <div class="checkbox-row" style="margin-bottom:18px;">
          <input type="checkbox" name="save_address" id="save_address" checked>
          <label for="save_address" style="margin:0;font-weight:400;">Save this address to my account</label>
        </div>
      <?php endif; ?>

      <h2 class="checkout-section-title">Billing details</h2>
      <div class="checkbox-row" style="margin-bottom:16px;">
        <input type="checkbox" name="billing_same" id="billing_same" <?= $billingSamePosted ? 'checked' : '' ?>>
        <label for="billing_same" style="margin:0;font-weight:400;">Billing address is the same as shipping</label>
      </div>

      <div id="billingFields" <?= $billingSamePosted ? 'hidden' : '' ?>>
        <?php if ($savedAddresses): ?>
          <div class="field">
            <label for="billing_address_select">Use a saved address</label>
            <select id="billing_address_select" class="address-picker" data-target="billing">
              <option value="">Enter a new address…</option>
              <?php foreach ($savedAddresses as $a): ?>
                <option value="<?= (int) $a['id'] ?>"
                  data-name="<?= e($a['full_name']) ?>" data-phone="<?= e($a['phone']) ?>" data-line1="<?= e($a['line1']) ?>"
                  data-city="<?= e($a['city']) ?>" data-state="<?= e((string) $a['state']) ?>" data-zip="<?= e((string) $a['zip']) ?>"
                  <?= (int) ($_POST['billing_address_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                  <?= e($a['label']) ?> — <?= e($a['line1']) ?>, <?= e($a['city']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <input type="hidden" name="billing_address_id" id="billing_address_id" value="<?= e($_POST['billing_address_id'] ?? '') ?>">

        <div class="field-row">
          <div class="field">
            <label for="billing_name">Full name</label>
            <input id="billing_name" name="billing_name" autocomplete="name" value="<?= e($_POST['billing_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="billing_phone">Phone number</label>
            <input id="billing_phone" name="billing_phone" type="tel" inputmode="tel" autocomplete="tel" value="<?= e($_POST['billing_phone'] ?? '') ?>">
          </div>
        </div>
        <div class="field">
          <label for="billing_line1">Street address</label>
          <input id="billing_line1" name="billing_line1" autocomplete="address-line1" value="<?= e($_POST['billing_line1'] ?? '') ?>">
        </div>
        <div class="field-row">
          <div class="field">
            <label for="billing_city">City</label>
            <input id="billing_city" name="billing_city" autocomplete="address-level2" value="<?= e($_POST['billing_city'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="billing_state">State / Division</label>
            <input id="billing_state" name="billing_state" autocomplete="address-level1" value="<?= e($_POST['billing_state'] ?? '') ?>">
          </div>
        </div>
        <div class="field">
          <label for="billing_zip">ZIP / postal code</label>
          <input id="billing_zip" name="billing_zip" inputmode="numeric" autocomplete="postal-code" value="<?= e($_POST['billing_zip'] ?? '') ?>">
        </div>
      </div>

      <div class="field">
        <label for="notes">Order notes (optional)</label>
        <textarea id="notes" name="notes" rows="3" placeholder="Delivery instructions, gift note, etc."><?= e($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div class="field">
        <label>Payment method</label>
        <?php $pmList = checkout_payment_methods(); $pmSel = (string) ($_POST['payment_method'] ?? $pmList[0]['id']); ?>
        <?php foreach ($pmList as $pm): ?>
          <label class="radio-option">
            <input type="radio" name="payment_method" value="<?= (int) $pm['id'] ?>" <?= $pmSel === (string) $pm['id'] ? 'checked' : '' ?>>
            <span class="radio-option-label"><?= e($pm['name']) ?><?= $pm['kind'] === 'cod' ? ' — pay when your order arrives' : '' ?>
              <?php if (!empty($pm['instructions'])): ?><span class="hint" style="display:block;font-weight:400;white-space:pre-line;"><?= e($pm['instructions']) ?></span><?php endif; ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <?php /* "Save this address" checkbox moved up under the shipping section, closer to what it saves. */ ?>

      <button type="submit" class="btn btn-primary btn-block place-order-desktop">Place order — <span id="submitTotal"><?= money($totals['subtotal'] - $discount + tax_for(max(0, $totals['subtotal'] - $discount))[1] + $shippingInside) ?></span></button>
    </form>
  </div>

  <div class="summary-card">
    <h3>Order summary</h3>
    <?php foreach ($totals['items'] as $it): ?>
      <div class="summary-row"><span><?= e($it['name']) ?><?= $it['variant_label'] ? ' <span style="color:var(--ink-faint);">(' . e($it['variant_label']) . ')</span>' : '' ?><?= $it['is_preorder'] ? ' <span class="pill pill-brass" style="font-size:11px;">Pre-order</span>' : '' ?> × <?= (int)$it['quantity'] ?></span><span class="val"><?= money($it['price'] * $it['quantity']) ?></span></div>
    <?php endforeach; ?>
    <div class="summary-row"><span>Subtotal</span><span class="val"><?= money($totals['subtotal']) ?></span></div>
    <div class="summary-row discount-row" id="summaryDiscountRow"<?= $discount > 0 ? '' : ' hidden' ?>><span>Discount<?= $appliedCoupon ? ' <small class="coupon-tag" id="summaryCouponCode">' . e($appliedCoupon['coupon']['code']) . '</small>' : ' <small class="coupon-tag" id="summaryCouponCode"></small>' ?></span><span class="val" id="summaryDiscount">&minus;<?= money($discount) ?></span></div>
    <?php $__tax = tax_settings(); if ($__tax['enabled'] && $__tax['rate'] > 0): ?>
      <div class="summary-row"><span><?= e($__tax['label']) ?> (<?= e(rtrim(rtrim(number_format($__tax['rate'], 3), '0'), '.')) ?>%<?= $__tax['inclusive'] ? ', included' : '' ?>)</span><span class="val" id="summaryTax"><?= money(tax_for(max(0, $totals['subtotal'] - $discount))[0]) ?></span></div>
    <?php endif; ?>
    <div class="summary-row"><span>Shipping</span><span class="val" id="summaryShipping"><?= money($shippingInside) ?></span></div>
    <div class="summary-row total"><span>Total</span><span class="val" id="summaryTotal"><?= money($totals['subtotal'] - $discount + tax_for(max(0, $totals['subtotal'] - $discount))[1] + $shippingInside) ?></span></div>

    <div class="coupon-box" id="couponBox" data-discount="<?= e((string) $discount) ?>">
      <form class="coupon-form" id="couponForm" autocomplete="off"<?= $appliedCoupon ? ' hidden' : '' ?>>
        <label for="couponCode" class="coupon-label">Have a coupon code?</label>
        <div class="coupon-row">
          <input id="couponCode" type="text" placeholder="Enter code" maxlength="40" autocapitalize="characters" spellcheck="false" enterkeyhint="done">
          <button type="submit" class="btn btn-outline btn-sm" id="couponApply">Apply</button>
        </div>
      </form>
      <div class="coupon-applied" id="couponApplied"<?= $appliedCoupon ? '' : ' hidden' ?>>
        <span class="coupon-ok" aria-hidden="true"><?= ui_icon('check', 13) ?></span>
        <span class="coupon-applied-text"><strong id="couponAppliedCode"><?= $appliedCoupon ? e($appliedCoupon['coupon']['code']) : '' ?></strong> applied<small id="couponAppliedDesc"><?= $appliedCoupon ? e(coupon_describe($appliedCoupon['coupon'])) : '' ?></small></span>
        <button type="button" class="link-btn" id="couponRemove">Remove</button>
      </div>
      <div class="coupon-msg" id="couponMsg" role="status" aria-live="polite"></div>
    </div>
  </div>

  <div class="checkout-bar">
    <div class="bb-price"><small>Total</small><strong id="barTotal"><?= money($totals['subtotal'] - $discount + tax_for(max(0, $totals['subtotal'] - $discount))[1] + $shippingInside) ?></strong></div>
    <button type="submit" form="checkoutForm" class="btn btn-primary">Place order</button>
  </div>
</div>

<script>
(function () {
  var subtotal = <?= (float)$totals['subtotal'] ?>;
  var discount = <?= (float) $discount ?>;
  var taxCfg = <?= json_encode(['on' => tax_settings()['enabled'], 'rate' => tax_settings()['rate'], 'inclusive' => tax_settings()['inclusive']]) ?>;
  var taxEl = document.getElementById('summaryTax');
  var symbol = <?= json_encode(store_currency_symbol()) ?>;
  var radios = document.querySelectorAll('input[name="delivery_area"]');
  var shippingEl = document.getElementById('summaryShipping');
  var totalEl = document.getElementById('summaryTotal');
  var submitEl = document.getElementById('submitTotal');
  var barEl = document.getElementById('barTotal');
  var discRow = document.getElementById('summaryDiscountRow');
  var discEl = document.getElementById('summaryDiscount');
  var discCode = document.getElementById('summaryCouponCode');

  function fmt(n) {
    return symbol + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  // Display only — the server recomputes the discount and total when the order is placed.
  function update() {
    var checked = document.querySelector('input[name="delivery_area"]:checked');
    var fee = checked ? parseFloat(checked.dataset.fee) : 0;
    var base = Math.max(0, subtotal - discount);
    var tax = 0, added = 0;
    if (taxCfg.on && taxCfg.rate > 0 && base > 0) {
      if (taxCfg.inclusive) { tax = Math.round((base - base / (1 + taxCfg.rate / 100)) * 100) / 100; }
      else { tax = Math.round(base * taxCfg.rate) / 100; added = tax; }
    }
    if (taxEl) taxEl.textContent = fmt(tax);
    var total = base + added + fee;
    shippingEl.textContent = fmt(fee);
    totalEl.textContent = fmt(total);
    submitEl.textContent = fmt(total);
    if (barEl) barEl.textContent = fmt(total);
    discRow.hidden = !(discount > 0);
    discEl.textContent = '\u2212' + fmt(discount);
  }

  radios.forEach(function (r) { r.addEventListener('change', update); });
  // main.js fires this when a coupon is applied or removed.
  document.addEventListener('coupon:changed', function (e) {
    discount = (e.detail && e.detail.discount) || 0;
    discCode.textContent = (e.detail && e.detail.code) || '';
    update();
  });
  update();

  // Saved-address pickers: fill the matching field group and remember which address was picked
  // (so the server can update it instead of saving a duplicate when "Save this address" is on).
  document.querySelectorAll('.address-picker').forEach(function (sel) {
    var target = sel.dataset.target; // 'shipping' or 'billing'
    var hiddenId = document.getElementById(target + '_address_id');
    sel.addEventListener('change', function () {
      var opt = sel.options[sel.selectedIndex];
      if (hiddenId) hiddenId.value = opt.value || '';
      if (!opt.value) return; // "Enter a new address…" — leave fields as they are
      ['name', 'phone', 'line1', 'city', 'state', 'zip'].forEach(function (f) {
        var el = document.getElementById(target + '_' + f);
        if (el) el.value = opt.dataset[f] || '';
      });
    });
  });
  // Editing a shipping field by hand after picking a saved one means it's no longer that exact
  // address — clear the picker's memory so a save doesn't silently overwrite the saved one.
  ['shipping_name', 'shipping_phone', 'shipping_line1', 'shipping_city', 'shipping_state', 'shipping_zip'].forEach(function (id) {
    var el = document.getElementById(id);
    var hiddenId = document.getElementById('shipping_address_id');
    if (el && hiddenId) el.addEventListener('input', function () {
      var sel = document.getElementById('shipping_address_select');
      if (sel) sel.value = '';
      hiddenId.value = '';
    });
  });

  // Billing: hide/show the billing fields, and make them required only while visible.
  var billingSame = document.getElementById('billing_same');
  var billingFields = document.getElementById('billingFields');
  var billingRequired = ['billing_name', 'billing_phone', 'billing_line1', 'billing_city'];
  function syncBilling() {
    var same = billingSame.checked;
    billingFields.hidden = same;
    billingRequired.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.required = !same;
    });
  }
  if (billingSame && billingFields) {
    billingSame.addEventListener('change', syncBilling);
    syncBilling();
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
