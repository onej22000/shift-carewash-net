-- 役員報酬（2026-10-04）
-- 役員は労働者ではないため、時給・勤怠によらず月額固定の役員報酬として給与計算する。

-- 1. 従業員／役員の区分
ALTER TABLE pay_employees
  ADD COLUMN employment_type ENUM('employee','officer') NOT NULL DEFAULT 'employee'
  COMMENT 'employee=従業員 officer=役員' AFTER payroll_enabled;

-- 西科潤一さん（employees.id=23）を役員・給与計算対象にする
INSERT INTO pay_employees (employee_id, payroll_enabled, employment_type, updated_at) VALUES (23, 1, 'officer', NOW())
  ON DUPLICATE KEY UPDATE payroll_enabled = VALUES(payroll_enabled), employment_type = VALUES(employment_type), updated_at = NOW();

-- 2. 役員報酬の月額（履歴管理）
CREATE TABLE pay_officer_compensation (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL COMMENT '支給日ベースの適用開始日',
  monthly_amount INT UNSIGNED NOT NULL COMMENT '役員報酬 月額（円）',
  note VARCHAR(200) NULL COMMENT '株主総会・社員総会の決議日など',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  UNIQUE KEY uq_pay_officer_comp (employee_id, effective_from),
  CONSTRAINT fk_pay_officer_comp_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. 事業年度の開始月（定期同額給与の改定期限チェック用。値は給与設定画面から入力）
ALTER TABLE pay_settings
  ADD COLUMN fiscal_year_start_month TINYINT UNSIGNED NULL COMMENT '事業年度の開始月（定期同額給与の改定期限チェック用）';

-- 4. 明細に区分と役員報酬額を持たせる（確定済み明細の表示・台帳の振り分けを計算時点の区分で行うため）
ALTER TABLE pay_slips
  ADD COLUMN employment_type ENUM('employee','officer') NOT NULL DEFAULT 'employee' COMMENT '計算時点の区分' AFTER employee_id,
  ADD COLUMN pay_officer INT NOT NULL DEFAULT 0 COMMENT '役員報酬（月額）' AFTER pay_night;
