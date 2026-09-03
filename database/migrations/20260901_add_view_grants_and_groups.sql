-- v0.4対応: 管理者権限とは独立した「閲覧権限」と、その付与単位となるグループを追加する（§4.3, §14）。
-- view_grants は viewer（閲覧する側）と target（閲覧される側）をそれぞれ個人/グループで指定でき、
-- 任意の組み合わせを表現する。閲覧は読み取りのみで、登録・取消・調整には用いない。

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
  target_type ENUM('employee','group') NOT NULL,
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
