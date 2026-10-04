<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Privacy Policy';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header wrap"><span class="eyebrow">Legal</span><h1>Privacy Policy</h1></div>
<div class="wrap section" style="padding-top:8px;">
  <div class="prose">
    <p>Last updated: <?= e(legal_updated_label()) ?>. This policy explains what information <?= e(store_name()) ?> collects when you use our website and place an order, and how we use it.</p>

    <h2>Information we collect</h2>
    <p>When you browse the site, create an account, or place an order, we may collect: your name, phone number, delivery address, email address, and details of the products you order. We also store basic technical information such as your session and cart contents so the site works correctly. If you submit a product review, the name and text you provide are shown publicly on that product's page.</p>
    <p><strong>Guest orders.</strong> You can check out without an account. We then keep the details you enter (including your email address) with the order. If you later create an account, or sign in with Google, using the same email address, we add those earlier orders to your account once the address is confirmed, so you can see them in one place. Orders can also be looked up without an account using the order number together with the email used at checkout.</p>
    <p><strong>Sign in with Google.</strong> If you choose "Continue with Google", Google tells us your name, your email address (and that Google has verified it) and a Google account identifier. We use these only to create or sign you in to your account here. We do not receive your Google password and we do not access your Google contacts, files, calendar or any other Google data. Accounts created this way have no password on our site unless you choose to add one. Your use of Google's sign-in is also covered by <a href="https://policies.google.com/privacy" rel="noopener" target="_blank">Google's Privacy Policy</a>.</p>

    <h2>How we use your information</h2>
    <p>We use this information to process and deliver your orders, to contact you about an order (for example to confirm delivery details or resolve an issue), to maintain your account if you create one, and to improve the site. We do not sell your personal information to third parties.</p>

    <h2>Emails we send you</h2>
    <p><strong>Service emails</strong> are sent whenever they're needed and can't be switched off while you have an order or account: your email confirmation link, password-reset links, order confirmations and order-status updates.</p>
    <p><strong>Promotional emails</strong> (offers and new arrivals) are sent only to customers with an account who have switched them on. You choose this when you sign up (you can untick it) and can change it at any time in <em>My account &rarr; Email preferences</em>. Every promotional email also contains an unsubscribe link. Accounts created with Google start with promotional emails switched off. We never send promotional emails to guest customers.</p>
    <p>We keep a short log of the emails we send (recipient, subject and whether delivery succeeded) for about 60 days, to diagnose delivery problems.</p>

    <h2>Who we share it with</h2>
    <p>We share order and delivery details with the courier service handling your delivery, so they can reach you. Our email service provider handles the delivery of the emails described above on our behalf, and Google receives the fact that you signed in if you use "Continue with Google". We do not share your information with advertisers or data brokers.</p>

    <h2>Cookies & sessions</h2>
    <p>We use a session cookie to keep your cart and login working while you browse, and to complete "Continue with Google" safely. This is required for the site to function and doesn't track you across other websites.</p>

    <h2>Data retention</h2>
    <p>We keep order records for as long as needed for accounting, warranty, and customer-service purposes. You can ask us to delete your account and associated personal data at any time by contacting us. Password-reset links expire after one hour and are stored only in a hashed form.</p>

    <h2>Your rights</h2>
    <p>You can ask us what personal information we hold about you, ask us to correct it, or ask us to delete it, subject to any records we're legally required to keep. Reach out through the <a href="/contact">contact page</a> for any of these requests.</p>

    <h2>Contact</h2>
    <p>Questions about this policy can be sent to <a href="mailto:<?= e(store_info()['email']) ?>"><?= e(store_info()['email']) ?></a> or through our <a href="/contact">contact page</a>.</p>
    <?= store_contact_extra_html() ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
