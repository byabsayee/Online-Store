# Online Store ⇄ Byabsayee integration — protocol v1 (as implemented by the store)

This is the contract the **Byabsayee** side must implement. Everything here is already built and tested on the store's side (`src/includes/erp/*`, `src/api/erp/*`, `src/erp_worker.php`, `src/admin/erp.php`). Where the brief left a choice, the decision the store made is stated as **[decision]** so Byabsayee can match it or push back.

The module ships **disabled**. Until an owner pairs the store, no event is queued, no route answers, and the shop behaves exactly as before.

---

## 1. Transport

* Always HTTPS, port 443, dedicated public domain on both sides (no IPs, no `localhost`, no `.local/.internal`, no path prefix on the store's domain). The store refuses to call a host that resolves to a private/loopback/link-local/CGNAT/metadata address and pins the connection to the vetted IP. No redirects are followed.
* JSON, UTF-8. Money is **always a 2-decimal string** (`"1980.00"`), never a float. Timestamps are UTC ISO-8601 with `Z` (`2026-09-30T10:15:00.123Z`).
* Ids are UUIDs. Entities that exist once per connection use **UUIDv5 over the connection id**: `uuid5(connection_id, "tax")` and `uuid5(connection_id, "delivery:inside_dhaka" | "delivery:suburbs" | "delivery:outside_dhaka")`. Same function on both sides (RFC 4122 §4.3, namespace = the connection UUID, name = the string).

### Base URLs

| Direction | Base |
|---|---|
| Store → book | `{book_base_url}/api/v1/integrations/` |
| Book → store | `https://{store}/api/erp/v1/` |
| Domain proof | `GET https://{store}/.well-known/erp-verify?token=…` |

### Authentication (both directions, every call except pairing)

Headers: `Authorization: Bearer <api_key>`, `X-Connection-Id`, `X-Timestamp` (Unix seconds), `X-Nonce` (16–64 chars `[A-Za-z0-9_-]`), `X-Signature`.

```
signature = hex( HMAC_SHA256( secret,
    METHOD + "\n" + request_target + "\n" + timestamp + "\n" + nonce + "\n" + hex(sha256(raw_body)) ) )
```

* `request_target` = path **plus `?query` exactly as sent** (e.g. `/api/erp/v1/events` or `/api/v1/integrations/snapshot/product?limit=200`). Sign the raw body bytes; empty body → sha256 of the empty string.
* One **API key** per connection, used in both directions (each side stores its hash/ciphertext). Two **secrets**: `site_to_book` (store signs with it) and `book_to_site` (book signs with it).
* Timestamp must be within ±300 s. Nonces are single-use (the store keeps them 10 min). Rate limits: 600 req/min per connection, 240/min per IP; over the limit → `429` with `Retry-After`.
* Secrets are stored AES-256-GCM encrypted at rest on the store (key from `ERP_SECRET_KEY` env, falling back to the app secret).

### Errors (store API)

Always JSON: `{"ok":false,"error":{"code":"…","message":"…"}}`. Codes: `https_required 400`, `invalid_json 400`, `invalid_request 400`, `unknown_connection 401`, `bad_api_key 401`, `timestamp_out_of_range 401`, `bad_nonce 401`, `bad_signature 401`, `replayed 401`, `not_found 404`, `method_not_allowed 405`, `invalid_state 409`, `not_verified 409`, `currency_conflict 409`, `payload_too_large 413 (2 MB)`, `batch_too_large 413 (50 events)`, `rate_limited 429`, `connection_paused 503`, `server_error 500`.

---

## 2. Connection lifecycle

States on the store: `disabled → pending → verifying → active ⇄ paused → revoked`.

1. **Owner generates a pairing code** in Byabsayee (Integrations → Website). The store admin enters the book's base URL + pairing code in *Admin → Accounting link*. (Manual alternative: connection id, API key and both secrets typed in; the handshake is then signed.)
2. **Handshake** `POST {book}/api/v1/integrations/connect/handshake` (unsigned when a `pairing_code` is present, `X-Connection-Id: -`):
   ```json
   { "pairing_code": "…", "module_version": "1.0.0", "api_version": "v1",
     "capabilities": ["categories","products","variants","stock","customers","orders","payments","payment_methods","coupons","taxes","delivery_charges","returns","snapshot","changes","reconcile"],
     "site": { "name":"…", "url":"https://shop.example.com", "currency_code":"BDT", "currency_symbol":"৳", "timezone":"Asia/Dhaka",
               "tax":{"enabled":false,"rate":"0.000","inclusive":false,"label":"Tax"},
               "delivery":{"inside_dhaka":"70.00","suburbs":"100.00","outside_dhaka":"130.00","free_weight_kg":"1.00","extra_per_kg":"20.00"} } }
   ```
   Book answers `200`:
   ```json
   { "connection_id":"<uuid>", "api_key":"…", "secrets":{"site_to_book":"…","book_to_site":"…"},
     "api_version":"v1", "module_version":"…", "capabilities":[…], "scopes":["catalog","stock","customers","orders","payments","money"], "book":{…} }
   ```
   A wrong/used code → non-2xx with `{"error":{"code","message"}}`; the store reverts to `disabled` and shows the message. Unknown capabilities are ignored; an `api_version` other than `v1` is refused.
3. Store is now `verifying`.
4. **Domain ownership.** The book calls `GET https://{store}/.well-known/erp-verify?token=<16–128 url-safe chars>` (unsigned; only answers while `verifying`/`active`). Response:
   ```json
   { "ok":true, "connection_id":"…", "token":"…", "proof":"hex(HMAC_SHA256(site_to_book_secret, "erp-verify\n" + token))" }
   ```
   The book must check the proof with the `site_to_book` secret it issued.
5. **Complete.** `POST /api/erp/v1/connect/complete` (signed) `{ "authority":"book"|"site", "scopes":[…], "capabilities":[…], "book":{ "currency_code","currency_symbol","timezone","tax":{enabled,rate,inclusive,label} } }`.
   * `authority=book` → the store **adopts** the book's currency, timezone and tax. Refused with `409 currency_conflict` if the store already has orders in a different currency (no conversion is ever attempted).
   * `authority=site` → the store keeps its own; the book must adopt the `site` block from the handshake / `GET status`.
   * Reply: `{ "ok":true, "status":"active", "adopted":[…], "setup_required":true, "site":{…} }`. Complete before verify → `409 not_verified`.
6. **Initial setup review on the store (D13/D14).** The store pulls `GET snapshot/category|product|customer` from the book and shows the owner matches (products by SKU, categories by name, customers by phone/email), items only at the book and items only in the store. **Nothing merges without a click.** Until the owner presses *Finish setup*, the store sends nothing and answers inbound events with `rejected / temporarily_unavailable / retry:true` — **the book must keep those events queued and retry.** On finish, the owner's choices plus payment methods, coupons, tax and delivery zones are queued to the book.
7. **Pause/resume** (store side button): while `paused` the store answers `503 connection_paused` and keeps queuing outbound events.
8. **Rotate:** either side. Store → book: `POST {book}/connect/rotate {"requested_by":"site"}` and the book returns `{api_key, secrets:{site_to_book,book_to_site}}`. Book → store: `POST /api/erp/v1/connect/rotate {api_key, secrets:{…}}` (≥32 chars each), authenticated with the **old** credentials; both sides swap immediately.
9. **Disconnect:** `POST /api/erp/v1/disconnect` (from the book) or `POST {book}/disconnect {"reason":"store_disconnect"}` (from the store). The store wipes keys and goes `revoked`. **Records and the links table are kept** so the same pair can re-link and resume. If the book answers HTTP 410 / `connection_revoked` to a delivery, the store marks itself revoked.

---

## 3. Endpoints

### Book → store (`/api/erp/v1/…`, all signed with `book_to_site`)
| | |
|---|---|
| `POST events` | batch of ≤ 50 envelopes, body `{ "batch_id":"<uuid>", "events":[…] }` → `{ "ok":true, "batch_id", "results":[{event_id,result,…}] }` |
| `GET changes?cursor=&limit=` | the store's own outbox events after a cursor (recovery; `limit` ≤ 200) → `{events,cursor,has_more}` |
| `GET snapshot/{entity}?cursor=&limit=` | paged current state. entities: `category product customer order payment payment_method coupon return tax delivery_charge`. Item: `{entity_uuid, version, archived, content_hash, fields, stock?}` (`stock:{product:int, variants:{variant_uuid:int}}` on products) |
| `GET status` | `{api_version, module_version, capabilities, connection_status, setup_done, queue:{pending,dead,done,conflict}, last_sync_at, server_time, site:{…}}` |
| `POST connect/complete`, `POST connect/rotate`, `POST disconnect` | lifecycle, above |

### Store → book (`{book}/api/v1/integrations/…`, signed with `site_to_book`)
| | |
|---|---|
| `POST connect/handshake`, `POST connect/rotate`, `POST disconnect` | lifecycle |
| `POST events` | same body/response shape as above, plus header `X-Event-Batch-Id` |
| `GET snapshot/{entity}?cursor=&limit=200` | **the book must serve this** (paged `{items,has_more,cursor}`; item shape as above, `archived:true` items are skipped by the store's matcher). The store uses it for the initial review, reconciliation and history import |

Entities the store reads from the book's snapshot: `category, product, customer, payment_method, coupon, order`. Product items **must include `stock`** (quantity at the book — the ledger of record) or stock reconciliation is skipped for that product.

---

## 4. Event envelope

```json
{ "event_id":"<uuid>", "connection_id":"<uuid>", "origin":"site"|"book",
  "entity":"product", "entity_uuid":"<uuid>", "op":"create|update|archive|restore|cancel|void",
  "version": 7, "base_version": 6, "occurred_at":"2026-09-30T10:15:00.123Z",
  "payload": { "fields": { … }, "field_ts": { "price":"2026-…Z" }, …extras } }
```

* `version` is the **sender's** per-entity counter (+1 per event). `base_version` is the last version of the **other side** the sender had seen.
* `create` carries all fields; `update` carries only fields that changed (with per-field `field_ts`). `archive`/`restore` carry `{is_active:false|true}`; `cancel` on an order carries `{fulfilment_status:"cancelled"}`; `void` on a payment carries `{status:"void"}`. **Nothing is ever hard-deleted**: deleting a linked record at the store becomes an `archive`.
* The store never sends a stock number as a field — stock changes travel as `stock_movement` events only (§6).

### Response per event

`{event_id, result}` with `result`:
* `applied` – done. `duplicate` – this `event_id` was already processed (idempotent; **both sides dedupe by `event_id`**, recorded in the same DB transaction as the change).
* `conflict` – queued for a human at the receiver; `message` explains. Not retried.
* `rejected` + `code` + `message` + `retry:bool`. Retry when `retry:true` (or code in `unknown_entity, dependency_missing, busy, rate_limited, temporarily_unavailable`); otherwise the store dead-letters it (visible with a *Retry* button).
  Codes the store returns: `invalid_envelope, unknown_connection, unsupported_entity, scope_denied, invalid_payload, unknown_entity*, dependency_missing*, insufficient_stock, duplicate_number, invalid_state, connection_paused*, temporarily_unavailable*, apply_failed*` (\* retryable).

### Store delivery behaviour (what the book can rely on)
Events are written to an outbox **in the same DB transaction** as the business change. Sent in batches ≤ 50 (≤ ~900 KB) in outbox order; an entity's later event is never sent while an earlier one for it is waiting (per-entity ordering). Retries back off **30 s → 2 min → 10 min → 1 h → 6 h**, then dead-letter. Parents are emitted before children (category → product → …; product/customer/payment method/coupon → order; order → payment/return). A worker (every 30 s in Docker, or cron each minute) plus an opportunistic flush after each web request delivers them.

---

## 5. Conflict & ownership rules

* **Field ownership.** Shared (either side may edit): product `name, sku, price, compare_price, weight_grams, is_active, category_uuid`; category `name, description, sort_order, is_active, parent_uuid`; customer contact fields; payment-method `name, is_active, sort_order, fund_name`; coupons; tax; delivery. **Store-only** (never overwritten from the book): product slug/descriptions/photos/dimensions/tags/warranty/pre-order, SEO, everything visual. **Book-only:** cost/purchase price, suppliers, accounting data — the store never sees them.
* **Per-field newest-wins (D10).** For each field compare `field_ts` (falls back to `occurred_at`); the newer wins; **exact tie → the book wins** [decision]. Every overwrite of a different local value and every kept-local decision is written to the activity log.
* **Order totals integrity.** Lines must sum to `subtotal`, and `subtotal − discount (+ tax unless tax_inclusive) + delivery_charge == total`, to the paisa. Otherwise the order is **not applied** and goes to the conflict queue (`totals`). A line's `subtotal` must equal `price × quantity`.
* **Locked orders.** An order with a recorded payment or status `completed` is locked. Book changes to its amounts/items/coupon are **not applied** and queue a `locked` conflict; status, shipping details and notes still apply.
* **Customers (D14).** A new customer from the book whose phone (normalised: `+880…/0…`) or email matches a local customer is **not merged** — it queues a `customer_match` conflict; the owner chooses *same person* (link) or *keep both*.
* **Product SKU clash** and **coupon code clash** on an inbound create/update → `conflict`, not an overwrite (except same coupon code on create = link to it).
* **Loop prevention.** Applying an inbound event never emits an outbound one.
* Out-of-order/lost events: updates are field-level and self-contained, so a gap is harmless; reconciliation catches anything else.

---

## 6. Entities (fields)

### category
`name, slug, description, sort_order, is_active, parent_uuid`

### product
`sku, name, slug, short_desc, description, price, compare_price, weight_grams, is_active, category_uuid, variants[]`
* `variants[]` = `{variant_uuid, color, size, sku, price_delta, is_active}` — **store-owned**, informational. Variant ids are real shared ids: order lines, stock movements and returns reference `variant_uuid`. The book may model them as separate stock-keeping lines or ignore them.
* `create` also carries `payload.opening_stock = {product:int, variants:{variant_uuid:int}}` so the book can record an **opening balance**. It is sent once; afterwards stock is movements only.
* From the book: `create` needs `name` + `price` (+ optional `opening_stock.product`, applied as a `purchase` movement). An archived product is hidden, kept, and restorable.

### customer
`name, email, phone, line1, city, state, zip, is_archived`. Guest checkouts create customers too (phone is mandatory at checkout; matched by phone then email) **[decision: every checkout with a phone or email produces a customer; nothing is guest-only]**.

### payment_method
`code, name, kind ("cod"|"manual"), is_active, sort_order` (+ `fund_name` accepted back). Store instructions are store-only. The book maps each to a fund (its choice: pick or auto-create) and may send `fund_name` back as an update. `gateway` is accepted but stored as `manual`.

### coupon
`code, type ("percent"|"fixed"), value, max_discount, min_subtotal, starts_at, expires_at, usage_limit, per_customer_limit, is_active, note`. Usage counts are derived from orders (orders carry `coupon_code`/`coupon_uuid`), so they stay consistent automatically.

### tax (singleton, id `uuid5(conn,"tax")`)
`enabled, rate ("7.500" percent), inclusive, label`. Tax applies to goods after discount, not delivery. Exclusive = added on top; inclusive = part of the price (shown as a line, total unchanged).

### delivery_charge (3 zones, ids `uuid5(conn,"delivery:<zone>")`)
`zone ("inside_dhaka"|"suburbs"|"outside_dhaka"), label, base_fee, free_weight_kg, extra_per_kg`. The weight rule is global on the store; the latest update wins.

### order
```
number, source("store"|"book"), fulfilment_status("placed|processing|shipped|delivered|cancelled"),
customer_uuid, contact{name,phone,email}, shipping{name,phone,line1,city,state,zip}, billing{…}|null,
currency, subtotal, discount, tax, tax_inclusive, delivery_charge, total, coupon_code, coupon_uuid,
delivery_area, payment_method_uuid, notes, placed_at,
items[{ line, product_uuid, variant_uuid, sku, name, variant_label, price, quantity, subtotal, is_preorder }]
```
* Store orders are created **at placement** (stock is deducted at placement on the store). The book must deduct stock for it **from the order event itself** — the store sends **no** separate `stock_movement` for sales, cancellations or returns, to avoid double counting.
* If the book cannot fulfil the stock it answers `rejected / insufficient_stock` (`retry:false`). The store then **flags the order “oversold”**, leaves it open, shows *Needs attention* with “fulfil as back-order or cancel”, and does not retry.
* Book-created orders (`source:"book"`): the store checks totals, resolves every product/variant/customer/payment-method uuid (`dependency_missing`, retryable, if unknown), rejects with `insufficient_stock` (atomically, nothing applied) if local stock is short, rejects `duplicate_number` if the order number exists, then deducts stock locally without echoing.
* `cancel` (or a status change to `cancelled`): puts remaining units back in stock (units already restocked by a return are not added twice) and **voids recorded payments**. Reviving a cancelled order takes stock out again (refused if short).
* Payment status is **not** a field: derive it from payments (`unpaid/partial/paid`).

### payment
`order_uuid, method_uuid, amount, paid_at, reference, note, status("recorded"|"void")`. Payments are **never edited**: correct with `void` + a new payment. Overpaying beyond the order total is refused. A payment is added via `create` (→ `applied`), voided via op `void`.

### return
`order_uuid, reason, refund_amount, refund_method_uuid, returned_at, items[{line, product_uuid, variant_uuid, quantity, restock}]`. Final once recorded. Restocked units go back on the shelf locally (no echo). Returning more than was ordered, or returning on a cancelled order → `conflict`.

### stock_movement
`product_uuid, variant_uuid|null, delta (signed int), reason, note`; reasons `sale, sale_cancel, return, manual_adjustment, purchase, reconciliation_adjustment`.
* **Store → book:** only `manual_adjustment` (a hand edit in the product form), as a **delta**, never an absolute overwrite.
* **Book → store:** `purchase`, `manual_adjustment`, `reconciliation_adjustment`. A standalone `sale` that would take stock below zero → `rejected insufficient_stock`. A movement is immutable.
* The store keeps a local ledger (`stock_movements`) of every change with `origin` (`store`/`book`).

---

## 7. Reconciliation & recovery (store-driven, hourly by the worker or on demand)

The store pages the book's snapshot of `category, product, customer, payment_method, coupon, order` and reports, per entity: present here but missing at the book, present at the book but missing here, and newer at the book. For products it compares stock: if nothing order/payment/return/stock-related is still queued, each drift is **corrected** with a `reconciliation_adjustment` movement (`origin=book`, never echoed) — the book's ledger is the record. Otherwise it is reported only. The owner can *Send to the book* (re-queue a full `create`) or *Bring in from the book* (apply the snapshot fields) per item. Result is kept and shown on the Accounting-link page.

**Requirement for Byabsayee:** serve `snapshot/*` with stable `entity_uuid`s and a product `stock` block, accept duplicate `create`s as updates, and be idempotent on `event_id`.

---

## 8. Historical import (optional, D7)

Store admin → *History import*. A **dry run** writes nothing (only the plan row) and reports counts and warnings; the real run is executed in chunks by the worker (pause/resume; shown live), and **exactly one batch can be rolled back**.
* **Store → book:** orders (with their payments and returns) and customers are emitted with `payload.import = {batch, stock:false}`. **The book must not change its stock and must not fire live webhooks for events carrying `payload.import`.** Rollback sends an op `void` per order with `payload.import.rollback:true`.
* **Book → store:** orders/customers are created locally with `import_batch` set; never touches stock unless the owner picked *opening balance*, in which case a reconciliation afterwards aligns stock to the book. Orders with bad totals, duplicate numbers or unknown products are skipped and counted; matching customers go to the review queue. Rollback deletes exactly that batch's imported orders/customers.

---

## 9. Security summary

HTTPS-only with SSRF guard; signed + keyed + replay-protected requests; constant-time comparisons; per-IP and per-connection rate limits; 2 MB body cap; PII redacted from the activity log (90-day summaries, 30-day detail); secrets encrypted at rest and never logged; all admin screens are owner-only and CSRF-protected; every admin action is audit-logged.

## 10. Operating it

* Docker: the image runs the worker under supervisord (every 30 s). Other hosts: `* * * * * php /path/to/erp_worker.php`.
* `ERP_SECRET_KEY` (env, optional): key material for encrypting the stored secrets. Set it in production; changing it afterwards requires re-pairing.
* Admin → *Accounting link* has Overview, Setup review, Sync queue (retry/discard stuck events), Conflicts, Activity, History import. The sidebar shows a count when something needs a person.

## 11. Open points Byabsayee should confirm

1. Variants: model them or ignore them (the store keeps sending them; nothing breaks either way).
2. Whether `gateway`-kind payment methods should exist (store only creates `cod`/`manual`).
3. Webhook suppression for `payload.import` events (required by §8).
4. Whether the book wants `base_version` enforcement (the store sends it but never depends on it).

---

## 12. Invoices (additive in v1 — capability `invoices`)

The store can show its customers the **invoice the Book generated** instead of its own. Nothing here changes the other sections; a Book or store without it keeps working exactly as before (the store falls back to its own invoice per order).

* **Capability:** the book lists `"invoices"` in `capabilities`.
* **Invoice number in the event answer.** When the book applies an `order` event it MAY add `"invoice": {"invoice_no","invoice_id","book_id","status","public_url"}` next to `result:"applied"`. The store keeps `invoice_no` as the order's *Invoice ID* and builds a staff-only "Open in Byabsayee" link from `/books/{book_id}/invoices/{invoice_id}`. Unknown keys are ignored by older stores.
* **Orders created in the book** (`source:"book"`): their `number` already IS the invoice number; the store stores it as the Invoice ID.
* **Lookup:** `GET {book}/api/v1/integrations/invoice/{order_uuid}` (signed with `site_to_book`) → `{ "ok":true, "invoice":{…same object…} }`, `404 not_found` when the order has no invoice. The store's worker uses it to fill in orders that never got the number (history imports, lost answers), at most once an hour per order.
* **PDF:** `GET {book}/api/v1/integrations/invoice/{order_uuid}/pdf` (signed) → `application/pdf`. The store proxies it to the customer **after** its own viewer check (guest grant / account owner), so customers never see the book's address and the book never sees customers. Rendered fresh each time; any failure/timeout (15 s) → the store shows its own invoice.
* **Owner choice (store only):** Admin → Accounting link → *Customer invoices*: **Byabsayee invoice** or **Store invoice**. Default: Byabsayee while linked, Store otherwise. It changes only what the website shows (order page, invoice PDF, status emails, Invoice ID in order lists and tracking). It is never sent to the book.
* **Tracking:** guests may enter either the order number or the Invoice ID with their email.
