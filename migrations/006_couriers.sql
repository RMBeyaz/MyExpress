CREATE TABLE IF NOT EXISTS couriers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  vehicle_type VARCHAR(80) NULL,
  plate VARCHAR(40) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_active_name (is_active, full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE courier_requests
  ADD COLUMN IF NOT EXISTS assigned_courier_id BIGINT UNSIGNED NULL AFTER status,
  ADD KEY IF NOT EXISTS idx_assigned_courier (assigned_courier_id);
