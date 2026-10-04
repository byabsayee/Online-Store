-- ============================================================
-- Migration 008: phase 6
--   - categories can now nest one level (or more) deep via parent_id,
--     e.g. "Men" and "Women" under "Bags & Carry"
--   - products get an optional warranty_days field; order_items snapshots
--     it at the time of purchase (like price/name) so an invoice always
--     shows what the customer was promised, even if the product changes later
--   - Signal Messenger joins WhatsApp as a social/contact link (settings table,
--     no schema change needed there)
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE categories ADD COLUMN IF NOT EXISTS parent_id INT DEFAULT NULL;

ALTER TABLE categories ADD KEY IF NOT EXISTS idx_cat_parent (parent_id);

ALTER TABLE products ADD COLUMN IF NOT EXISTS warranty_days INT DEFAULT NULL;

ALTER TABLE order_items ADD COLUMN IF NOT EXISTS warranty_days INT DEFAULT NULL;
