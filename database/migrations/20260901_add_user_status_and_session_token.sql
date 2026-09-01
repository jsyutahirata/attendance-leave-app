-- v0.4対応: アカウント状態をステータス値へ拡張し、全端末セッション失効用トークンを追加する。
-- users.is_active(boolean) を廃止し、将来の休職中等を見据えた status(ENUM) へ移行する。
-- session_token は「失効エポック」。ログイン時にセッションへ複製し、無効化・パスワード変更・
-- 強制ログアウト時にローテーションすることで、当該ユーザーの全端末セッションを一括失効させる。

ALTER TABLE users
  ADD COLUMN status ENUM('active','disabled','suspended') NOT NULL DEFAULT 'active' AFTER role,
  ADD COLUMN session_token CHAR(32) NOT NULL DEFAULT '' AFTER status;

-- 既存データの移行: is_active=1 を active、0 を disabled とする。
UPDATE users SET status = CASE WHEN is_active = 1 THEN 'active' ELSE 'disabled' END;

-- 既存ユーザーへ初期の失効エポックを付与する（32桁の16進）。
UPDATE users SET session_token = MD5(CONCAT(id, '-', RAND(), '-', UUID())) WHERE session_token = '';

ALTER TABLE users
  DROP COLUMN is_active;
