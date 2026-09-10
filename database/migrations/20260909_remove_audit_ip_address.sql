-- 操作履歴から接続元IPを完全に削除する。
-- 既存のIP値も列と同時に削除され、復元できないため事前バックアップを推奨する。
ALTER TABLE audit_logs DROP COLUMN ip_address;
