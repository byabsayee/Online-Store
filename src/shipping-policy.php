<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Shipping & Delivery Policy';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header wrap"><span class="eyebrow">Legal</span><h1>Shipping & Delivery Policy</h1></div>
<div class="wrap section" style="padding-top:8px;">
  <div class="prose">
    <p>Last updated: <?= e(legal_updated_label()) ?>. Here's how we get your order to you.</p>

    <h2>Delivery areas & fees</h2>
    <p>We ship nationwide across Bangladesh via courier, with cash on delivery available everywhere we deliver. Delivery fees depend on the zone:</p>
    <ul>
      <li><strong>Inside Dhaka:</strong> <?= money(shipcfg('inside')) ?></li>
      <li><strong>Dhaka suburbs:</strong> <?= money(shipcfg('suburbs')) ?></li>
      <li><strong>Outside Dhaka:</strong> <?= money(shipcfg('outside')) ?></li>
    </ul>
    <p>The first <?= (int) shipcfg('free_kg') ?>kg of a parcel is covered by the base fee above; every additional kg (or part of a kg) adds <?= money(shipcfg('extra_kg')) ?>. Your exact delivery fee is always shown at checkout before you place the order.</p>

    <h2>Delivery time</h2>
    <p>Most orders arrive within <?= (int) DELIVERY_DAYS_MIN ?>–<?= (int) DELIVERY_DAYS_MAX ?> days of being placed, depending on your location and the courier's schedule. Customized or made-to-order items may take longer to ship — we'll let you know if that's the case for something in your order.</p>

    <h2>Order processing</h2>
    <p>Orders are typically confirmed and handed to courier within 1–2 business days. You can track the status of your order any time from the <a href="/orders">order tracking page</a> using your order number and phone number.</p>

    <h2>Payment on delivery</h2>
    <p>Cash on delivery is our standard payment method: you pay the courier when your parcel arrives. Please have the order total ready.</p>

    <h2>Failed or missed delivery</h2>
    <p>If a courier is unable to reach you, they'll normally attempt delivery again or hold the parcel briefly for pickup. If a delivery can't be completed after repeated attempts, the order may be returned to us — contact us and we'll arrange a re-delivery.</p>

    <h2>Damaged or missing parcels</h2>
    <p>If your parcel arrives visibly damaged, or an item is missing from it, please contact us within 3 days of delivery with photos and unboxing video — see our <a href="/refund-policy">refund & return policy</a> for how we handle this.</p>

    <h2>Contact</h2>
    <p>Questions about a delivery can go to <a href="mailto:<?= e(store_info()['email']) ?>"><?= e(store_info()['email']) ?></a> or the <a href="/contact">contact page</a>.</p>
    <?= store_contact_extra_html() ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
