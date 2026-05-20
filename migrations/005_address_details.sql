ALTER TABLE courier_requests
  ADD COLUMN IF NOT EXISTS pickup_city VARCHAR(80) NULL AFTER pickup_street,
  ADD COLUMN IF NOT EXISTS pickup_district VARCHAR(80) NULL AFTER pickup_city,
  ADD COLUMN IF NOT EXISTS pickup_road VARCHAR(160) NULL AFTER pickup_district,
  ADD COLUMN IF NOT EXISTS pickup_building_no VARCHAR(80) NULL AFTER pickup_road,
  ADD COLUMN IF NOT EXISTS dropoff_city VARCHAR(80) NULL AFTER dropoff_street,
  ADD COLUMN IF NOT EXISTS dropoff_district VARCHAR(80) NULL AFTER dropoff_city,
  ADD COLUMN IF NOT EXISTS dropoff_road VARCHAR(160) NULL AFTER dropoff_district,
  ADD COLUMN IF NOT EXISTS dropoff_building_no VARCHAR(80) NULL AFTER dropoff_road;
