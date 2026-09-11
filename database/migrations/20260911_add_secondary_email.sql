-- サブ（副）メールアドレス。主メールに加えて、これでもログイン・パスワード再設定ができる。
-- 一意制約（NULLは複数可）。主メールとの重複はアプリ側で検証する。
ALTER TABLE users
  ADD COLUMN secondary_email VARCHAR(255) NULL,
  ADD UNIQUE KEY uniq_users_secondary_email (secondary_email);
