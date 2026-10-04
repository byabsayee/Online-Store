# Phase 8 setup: email, Google sign-in, promotional emails

Everything below is done in the admin panel. No code changes are needed.

## 1. Deploy

1. Copy the new files over your repo (keep your own `.env`), commit and push to `main`.
2. Wait for the GitHub Action to publish the image, then redeploy the stack in Portainer.
3. The first request runs migration `011_auth_email.sql` by itself (adds Google/promo columns, password-reset, email log and campaign tables).
4. **Fix `SITE_URL` in your `.env`** (or leave it blank). It must be an address that works *today*. If it says `https://kafeel.com.bd` and you don't own that domain yet, every "Verify my email" / "Reset password" link in an email points at a dead site. You can also set it in *Admin -> Settings & email -> Public site address*, which overrides `.env`.

## 2. Make email work (SMTP)

Why nothing was being sent: with no SMTP host set, the app falls back to PHP `mail()`, which cannot work inside the Docker image (no sendmail). PHP-FPM was also hiding the error message, so the failure was invisible. Both are fixed: failures now appear in *Recent emails* (with the server's exact error and a hint) and in `docker logs kafeel_web`.

Pick one provider:

**A. Gmail (fastest, no domain needed).** Limits: about 500 recipients/day, so fine for orders, tight for big promotions.
1. Google Account -> Security -> turn on 2-Step Verification.
2. Security -> App passwords -> create one named "Kafeel". Copy the 16 characters (spaces are ignored).
3. Admin -> Settings & email -> Email (SMTP): host `smtp.gmail.com`, port `587`, encryption STARTTLS, username = your full Gmail address, password = the app password.
4. Leave "From" alone. Gmail only sends as the login address, and the app handles that (replies still go to your store email).

**B. Brevo (free 300/day, better for promotions).** Create an account, add and verify a single sender email, then use SMTP host `smtp-relay.brevo.com`, port `587`, with the SMTP login and key from Brevo's SMTP & API page. Set the "From" email to the sender you verified.

**C. Your own domain (best long term).** Once you have a domain, use its mail host (Zoho, cPanel, etc.) and add SPF and DKIM DNS records from the provider. This is what keeps promotional email out of spam.

Then click **Send a test email**. If it fails, the message now includes the mail server's own reply (for example `535 5.7.8 Authentication Failed`) plus a hint.

**Zoho specifics:** host `smtp.zoho.com` (or `smtppro.zoho.com` for paid business mail; use `smtp.zoho.in` / `.eu` / `.com.au` if your account lives in that region), port `465` with SSL/TLS or `587` with STARTTLS, username = the full mailbox address. If two-factor is on for that mailbox, a normal password is refused: create an Application-Specific Password (Zoho Account -> Security) and use that. Make sure IMAP/SMTP access is enabled for the mailbox.

## 3. Google sign-in

You need an `https://` address on a real domain. Google refuses plain `http://` (except localhost) and raw IPs like `192.168.x.x`.

1. https://console.cloud.google.com -> create a project ("Kafeel").
2. Google Auth Platform (APIs & Services -> OAuth consent screen): App name = your store name, support email, developer email. User type **External**.
3. Scopes: only `openid`, `email`, `profile` (these are the basic ones).
4. Authorized domains: your site's domain. Add links to your Privacy Policy (`/privacy-policy`) and Terms (`/terms`).
5. Credentials -> Create credentials -> OAuth client ID -> **Web application**.
6. **Authorized redirect URIs:** copy the exact value shown in *Admin -> Settings & email -> Google sign-in* (it looks like `https://yourdomain/google-callback`). Open the admin on your real https address first so the value is right. Add one URI per address you use (e.g. a temporary domain and the final one).
7. Copy the Client ID and Client secret into that admin panel, keep "Show the Google button" on, save.
8. Back in Google, set the consent screen's publishing status to **In production**. While it is "Testing", only accounts you list as test users can sign in.

## 4. How accounts and orders merge

| Situation | Result |
|---|---|
| Guest checks out | Order stays a guest order, stored with the email typed at checkout (email is now required). |
| Later signs up with a password using that email | Account is created. After they click the emailed confirmation link, all earlier guest orders with that email attach to the account. |
| Later uses Google with that email | Google has already verified the email, so the account is created (or linked) and guest orders attach immediately. |
| Has a password account, then uses Google with the same email | Google is linked to the same account; nothing is duplicated. |
| Has a Google-only account and wants a password | *Forgot password* or *Account -> Add a password*. |

Why merging waits for a confirmed email: otherwise anyone could register with somebody else's address and read that person's guest orders (names, phones, addresses).

Guests can also track an order without an account: **Track an order** (footer) with the order number and checkout email.

## 5. Promotional emails

- Customers have a *Send me promotional emails* switch at sign-up (default on) and in *My account -> Email preferences*. Google sign-ups start with it **off**.
- Admin -> **Promotional emails** (owner only): write the message, send yourself a test, then send. It goes only to active customers with a confirmed email who opted in, in batches of 15 from the page's progress bar (keep it open until it finishes; if you close it, reopen the page and it continues).
- Each email has a personal unsubscribe link and one-click unsubscribe headers. Someone who unsubscribes mid-campaign is skipped.
- Order, verification and password emails ignore the switch.

## 6. The domain question (kafeel.com.bd)

- Google sign-in and email do **not** need `.com.bd` specifically, only a real domain with HTTPS. You can start on any domain or subdomain you control.
- `.com.bd` is issued by BTCL and is a business domain: expect to supply a trade licence / RJSC certificate and NID, and allow up to a week. Recent listings put it around ৳700 to ৳1,280 a year through BTCL resellers after the January 2026 price cut (check the reseller's live price).
- If you have the paperwork, buying it now is cheap and worth it: link previews, emails from `@kafeel.com.bd`, Google's consent screen and shared links all stay on one permanent address. If you don't have a trade licence yet, don't wait on it: launch on a subdomain you already run, then move over later (add the new redirect URI in Google, update *Public site address*).
- Don't put the domain in `SITE_URL` or in the email "From" address until it actually resolves to your store.
