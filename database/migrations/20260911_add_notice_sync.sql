-- 部分移行: アプリの勤怠連絡を元「勤怠連絡フォーム」へ転送する対象者フラグ。
-- 出退勤の form_sync_enabled とは別に、勤怠連絡だけをONにできる。氏名は form_sync_name を共用。
ALTER TABLE employees
  ADD COLUMN notice_sync_enabled TINYINT(1) NOT NULL DEFAULT 0;
