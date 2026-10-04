<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Terms of Service';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header wrap"><span class="eyebrow">Legal</span><h1>Terms of Service</h1></div>
<div class="wrap section" style="padding-top:8px;">
  <div class="prose">
    <p>Last updated: <?= e(legal_updated_label()) ?>. These terms apply whenever you browse or order from <?= e(store_name()) ?>. By placing an order with us, you agree to them.</p>

    <h2>Orders</h2>
    <p>Placing an order is an offer to buy the listed product at the listed price. We confirm your order after it's placed; we may cancel or adjust an order if a product turns out to be unexpectedly out of stock or incorrectly priced, and we'll let you know if that happens. Some out-of-stock products are marked "Pre-order" — ordering one of these is a confirmed order for stock we expect to receive, and any note or expected-availability date shown is our best estimate rather than a guarantee.</p>

    <h2>Pricing</h2>
    <p>All prices are shown in Bangladeshi Taka (৳) and include applicable taxes unless stated otherwise. Delivery charges are shown separately at checkout.</p>

    <h2>Payment</h2>
    <p>We currently accept cash on delivery — you pay the courier when your order arrives. Online payment is coming soon.</p>

    <h2>Delivery</h2>
    <p>We aim to deliver within <?= (int)DELIVERY_DAYS_MIN ?>–<?= (int)DELIVERY_DAYS_MAX ?> days of an order being placed. Delivery timelines can occasionally be affected by courier delays, weather, or remote locations, and are estimates rather than guarantees.</p>

    <h2>Product information</h2>
    <p>We try to describe and photograph every product accurately. Colors may vary slightly due to screen display, and handmade or leather items may show natural variation piece to piece.</p>

    <h2>Returns & cancellations</h2>
    <p>See our <a href="/refund-policy">refund & return policy</a> for how to cancel or return an order.</p>

    <h2>Accounts</h2>
    <p>If you create an account, you're responsible for keeping your login details secure. You can sign up with an email and password or with "Continue with Google"; if both use the same email address they are the same account. Let us know right away if you believe your account has been accessed without your permission.</p>
    <p>Orders you placed as a guest with an email address are added to the account for that address once the address is confirmed, so please only sign up with an email you own. Guest orders can also be viewed with the order number and the email used at checkout.</p>

    <h2>Emails</h2>
    <p>We send you emails needed to run your account and orders (email confirmation, password resets, order confirmations and status updates). Offers and new-arrival emails are optional: you can switch them on or off in your account, or use the unsubscribe link in any of them, and doing so never affects your orders.</p>

    <h2>Changes to these terms</h2>
    <p>We may update these terms from time to time as the store evolves. The current version is always available on this page.</p>

    <h2>Contact</h2>
    <p>Questions about these terms can be sent to <a href="mailto:<?= e(store_info()['email']) ?>"><?= e(store_info()['email']) ?></a> or through our <a href="/contact">contact page</a>.</p>
    <?= store_contact_extra_html() ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
