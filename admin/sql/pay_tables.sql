-- 給与計算機能（admin/payroll*.php）用テーブル
-- 既存テーブル（attendance / employees / employee_allowances / monthly_wages 等）の構造には一切変更を加えない。
-- （employees.hourly_wage_weekday / hourly_wage_holiday は表示用として、pay_wage_history の今日時点で有効な値をアプリから同期する）
-- 従業員IDは employees.id（INT UNSIGNED）を参照する。
-- マイナンバーはこのシステムに保存しない。

-- ---------------------------------------------------------------------------
-- 全体設定（1行のみ）
-- ---------------------------------------------------------------------------
CREATE TABLE pay_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  company_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '明細・賃金台帳に表示する雇用主の正式名称（初期値は空、給与設定画面から入力）',
  closing_day TINYINT UNSIGNED NOT NULL DEFAULT 31 COMMENT '締日（31=末日）',
  pay_month_offset TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0=当月払い 1=翌月払い',
  pay_day TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT '支給日（31=末日）。土日祝は直前の平日に前倒し',
  week_start_dow TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '週40h判定の起算曜日（0=日曜）',
  overtime_rate DECIMAL(4,2) NOT NULL DEFAULT 1.25 COMMENT '時間外（1日8h・週40h超）の支給倍率',
  night_rate DECIMAL(4,2) NOT NULL DEFAULT 0.25 COMMENT '深夜（22時〜5時）の加算率',
  public_transit_nontax_limit INT UNSIGNED NOT NULL DEFAULT 150000 COMMENT '通勤手当 非課税限度（公共交通機関・併用の合計上限、月額）',
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='給与計算の全体設定';

INSERT INTO pay_settings (id, closing_day, pay_month_offset, pay_day, week_start_dow, overtime_rate, night_rate, public_transit_nontax_limit, updated_at)
VALUES (1, 31, 1, 10, 0, 1.25, 0.25, 150000, NOW());

