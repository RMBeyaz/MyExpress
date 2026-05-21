ALTER TABLE courier_requests
  ADD COLUMN IF NOT EXISTS pickup_neighborhood VARCHAR(120) NULL AFTER pickup_district,
  ADD COLUMN IF NOT EXISTS dropoff_neighborhood VARCHAR(120) NULL AFTER dropoff_district,
  ADD COLUMN IF NOT EXISTS pickup_address_source VARCHAR(40) NULL AFTER pickup_lng,
  ADD COLUMN IF NOT EXISTS dropoff_address_source VARCHAR(40) NULL AFTER dropoff_lng,
  ADD COLUMN IF NOT EXISTS distance_type VARCHAR(40) NULL AFTER distance_km,
  ADD COLUMN IF NOT EXISTS route_distance_km DECIMAL(8, 2) NULL AFTER distance_type,
  ADD COLUMN IF NOT EXISTS route_duration_min DECIMAL(8, 2) NULL AFTER route_distance_km,
  ADD COLUMN IF NOT EXISTS route_provider VARCHAR(80) NULL AFTER route_duration_min,
  ADD COLUMN IF NOT EXISTS route_status VARCHAR(80) NULL AFTER route_provider;
