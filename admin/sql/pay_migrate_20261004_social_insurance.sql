-- 社会保険（健康保険・介護保険・子ども・子育て支援金・厚生年金）の控除（2026-10-04）
-- 標準報酬月額は年金事務所の決定通知書の額を「適用開始月（◯月分）」つきで手入力する。保険料は「◯月分」で管理する。
-- 端数処理は保険料額表の欄どおり（各欄の折半額を 50銭以下切捨て・50銭超切上げ）。
--   健康保険料 = 端数処理(標準報酬×健康保険料率÷2)
--   介護保険料 = 端数処理(標準報酬×(健康＋介護)÷2) − 健康保険料（介護保険第2号被保険者のみ）
--   子ども・子育て支援金 = 端数処理(標準報酬×支援金率÷2)
--   厚生年金保険料 = 端数処理(標準報酬×厚生年金保険料率÷2)

CREATE TABLE pay_si_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  effective_month CHAR(7) NOT NULL COMMENT '適用開始の保険料対象月（◯月分） YYYY-MM',
  prefecture VARCHAR(10) NOT NULL,
  health_rate DECIMAL(6,3) NOT NULL COMMENT '健康保険料率（%、労使合計）',
  care_rate DECIMAL(6,3) NOT NULL COMMENT '介護保険料率（%、労使合計）',
  child_support_rate DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT '子ども・子育て支援金率（%、労使合計）',
  pension_rate DECIMAL(6,3) NOT NULL COMMENT '厚生年金保険料率（%、労使合計）',
  source_url VARCHAR(255) NULL,
  UNIQUE KEY uq_pay_si_rates (prefecture, effective_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 協会けんぽ京都支部（r07・r08 の料率ページと R8_26kyoto.pdf の保険料額表で照合済み、2026-10-04）。
-- 子ども・子育て支援金の開始（2026-04）は別行で持つ。
INSERT INTO pay_si_rates (effective_month, prefecture, health_rate, care_rate, child_support_rate, pension_rate, source_url) VALUES
('2025-03','京都府',10.030,1.590,0.000,18.300,'https://www.kyoukaikenpo.or.jp/about/business/insurance_rate/rate_prefectures/r07'),
('2026-03','京都府', 9.890,1.620,0.000,18.300,'https://www.kyoukaikenpo.or.jp/about/business/insurance_rate/rate_prefectures/r08'),
('2026-04','京都府', 9.890,1.620,0.230,18.300,'https://www.kyoukaikenpo.or.jp/about/business/insurance_rate/rate_prefectures/r08');

CREATE TABLE pay_si_standard (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  effective_month CHAR(7) NOT NULL COMMENT '適用開始の保険料対象月（◯月分）',
  enrolled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=資格喪失（この月分から控除しない）',
  health_standard INT UNSIGNED NULL COMMENT '健康保険 標準報酬月額',
  pension_standard INT UNSIGNED NULL COMMENT '厚生年金 標準報酬月額',
  note VARCHAR(200) NULL COMMENT '資格取得・定時決定・随時改定など、根拠の通知書',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  UNIQUE KEY uq_pay_si_standard (employee_id, effective_month),
  CONSTRAINT fk_pay_si_standard_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE pay_employees ADD COLUMN birth_date DATE NULL COMMENT '介護保険第2号（40〜64歳）の自動判定用';

ALTER TABLE pay_settings
  ADD COLUMN si_prefecture VARCHAR(10) NOT NULL DEFAULT '京都府' COMMENT '社保の適用事業所の都道府県',
  ADD COLUMN si_collection ENUM('next_month','same_month') NOT NULL DEFAULT 'next_month' COMMENT '社保の徴収（next_month=翌月徴収：前月分を当月支給から控除）';

ALTER TABLE pay_slips
  ADD COLUMN si_month CHAR(7) NULL COMMENT '控除した保険料の対象月（◯月分）',
  ADD COLUMN si_health INT NOT NULL DEFAULT 0 COMMENT '健康保険料 本人負担（子ども・子育て支援金を含まない）',
  ADD COLUMN si_care INT NOT NULL DEFAULT 0 COMMENT '介護保険料 本人負担',
  ADD COLUMN si_child_support INT NOT NULL DEFAULT 0 COMMENT '子ども・子育て支援金 本人負担',
  ADD COLUMN si_pension INT NOT NULL DEFAULT 0 COMMENT '厚生年金保険料 本人負担';
