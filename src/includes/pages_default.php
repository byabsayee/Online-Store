<?php
/**
 * Built-in starter text for the editable pages. Placeholders such as {store_name} always show the store's current
 * details. This is generic sample wording, NOT legal advice — every store owner must review and adapt it.
 */
return [
'about' => '<p><strong>{store_name}</strong> is an online store. Tell your story here: who you are, what you sell and why customers can trust you.</p>
<h2>What we offer</h2>
<p>Describe your products or services in a few sentences. Edit this page any time from the admin panel (Pages).</p>
<h2>Shipping &amp; returns</h2>
<p>We deliver in {delivery_days} days. If something is not right, please read our <a href="/refund-policy">refund &amp; return policy</a> or <a href="/contact">contact us</a>.</p>
<h2>Questions?</h2>
<p>Reach out any time through the <a href="/contact">contact page</a>.</p>',

'faq' => '<h2>How do I place an order?</h2>
<p>Add products to your cart, go to checkout, enter your delivery details and choose a payment method.</p>
<h2>How long does delivery take?</h2>
<p>Most orders arrive within {delivery_days} days. Your exact delivery fee is shown at checkout before you place the order.</p>
<h2>Which payment methods do you accept?</h2>
<p>The available methods are listed at checkout. For manual methods, follow the instructions shown and keep your transaction ID.</p>
<h2>Can I return or exchange an item?</h2>
<p>Please read our <a href="/refund-policy">Refund &amp; Return Policy</a>.</p>
<h2>How can I track my order?</h2>
<p>Use <a href="/orders">Track an order</a> with your order number, or sign in to your account.</p>
<h2>How do I contact you?</h2>
<p>Email <a href="mailto:{store_email}">{store_email}</a> or use the <a href="/contact">contact page</a>.</p>',

'terms' => '<p>Last updated: {updated}. These terms apply whenever you browse or order from {store_name}. By placing an order, you agree to them.</p>
<h2>Orders</h2>
<p>An order is accepted when we confirm it. We may cancel an order if an item is unavailable, a price was shown in error, or we cannot verify the details you gave. If we cancel a paid order, we refund you in full.</p>
<h2>Prices and payment</h2>
<p>All prices are in {currency}. The price you see at checkout, including delivery and any tax, is the price you pay. Payment methods are listed at checkout.</p>
<h2>Delivery</h2>
<p>We aim to deliver within {delivery_days} days of an order being confirmed. Delivery times are estimates, not guarantees. See our <a href="/shipping-policy">Shipping &amp; Delivery Policy</a>.</p>
<h2>Returns and refunds</h2>
<p>See our <a href="/refund-policy">Refund &amp; Return Policy</a>.</p>
<h2>Your account</h2>
<p>You are responsible for keeping your password safe and for what happens under your account.</p>
<h2>Contact</h2>
<p>{store_name} · <a href="mailto:{store_email}">{store_email}</a> · {store_phone}<br>{store_address}</p>
<p><em>This is a starter template and not legal advice. Please have it reviewed for your country and business.</em></p>',

'privacy-policy' => '<p>Last updated: {updated}. This policy explains what information {store_name} collects when you use our website and how we use it.</p>
<h2>Information we collect</h2>
<p>Your name, email, phone number and delivery address when you order or create an account; the items you order; and basic technical data (such as your browser and IP address) needed to run and secure the site.</p>
<h2>How we use it</h2>
<p>To process and deliver your orders, contact you about them, run your account, prevent fraud, and, if you agree, send you news and offers. You can unsubscribe at any time.</p>
<h2>Sharing</h2>
<p>We share only what is needed with delivery partners and payment providers, and where the law requires it. We do not sell your personal information.</p>
<h2>Cookies</h2>
<p>We use cookies to keep you signed in, remember your cart and your display preferences. If advertising is shown on this site, advertising partners may use cookies too; you can accept or refuse where a cookie notice is shown. Details are in our <a href="/cookie-policy">Cookie Policy</a>.</p>
<h2>Your rights</h2>
<p>You can ask to see, correct or delete your personal data by contacting us at <a href="mailto:{store_email}">{store_email}</a>.</p>
<p><em>This is a starter template and not legal advice. Please have it reviewed for your country and business.</em></p>',

'cookie-policy' => '<p>Last updated: {updated}. This policy explains what cookies are and how {store_name} uses them. It should be read together with our <a href="/privacy-policy">Privacy Policy</a>.</p>
<h2>What is a cookie?</h2>
<p>A cookie is a small text file that a website stores on your device. Cookies (and similar technologies such as local storage) let a site remember who you are between pages and visits.</p>
<h2>Cookies we use</h2>
<ul>
<li><strong>Essential cookies</strong> keep you signed in, remember what is in your cart, protect forms against misuse and keep the site secure. The site cannot work properly without them, so they are always on.</li>
<li><strong>Preference storage</strong> remembers choices such as light or dark mode and your answer to the cookie notice. It stays on your device and is not used to identify you.</li>
<li><strong>Advertising and measurement cookies</strong> are used only if this site shows ads or analytics, and only after you press Accept. They are set by our advertising or analytics partners (for example Google) to show and measure ads.</li>
</ul>
<h2>Your choices</h2>
<p>When the cookie notice appears you can accept or decline non-essential cookies. You can change your mind at any time: <a href="#" data-cookie-reset>change my cookie choice</a>. You can also block or delete cookies in your browser settings; if you block essential cookies, parts of the site (such as the cart and sign-in) will stop working.</p>
<h2>Third parties</h2>
<p>If advertising is enabled, our partners may set their own cookies. Their use of information is described in their own privacy policies, for example Google\'s at <a href="https://policies.google.com/technologies/ads" target="_blank" rel="noopener">policies.google.com/technologies/ads</a>.</p>
<h2>Contact</h2>
<p>Questions about cookies? Write to <a href="mailto:{store_email}">{store_email}</a>.</p>
<p><em>This is a starter template and not legal advice. Please have it reviewed for your country and business.</em></p>',

'refund-policy' => '<p>Last updated: {updated}. We want you to be happy with what you buy from {store_name}.</p>
<h2>Returns</h2>
<p>If something is wrong, contact us within 7 days of receiving your order at <a href="mailto:{store_email}">{store_email}</a> with your order number. Items must be unused and in their original packaging.</p>
<h2>Damaged or wrong items</h2>
<p>If your item arrives damaged or is not what you ordered, tell us as soon as possible and we will repair, replace or refund it.</p>
<h2>Refunds</h2>
<p>Approved refunds are sent back using the same method you paid with, or as agreed with you, once we receive and check the returned item.</p>
<h2>Items that cannot be returned</h2>
<p>Made-to-order or personalised items and anything marked non-returnable on its product page.</p>
<p><em>This is a starter template and not legal advice. Please adapt it to your business and local consumer law.</em></p>',

'shipping-policy' => '<p>Last updated: {updated}. This page explains how {store_name} delivers your order.</p>
<h2>Delivery fees</h2>
{delivery_zones}
<p>The first {free_kg}kg of a parcel is covered by the base fee above; every additional kg (or part of a kg) adds {extra_per_kg}. Your exact delivery fee is always shown at checkout before you place the order.</p>
<h2>Delivery time</h2>
<p>Most orders arrive within {delivery_days} days of being confirmed. Remote areas and busy periods can take longer.</p>
<h2>Tracking</h2>
<p>Follow your order any time from <a href="/orders">Track an order</a>.</p>
<h2>Problems with delivery</h2>
<p>If your order is late or damaged, contact us at <a href="mailto:{store_email}">{store_email}</a>.</p>',
];
