-- 部分移行用: アプリ打刻を元Googleフォームへ転送する対象者フラグ。
-- form_sync_enabled=1 の社員だけ、アプリのネイティブ打刻が formResponse へ送信される。
-- form_sync_name は送信先フォームの「氏名」選択肢と完全一致させる文字列（スペース有無含む）。
ALTER TABLE employees
  ADD COLUMN form_sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN form_sync_name VARCHAR(100) NULL;
