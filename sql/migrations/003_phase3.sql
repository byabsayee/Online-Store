-- Phase 3 additions: brute-force login throttling for both the storefront
-- and admin login forms.
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(20) NOT NULL,        -- 'customer' | 'admin'
  identifier VARCHAR(160) NOT NULL,   -- email or username attempted, lowercased
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bucket_identifier (bucket, identifier, attempted_at),
  KEY idx_bucket_ip (bucket, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
