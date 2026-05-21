ALTER TABLE customers
  ADD COLUMN tckn VARCHAR(11) NULL AFTER phone;

ALTER TABLE customer_addresses
  ADD COLUMN contact_email VARCHAR(160) NULL AFTER contact_phone;

ALTER TABLE customer_addresses
  ADD COLUMN contact_tckn VARCHAR(11) NULL AFTER contact_email;
