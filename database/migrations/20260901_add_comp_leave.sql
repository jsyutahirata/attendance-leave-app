-- v0.4対応: 代休（休日出勤に応じて発生する休暇）を有給とは別枠で管理する（§7.8）。
-- 休日出勤の勤怠連絡が登録されると comp_leave_grants を自動発生させ、取得予定・取得・取消は
-- comp_leave_entries で管理する。有効期限は発生日が属する事業年度（4月始まり）の年度末（翌3/31）。

CREATE TABLE comp_leave_grants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  occurred_on DATE NOT NULL,
  fiscal_year SMALLINT UNSIGNED NOT NULL,
  days DECIMAL(2,1) NOT NULL DEFAULT 1.0,
  expires_on DATE NOT NULL,
  notice_id BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  cancelled_by BIGINT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_comp_grant_employee (employee_id, expires_on),
  CONSTRAINT fk_comp_grant_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_comp_grant_notice FOREIGN KEY (notice_id) REFERENCES attendance_notices(id),
  CONSTRAINT fk_comp_grant_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
  CONSTRAINT fk_comp_grant_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comp_leave_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  leave_date DATE NOT NULL,
  days DECIMAL(2,1) NOT NULL DEFAULT 1.0,
  status VARCHAR(30) NOT NULL DEFAULT 'registered',
  note TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  cancellation_reason TEXT NULL,
  cancelled_by BIGINT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_comp_entry_employee_date (employee_id, leave_date),
  INDEX idx_comp_entry_status_date (status, leave_date),
  CONSTRAINT fk_comp_entry_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_comp_entry_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_comp_entry_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
