SET NAMES utf8mb4;
SET time_zone = '+09:00';

CREATE TABLE employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(50) NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  hired_on DATE NULL,
  leave_renewal_month TINYINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('employee','admin') NOT NULL DEFAULT 'employee',
  status ENUM('active','disabled','suspended') NOT NULL DEFAULT 'active',
  session_token CHAR(32) NOT NULL,
  totp_secret VARCHAR(255) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_grants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  granted_on DATE NOT NULL,
  grant_year SMALLINT UNSIGNED NOT NULL,
  days DECIMAL(5,1) NOT NULL,
  expires_on DATE NOT NULL,
  reason VARCHAR(255) NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_grant_employee_expiry (employee_id, expires_on),
  INDEX idx_grant_auto (employee_id, grant_year, source),
  CONSTRAINT fk_grant_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_grant_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  leave_date DATE NOT NULL,
  leave_type ENUM('full','am','pm') NOT NULL,
  days DECIMAL(2,1) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'registered',
  note TEXT NULL,
  confirmed_with VARCHAR(100) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  cancellation_reason TEXT NULL,
  cancelled_by BIGINT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  rejection_reason TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_leave_employee_date (employee_id, leave_date),
  INDEX idx_leave_status_date (status, leave_date),
  CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_leave_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_leave_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
  CONSTRAINT fk_leave_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_adjustments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  grant_year SMALLINT UNSIGNED NOT NULL,
  days_delta DECIMAL(5,1) NOT NULL,
  reason TEXT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_adjust_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_adjust_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE company_calendar_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(30) NOT NULL,
  title VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_company_calendar_dates (start_date, end_date),
  CONSTRAINT fk_company_calendar_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_company_calendar_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE japanese_holidays (
  holiday_date DATE PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  source_url VARCHAR(255) NOT NULL,
  synced_at DATETIME NOT NULL,
  INDEX idx_japanese_holidays_synced (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('clock_in','clock_out') NOT NULL,
  occurred_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_attendance_employee_time (employee_id, occurred_at),
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_attendance_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Googleフォーム打刻連携の冪等キー（フォーム回答IDの重複記録防止）。
CREATE TABLE form_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id VARCHAR(64) NOT NULL UNIQUE,
  attendance_event_id BIGINT UNSIGNED NULL,
  received_at DATETIME NOT NULL,
  CONSTRAINT fk_form_webhook_event FOREIGN KEY (attendance_event_id) REFERENCES attendance_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_notices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  target_date DATE NOT NULL,
  notice_type ENUM('late','early','leave_full','leave_am','leave_pm','absence','holiday_work','medical','other') NOT NULL,
  expected_start TIME NULL,
  expected_end TIME NULL,
  details TEXT NULL,
  leave_entry_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_notice_employee_date (employee_id, target_date),
  CONSTRAINT fk_notice_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_notice_leave FOREIGN KEY (leave_entry_id) REFERENCES leave_entries(id),
  CONSTRAINT fk_notice_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE employee_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_group_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_memberships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_group_member (group_id, employee_id),
  CONSTRAINT fk_membership_group FOREIGN KEY (group_id) REFERENCES employee_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_membership_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE view_grants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  viewer_type ENUM('employee','group') NOT NULL,
  viewer_employee_id BIGINT UNSIGNED NULL,
  viewer_group_id BIGINT UNSIGNED NULL,
  target_type ENUM('employee','group','all') NOT NULL,
  target_employee_id BIGINT UNSIGNED NULL,
  target_group_id BIGINT UNSIGNED NULL,
  expires_on DATE NULL,
  granted_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_vg_viewer_emp (viewer_employee_id),
  INDEX idx_vg_target_emp (target_employee_id),
  CONSTRAINT fk_vg_viewer_emp FOREIGN KEY (viewer_employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_vg_viewer_grp FOREIGN KEY (viewer_group_id) REFERENCES employee_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_vg_target_emp FOREIGN KEY (target_employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_vg_target_grp FOREIGN KEY (target_group_id) REFERENCES employee_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_vg_granter FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_preferences (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  reminder_time TIME NOT NULL DEFAULT '18:00:00',
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_push_preference_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(50) NOT NULL,
  work_date DATE NOT NULL,
  sent_at DATETIME NOT NULL,
  UNIQUE KEY uniq_push_notice (user_id, notification_type, work_date),
  CONSTRAINT fk_push_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(100) NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE totp_backup_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_backup_user (user_id),
  CONSTRAINT fk_backup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE remember_login_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  selector CHAR(32) NOT NULL UNIQUE,
  validator_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_remember_user (user_id),
  INDEX idx_remember_expiry (expires_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
