-- ============================================================
-- Migration 002: Phase 2
--   - product dimensions/color, product variants
--   - order status history timeline
--   - suburbs shipping zone
--   - email verification
--   - site settings (theme colors, seasonal effects)
-- Run this against an EXISTING Kafeel database. Fresh installs get
-- all of this automatically via the updated sql/schema.sql.
-- ============================================================

ALTER TABLE products
  ADD COLUMN height_mm INT DEFAULT NULL AFTER weight_grams,
  ADD COLUMN width_mm INT DEFAULT NULL AFTER height_mm,
  ADD COLUMN depth_mm INT DEFAULT NULL AFTER width_mm,
  ADD COLUMN color VARCHAR(60) DEFAULT NULL AFTER depth_mm;

CREATE TABLE IF NOT EXISTS product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  color VARCHAR(60) DEFAULT NULL,
  size VARCHAR(60) DEFAULT NULL,
  sku VARCHAR(60) DEFAULT NULL,
  price_delta DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cart_items
  ADD COLUMN variant_id INT DEFAULT NULL AFTER product_id,
  ADD CONSTRAINT fk_cart_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE;

ALTER TABLE order_items
  ADD COLUMN variant_id INT DEFAULT NULL AFTER product_id,
  ADD COLUMN variant_label VARCHAR(150) DEFAULT NULL AFTER product_name,
  ADD CONSTRAINT fk_order_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL;

-- Suburbs joins inside/outside Dhaka as a third shipping zone.
ALTER TABLE orders
  MODIFY COLUMN delivery_area ENUM('inside_dhaka','suburbs','outside_dhaka') NOT NULL DEFAULT 'inside_dhaka';

CREATE TABLE IF NOT EXISTS order_status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  status ENUM('pending','processing','shipped','completed','cancelled') NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill a "pending" history entry (at order creation time) for existing orders.
INSERT INTO order_status_history (order_id, status, changed_at)
SELECT id, 'pending', created_at FROM orders
WHERE id NOT IN (SELECT DISTINCT order_id FROM order_status_history);

-- Also backfill the *current* status for orders already past "pending", so
-- existing orders show at least one timeline entry matching their real state.
INSERT INTO order_status_history (order_id, status, changed_at)
SELECT id, status, updated_at FROM orders
WHERE status != 'pending';

ALTER TABLE users
  ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN email_verify_token VARCHAR(64) DEFAULT NULL AFTER email_verified,
  ADD COLUMN email_verify_sent_at DATETIME DEFAULT NULL AFTER email_verify_token;

-- Existing users are grandfathered in as verified so nobody gets locked out.
UPDATE users SET email_verified = 1 WHERE email_verified = 0;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('theme_primary', '#a97c34'),
  ('theme_secondary', '#5f7d5b'),
  ('seasonal_enabled', '0'),
  ('seasonal_effect', 'snow');
