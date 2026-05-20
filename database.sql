CREATE TABLE IF NOT EXISTS courier_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tracking_code VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  assigned_courier_id BIGINT UNSIGNED NULL,
  pickup VARCHAR(255) NOT NULL,
  pickup_lat DECIMAL(10, 7) NULL,
  pickup_lng DECIMAL(10, 7) NULL,
  pickup_street TEXT NOT NULL,
  pickup_city VARCHAR(80) NULL,
  pickup_district VARCHAR(80) NULL,
  pickup_road VARCHAR(160) NULL,
  pickup_building_no VARCHAR(80) NULL,
  dropoff VARCHAR(255) NOT NULL,
  dropoff_lat DECIMAL(10, 7) NULL,
  dropoff_lng DECIMAL(10, 7) NULL,
  dropoff_street TEXT NOT NULL,
  dropoff_city VARCHAR(80) NULL,
  dropoff_district VARCHAR(80) NULL,
  dropoff_road VARCHAR(160) NULL,
  dropoff_building_no VARCHAR(80) NULL,
  service VARCHAR(40) NOT NULL,
  service_label VARCHAR(80) NOT NULL,
  package_type VARCHAR(40) NOT NULL,
  package_label VARCHAR(80) NOT NULL,
  delivery_time VARCHAR(80) NULL,
  note TEXT NULL,
  price VARCHAR(40) NOT NULL,
  distance_km DECIMAL(8, 2) NULL,
  sender_name VARCHAR(120) NOT NULL,
  sender_phone VARCHAR(40) NOT NULL,
  sender_email VARCHAR(160) NULL,
  sender_tckn VARCHAR(11) NOT NULL,
  recipient_name VARCHAR(120) NOT NULL,
  recipient_phone VARCHAR(40) NOT NULL,
  recipient_email VARCHAR(160) NULL,
  recipient_tckn VARCHAR(11) NOT NULL,
  service_agreement_accepted TINYINT(1) NOT NULL DEFAULT 0,
  kvkk_accepted TINYINT(1) NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tracking_code (tracking_code),
  KEY idx_status_created (status, created_at),
  KEY idx_assigned_courier (assigned_courier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS request_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_request_id (request_id),
  CONSTRAINT fk_status_logs_request
    FOREIGN KEY (request_id) REFERENCES courier_requests(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS pricing_settings (
  setting_key VARCHAR(80) NOT NULL,
  label VARCHAR(120) NOT NULL,
  setting_group VARCHAR(40) NOT NULL,
  numeric_value DECIMAL(10, 3) NOT NULL,
  unit VARCHAR(24) NULL,
  description VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  KEY idx_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pricing_settings (setting_key, label, setting_group, numeric_value, unit, description) VALUES
('service.normal.base', 'Motorlu başlangıç', 'service', 240, 'TL', 'Motorlu kurye sabit başlangıç ücreti'),
('service.normal.km', 'Motorlu km', 'service', 14, 'TL/km', 'Motorlu kurye kilometre ücreti'),
('service.normal.multiplier', 'Motorlu çarpan', 'service', 1, 'x', 'Motorlu kurye genel çarpanı'),
('service.express.base', 'Express başlangıç', 'service', 320, 'TL', 'Express kurye sabit başlangıç ücreti'),
('service.express.km', 'Express km', 'service', 17, 'TL/km', 'Express kurye kilometre ücreti'),
('service.express.multiplier', 'Express çarpan', 'service', 1.25, 'x', 'Express kurye genel çarpanı'),
('service.vip.base', 'VIP başlangıç', 'service', 420, 'TL', 'VIP kurye sabit başlangıç ücreti'),
('service.vip.km', 'VIP km', 'service', 20, 'TL/km', 'VIP kurye kilometre ücreti'),
('service.vip.multiplier', 'VIP çarpan', 'service', 1.55, 'x', 'VIP kurye genel çarpanı'),
('service.aracli.base', 'Arabalı başlangıç', 'service', 650, 'TL', 'Arabalı kurye sabit başlangıç ücreti'),
('service.aracli.km', 'Arabalı km', 'service', 28, 'TL/km', 'Arabalı kurye kilometre ücreti'),
('service.aracli.multiplier', 'Arabalı çarpan', 'service', 1.75, 'x', 'Arabalı kurye genel çarpanı'),
('service.eticaret.base', 'E-Ticaret başlangıç', 'service', 260, 'TL', 'E-ticaret teslimatı sabit başlangıç ücreti'),
('service.eticaret.km', 'E-Ticaret km', 'service', 13, 'TL/km', 'E-ticaret teslimatı kilometre ücreti'),
('service.eticaret.multiplier', 'E-Ticaret çarpan', 'service', 0.95, 'x', 'E-ticaret teslimatı genel çarpanı'),
('package.evrak.fee', 'Evrak ek ücreti', 'package', 0, 'TL', 'Evrak paket tipi ek ücreti'),
('package.kucuk.fee', 'Küçük paket ek ücreti', 'package', 60, 'TL', 'Küçük paket tipi ek ücreti'),
('package.orta.fee', 'Orta paket ek ücreti', 'package', 120, 'TL', 'Orta paket tipi ek ücreti'),
('package.buyuk.fee', 'Büyük paket ek ücreti', 'package', 220, 'TL', 'Büyük paket tipi ek ücreti'),
('package.motorDisi.fee', 'Motor çantasına sığmayan ek ücreti', 'package', 430, 'TL', 'Motor çantasına sığmayan paket tipi ek ücreti'),
('rule.route_multiplier', 'Rota katsayısı', 'rule', 1.28, 'x', 'Kuş uçuşu mesafeyi yol mesafesine yaklaştıran katsayı'),
('rule.min_same_area_km', 'Aynı bölge minimum mesafe', 'rule', 4, 'km', 'Aynı alım/teslim bölgesinde minimum ücretlenen km'),
('rule.min_default_km', 'Minimum mesafe', 'rule', 7, 'km', 'Farklı bölgelerde minimum ücretlenen km'),
('rule.bridge_fee', 'Köprü/geçiş ücreti', 'rule', 90, 'TL', 'Avrupa/Anadolu geçişlerinde eklenen ücret'),
('rule.round_to', 'Yuvarlama', 'rule', 10, 'TL', 'Ücretin yuvarlanacağı basamak'),
('rule.home_min_factor', 'Anasayfa alt aralık', 'rule', 0.92, 'x', 'Anasayfa tahmini fiyat alt çarpanı'),
('rule.home_max_factor', 'Anasayfa üst aralık', 'rule', 1.08, 'x', 'Anasayfa tahmini fiyat üst çarpanı')
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  setting_group = VALUES(setting_group),
  unit = VALUES(unit),
  description = VALUES(description);

CREATE TABLE IF NOT EXISTS panel_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  role ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff',
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_by VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_panel_username (username),
  KEY idx_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
