ALTER TABLE customer_invoices
  ADD COLUMN request_id BIGINT UNSIGNED NULL AFTER customer_id,
  ADD KEY idx_customer_invoices_request (request_id),
  ADD CONSTRAINT fk_customer_invoices_request
    FOREIGN KEY (request_id) REFERENCES courier_requests(id)
    ON DELETE SET NULL;
