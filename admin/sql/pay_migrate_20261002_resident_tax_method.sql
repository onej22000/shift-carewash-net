-- 追加指示書①より（給与計算本体の payroll_employees.php 作成時に先行実施、2026-10-02）
-- 住民税の徴収方法（確定版 pay_employee_terms に不足していたため追加）
ALTER TABLE pay_employee_terms
  ADD COLUMN resident_tax_method ENUM('special','ordinary') NOT NULL DEFAULT 'ordinary'
  COMMENT '住民税 special=特別徴収（給与から天引き） ordinary=普通徴収（本人納付）'
  AFTER emp_insurance;
