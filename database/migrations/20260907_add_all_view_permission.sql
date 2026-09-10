-- 管理者権限とは独立した、全社員データの読み取り専用権限を追加する。
ALTER TABLE view_grants
  MODIFY target_type ENUM('employee','group','all') NOT NULL;
