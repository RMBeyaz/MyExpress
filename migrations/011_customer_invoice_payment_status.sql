ALTER TABLE customer_invoices
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(32) NOT NULL DEFAULT 'unpaid' AFTER status,
  ADD COLUMN IF NOT EXISTS original_file_name VARCHAR(180) NULL AFTER file_path;
