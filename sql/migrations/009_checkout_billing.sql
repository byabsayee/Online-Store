-- ============================================================
-- Migration 009: separate shipping & billing details at checkout
--   - orders can now record a billing address distinct from the shipping
--     (delivery) address, with a flag for the common "same as shipping" case
--   - billing_* columns always hold the address actually used (mirrored from
--     shipping when billing_same_as_shipping = 1), so anything reading an
--     order never has to branch on the flag just to know where to bill
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE orders ADD COLUMN IF NOT EXISTS billing_same_as_shipping TINYINT(1) NOT NULL DEFAULT 1 AFTER shipping_zip;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS billing_name VARCHAR(120) DEFAULT NULL AFTER billing_same_as_shipping;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS billing_phone VARCHAR(30) DEFAULT NULL AFTER billing_name;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS billing_line1 VARCHAR(200) DEFAULT NULL AFTER billing_phone;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS billing_city VARCHAR(100) DEFAULT NULL AFTER billing_line1;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS billing_state VARCHAR(100) DEFAULT NULL AFTER billing_city;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS billing_zip VARCHAR(20) DEFAULT NULL AFTER billing_state;

-- Existing orders predate billing addresses entirely — they're on the "same as shipping" path already
-- (the default above covers new rows; this backfills the shipping copy onto pre-existing ones so the
-- billing_* columns are never blank for an order that's really just billing-equals-shipping).
UPDATE orders SET billing_name = shipping_name, billing_phone = shipping_phone, billing_line1 = shipping_line1,
  billing_city = shipping_city, billing_state = shipping_state, billing_zip = shipping_zip
  WHERE billing_same_as_shipping = 1 AND billing_name IS NULL;