-- ---------------------------------------------------------------------------
-- 従業員ごとの給与設定（履歴を持たない項目）
-- ---------------------------------------------------------------------------
CREATE TABLE pay_employees (
  employee_id INT UNSIGNED NOT NULL PRIMARY KEY,
  payroll_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '給与計算対象（0=対象外）',
  gender ENUM('male','female','other') NULL COMMENT '性別（賃金台帳の法定記載事項）',
  updated_at DATETIME NULL,
  CONSTRAINT fk_pay_employees_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='従業員の給与計算対象フラグ・性別';

-- 無効化済みを含む全アカウント分の行を作る。
-- 対象外4名（西潤=4、西科潤一=23、共用アカウント フトン巻きのジロー=93、検証用管理者=61）はOFF、それ以外はON。
-- 給与計算の対象は「payroll_enabled=1 かつ（status<>'disabled'、または無効化済みでも計算期間内に勤怠がある）」
-- （退職者を最終給与の確定前に無効化しても計算から漏れないようにするため）
INSERT INTO pay_employees (employee_id, payroll_enabled, updated_at)
SELECT id, CASE WHEN id IN (4, 23, 61, 93) THEN 0 ELSE 1 END, NOW()
FROM employees;

-- ---------------------------------------------------------------------------
-- 時給履歴（平日／土日祝の2本立て。日ごとにその日有効な行を使う）
-- ---------------------------------------------------------------------------
CREATE TABLE pay_wage_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  wage_weekday INT UNSIGNED NOT NULL COMMENT '平日時給（円）',
  wage_holiday INT UNSIGNED NOT NULL COMMENT '土日祝時給（円）',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  UNIQUE KEY uq_pay_wage_history (employee_id, effective_from),
  CONSTRAINT fk_pay_wage_history_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='時給履歴（適用開始日つき）。employees.hourly_wage_* は表示用で、今日時点で有効な行を同期する';

-- employees に入社日が無いため、全員 2026-01-01 適用で現在値を移行。
-- wages.php（賃金確認）も共通関数経由でこの表を読むため、給与計算対象外・無効化済みを含む全アカウント分を移行する
INSERT INTO pay_wage_history (employee_id, effective_from, wage_weekday, wage_holiday)
SELECT id, '2026-01-01', hourly_wage_weekday, hourly_wage_holiday
FROM employees;

-- ---------------------------------------------------------------------------
-- 従業員の税・保険・通勤の設定（履歴管理：適用開始日つき）
-- 交通費の金額（日額/月額）は既存の employees.commute_allowance_* を正とし、ここには持たない
-- 初期データなし（給与設定画面から入力）。計算期間に有効な行が無い従業員は確定不可のエラーとする
-- ---------------------------------------------------------------------------
CREATE TABLE pay_employee_terms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  tax_column ENUM('kou','otsu') NOT NULL COMMENT '扶養控除等申告書の提出あり=甲',
  dependents TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '源泉控除対象の扶養親族等の数',
  emp_insurance TINYINT(1) NOT NULL DEFAULT 0 COMMENT '雇用保険被保険者',
  commute_method ENUM('public','car','mixed') NULL COMMENT '通勤手段（公共交通機関／自動車等／併用）。交通費がある人は必須',
  commute_distance_km DECIMAL(5,1) NULL COMMENT '自動車等を使う片道距離（km）。car / mixed で必須',
  commute_public_monthly INT UNSIGNED NULL COMMENT '併用時の公共交通機関部分（1か月の合理的な運賃等の額）',
  parking_pay_type ENUM('none','monthly','daily') NOT NULL DEFAULT 'none' COMMENT '駐車場代の支給方法（なし／月額／日額。日額は交通費と同じ出勤回数で計上）',
  parking_pay_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '駐車場代の支給額（月額 or 1回あたり）',
  parking_fee_type ENUM('monthly','per_use') NULL COMMENT '本人が負担する駐車場等の料金の定め方（月単位／利用の都度）。非課税限度の加算額の算定用',
  parking_fee_amount INT UNSIGNED NULL COMMENT '本人負担の駐車場等の料金（月額 or 1回あたり。複数利用は合計、税込）',
  parking_qualified TINYINT(1) NOT NULL DEFAULT 0 COMMENT '「一定の要件を満たす駐車場等」（勤務場所又は利用駅等の周辺。自宅付近は不可）に該当',
  work_prefecture VARCHAR(10) NOT NULL DEFAULT '滋賀県' COMMENT '最低賃金判定用の就業地',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  UNIQUE KEY uq_pay_employee_terms (employee_id, effective_from),
  CONSTRAINT fk_pay_employee_terms_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='従業員の源泉・雇用保険・通勤設定（履歴）';

-- ---------------------------------------------------------------------------
-- 通勤手当の非課税限度（自動車等の距離区分）。min_km以上 max_km未満、max_km NULL=上限なし
-- ---------------------------------------------------------------------------
CREATE TABLE pay_commute_limits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  effective_from DATE NOT NULL,
  min_km DECIMAL(5,1) NOT NULL,
  max_km DECIMAL(5,1) NULL,
  limit_amount INT UNSIGNED NOT NULL COMMENT '月額の非課税限度（円）',
  UNIQUE KEY uq_pay_commute_limits (effective_from, min_km)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通勤手当 非課税限度（自動車等・距離区分）';

INSERT INTO pay_commute_limits (effective_from, min_km, max_km, limit_amount) VALUES
('2025-04-01',  0.0,  2.0,     0),
('2025-04-01',  2.0, 10.0,  4200),
('2025-04-01', 10.0, 15.0,  7300),
('2025-04-01', 15.0, 25.0, 13500),
('2025-04-01', 25.0, 35.0, 19700),
('2025-04-01', 35.0, 45.0, 25900),
('2025-04-01', 45.0, 55.0, 32300),
('2025-04-01', 55.0, NULL, 38700),
('2026-04-01',  0.0,  2.0,     0),
('2026-04-01',  2.0, 10.0,  4200),
('2026-04-01', 10.0, 15.0,  7300),
('2026-04-01', 15.0, 25.0, 13500),
('2026-04-01', 25.0, 35.0, 19700),
('2026-04-01', 35.0, 45.0, 25900),
('2026-04-01', 45.0, 55.0, 32300),
('2026-04-01', 55.0, 65.0, 38700),
('2026-04-01', 65.0, 75.0, 45700),
('2026-04-01', 75.0, 85.0, 52700),
('2026-04-01', 85.0, 95.0, 59600),
('2026-04-01', 95.0, NULL, 66400);

-- ---------------------------------------------------------------------------
-- 通勤手当の非課税限度への駐車場等料金の加算ルール（国税庁「通勤手当の非課税限度額の改正について」Q&A Q3-1〜Q3-4）
-- 加算額 = MIN(1か月当たりの駐車場等の料金相当額, cap_amount)。
-- 対象：通勤手段が自動車等／併用、parking_qualified=1、交通用具を使う片道距離が min_distance_km 以上
-- ---------------------------------------------------------------------------
CREATE TABLE pay_parking_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  effective_from DATE NOT NULL,
  cap_amount INT UNSIGNED NOT NULL COMMENT '加算の上限（月額）',
  min_distance_km DECIMAL(5,1) NOT NULL COMMENT 'この距離未満は加算しない',
  UNIQUE KEY uq_pay_parking_rules (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通勤手当 非課税限度への駐車場等料金の加算ルール';

INSERT INTO pay_parking_rules (effective_from, cap_amount, min_distance_km) VALUES
('2026-04-01', 5000, 2.0);

-- ---------------------------------------------------------------------------
-- 住民税特別徴収（6月〜翌5月。通知書の月割額をそのまま入力）
-- ---------------------------------------------------------------------------
CREATE TABLE pay_resident_tax (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  fiscal_year SMALLINT UNSIGNED NOT NULL COMMENT '6月始まりの年度（2026=2026年6月〜2027年5月）',
  june_amount INT UNSIGNED NOT NULL COMMENT '6月分（端数込み）',
  monthly_amount INT UNSIGNED NOT NULL COMMENT '7月〜翌5月分',
  municipality VARCHAR(50) NULL,
  UNIQUE KEY uq_pay_resident_tax (employee_id, fiscal_year),
  CONSTRAINT fk_pay_resident_tax_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='住民税特別徴収額';

-- ---------------------------------------------------------------------------
-- 源泉徴収税額表（月額表）。年分ごとに国税庁Excelから取込
-- ---------------------------------------------------------------------------
CREATE TABLE pay_withholding_table (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_year SMALLINT UNSIGNED NOT NULL COMMENT '2027 = 令和9年分',
  min_amount INT UNSIGNED NOT NULL COMMENT '以上',
  max_amount INT UNSIGNED NOT NULL COMMENT '未満',
  kou0 INT UNSIGNED NULL, kou1 INT UNSIGNED NULL, kou2 INT UNSIGNED NULL, kou3 INT UNSIGNED NULL,
  kou4 INT UNSIGNED NULL, kou5 INT UNSIGNED NULL, kou6 INT UNSIGNED NULL, kou7 INT UNSIGNED NULL,
  otsu INT UNSIGNED NULL COMMENT '乙欄（率で決まる行はNULL）',
  otsu_rate DECIMAL(6,3) NULL COMMENT '乙欄の税率（%）。例：3.063',
  KEY idx_pay_withholding_year (table_year, min_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='源泉徴収税額表（月額表）';

-- ---------------------------------------------------------------------------
-- 雇用保険料率（労働者負担）
-- ---------------------------------------------------------------------------
CREATE TABLE pay_emp_insurance_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  effective_from DATE NOT NULL,
  employee_rate DECIMAL(7,5) NOT NULL COMMENT '労働者負担率（例：0.00550）',
  UNIQUE KEY uq_pay_emp_insurance_rates (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='雇用保険料率（労働者負担）';

-- 一般の事業・労働者負担（失業等給付・育児休業給付の保険料率）。出典：厚生労働省
--   令和7年度 https://www.mhlw.go.jp/content/001401966.pdf 「令和７年度の雇用保険料率」表 一般の事業 ①労働者負担 5.5/1,000
--   令和8年度 https://www.mhlw.go.jp/content/001692566.pdf 「令和８年度の雇用保険料率」表 一般の事業 ①労働者負担 5/1,000
INSERT INTO pay_emp_insurance_rates (effective_from, employee_rate) VALUES
('2025-04-01', 0.00550),
('2026-04-01', 0.00500);

-- ---------------------------------------------------------------------------
-- 最低賃金
-- ---------------------------------------------------------------------------
CREATE TABLE pay_min_wages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prefecture VARCHAR(10) NOT NULL,
  effective_from DATE NOT NULL,
  hourly INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_pay_min_wages (prefecture, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='地域別最低賃金';

INSERT INTO pay_min_wages (prefecture, effective_from, hourly) VALUES
('滋賀県', '2025-10-05', 1080),
('滋賀県', '2026-10-03', 1136);

-- ---------------------------------------------------------------------------
-- 給与計算の回（勤務月ごと）。取消（void）した回は残し、新しい draft を作り直す
-- ---------------------------------------------------------------------------
CREATE TABLE pay_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_month CHAR(7) NOT NULL COMMENT '勤務月 YYYY-MM',
  period_start DATE NOT NULL COMMENT '賃金計算期間 開始',
  period_end DATE NOT NULL COMMENT '賃金計算期間 終了',
  pay_date DATE NOT NULL COMMENT '支給日（前倒し後の実際の日付。税額表の年分はこの日付で決める）',
  status ENUM('draft','closed','void') NOT NULL DEFAULT 'draft',
  closed_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  voided_by INT UNSIGNED NULL,
  void_reason VARCHAR(255) NULL COMMENT '取消理由（必須）',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pay_runs_month (work_month, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='給与計算の回';

-- ---------------------------------------------------------------------------
-- 給与明細（従業員1人×1回につき1行）
-- ---------------------------------------------------------------------------
CREATE TABLE pay_slips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  employee_snapshot TEXT NULL COMMENT '確定時の氏名・時給履歴・税/保険/通勤設定・手当（JSON）',
  calc_detail MEDIUMTEXT NULL COMMENT '日別・週別の計算明細（JSON、検算用）',
  -- 勤怠
  work_days TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '出勤日数（実日数）',
  holiday_work_days TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'うち土日祝の出勤日数',
  minutes_total INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '総労働時間（分）',
  minutes_laundry INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '洗濯代行 所定内（分）',
  minutes_store INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '店舗 所定内（分）',
  minutes_pickup INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '集荷 所定内（分）',
  minutes_overtime_daily INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '時間外（1日8h超、分）',
  minutes_overtime_weekly INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '時間外（週40h超、日単位分を除く、分）',
  minutes_night INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '深夜（分）',
  commute_trips SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '交通費・駐車場代（日額）の計上回数',
  -- 支給
  pay_laundry INT NOT NULL DEFAULT 0 COMMENT '洗濯代行 基本給（所定内）',
  pay_store INT NOT NULL DEFAULT 0 COMMENT '店舗 基本給（所定内）',
  pay_pickup INT NOT NULL DEFAULT 0 COMMENT '集荷 基本給（所定内）',
  pay_overtime INT NOT NULL DEFAULT 0 COMMENT '時間外手当（1.25倍の全額）',
  pay_night INT NOT NULL DEFAULT 0 COMMENT '深夜手当（加算分のみ）',
  allowance_total INT NOT NULL DEFAULT 0 COMMENT '手当合計（employee_allowances、課税）',
  allowance_detail TEXT NULL COMMENT '手当の内訳 [{name, amount}]（JSON）',
  commute_total INT NOT NULL DEFAULT 0 COMMENT '交通費 支給額',
  parking_total INT NOT NULL DEFAULT 0 COMMENT '駐車場代 支給額（明細では交通費と別行）',
  commute_nontax_limit INT NOT NULL DEFAULT 0 COMMENT '適用した通勤手当の非課税限度額（駐車場加算込み）',
  commute_nontax INT NOT NULL DEFAULT 0 COMMENT '交通費＋駐車場代のうち非課税',
  commute_taxable INT NOT NULL DEFAULT 0 COMMENT '交通費＋駐車場代のうち課税（非課税限度超過分）',
  attendance_adjust INT NOT NULL DEFAULT 0 COMMENT '勤怠調整額（円、課税・雇用保険対象。マイナス可）',
  attendance_adjust_reason VARCHAR(255) NULL COMMENT '勤怠調整の理由（明細に表示）',
  other_taxable INT NOT NULL DEFAULT 0 COMMENT 'その他課税支給（手入力）',
  other_taxable_label VARCHAR(50) NULL,
  other_nontax INT NOT NULL DEFAULT 0 COMMENT 'その他非課税支給（手入力）',
  other_nontax_label VARCHAR(50) NULL,
  gross_total INT NOT NULL DEFAULT 0 COMMENT '総支給額',
  -- 控除
  emp_insurance_rate DECIMAL(7,5) NULL COMMENT '適用した雇用保険料率',
  emp_insurance INT NOT NULL DEFAULT 0,
  taxable_amount INT NOT NULL DEFAULT 0 COMMENT '社会保険料等控除後の課税対象額',
  tax_table_year SMALLINT UNSIGNED NULL COMMENT '適用した税額表の年分',
  withholding_tax INT NOT NULL DEFAULT 0,
  resident_tax INT NOT NULL DEFAULT 0,
  other_deduction INT NOT NULL DEFAULT 0 COMMENT 'その他控除（手入力）',
  other_deduction_label VARCHAR(50) NULL,
  deduction_total INT NOT NULL DEFAULT 0,
  net_pay INT NOT NULL DEFAULT 0 COMMENT '差引支給額',
  errors TEXT NULL COMMENT '確定不可の理由（JSON配列。空なら確定可）',
  note TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_slips (run_id, employee_id),
  CONSTRAINT fk_pay_slips_run FOREIGN KEY (run_id) REFERENCES pay_runs (id),
  CONSTRAINT fk_pay_slips_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='給与明細';
