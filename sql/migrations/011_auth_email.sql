-- ============================================================
-- Migration 011: Google sign-in, guest-order merging, password reset,
--                promotional emails and an email delivery log
--   - users: google_id (Google account link), has_password (Google-only
--     accounts have no password until they set one), promo_emails (opt-in
--     to promotional email, off by default)
--   - orders: claimed_at (when a guest order was merged into an account),
--     plus an index on customer_email for the merge lookup
--   - password_resets: single-use, expiring reset links (only a hash of the token is stored)
--   - email_log: what was sent / failed, shown in Admin -> Settings & email
--   - promo_campaigns / promo_recipients: admin promotional emails, sent in small batches
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) DEFAULT NULL AFTER email_verify_sent_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS has_password TINYINT(1) NOT NULL DEFAULT 1 AFTER google_id;
ALTER TABLE users ADD COLUMN IF NOT EXISTS promo_emails TINYINT(1) NOT NULL DEFAULT 0 AFTER has_password;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_google (google_id);

ALTER TABLE orders ADD COLUMN IF NOT EXISTS claimed_at DATETIME DEFAULT NULL AFTER updated_at;
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_customer_email (customer_email);

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
