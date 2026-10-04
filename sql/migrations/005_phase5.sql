-- ============================================================
-- Migration 005: Phase 5
--   - admin activity log (who did what, and when)
--   - order status history now records the previous status and
--     which admin made the change
--   - coupons (percent or fixed amount) + discount stored on orders
--   - product reviews
-- Fully idempotent: safe to run more than once. The app also applies
-- this file automatically on the first request after a deploy (see
-- src/includes/migrate.php), so running it by hand is optional.
-- Fresh installs get all of this from sql/schema.sql.
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT DEFAULT NULL,
  admin_name VARCHAR(120) NOT NULL,
  admin_username VARCHAR(60) DEFAULT NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(40) DEFAULT NULL,
  target_id INT DEFAULT NULL,
  summary VARCHAR(255) NOT NULL,
  details TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_admin (admin_id, created_at),
  KEY idx_action (action, created_at),
  KEY idx_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE order_status_history ADD COLUMN IF NOT EXISTS from_status VARCHAR(20) DEFAULT NULL AFTER order_id;

ALTER TABLE order_status_history ADD COLUMN IF NOT EXISTS changed_by INT DEFAULT NULL;

ALTER TABLE order_status_history ADD COLUMN IF NOT EXISTS changed_by_name VARCHAR(120) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS coupons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_discount DECIMAL(10,2) DEFAULT NULL,
  min_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  starts_at DATETIME DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL,
  usage_limit INT DEFAULT NULL,
  per_customer_limit INT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER subtotal;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_id INT DEFAULT NULL AFTER discount;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(40) DEFAULT NULL AFTER coupon_id;

ALTER TABLE orders ADD KEY IF NOT EXISTS idx_orders_coupon (coupon_id);

ALTER TABLE orders ADD CONSTRAINT fk_orders_coupon FOREIGN KEY IF NOT EXISTS (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS product_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  author_name VARCHAR(120) NOT NULL,
  rating TINYINT NOT NULL,
  title VARCHAR(120) DEFAULT NULL,
  body TEXT NOT NULL,
  status ENUM('published','hidden') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_review (user_id, product_id),
  KEY idx_product_status (product_id, status, created_at),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

