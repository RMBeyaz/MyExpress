ALTER TABLE courier_requests
  ADD COLUMN distance_km DECIMAL(8, 2) NULL AFTER price;

CREATE TABLE IF NOT EXISTS request_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NULL,
  admin_user VARCHAR(120) NOT NULL,
  action VARCHAR(80) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_request_id (request_id),
  KEY idx_admin_created (admin_user, created_at),
  CONSTRAINT fk_audit_logs_request
    FOREIGN KEY (request_id) REFERENCES courier_requests(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
