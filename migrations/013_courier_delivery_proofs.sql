ALTER TABLE courier_requests
  ADD COLUMN IF NOT EXISTS courier_access_token_hash CHAR(64) NULL AFTER assigned_courier_id,
  ADD COLUMN IF NOT EXISTS courier_access_token_expires_at DATETIME NULL AFTER courier_access_token_hash,
  ADD UNIQUE KEY IF NOT EXISTS uq_courier_access_token_hash (courier_access_token_hash);

CREATE TABLE IF NOT EXISTS courier_delivery_proofs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,
  courier_id BIGINT UNSIGNED NOT NULL,
  proof_type ENUM('pickup', 'delivery') NOT NULL,
  file_name VARCHAR(190) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  delivered_to VARCHAR(160) NULL,
  note VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_courier_proof_request (request_id),
  KEY idx_courier_proof_courier (courier_id),
  KEY idx_courier_proof_type (proof_type),
  CONSTRAINT fk_courier_proof_request
    FOREIGN KEY (request_id) REFERENCES courier_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_courier_proof_courier
    FOREIGN KEY (courier_id) REFERENCES couriers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
