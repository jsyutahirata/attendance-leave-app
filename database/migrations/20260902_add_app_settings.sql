-- 後続フェーズ: アプリ全体の設定を保持するキー・バリュー表。
-- 初期用途は有給承認フローのON/OFF（leave_approval_required）。

CREATE TABLE app_settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
VALUES ('leave_approval_required', '1', NULL, NOW())
ON DUPLICATE KEY UPDATE setting_value = setting_value;
