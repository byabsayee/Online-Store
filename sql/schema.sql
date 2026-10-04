-- ============================================================
-- Store database schema
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- customers
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verify_sent_at DATETIME DEFAULT NULL,
  google_id VARCHAR(255) DEFAULT NULL,
  has_password TINYINT(1) NOT NULL DEFAULT 1,
  promo_emails TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_google (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  label VARCHAR(50) NOT NULL DEFAULT 'Home',
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  line1 VARCHAR(200) NOT NULL,
  city VARCHAR(100) NOT NULL,
  state VARCHAR(100) DEFAULT NULL,
  zip VARCHAR(20) DEFAULT NULL,
  country VARCHAR(100) NOT NULL DEFAULT 'Bangladesh',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- admins
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','staff') NOT NULL DEFAULT 'staff',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  phone VARCHAR(40) DEFAULT NULL,
  email VARCHAR(160) DEFAULT NULL,
  address VARCHAR(500) DEFAULT NULL,
  blood_group VARCHAR(4) DEFAULT NULL,
  gender VARCHAR(10) DEFAULT NULL,
  id_number VARCHAR(20) DEFAULT NULL,
  id_type VARCHAR(20) NOT NULL DEFAULT 'nid',
  date_of_birth DATE DEFAULT NULL,
  facebook_url VARCHAR(255) DEFAULT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_email (email),
  UNIQUE KEY uniq_admin_nid (id_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One optional private document per staff member. Kept in the database (not in the public
-- uploads folder) and only served through admin/staff_document.php to owners and to that person.
CREATE TABLE IF NOT EXISTS admin_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size INT NOT NULL,
  data LONGBLOB NOT NULL,
  uploaded_by INT DEFAULT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_doc_admin (admin_id),
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One profile picture per staff member (resized, metadata stripped). Private: served only to owners and to that person.
CREATE TABLE IF NOT EXISTS admin_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  mime VARCHAR(50) NOT NULL,
  size INT NOT NULL,
  data MEDIUMBLOB NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_photo_admin (admin_id),
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------
-- catalog
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT DEFAULT NULL,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  description TEXT,
  image VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cat_parent (parent_id),
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT DEFAULT NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  sku VARCHAR(60) DEFAULT NULL,
  short_desc VARCHAR(255) DEFAULT NULL,
  tags VARCHAR(600) DEFAULT NULL,
  description TEXT,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  compare_price DECIMAL(10,2) DEFAULT NULL,
  stock INT NOT NULL DEFAULT 0,
  is_preorder TINYINT(1) NOT NULL DEFAULT 0,
  preorder_note VARCHAR(255) DEFAULT NULL,
  preorder_available_date DATE DEFAULT NULL,
  weight_grams INT NOT NULL DEFAULT 500,
  height_mm INT DEFAULT NULL,
  width_mm INT DEFAULT NULL,
  depth_mm INT DEFAULT NULL,
  color VARCHAR(60) DEFAULT NULL,
  warranty_days INT DEFAULT NULL,
  image_main VARCHAR(255) DEFAULT NULL,
  youtube_url VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  archived_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FULLTEXT KEY ft_search (name, short_desc, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional per-product variants (e.g. color / size combinations). A product
-- with no rows here is sold as-is; price_delta is added to the base price.
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

-- Selectable colors and sizes of a product (one row each). Colors carry a
-- swatch + preview image; sizes carry dimensions/weight overrides (NULL =
-- inherit the product's own value) and may carry a preview image too.
-- Stock and price adjustments live per combination in product_variants.
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

-- ---------------------------------------------------------------
-- cart / favorites (support both logged-in users and guest sessions)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cart_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  session_id VARCHAR(64) DEFAULT NULL,
  product_id INT NOT NULL,
  variant_id INT DEFAULT NULL,
  quantity INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS favorites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  product_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fav (user_id, product_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- coupons (percentage or fixed-amount discount codes)
-- ---------------------------------------------------------------
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

-- ---------------------------------------------------------------
-- orders
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(30) NOT NULL UNIQUE,
  user_id INT DEFAULT NULL,
  status ENUM('pending','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  payment_method ENUM('cod','bank_transfer') NOT NULL DEFAULT 'cod',
  payment_method_id INT DEFAULT NULL,
  delivery_area ENUM('inside_dhaka','suburbs','outside_dhaka') NOT NULL DEFAULT 'inside_dhaka',
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_inclusive TINYINT(1) NOT NULL DEFAULT 0,
  coupon_id INT DEFAULT NULL,
  coupon_code VARCHAR(40) DEFAULT NULL,
  shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  shipping_name VARCHAR(120) NOT NULL,
  shipping_phone VARCHAR(30) NOT NULL,
  customer_email VARCHAR(160) DEFAULT NULL,
  shipping_line1 VARCHAR(200) NOT NULL,
  shipping_city VARCHAR(100) NOT NULL,
  shipping_state VARCHAR(100) DEFAULT NULL,
  shipping_zip VARCHAR(20) DEFAULT NULL,
  billing_same_as_shipping TINYINT(1) NOT NULL DEFAULT 1,
  billing_name VARCHAR(120) DEFAULT NULL,
  billing_phone VARCHAR(30) DEFAULT NULL,
  billing_line1 VARCHAR(200) DEFAULT NULL,
  billing_city VARCHAR(100) DEFAULT NULL,
  billing_state VARCHAR(100) DEFAULT NULL,
  billing_zip VARCHAR(20) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  claimed_at DATETIME DEFAULT NULL,
  source ENUM('store','book') NOT NULL DEFAULT 'store',
  attention VARCHAR(30) DEFAULT NULL,
  attention_note VARCHAR(255) DEFAULT NULL,
  customer_sync_id INT DEFAULT NULL,
  external_ref VARCHAR(60) DEFAULT NULL,
  import_batch INT DEFAULT NULL,
  KEY idx_orders_coupon (coupon_id),
  KEY idx_orders_customer_email (customer_email),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT DEFAULT NULL,
  variant_id INT DEFAULT NULL,
  variant_label VARCHAR(150) DEFAULT NULL,
  product_name VARCHAR(180) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  quantity INT NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  warranty_days INT DEFAULT NULL,
  is_preorder TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Timestamped log of every status an order has passed through, so the
-- storefront and admin can show a real "22 Feb 2026, 3:00 AM: Shipped"
-- style timeline instead of just the current status. from_status and
-- changed_by(_name) say what it changed from and which admin did it; those
-- are only ever shown in the admin portal.
CREATE TABLE IF NOT EXISTS order_status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  from_status VARCHAR(20) DEFAULT NULL,
  status ENUM('pending','processing','shipped','completed','cancelled') NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changed_by INT DEFAULT NULL,
  changed_by_name VARCHAR(120) DEFAULT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Simple key/value store for admin-editable site settings (theme colors,
-- seasonal effects, etc.) that don't need their own dedicated columns.
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- product reviews (customers who received the product can review it)
-- ---------------------------------------------------------------
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

-- ---------------------------------------------------------------
-- admin activity log: one row per admin action (append-only, no UI to edit or delete)
-- ---------------------------------------------------------------
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

-- ---------------------------------------------------------------
-- login throttling (brute-force guard for storefront + admin login)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(20) NOT NULL,        -- 'customer' | 'admin'
  identifier VARCHAR(160) NOT NULL,   -- email or username attempted, lowercased
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bucket_identifier (bucket, identifier, attempted_at),
  KEY idx_bucket_ip (bucket, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Added in migration 011 (password reset, email log, promotional emails)
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL DEFAULT '',
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status ENUM('sent','failed') NOT NULL,
  error VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promo_campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject VARCHAR(200) NOT NULL,
  heading VARCHAR(200) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  button_label VARCHAR(60) DEFAULT NULL,
  button_url VARCHAR(500) DEFAULT NULL,
  status ENUM('sending','done','cancelled') NOT NULL DEFAULT 'sending',
  total INT NOT NULL DEFAULT 0,
  created_by_name VARCHAR(120) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promo_recipients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  email VARCHAR(160) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  error VARCHAR(255) DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  KEY idx_promo_rcpt (campaign_id, status),
  FOREIGN KEY (campaign_id) REFERENCES promo_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Added in migration 012 (ERP integration module, payments, returns, tax)

CREATE TABLE IF NOT EXISTS sync_connection (
  id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  status ENUM('disabled','pending','verifying','active','paused','revoked') NOT NULL DEFAULT 'disabled',
  connection_id CHAR(36) DEFAULT NULL,
  book_base_url VARCHAR(255) DEFAULT NULL,
  site_domain VARCHAR(190) DEFAULT NULL,
  api_key_enc TEXT DEFAULT NULL,
  site_api_key_hash CHAR(64) DEFAULT NULL,
  secret_site_to_book_enc TEXT DEFAULT NULL,
  secret_book_to_site_enc TEXT DEFAULT NULL,
  scopes VARCHAR(600) DEFAULT NULL,
  authority ENUM('book','site') DEFAULT NULL,
  api_version VARCHAR(10) DEFAULT NULL,
  peer_module_version VARCHAR(40) DEFAULT NULL,
  peer_capabilities TEXT DEFAULT NULL,
  book_info MEDIUMTEXT DEFAULT NULL,
  verify_token VARCHAR(80) DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  last_sync_at DATETIME DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO sync_connection (id, status) VALUES (1, 'disabled');

CREATE TABLE IF NOT EXISTS sync_links (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  connection_id CHAR(36) NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_uuid CHAR(36) NOT NULL,
  local_id INT DEFAULT NULL,
  local_version INT NOT NULL DEFAULT 0,
  remote_version INT NOT NULL DEFAULT 0,
  content_hash CHAR(40) DEFAULT NULL,
  last_payload MEDIUMTEXT DEFAULT NULL,
  field_ts TEXT DEFAULT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  last_synced_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_link_uuid (connection_id, entity, entity_uuid),
  UNIQUE KEY uq_link_local (connection_id, entity, local_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_outbox (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  connection_id CHAR(36) NOT NULL,
  event_id CHAR(36) NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_uuid CHAR(36) NOT NULL,
  op VARCHAR(12) NOT NULL,
  version INT NOT NULL DEFAULT 1,
  envelope MEDIUMTEXT NOT NULL,
  status ENUM('pending','sending','done','conflict','dead') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_outbox_event (event_id),
  KEY idx_outbox_due (status, next_attempt_at),
  KEY idx_outbox_entity (entity, entity_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_inbox (
  event_id CHAR(36) NOT NULL PRIMARY KEY,
  connection_id CHAR(36) NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_uuid CHAR(36) NOT NULL,
  op VARCHAR(12) NOT NULL,
  result VARCHAR(20) NOT NULL,
  detail VARCHAR(255) DEFAULT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inbox_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_nonces (
  nonce CHAR(64) NOT NULL PRIMARY KEY,
  expires_at DATETIME NOT NULL,
  KEY idx_nonce_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_rate (
  bucket VARCHAR(90) NOT NULL,
  win INT NOT NULL,
  cnt INT NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, win)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('in','out','system') NOT NULL,
  kind VARCHAR(30) NOT NULL,
  event_id CHAR(36) DEFAULT NULL,
  http_status SMALLINT DEFAULT NULL,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  summary VARCHAR(500) NOT NULL,
  detail MEDIUMTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_slog_created (created_at),
  KEY idx_slog_ok (ok, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_conflicts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('field','locked','totals','customer_match','oversold','other') NOT NULL DEFAULT 'other',
  entity VARCHAR(30) NOT NULL,
  entity_uuid CHAR(36) DEFAULT NULL,
  local_id INT DEFAULT NULL,
  event_id CHAR(36) DEFAULT NULL,
  local_data MEDIUMTEXT DEFAULT NULL,
  remote_data MEDIUMTEXT DEFAULT NULL,
  note VARCHAR(500) DEFAULT NULL,
  status ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution VARCHAR(40) DEFAULT NULL,
  resolved_by VARCHAR(120) DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conf_status (status, created_at),
  KEY idx_conf_entity (entity, entity_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_match_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(30) NOT NULL DEFAULT 'product',
  kind ENUM('sku_match','remote_only','local_only') NOT NULL,
  remote_uuid CHAR(36) DEFAULT NULL,
  remote_data MEDIUMTEXT DEFAULT NULL,
  local_id INT DEFAULT NULL,
  status ENUM('pending','linked','created_here','pushed','ignored') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_match_status (entity, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_import_batches (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('store_to_book','book_to_store') NOT NULL,
  entities VARCHAR(200) NOT NULL,
  date_from DATE DEFAULT NULL,
  date_to DATE DEFAULT NULL,
  stock_mode ENUM('none','opening_balance') NOT NULL DEFAULT 'none',
  status ENUM('dry_run','ready','running','paused','done','rolled_back','failed') NOT NULL DEFAULT 'dry_run',
  plan MEDIUMTEXT DEFAULT NULL,
  progress MEDIUMTEXT DEFAULT NULL,
  cursor_json TEXT DEFAULT NULL,
  created_by VARCHAR(120) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  phone_norm VARCHAR(20) DEFAULT NULL,
  line1 VARCHAR(200) DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  state VARCHAR(100) DEFAULT NULL,
  zip VARCHAR(20) DEFAULT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  origin ENUM('store','book') NOT NULL DEFAULT 'store',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sc_phone (phone_norm),
  KEY idx_sc_email (email),
  KEY idx_sc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_methods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(80) NOT NULL,
  kind ENUM('cod','manual','gateway') NOT NULL DEFAULT 'manual',
  instructions TEXT DEFAULT NULL,
  fund_name VARCHAR(120) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pm_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO payment_methods (code, name, kind, instructions, sort_order) VALUES ('cod', 'Cash on delivery', 'cod', NULL, 0);

CREATE TABLE IF NOT EXISTS order_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  method_id INT DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reference VARCHAR(120) DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  status ENUM('recorded','void') NOT NULL DEFAULT 'recorded',
  recorded_by VARCHAR(120) DEFAULT NULL,
  origin ENUM('store','book') NOT NULL DEFAULT 'store',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_order (order_id, status),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (method_id) REFERENCES payment_methods(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_returns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  refund_method_id INT DEFAULT NULL,
  returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(120) DEFAULT NULL,
  origin ENUM('store','book') NOT NULL DEFAULT 'store',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ret_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (refund_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_return_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_id INT NOT NULL,
  order_item_id INT DEFAULT NULL,
  product_id INT DEFAULT NULL,
  variant_id INT DEFAULT NULL,
  quantity INT NOT NULL,
  restock TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (return_id) REFERENCES order_returns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  variant_id INT DEFAULT NULL,
  delta INT NOT NULL,
  reason VARCHAR(30) NOT NULL,
  ref_type VARCHAR(20) DEFAULT NULL,
  ref_id INT DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  origin ENUM('store','book') NOT NULL DEFAULT 'store',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sm_product (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Seed data
-- ============================================================

-- Default admin login -> username: admin  password: ChangeMe123! (CHANGE THIS before going live)
INSERT INTO admins (username, name, password_hash, role) VALUES
('admin', 'admin', '$2b$10$t65uPHFANoBh6cQGF5pB9Ow1R6T2bR2JaMqJsWE1TNgjilZdgl5Wq', 'owner');

INSERT INTO categories (name, slug, description, sort_order) VALUES
('EDC Gear', 'edc-gear', 'Everyday carry tools, pocket knives, keychains and organizers.', 1),
('Bags & Carry', 'bags-carry', 'Slings, backpacks and pouches built for daily use.', 2),
('Leather Goods', 'leather-goods', 'Wallets, cardholders and full-grain leather accessories.', 3),
('Customized', 'customized', 'Engraved, monogrammed and made-to-order pieces.', 4);

INSERT INTO products (category_id, name, slug, sku, short_desc, description, price, compare_price, stock, is_active, is_featured) VALUES
(1, 'Titanium Pocket Pry Bar', 'titanium-pocket-pry-bar', 'EDC-001', 'Compact titanium multi-tool for keychain carry.', 'A compact titanium pry bar with bottle opener, flathead and box-cutter notch. Fits any keychain and weighs under 15g.', 950.00, 1100.00, 60, 1, 1),
(1, 'Brass Keychain Organizer', 'brass-keychain-organizer', 'EDC-002', 'Keeps keys quiet and organized.', 'A solid brass keychain clip that keeps your keys organized and silent in your pocket. Ages beautifully with a natural patina.', 720.00, NULL, 45, 1, 0),
(1, 'Mini EDC Flashlight', 'mini-edc-flashlight', 'EDC-003', '400 lumen rechargeable pocket light.', 'A rechargeable 400-lumen pocket flashlight with pocket clip, three brightness modes and USB-C charging.', 1250.00, 1450.00, 40, 1, 1),
(2, 'Waxed Canvas Sling Bag', 'waxed-canvas-sling-bag', 'BAG-001', 'Compact crossbody sling with leather trim.', 'A weatherproof waxed-canvas sling bag with leather trim, padded strap and organized interior pockets for daily carry.', 2450.00, 2800.00, 40, 1, 1),
(2, 'Leather Tech Pouch', 'leather-tech-pouch', 'BAG-002', 'Full-grain leather pouch for EDC & cables.', 'A full-grain leather pouch for carrying EDC gear, cables and chargers. Ages with a rich patina over time.', 1350.00, 1550.00, 35, 1, 1),
(2, 'Canvas Travel Pouch Set', 'canvas-travel-pouch-set', 'BAG-003', 'Three-piece packing pouch set.', 'A set of three durable canvas packing pouches in graduated sizes, with brass zippers and leather pulls.', 980.00, NULL, 55, 1, 0),
(3, 'Full-Grain Bifold Wallet', 'full-grain-bifold-wallet', 'LTH-001', 'Hand-stitched leather bifold wallet.', 'A hand-stitched full-grain leather bifold wallet with six card slots, a bill compartment and a slim profile that ages beautifully.', 1650.00, 1900.00, 70, 1, 1),
(3, 'Slim Leather Cardholder', 'slim-leather-cardholder', 'LTH-002', 'Minimalist front-pocket cardholder.', 'A minimalist front-pocket cardholder in vegetable-tanned leather, holding up to six cards with a central pull-tab.', 850.00, NULL, 90, 1, 0),
(3, 'Leather Belt, Classic Brown', 'leather-belt-classic-brown', 'LTH-003', 'Full-grain leather belt with brass buckle.', 'A full-grain leather belt in classic brown with a solid brass buckle, stitched edges and a break-in that only gets better.', 1200.00, 1400.00, 50, 1, 0),
(4, 'Personalized Engraved Keychain', 'personalized-engraved-keychain', 'CUS-001', 'Custom name or initials, laser engraved.', 'A solid brass or leather keychain laser-engraved with your choice of name, initials or a short message. Ships in 3-5 days.', 550.00, NULL, 200, 1, 1);

INSERT INTO settings (setting_key, setting_value) VALUES
('theme_primary', '#a97c34'),
('theme_secondary', '#5f7d5b'),
('seasonal_enabled', '0'),
('seasonal_effect', 'snow'),
('topbar_enabled', '0'),
('topbar_text', ''),
('topbar_link', ''),
('schema_version', '12');
