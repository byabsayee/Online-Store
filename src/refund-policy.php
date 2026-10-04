<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Refund & Return Policy';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header wrap"><span class="eyebrow">Legal</span><h1>Refund & Return Policy</h1></div>
<div class="wrap section" style="padding-top:8px;">
  <div class="prose">
    <p>Last updated: <?= e(legal_updated_label()) ?>. We want you to be happy with what you order. Here's how returns and refunds work.</p>

    <h2>Return window</h2>
    <p>You can return an unused item, in its original condition and packaging, within 7 days of delivery.</p>

    <h2>What can't be returned</h2>
    <p>Customized or personalized items (such as engraved or made-to-order pieces) can't be returned unless they arrive damaged or defective, since they're made specifically for you.</p>

    <h2>Damaged or wrong items</h2>
    <p>If an item arrives damaged, defective, or different from what you ordered, contact us within 3 days of delivery with photos as reference and you must provide us an unboxing video of the item as proof, and we'll arrange a replacement or refund at no extra cost to you. Make sure to capture the stickers on the package clearly and record the whole process while you open the parcel.</p>

    <h2>How to start a return</h2>
    <p>Reach out through the <a href="/contact">contact page</a>, DM us, or email <a href="mailto:<?= e(store_info()['email']) ?>"><?= e(store_info()['email']) ?></a> with your order number and the reason for the return. We'll confirm pickup or drop-off details with you.</p>

    <h2>Refunds</h2>
    <p>Since payment is currently by cash on delivery, refunds for eligible returns are made via mobile banking transfer (bKash/Nagad) or bank transfer to your provided account, once the returned item is received and checked. Refunds are typically processed within a few business days of us receiving the item back.</p>

    <h2>Cancellations</h2>
    <p>You can cancel an order any time before it ships by contacting us with your order number. Once an order has shipped, it falls under the return process above instead.</p>

    <h2>Contact</h2>
    <p>Any questions about a return can go to <a href="mailto:<?= e(store_info()['email']) ?>"><?= e(store_info()['email']) ?></a> or the <a href="/contact">contact page</a>.</p>
    <?= store_contact_extra_html() ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
