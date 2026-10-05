-- ============================================================
-- Migration 016: customization options, per-option prices
--   - product_options.price_delta: optional extra price for choosing a color or a size
--   - product_customizations: optional "customization" choices an admin offers on a
--     product (e.g. "Name engraving", "Gold stitching"), each with its own optional
--     extra price and preview photo
--   - cart_items.customization_id: which customization a cart line has chosen
-- (Maintenance mode needs no tables — it uses the settings table.)
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE product_options ADD COLUMN IF NOT EXISTS price_delta DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER depth_mm;

CREATE TABLE IF NOT EXISTS product_customizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  price_delta DECIMAL(10,2) NOT NULL DEFAULT 0,
  image VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_customization (product_id, name),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cart_items ADD COLUMN IF NOT EXISTS customization_id INT DEFAULT NULL AFTER variant_id;
