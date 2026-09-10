-- Googleフォーム打刻連携の冪等キー（フォーム回答IDの重複記録防止）。
CREATE TABLE form_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id VARCHAR(64) NOT NULL UNIQUE,
  attendance_event_id BIGINT UNSIGNED NULL,
  received_at DATETIME NOT NULL,
  CONSTRAINT fk_form_webhook_event FOREIGN KEY (attendance_event_id) REFERENCES attendance_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
