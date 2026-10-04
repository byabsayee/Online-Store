-- ============================================================
-- Migration 010: pre-orders
--   - a product can be marked as available for pre-order, with an optional
--     note ("Ships in 2-3 weeks") and expected availability date — customers
--     can then order it even while stock is 0
--   - order_items snapshots whether each line was a pre-order at the time it
--     was placed, so admins can still see that later even if the product's
--     pre-order flag has since been turned off (e.g. once real stock arrives)
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE products ADD COLUMN IF NOT EXISTS is_preorder TINYINT(1) NOT NULL DEFAULT 0 AFTER stock;
ALTER TABLE products ADD COLUMN IF NOT EXISTS preorder_note VARCHAR(255) DEFAULT NULL AFTER is_preorder;
ALTER TABLE products ADD COLUMN IF NOT EXISTS preorder_available_date DATE DEFAULT NULL AFTER preorder_note;

ALTER TABLE order_items ADD COLUMN IF NOT EXISTS is_preorder TINYINT(1) NOT NULL DEFAULT 0 AFTER warranty_days;
