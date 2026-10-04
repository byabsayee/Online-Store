-- 013: which invoice customers see (Kafeel's own, or the connected Byabsayee book's) — per-order book invoice reference.
-- Idempotent: safe to re-run.
ALTER TABLE orders ADD COLUMN IF NOT EXISTS book_invoice_no VARCHAR(60) DEFAULT NULL AFTER import_batch;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS book_invoice_path VARCHAR(80) DEFAULT NULL AFTER book_invoice_no;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS book_invoice_checked_at DATETIME DEFAULT NULL AFTER book_invoice_path;
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_book_invoice (book_invoice_no);
