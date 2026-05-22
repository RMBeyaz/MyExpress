ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS email_verification_code VARCHAR(12) NULL AFTER email_verified_at,
  ADD COLUMN IF NOT EXISTS email_verification_token VARCHAR(128) NULL AFTER email_verification_code,
  ADD COLUMN IF NOT EXISTS email_verification_expires_at DATETIME NULL AFTER email_verification_token;

UPDATE customers
SET email_verified_at = COALESCE(email_verified_at, NOW())
WHERE is_active = 1;
