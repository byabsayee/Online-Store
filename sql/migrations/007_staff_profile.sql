-- ============================================================
-- Migration 007: staff profile additions
--   - the NID column becomes a general ID number, with a type
--     (NID or birth certificate)
--   - date of birth, mandatory Facebook profile link
--   - one profile picture per staff member, kept in the database
--     (resized, metadata stripped) and served only to owners and to
--     the person themselves
-- Idempotent. Applied automatically on the first request after deploy.
-- ============================================================

ALTER TABLE admins CHANGE COLUMN IF EXISTS nid_number id_number VARCHAR(20) DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS id_type VARCHAR(20) NOT NULL DEFAULT 'nid';

ALTER TABLE admins ADD COLUMN IF NOT EXISTS date_of_birth DATE DEFAULT NULL;

ALTER TABLE admins ADD COLUMN IF NOT EXISTS facebook_url VARCHAR(255) DEFAULT NULL;

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
