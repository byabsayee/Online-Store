-- ============================================================
-- Migration 017: stock visibility + take orders at zero stock
--   - products.show_stock: 1 = customers see how many units are left ("Only 3 left"),
--     0 = customers only ever see "In stock" / "Out of stock", never a number
--   - products.allow_backorder: 1 = shoppers can still order this product when its
--     stock is 0 (no "Pre-order" label; the stock level is left untouched)
--   - order_items.is_backorder: marks lines that were taken while out of stock
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE products ADD COLUMN IF NOT EXISTS show_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER stock;

ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_backorder TINYINT(1) NOT NULL DEFAULT 0 AFTER show_stock;

ALTER TABLE order_items ADD COLUMN IF NOT EXISTS is_backorder TINYINT(1) NOT NULL DEFAULT 0 AFTER is_preorder
