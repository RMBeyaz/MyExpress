ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(128) NULL AFTER email_verification_expires_at,
  ADD COLUMN IF NOT EXISTS password_reset_expires_at DATETIME NULL AFTER password_reset_token,
  ADD KEY IF NOT EXISTS idx_customer_password_reset_token (password_reset_token);
