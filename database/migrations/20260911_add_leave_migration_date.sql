-- 有給の移行基準日。設定すると、その日より前の入社月自動付与は行わない
-- （過去ぶんは実残数のCSV取込に任せ、二重付与を防ぐ）。NULLなら従来どおり入社日からさかのぼって自動付与。
ALTER TABLE employees
  ADD COLUMN leave_migration_date DATE NULL;
