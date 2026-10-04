# Install, update and operate

## 1. Install with Docker Compose

```bash
git clone https://github.com/<your-account>/online-store.git && cd online-store
cp .env.example .env            # edit DB_PASS, DB_ROOT_PASS, WEB_PORT, SITE_URL
docker compose up -d --build    # first build takes a few minutes
```

1. Open `http://<server-ip>:8080/admin` (or your `WEB_PORT`).
2. Sign in: **admin / ChangeMe123!** → choose a new password when asked.
3. The **setup wizard** opens: store name & contact → region & currency → colours → delivery zones & payment methods → pages.
4. Finish the checklist on the last screen (logo, outgoing email, order alerts, products).

`docker compose up` also starts phpMyAdmin on `PMA_PORT` (default 8081). Remove that service from
`docker-compose.yml`, or block the port, on a public server.

## 2. Install with Portainer

*Stacks → Add stack → Repository*: point at your copy of this repository, compose path `docker-compose.yml`, and add
the variables from `.env.example` under *Environment variables*. Delete `docker-compose.override.yml` from the
repository (or choose the “Repository” method so only `docker-compose.yml` is used). If you publish an image with
the included GitHub workflow, set `STORE_IMAGE=ghcr.io/<owner>/online-store:latest`.

## 3. HTTPS and your own domain

Put a reverse proxy in front of `WEB_PORT` (Nginx Proxy Manager, Caddy, Cloudflare Tunnel…) that terminates HTTPS and
forwards to `http://<server>:<WEB_PORT>`, passing `X-Forwarded-Proto` and `X-Forwarded-For`. Then set the public
address in Admin → Store settings → Details & email → *Public site address* (or `SITE_URL` in `.env`).

## 4. Email

Admin → Store settings → Details & email → Outgoing email (SMTP). Without it, order confirmations and password
resets may not be delivered. Use *Order alerts* to be told about new orders.

## 5. Updating

```bash
git pull            # or copy the new files over (keep .env and uploads/)
docker compose up -d --build
```
Database migrations (`sql/migrations/`) run automatically on the first page load. Always back up first:
`docker compose exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > backup.sql`
and copy the `uploads/` folder.

## 6. Moving settings between stores

Admin → Store settings → Backup & presets: *Download preset* exports store details, colours, fonts choice, delivery and
tax numbers, footer links, page wording and manual payment methods (never passwords, keys, customers or orders).
*Import preset* applies it to another store. Logos and font files are not included — upload them again.

## 7. Link to Byabsayee accounting (optional)

Admin → Store settings → Accounting link. Protocol details: [INTEGRATION.md](INTEGRATION.md).
