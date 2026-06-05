ALTER TABLE courier_delivery_proofs
  ADD COLUMN IF NOT EXISTS location_lat DECIMAL(10,7) NULL AFTER note,
  ADD COLUMN IF NOT EXISTS location_lng DECIMAL(10,7) NULL AFTER location_lat,
  ADD COLUMN IF NOT EXISTS location_accuracy_m DECIMAL(8,2) NULL AFTER location_lng,
  ADD COLUMN IF NOT EXISTS location_captured_at DATETIME NULL AFTER location_accuracy_m,
  ADD COLUMN IF NOT EXISTS location_status VARCHAR(40) NULL AFTER location_captured_at,
  ADD KEY IF NOT EXISTS idx_courier_proof_location (location_lat, location_lng);
