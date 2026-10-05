# Online Store

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)

A self-hosted online store you can set up in minutes and run entirely from an admin panel — no code or `.env`
editing needed after the first start. Plain PHP + MariaDB, vanilla HTML/CSS/JS, no framework; ships as one
Docker Compose stack. English interface; **Bangladesh is the default preset** (৳ BDT, Dhaka delivery zones, cash on
delivery, bKash / Rocket / Nagad / Upay / bank transfer) and an **International** option lets you pick any currency,
time zone and delivery zones.

> Screenshots: _add your own here_ (`docs/screenshots/`).

## Features

- **Storefront** — categories & subcategories, products with galleries, colour/size variants, pre-orders, warranty,
  search, cart, wishlist, reviews, coupons, order tracking, PDF invoices, guest checkout, Google sign-in.
- **Setup wizard** — store name, region/currency, colours, delivery zones, payment methods, starter pages. Re-runnable.
- **Everything editable in admin** — store details, logo/favicon/share image, theme colours, **fonts** (upload your
  own or use Google Fonts), home-page text, **About / FAQ / Terms / Privacy / Refund / Shipping pages with a rich
  editor** (placeholders fill in your store details), **footer links** (any title, any link), **partners page**,
  announcement bar, seasonal effects, cookie notice, **ads** (Google AdSense, six placements), currency symbol /
  code / position / decimals, time zone, order-number prefix, delivery time, invoice text and tax number.
- **Payments** — cash on delivery plus any number of manual methods (bKash, Rocket, Nagad, Upay, bank transfer…). Each has its own name, logo, receiving number and instructions; customers enter their sender
  number and transaction ID, you verify and mark the order paid.
- **Order alerts** — an email to up to three addresses for every new order; customers can cancel their own order until it ships.
- **Email templates** — every email the store sends (sign-up, password reset, order emails, owner alerts, contact form) has
  editable wording with placeholders, a preview and a test-send button.
- **Addresses & contacts** — one page for the main and extra addresses (with Google Maps links), emails and phone numbers.
- **Products** — each product can carry one external link with its own title (video, size guide, manual…).
- **Admin** — grouped navigation, a settings hub with tabs, staff roles, activity log, preset export/import.
- **Optional accounting link** — pairs with [Byabsayee](https://byabsayee.com) (off until paired; see `docs/INTEGRATION.md`).

## Install (one command)

Requires Docker with the Compose plugin.

```bash
cp .env.example .env        # optional — every value has a default (admin / admin); change passwords before going public
docker compose up -d --build
```

Open `http://<server>:8080/admin` and sign in with **admin / admin**. You are asked to choose a new password
immediately, then the setup wizard starts. Full guide, reverse-proxy and update instructions: [`docs/INSTALL.md`](docs/INSTALL.md).

## Starter admin

Sign in with admin / admin the first time; you must choose a new password immediately.

## Updating

Pull or copy the new version, then `docker compose up -d --build`. Database upgrades run automatically on the next
page load. Back up the `db_data` volume and the `uploads/` folder first.

## Project layout

```
src/            PHP application (public pages in src/, admin in src/admin/, shared code in src/includes/)
sql/            schema.sql (fresh install) + migrations/ (upgrades, applied automatically)
docker/         Dockerfile, nginx, PHP-FPM and supervisor config
uploads/        photos, logos and fonts you upload (mounted into the container)
docs/           INSTALL.md, INTEGRATION.md (accounting protocol)
```

## Security notes before going live

Change the starter admin password (the first sign-in forces it), set `APP_DEBUG=0`, put the site behind HTTPS,
firewall or remove the phpMyAdmin port, and keep `.env` out of version control. The starter page wording is a
generic sample and **not legal advice** — review it for your country.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

GNU Affero General Public License v3.0 (see [LICENSE](LICENSE)). If you run a modified copy for the public, the
licence asks you to offer your visitors its source code — the footer “Source code” link does that; point it at your
repository in Admin → Store settings → Footer. Third-party components: [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
