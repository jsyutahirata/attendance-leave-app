ALTER TABLE leave_grants ADD COLUMN grant_year SMALLINT UNSIGNED NULL AFTER granted_on;
UPDATE leave_grants SET grant_year = YEAR(granted_on) WHERE grant_year IS NULL;
ALTER TABLE leave_grants MODIFY grant_year SMALLINT UNSIGNED NOT NULL;

ALTER TABLE leave_adjustments ADD COLUMN grant_year SMALLINT UNSIGNED NULL AFTER employee_id;
UPDATE leave_adjustments SET grant_year = YEAR(created_at) WHERE grant_year IS NULL;
ALTER TABLE leave_adjustments MODIFY grant_year SMALLINT UNSIGNED NOT NULL;

