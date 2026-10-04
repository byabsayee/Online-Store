-- ============================================================
-- Migration 004: Phase 4
--   - product tags (search), per-option variant data (color/size
--     images, size dimensions + weight)
--   - customer email on orders (shown on invoices)
--   - announcement-bar settings
-- Fully idempotent: safe to run more than once. The app also applies
-- this file automatically on first request after a deploy (see
-- src/includes/migrate.php), so running it by hand is optional.
-- Fresh installs get all of this from sql/schema.sql.
-- ============================================================

ALTER TABLE products ADD COLUMN IF NOT EXISTS tags VARCHAR(600) DEFAULT NULL AFTER short_desc;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_email VARCHAR(160) DEFAULT NULL AFTER shipping_phone;

-- Backfill: registered customers' orders get their account email.
-- (updated_at is pinned so this doesn't bump every order's "last updated".)
UPDATE orders o JOIN users u ON u.id = o.user_id
   SET o.customer_email = u.email, o.updated_at = o.updated_at
 WHERE o.customer_email IS NULL;

-- One row per selectable color / size of a product. Colors carry a swatch
-- and preview image; sizes carry dimensions + weight (NULL = use the
-- product's own value) and may also carry a preview image. Stock and price
-- adjustments still live per combination in product_variants.
CREATE TABLE IF NOT EXISTS product_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  kind ENUM('color','size') NOT NULL,
  name VARCHAR(60) NOT NULL,
  swatch VARCHAR(7) DEFAULT NULL,
  image VARCHAR(255) DEFAULT NULL,
  weight_grams INT DEFAULT NULL,
  height_mm INT DEFAULT NULL,
  width_mm INT DEFAULT NULL,
  depth_mm INT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_option (product_id, kind, name),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill options from variants that already exist.
INSERT IGNORE INTO product_options (product_id, kind, name, sort_order)
SELECT product_id, 'color', color, MIN(sort_order) FROM product_variants
 WHERE color IS NOT NULL AND color <> '' GROUP BY product_id, color;

INSERT IGNORE INTO product_options (product_id, kind, name, sort_order)
SELECT product_id, 'size', size, MIN(sort_order) FROM product_variants
 WHERE size IS NOT NULL AND size <> '' GROUP BY product_id, size;

-- Announcement bar (top of every storefront page). Off until you switch it on.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('topbar_enabled', '0'),
  ('topbar_text', ''),
  ('topbar_link', '');
