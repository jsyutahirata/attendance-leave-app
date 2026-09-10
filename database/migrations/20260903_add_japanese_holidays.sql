CREATE TABLE japanese_holidays (
  holiday_date DATE PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  source_url VARCHAR(255) NOT NULL,
  synced_at DATETIME NOT NULL,
  INDEX idx_japanese_holidays_synced (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
