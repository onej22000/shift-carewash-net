-- 住民税（特別徴収）を月ごとに保持する（2026-10-04）
-- 年度途中からの特別徴収開始（例：年度途中（7月）から開始、初回と2回目以降で額が異なる通知）や、
-- 年度途中の税額変更通知に対応するため、6月〜翌5月の12か月分を1行ずつ持つ。
-- pay_resident_tax は市町村名などのヘッダ情報用として残し、june_amount / monthly_amount は今後使わない。

CREATE TABLE pay_resident_tax_months (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  fiscal_year SMALLINT UNSIGNED NOT NULL COMMENT '6月始まりの年度（2026=2026年6月〜2027年5月）',
  month TINYINT UNSIGNED NOT NULL COMMENT '控除する月（支給日の属する月。6〜12, 1〜5）',
  amount INT UNSIGNED NOT NULL COMMENT 'その月の特別徴収額（円）',
  UNIQUE KEY uq_pay_resident_tax_months (employee_id, fiscal_year, month),
  CONSTRAINT fk_pay_resident_tax_months_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT chk_pay_resident_tax_months_month CHECK (month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='住民税特別徴収額（月別）';

-- 旧列は使わないため NULL 可にする（新しい登録ではヘッダ行に NULL を入れる。既存値は記録として残す）
ALTER TABLE pay_resident_tax
  MODIFY june_amount INT UNSIGNED NULL COMMENT '旧: 6月分（2026-10-04以降は未使用。pay_resident_tax_months を参照）',
  MODIFY monthly_amount INT UNSIGNED NULL COMMENT '旧: 7月〜翌5月分（2026-10-04以降は未使用。pay_resident_tax_months を参照）';

-- 既存データの移行: june_amount → 6月、monthly_amount → 7月〜翌5月
INSERT INTO pay_resident_tax_months (employee_id, fiscal_year, month, amount)
SELECT r.employee_id, r.fiscal_year, m.month, CASE WHEN m.month = 6 THEN r.june_amount ELSE r.monthly_amount END
FROM pay_resident_tax r
JOIN (SELECT 6 AS month UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
      UNION ALL SELECT 12 UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) m
WHERE r.june_amount IS NOT NULL AND r.monthly_amount IS NOT NULL;
