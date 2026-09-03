-- 社員ごとの退勤忘れ通知ON/OFF・通知時刻。

CREATE TABLE IF NOT EXISTS push_preferences (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  reminder_time TIME NOT NULL DEFAULT '18:00:00',
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_push_preference_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
