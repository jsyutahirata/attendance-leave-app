-- v0.4対応: TOTP（二要素認証）を追加する（§5, §4.2）。
-- 管理者が任意でON/OFFでき、有効時はQRでシークレットを登録し、バックアップコードを発行する。
-- totp_secret は確認完了後にのみ保存し、totp_enabled=1 とする。バックアップコードはハッシュ保存。

ALTER TABLE users
  ADD COLUMN totp_secret VARCHAR(255) NULL AFTER session_token,
  ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;

CREATE TABLE totp_backup_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_backup_user (user_id),
  CONSTRAINT fk_backup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
