-- 社員ごとの有給更新月。NULLは未設定（従来どおり1月更新として計算）。
ALTER TABLE employees
  ADD COLUMN leave_renewal_month TINYINT UNSIGNED NULL AFTER hired_on;
