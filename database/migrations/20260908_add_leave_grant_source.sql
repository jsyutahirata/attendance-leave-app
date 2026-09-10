-- 入社月による自動有給付与のための拡張。
-- source: 付与の出所（manual=管理者手動 / auto_hire=入社月自動付与）。
-- created_by を NULL 許可にし、自動付与（操作者なし・cron等）を記録できるようにする。
ALTER TABLE leave_grants
  MODIFY created_by BIGINT UNSIGNED NULL,
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER reason;

-- 同一社員・同一付与年度の自動付与が二重登録されないよう補助インデックスを付与する。
CREATE INDEX idx_grant_auto ON leave_grants (employee_id, grant_year, source);
