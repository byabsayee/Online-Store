-- 014: Online Store template (Phase A).
-- Manual payment details, product external link, editable footer links, partners page, editable site pages.
-- Every statement is idempotent. Existing stores keep working exactly as before.

ALTER TABLE payment_methods ADD COLUMN IF NOT EXISTS account_details VARCHAR(200) DEFAULT NULL;
ALTER TABLE payment_methods ADD COLUMN IF NOT EXISTS logo_url VARCHAR(255) DEFAULT NULL;
ALTER TABLE payment_methods ADD COLUMN IF NOT EXISTS ask_txn TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_sender VARCHAR(40) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_txn VARCHAR(60) DEFAULT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS link_url VARCHAR(500) DEFAULT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS link_title VARCHAR(80) DEFAULT NULL;
UPDATE products SET link_url = youtube_url, link_title = 'Watch video' WHERE youtube_url IS NOT NULL AND youtube_url <> '' AND link_url IS NULL;

CREATE TABLE IF NOT EXISTS footer_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_title VARCHAR(60) NOT NULL,
  label VARCHAR(80) NOT NULL,
  url VARCHAR(500) NOT NULL,
  new_tab TINYINT(1) NOT NULL DEFAULT 0,
  group_order INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_footer_group (group_order, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO footer_links (group_title, label, url, group_order, sort_order)
SELECT * FROM (
  SELECT 'Support' AS g, 'Contact us' AS l, '/contact' AS u, 1 AS go, 1 AS so UNION ALL
  SELECT 'Support', 'About us', '/about', 1, 2 UNION ALL
  SELECT 'Support', 'Track an order', '/orders', 1, 3 UNION ALL
  SELECT 'Support', 'My account', '/account', 1, 4 UNION ALL
  SELECT 'Legal', 'Terms of Service', '/terms', 2, 1 UNION ALL
  SELECT 'Legal', 'Privacy Policy', '/privacy-policy', 2, 2 UNION ALL
  SELECT 'Legal', 'Refund & Return Policy', '/refund-policy', 2, 3 UNION ALL
  SELECT 'Legal', 'Shipping & Delivery Policy', '/shipping-policy', 2, 4
) seed WHERE NOT EXISTS (SELECT 1 FROM footer_links);

CREATE TABLE IF NOT EXISTS partners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT DEFAULT NULL,
  logo_url VARCHAR(255) DEFAULT NULL,
  link_url VARCHAR(500) DEFAULT NULL,
  extra_links TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_pages (
  slug VARCHAR(40) PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  body_html MEDIUMTEXT DEFAULT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE settings SET setting_value = 'store' WHERE setting_key = 'invoice_source' AND setting_value <> '' AND setting_value NOT IN ('store', 'book');

INSERT IGNORE INTO settings (setting_key, setting_value)
SELECT 'setup_done', '1' FROM DUAL WHERE EXISTS (SELECT 1 FROM products) OR EXISTS (SELECT 1 FROM settings WHERE setting_key = 'store_name');

-- Existing stores keep the order-number prefix their orders already use (the letters before the first dash).
INSERT IGNORE INTO settings (setting_key, setting_value)
SELECT 'order_prefix', SUBSTRING_INDEX(order_number, '-', 1) FROM orders WHERE order_number REGEXP '^[A-Za-z0-9]{2,6}-[0-9]{6}-' ORDER BY id LIMIT 1;
