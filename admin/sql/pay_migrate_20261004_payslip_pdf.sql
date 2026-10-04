-- 給与明細PDF（弥生形式）と支給欄の内訳変更（2026-10-04）
-- 支給欄は弥生の分け方をベースに、基本給だけ区分別にする。端数は項目ごとに1円未満切上げ（弥生と同じ）。
--   洗濯代行／店舗／集荷 = その区分の全労働時間（時間外を含む）× 平日時給
--   休日手当             = 土日祝の労働時間（時間外を含む）×（土日祝時給 − 平日時給）
--   普通残業手当         = 平日の時間外時間 × 平日時給 × 0.25
--   休日残業手当         = 土日祝の時間外時間 × 土日祝時給 × 0.25
--   深夜手当             = 深夜時間 × その日の時給 × 0.25

-- 1. 明細に出す社員コード・支払方法・所属
ALTER TABLE pay_employees
  ADD COLUMN employee_code VARCHAR(10) NULL COMMENT '社員コード（弥生の従業員コード。明細の (004) に表示）' AFTER employment_type,
  ADD COLUMN payment_method ENUM('cash','bank') NOT NULL DEFAULT 'cash' COMMENT 'cash=現金支給 bank=振込支給' AFTER employee_code,
  ADD COLUMN department VARCHAR(50) NULL COMMENT '所属（明細に表示。空欄可）' AFTER payment_method;

-- 2. 明細の支給内訳・勤怠時間
ALTER TABLE pay_slips
  ADD COLUMN minutes_holiday INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '休日勤務時間（土日祝の実働、時間外を含む、分）' AFTER minutes_pickup,
  ADD COLUMN minutes_overtime_holiday INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '休日残業時間（土日祝の時間外、分）' AFTER minutes_overtime_weekly,
  ADD COLUMN pay_holiday INT NOT NULL DEFAULT 0 COMMENT '休日手当（土日祝の時間×(土日祝時給−平日時給)）' AFTER pay_pickup,
  ADD COLUMN pay_overtime_holiday INT NOT NULL DEFAULT 0 COMMENT '休日残業手当（土日祝の時間外×土日祝時給×割増分）' AFTER pay_overtime,
  MODIFY COLUMN minutes_laundry INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '洗濯代行時間（時間外を含む、分）',
  MODIFY COLUMN minutes_store INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '店舗時間（時間外を含む、分）',
  MODIFY COLUMN minutes_pickup INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '集荷時間（時間外を含む、分）',
  MODIFY COLUMN pay_laundry INT NOT NULL DEFAULT 0 COMMENT '洗濯代行（区分の全時間×平日時給）',
  MODIFY COLUMN pay_store INT NOT NULL DEFAULT 0 COMMENT '店舗（区分の全時間×平日時給）',
  MODIFY COLUMN pay_pickup INT NOT NULL DEFAULT 0 COMMENT '集荷（区分の全時間×平日時給）',
  MODIFY COLUMN pay_overtime INT NOT NULL DEFAULT 0 COMMENT '普通残業手当（平日の時間外×平日時給×割増分）';
