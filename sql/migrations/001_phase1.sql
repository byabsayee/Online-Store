-- ============================================================
-- Migration 002: Phase 1 (shipping zones, product weight, product
-- YouTube link, order delivery area)
-- Run this against an EXISTING Kafeel database. Fresh installs get
-- these columns automatically via the updated sql/schema.sql.
-- ============================================================

ALTER TABLE products
  ADD COLUMN weight_grams INT NOT NULL DEFAULT 500 AFTER stock,
  ADD COLUMN youtube_url VARCHAR(255) DEFAULT NULL AFTER image_main;

ALTER TABLE orders
  ADD COLUMN delivery_area ENUM('inside_dhaka','outside_dhaka') NOT NULL DEFAULT 'inside_dhaka' AFTER payment_method;
