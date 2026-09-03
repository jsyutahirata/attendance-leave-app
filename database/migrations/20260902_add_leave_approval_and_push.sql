-- 有給承認フローとWeb Push購読・送信履歴。

ALTER TABLE leave_entries
  ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER cancelled_at,
  ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
  ADD COLUMN rejection_reason TEXT NULL AFTER reviewed_at,
  ADD CONSTRAINT fk_leave_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id);

CREATE TABLE push_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(50) NOT NULL,
  work_date DATE NOT NULL,
  sent_at DATETIME NOT NULL,
  UNIQUE KEY uniq_push_notice (user_id, notification_type, work_date),
  CONSTRAINT fk_push_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
VALUES ('leave_approval_required', '1', NULL, NOW())
ON DUPLICATE KEY UPDATE setting_value = setting_value;
