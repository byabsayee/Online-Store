-- ============================================================
-- Migration 012: ERP / accounting integration module (Byabsayee protocol v1)
--   Ships DISABLED: sync_connection starts as status 'disabled' and nothing
--   below changes how the store behaves until an owner links it to a book.
--
--   Module tables (prefix sync_): connection, links, outbox, inbox, nonces,
--   log, conflicts, match review, import batches, rate limit counters,
--   and a customer directory that also covers guest checkouts.
--
--   Store features the integration needs, useful on their own:
--   payment_methods, order_payments, order_returns(+items), stock_movements,
--   orders.tax / source / payment_method_id / attention / customer_sync_id.
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

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

ALTER TABLE orders ADD COLUMN IF NOT EXISTS source ENUM('store','book') NOT NULL DEFAULT 'store' AFTER claimed_at;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS tax DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_method_id INT DEFAULT NULL AFTER payment_method;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS attention VARCHAR(30) DEFAULT NULL AFTER source;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS attention_note VARCHAR(255) DEFAULT NULL AFTER attention;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_sync_id INT DEFAULT NULL AFTER attention_note;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS external_ref VARCHAR(60) DEFAULT NULL AFTER customer_sync_id;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS tax_inclusive TINYINT(1) NOT NULL DEFAULT 0 AFTER tax;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS import_batch INT DEFAULT NULL AFTER external_ref;
ALTER TABLE products ADD COLUMN IF NOT EXISTS archived_at DATETIME DEFAULT NULL AFTER is_featured;
