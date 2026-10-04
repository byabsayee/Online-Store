-- ============================================================
-- Migration 006: staff management
--   - staff profile details on the admins table (number, email, address,
--     blood group, gender, NID number), account status, and a flag that
--     forces a new password at first sign-in
--   - one optional private document per staff member, kept in the database
--     (NOT in the public uploads folder) and only served to owners and to
--     the staff member themselves
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE admins ADD COLUMN IF NOT EXISTS phone VARCHAR(40) DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS email VARCHAR(160) DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS address VARCHAR(500) DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS blood_group VARCHAR(4) DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS gender VARCHAR(10) DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS nid_number VARCHAR(20) DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS status ENUM('active','disabled') NOT NULL DEFAULT 'active';

ALTER TABLE admins ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE admins ADD UNIQUE KEY IF NOT EXISTS uniq_admin_email (email);

ALTER TABLE admins ADD UNIQUE KEY IF NOT EXISTS uniq_admin_nid (nid_number);

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
