-- ============================================
-- shift.carewash.net テーブル設計
-- 文字コード: utf8mb4 / ストレージエンジン: InnoDB
-- ============================================

CREATE TABLE employees (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                   VARCHAR(100) NOT NULL COMMENT '氏名',
    role                   ENUM('admin','staff') NOT NULL DEFAULT 'staff' COMMENT '権限',
    login_id               VARCHAR(50)  NULL UNIQUE COMMENT 'ログインID（本登録完了後に設定）',
    password_hash          VARCHAR(255) NULL COMMENT 'パスワードハッシュ',
    hourly_wage_weekday    INT UNSIGNED NOT NULL COMMENT '平日時給（円）',
    hourly_wage_holiday    INT UNSIGNED NOT NULL COMMENT '土日祝時給（円）',
    status                 ENUM('invited','active','disabled') NOT NULL DEFAULT 'invited' COMMENT '登録状態',
    invite_code            VARCHAR(20)  NULL UNIQUE COMMENT '招待コード（本登録完了でNULLに戻す）',
    invite_code_expires_at DATETIME     NULL COMMENT '招待コード有効期限',
    calendar_token         VARCHAR(64)  NULL UNIQUE COMMENT 'カレンダー購読(.ics)用トークン。再発行で旧トークンは失効',
    created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='従業員・管理者アカウント';

CREATE TABLE holidays (
    `date` DATE PRIMARY KEY COMMENT '祝日日付',
    name    VARCHAR(100) NOT NULL COMMENT '祝日名'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='日本の国民の祝日マスタ（年次で追加投入）';

CREATE TABLE shifts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT UNSIGNED NOT NULL COMMENT '従業員ID',
    work_date     DATE         NOT NULL COMMENT '勤務予定日',
    start_time    TIME         NOT NULL COMMENT '開始予定時刻',
    end_time      TIME         NOT NULL COMMENT '終了予定時刻',
    break_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '休憩時間（分）',
    note          VARCHAR(255) NULL COMMENT '備考',
    categories    SET('店舗','洗濯代行','集荷') NOT NULL DEFAULT '' COMMENT '業務種別（複数選択可）',
    created_by    INT UNSIGNED NULL COMMENT '作成した管理者のemployee_id',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shifts_employee   FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_shifts_created_by FOREIGN KEY (created_by)  REFERENCES employees(id),
    INDEX idx_shifts_employee_date (employee_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='シフト表（勤務予定）。実働時間の目安 = (end_time - start_time) - break_minutes';

CREATE TABLE attendance (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT UNSIGNED NOT NULL COMMENT '従業員ID',
    shift_id      INT UNSIGNED NULL COMMENT '対応するシフトID（任意）',
    category      ENUM('店舗','洗濯代行','集荷') NULL COMMENT '打刻区分（出勤時に選択。シフトの区分から初期値を提案し、変更可能）',
    clock_in_at   DATETIME     NULL COMMENT '出勤日時',
    clock_in_lat  DECIMAL(10,7) NULL COMMENT '出勤時緯度',
    clock_in_lng  DECIMAL(10,7) NULL COMMENT '出勤時経度',
    break_start_at DATETIME    NULL COMMENT '休憩開始時刻（休憩中のみ設定、戻り確定で総分数に加算しNULLに戻す）',
    break_end_at   DATETIME    NULL COMMENT '休憩終了時刻（休憩中のみ設定、戻り確定でNULLに戻す）',
    total_break_minutes INT UNSIGNED NULL COMMENT '休憩合計（分、退勤確定時点の値。手動打刻の積み上げ）',
    clock_out_at  DATETIME     NULL COMMENT '退勤日時',
    clock_out_lat DECIMAL(10,7) NULL COMMENT '退勤時緯度',
    clock_out_lng DECIMAL(10,7) NULL COMMENT '退勤時経度',
    work_minutes  INT UNSIGNED NULL COMMENT '実働時間（分、退勤時に自動計算 = (clock_out_at - clock_in_at) - total_break_minutes）',
    status        ENUM('working','done') NOT NULL DEFAULT 'working' COMMENT '打刻状態（休憩中もworkingのまま。休憩中判定はbreak_start_at IS NOT NULL AND break_end_at IS NULLで行う）',
    deleted_at    DATETIME     NULL COMMENT '論理削除日時（本人による打刻取り消し等、NULLなら有効。物理削除はattendance_edit_logsのFK制約により基本的に不可）',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_attendance_shift    FOREIGN KEY (shift_id)    REFERENCES shifts(id),
    INDEX idx_attendance_employee_date (employee_id, clock_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='打刻記録（出退勤、位置情報は取得拒否時NULL許容）';

CREATE TABLE attendance_edit_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id   INT UNSIGNED NOT NULL COMMENT '対象の打刻レコード',
    edited_by       INT UNSIGNED NOT NULL COMMENT '編集した従業員ID（本人）',
    action          ENUM('create','update','delete','auto_break','month_end_correction') NOT NULL DEFAULT 'update' COMMENT '操作種別（createは打刻漏れ日への新規追加、deleteはattendance.deleted_atによる論理削除の記録、auto_breakは退勤時の休憩時間自動計算、month_end_correctionは月末チェックによる出勤時刻自動補正）',
    field_name      VARCHAR(50) NOT NULL COMMENT '変更したフィールド名（clock_in_at等）',
    old_value       VARCHAR(100) NULL COMMENT '変更前の値（削除時は削除前の値）',
    new_value       VARCHAR(100) NULL COMMENT '変更後の値（削除時は常にNULL）',
    edited_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edit_logs_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id),
    CONSTRAINT fk_edit_logs_employee FOREIGN KEY (edited_by) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='打刻修正・取り消し履歴';

-- attendance.break_start_at/break_end_atは「今まさに休憩中かどうか」の現在状態を持つだけで、
-- 1日に複数回休憩すると前回分の開始・終了時刻は次の休憩開始時に上書きされ失われる
-- （total_break_minutesに累計分数だけは残る）。施設間移動時間の算出（travel_time機能）では
-- 「いつからいつまで休憩していたか」を個々に参照する必要があるため、休憩1回ごとに1行残す
-- 履歴テーブルを別途持つ。staff/break.phpから、既存のattendance側の更新と並行して
-- こちらにも書き込む（dual-write。既存機能の挙動は変えない）。
CREATE TABLE attendance_breaks (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id  INT UNSIGNED NOT NULL COMMENT '対象の打刻レコード（attendance.id）',
    employee_id    INT UNSIGNED NOT NULL COMMENT '従業員ID（attendance経由でも引けるが、集計クエリの簡略化のため非正規化して保持）',
    break_start_at DATETIME NOT NULL COMMENT '休憩開始日時',
    break_end_at   DATETIME NULL COMMENT '休憩終了日時（休憩中はNULL）',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_breaks_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id),
    CONSTRAINT fk_attendance_breaks_employee   FOREIGN KEY (employee_id)   REFERENCES employees(id),
    INDEX idx_attendance_breaks_attendance (attendance_id),
    INDEX idx_attendance_breaks_employee_start (employee_id, break_start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='休憩1回ごとの開始・終了時刻の履歴（attendanceの現在状態カラムとは別に、過去の個々の休憩区間を保持する）';

CREATE TABLE shift_edit_logs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shift_id     INT UNSIGNED NULL COMMENT '対象シフトID（削除の場合は削除前のID）',
    edited_by    INT UNSIGNED NOT NULL COMMENT '編集した従業員ID（本人）',
    action       ENUM('create','update','delete') NOT NULL,
    field_name   VARCHAR(50) NULL COMMENT '更新時の変更フィールド名',
    old_value    VARCHAR(100) NULL,
    new_value    VARCHAR(100) NULL,
    edited_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shift_edit_logs_employee FOREIGN KEY (edited_by) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='従業員によるシフト自己編集履歴';

CREATE TABLE facilities (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(100) NOT NULL,
    facility_type         ENUM('介護施設','自社','クリーニング所') NOT NULL DEFAULT '介護施設' COMMENT '施設種別',
    room_count            INT UNSIGNED NULL COMMENT '居室数',
    onboarding_start_date DATE NULL COMMENT '受託開始日',
    pickup_schedule       ENUM('月・木','火・金','水・土') NULL COMMENT '集荷曜日パターン',
    address               VARCHAR(255) NULL COMMENT '施設住所',
    phone_number          VARCHAR(20) NULL COMMENT '電話番号',
    note                  TEXT NULL COMMENT '備考',
    issued_linen_bag_orange  INT UNSIGNED NULL COMMENT '交付リネン袋数（オレンジ）',
    issued_linen_bag_yellow  INT UNSIGNED NULL COMMENT '交付リネン袋数（黄）',
    issued_laundry_net_count INT UNSIGNED NULL COMMENT '交付洗濯ネット数',
    is_active             TINYINT(1) NOT NULL DEFAULT 1,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='取引先施設マスタ';

CREATE TABLE work_stage_records (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id       INT UNSIGNED NOT NULL COMMENT '記録した従業員ID',
    category          ENUM('店舗','洗濯代行','集荷') NULL COMMENT '区分（退勤時の作業実績入力で選択。attendance.categoryから初期値を提案）',
    facility_id       INT UNSIGNED NOT NULL COMMENT '対象施設',
    collection_cycle_id INT UNSIGNED NULL COMMENT '集荷サイクル（collection_cycles.id）。ドライバーが届けたリネン袋の中身をスタッフが確認した「人数確認」記録の場合にのみ設定（stage=wash想定）。それ以外の作業実績（乾燥・畳み等）はNULL',
    stage             ENUM('pickup','wash','dry','fold') NOT NULL COMMENT '工程（集荷/洗濯/乾燥/畳み）',
    person_count      INT UNSIGNED NOT NULL COMMENT '人数',
    record_date       DATE NOT NULL COMMENT '作業日',
    record_time       TIME NULL COMMENT '作業時刻（列追加前に登録された記録はNULL）',
    deleted_at        DATETIME NULL COMMENT '論理削除日時（管理者による削除、NULLなら有効）',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wsr_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_wsr_facility FOREIGN KEY (facility_id) REFERENCES facilities(id),
    CONSTRAINT fk_wsr_collection_cycle FOREIGN KEY (collection_cycle_id) REFERENCES collection_cycles(id),
    INDEX idx_wsr_facility_stage (facility_id, stage),
    INDEX idx_wsr_date (record_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='洗濯代行 工程別作業記録';

CREATE TABLE work_stage_record_edit_logs (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_stage_record_id  INT UNSIGNED NOT NULL COMMENT '対象の作業実績レコード',
    edited_by             INT UNSIGNED NOT NULL COMMENT '編集した管理者の従業員ID',
    action                ENUM('create','update','delete') NOT NULL DEFAULT 'update' COMMENT '操作種別',
    field_name            VARCHAR(50) NOT NULL COMMENT '変更したフィールド名',
    old_value             VARCHAR(100) NULL COMMENT '変更前の値（削除時は削除前の値）',
    new_value             VARCHAR(100) NULL COMMENT '変更後の値（削除時は常にNULL）',
    edited_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wsr_edit_logs_record   FOREIGN KEY (work_stage_record_id) REFERENCES work_stage_records(id),
    CONSTRAINT fk_wsr_edit_logs_employee FOREIGN KEY (edited_by) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作業実績（work_stage_records）の管理者による修正・削除履歴';

CREATE TABLE monthly_wages (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id              INT UNSIGNED NOT NULL COMMENT '従業員ID',
    `year_month`             CHAR(7)      NOT NULL COMMENT '対象年月 YYYY-MM形式',
    total_work_minutes       INT UNSIGNED NOT NULL COMMENT '月間実働時間（分）',
    hourly_wage_weekday      INT UNSIGNED NOT NULL COMMENT '確定時点の平日時給（円）',
    hourly_wage_holiday      INT UNSIGNED NOT NULL COMMENT '確定時点の土日祝時給（円）',
    total_wage               INT UNSIGNED NOT NULL COMMENT '確定支給額（円、日ごとの平日/土日祝時給を積み上げた金額）',
    weekday_regular_minutes  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平日所定内労働時間（分、1日480分まで）',
    weekday_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平日残業時間（分、1日480分超過分）',
    holiday_regular_minutes  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '休日（土日祝）所定内労働時間（分、1日480分まで）',
    holiday_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '休日（土日祝）残業時間（分、1日480分超過分）',
    night_minutes            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '深夜労働時間（分、22:00〜翌5:00にかかった実働時間）',
    weekday_wage             INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平日所定内賃金（円）',
    weekday_overtime_wage    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平日残業賃金（円、平日時給×1.25）',
    holiday_wage             INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '休日所定内賃金（円）',
    holiday_overtime_wage    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '休日残業賃金（円、休日時給×1.25）',
    night_wage               INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '深夜手当（円、深夜労働時間に対する割増分のみ。基本給・残業手当とは別建て）',
    confirmed_at             DATETIME     NOT NULL COMMENT '締め処理を実行した日時',
    confirmed_by             INT UNSIGNED NULL COMMENT '締め処理を行った管理者のemployee_id',
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_monthly_wages_employee    FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_monthly_wages_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES employees(id),
    UNIQUE KEY uniq_employee_month (employee_id, `year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='月次確定賃金（一度確定した月は原則上書きしない）。業務種別ごとの時間内訳はここには保存せず常に動的計算する';

CREATE TABLE collection_cycles (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id           INT UNSIGNED NOT NULL COMMENT '対象施設',
    pickup_date           DATE NOT NULL COMMENT '集荷日（このサイクルの起点）',
    pickup_bag_count      INT UNSIGNED NULL COMMENT '集荷時のリネン袋数',
    pickup_time           TIME NULL COMMENT '集荷時刻',
    pickup_employee_id    INT UNSIGNED NULL COMMENT '集荷担当者',
    issued_bag_orange     INT UNSIGNED NULL COMMENT 'リネン袋交付数（オレンジ）。集荷時に施設へ渡した交換用の空袋数',
    issued_bag_yellow     INT UNSIGNED NULL COMMENT 'リネン袋交付数（黄）。集荷時に施設へ渡した交換用の空袋数',
    issued_bag_blue       INT UNSIGNED NULL COMMENT 'リネン袋交付数（青）。集荷時に施設へ渡した交換用の空袋数',
    issued_laundry_net_count INT UNSIGNED NULL COMMENT '洗濯ネット交付数。集荷時に施設へ渡した洗濯ネット数',
    arrival_bag_count     INT UNSIGNED NULL COMMENT 'クリーニング所到着時のリネン袋数',
    arrival_date          DATE NULL COMMENT 'クリーニング所到着日（集荷日と異なる日になりうる）',
    arrival_time          TIME NULL COMMENT 'クリーニング所到着時刻',
    arrival_employee_id   INT UNSIGNED NULL COMMENT 'クリーニング所到着担当者',
    arrival_facility_id   INT UNSIGNED NULL COMMENT '到着したクリーニング所（facilities.facility_type=クリーニング所）。施設間移動時間の算出に使用',
    dispatch_bag_count    INT UNSIGNED NULL COMMENT 'クリーニング所発送時のリネン袋数',
    dispatch_date         DATE NULL COMMENT 'クリーニング所発送日（集荷日と異なる日になりうる）',
    dispatch_time         TIME NULL COMMENT 'クリーニング所発送時刻',
    dispatch_employee_id  INT UNSIGNED NULL COMMENT 'クリーニング所発送担当者',
    dispatch_facility_id  INT UNSIGNED NULL COMMENT '発送元のクリーニング所（facilities.facility_type=クリーニング所）。施設間移動時間の算出に使用',
    return_bag_count      INT UNSIGNED NULL COMMENT '返却時のリネン袋数',
    return_date           DATE NULL COMMENT '返却日（集荷日から日をまたいで後日になることが多い。返却は次回集荷と同じ訪問で行われることが多いため、pickup_dateとは独立して持つ）',
    return_time           TIME NULL COMMENT '返却時刻',
    return_employee_id    INT UNSIGNED NULL COMMENT '返却担当者',
    remarks               VARCHAR(255) NULL COMMENT '備考',
    deleted_at            DATETIME NULL COMMENT '論理削除日時（従業員・管理者による削除、NULLなら有効）',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cc_facility           FOREIGN KEY (facility_id)           REFERENCES facilities(id),
    CONSTRAINT fk_cc_pickup_employee    FOREIGN KEY (pickup_employee_id)    REFERENCES employees(id),
    CONSTRAINT fk_cc_arrival_employee   FOREIGN KEY (arrival_employee_id)   REFERENCES employees(id),
    CONSTRAINT fk_cc_arrival_facility   FOREIGN KEY (arrival_facility_id)   REFERENCES facilities(id),
    CONSTRAINT fk_cc_dispatch_employee  FOREIGN KEY (dispatch_employee_id) REFERENCES employees(id),
    CONSTRAINT fk_cc_dispatch_facility  FOREIGN KEY (dispatch_facility_id) REFERENCES facilities(id),
    CONSTRAINT fk_cc_return_employee    FOREIGN KEY (return_employee_id)   REFERENCES employees(id),
    INDEX idx_cc_facility_pickup_date (facility_id, pickup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='施設×集荷日を1サイクルとする集荷・配送記録簿（集荷→クリーニング所到着→発送→返却）。各工程は前工程が入力済みの直近未完了サイクルにのみ入力できる';

CREATE TABLE collection_cycle_edit_logs (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collection_cycle_id   INT UNSIGNED NOT NULL COMMENT '対象の集荷・配送サイクル',
    edited_by             INT UNSIGNED NOT NULL COMMENT '編集した従業員ID（従業員本人または管理者）',
    action                ENUM('create','update','delete') NOT NULL DEFAULT 'update' COMMENT '操作種別（deleteはdeleted_atによる論理削除の記録）',
    field_name            VARCHAR(50) NULL COMMENT '変更したフィールド名',
    old_value             VARCHAR(100) NULL COMMENT '変更前の値（削除時は削除前の値）',
    new_value             VARCHAR(100) NULL COMMENT '変更後の値（削除時は常にNULL）',
    edited_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cc_edit_logs_cycle    FOREIGN KEY (collection_cycle_id) REFERENCES collection_cycles(id),
    CONSTRAINT fk_cc_edit_logs_employee FOREIGN KEY (edited_by)           REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='集荷・配送記録簿（collection_cycles）の追加・修正・削除履歴';

CREATE TABLE consumable_stock_transactions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_type           ENUM('linen_bag_orange','linen_bag_yellow','linen_bag_blue','laundry_net') NOT NULL COMMENT '品目種別',
    quantity            INT NOT NULL COMMENT '増減数（入庫等プラス、出庫・使用等マイナス）',
    reason              ENUM('purchase','return_from_facility','disposal','loss','issuance_to_facility') NOT NULL COMMENT '増減理由（購入／施設等からの返却／廃棄／紛失／施設等への交付）',
    facility_id         INT UNSIGNED NULL COMMENT '対象施設等（施設等への交付／施設等からの返却の場合のみ）',
    collection_cycle_id INT UNSIGNED NULL COMMENT '発生源の集荷・配送記録（自動記録の場合のみ。この記録が削除された際、紐づく増減記録を取り消すために使用）',
    transaction_date    DATE NOT NULL COMMENT '発生日',
    note                VARCHAR(255) NULL COMMENT '理由・備考',
    created_by          INT UNSIGNED NOT NULL COMMENT '記録した管理者の従業員ID',
    canceled_at         DATETIME NULL COMMENT '取り消し日時（NULLなら有効）',
    canceled_by         INT UNSIGNED NULL COMMENT '取り消した管理者の従業員ID',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cst_created_by       FOREIGN KEY (created_by)          REFERENCES employees(id),
    CONSTRAINT fk_cst_canceled_by      FOREIGN KEY (canceled_by)         REFERENCES employees(id),
    CONSTRAINT fk_cst_facility         FOREIGN KEY (facility_id)         REFERENCES facilities(id),
    CONSTRAINT fk_cst_collection_cycle FOREIGN KEY (collection_cycle_id) REFERENCES collection_cycles(id),
    INDEX idx_cst_item_type (item_type),
    INDEX idx_cst_facility (facility_id),
    INDEX idx_cst_collection_cycle (collection_cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消耗品（リネン袋・洗濯ネット）在庫増減履歴';

-- 車両マスタ
CREATE TABLE vehicles (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plate_number   VARCHAR(20) NOT NULL COMMENT 'ナンバープレート',
    vehicle_name   VARCHAR(100) NULL COMMENT '号車名・呼称',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='車両マスタ';

-- 集荷前車両等チェック 点検項目マスタ
CREATE TABLE vehicle_check_items (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category       VARCHAR(50) NOT NULL COMMENT '項目カテゴリ（法定点呼・制動系・タイヤ等）',
    label          VARCHAR(255) NOT NULL COMMENT '点検項目文言',
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '表示順',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    requires_value TINYINT(1) NOT NULL DEFAULT 0 COMMENT '数値入力を伴うか（酒気帯び濃度等）',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='集荷前車両等チェック 点検項目マスタ';

-- 集荷前車両等チェック 記録ヘッダ
CREATE TABLE vehicle_checks (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT UNSIGNED NOT NULL COMMENT '点検を行った従業員',
    vehicle_id     INT UNSIGNED NOT NULL COMMENT '対象車両',
    attendance_id  INT UNSIGNED NULL COMMENT '紐づく出勤打刻（集荷区分での出勤確定時にセット）',
    check_date     DATE NOT NULL COMMENT '点検日',
    checked_at     DATETIME NOT NULL COMMENT '点検日時',
    alcohol_value  DECIMAL(4,2) NULL COMMENT '酒気帯びチェック値',
    overall_status ENUM('ok','issue') NOT NULL DEFAULT 'ok' COMMENT '総合判定',
    notes          TEXT NULL COMMENT '備考',
    created_by     INT UNSIGNED NOT NULL COMMENT '記録した従業員/管理者',
    updated_by     INT UNSIGNED NULL COMMENT '最終更新者',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME NULL COMMENT '論理削除日時（NULLなら有効）',
    CONSTRAINT fk_vc_employee   FOREIGN KEY (employee_id)   REFERENCES employees(id),
    CONSTRAINT fk_vc_vehicle    FOREIGN KEY (vehicle_id)    REFERENCES vehicles(id),
    CONSTRAINT fk_vc_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id),
    CONSTRAINT fk_vc_created_by FOREIGN KEY (created_by)    REFERENCES employees(id),
    CONSTRAINT fk_vc_updated_by FOREIGN KEY (updated_by)    REFERENCES employees(id),
    INDEX idx_vc_employee_date (employee_id, check_date),
    INDEX idx_vc_vehicle_date (vehicle_id, check_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='集荷前車両等チェック 記録ヘッダ（酒気帯び記録を含むため道路交通法施行規則により1年間保存必須。物理削除禁止）';

-- 集荷前車両等チェック 記録明細
CREATE TABLE vehicle_check_results (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_check_id INT UNSIGNED NOT NULL COMMENT '対象の点検記録ヘッダ',
    item_id          INT UNSIGNED NOT NULL COMMENT '点検項目',
    result           ENUM('ok','issue') NOT NULL COMMENT '判定結果',
    issue_note       TEXT NULL COMMENT '異常時の詳細',
    CONSTRAINT fk_vcr_check FOREIGN KEY (vehicle_check_id) REFERENCES vehicle_checks(id),
    CONSTRAINT fk_vcr_item  FOREIGN KEY (item_id)          REFERENCES vehicle_check_items(id),
    INDEX idx_vcr_check (vehicle_check_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='集荷前車両等チェック 記録明細（項目ごとの判定）';

-- 集荷前車両等チェック 変更履歴
CREATE TABLE vehicle_check_history (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_check_id INT UNSIGNED NOT NULL COMMENT '対象の点検記録ヘッダ',
    action           ENUM('create','update','delete') NOT NULL COMMENT '操作種別',
    changed_by       INT UNSIGNED NOT NULL COMMENT '操作した従業員/管理者',
    changed_by_role  ENUM('staff','admin') NOT NULL COMMENT '操作時の権限（employees.roleに合わせる）',
    before_data      JSON NULL COMMENT '変更前スナップショット（ヘッダ＋明細）',
    after_data       JSON NULL COMMENT '変更後スナップショット（ヘッダ＋明細、削除時はNULL）',
    changed_at       DATETIME NOT NULL COMMENT '操作日時',
    CONSTRAINT fk_vch_check      FOREIGN KEY (vehicle_check_id) REFERENCES vehicle_checks(id),
    CONSTRAINT fk_vch_changed_by FOREIGN KEY (changed_by)       REFERENCES employees(id),
    INDEX idx_vch_check (vehicle_check_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='集荷前車両等チェック（vehicle_checks）の追加・修正・削除履歴。物理削除禁止';

-- 車両管理記録（車検・保険・オイル・タイヤ）
CREATE TABLE vehicle_maintenance (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id         INT UNSIGNED NOT NULL COMMENT '対象車両',
    shaken_date        DATE NULL COMMENT '車検日',
    shaken_expiry      DATE NULL COMMENT '次回車検期限',
    jibaiseki_company  VARCHAR(100) NULL COMMENT '自賠責保険会社',
    jibaiseki_start    DATE NULL COMMENT '自賠責保険契約開始日',
    jibaiseki_end      DATE NULL COMMENT '自賠責保険契約終了日',
    ninni_company      VARCHAR(100) NULL COMMENT '任意保険会社',
    ninni_start        DATE NULL COMMENT '任意保険契約開始日',
    ninni_end          DATE NULL COMMENT '任意保険契約終了日',
    oil_change_date    DATE NULL COMMENT 'オイル交換日',
    battery_change_date DATE NULL COMMENT 'バッテリー交換日',
    battery_type        VARCHAR(20) NULL COMMENT 'バッテリー種類（例: 750D23L）',
    tire_change_date_front_right DATE NULL COMMENT 'タイヤ交換日（前輪右）',
    tire_change_date_front_left  DATE NULL COMMENT 'タイヤ交換日（前輪左）',
    tire_change_date_rear_left   DATE NULL COMMENT 'タイヤ交換日（後輪左）',
    tire_change_date_rear_right  DATE NULL COMMENT 'タイヤ交換日（後輪右）',
    notes              TEXT NULL COMMENT '備考',
    created_by         INT UNSIGNED NOT NULL COMMENT '記録した従業員/管理者',
    updated_by         INT UNSIGNED NULL COMMENT '最終更新者',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         DATETIME NULL COMMENT '論理削除日時（NULLなら有効）',
    CONSTRAINT fk_vm_vehicle    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    CONSTRAINT fk_vm_created_by FOREIGN KEY (created_by) REFERENCES employees(id),
    CONSTRAINT fk_vm_updated_by FOREIGN KEY (updated_by) REFERENCES employees(id),
    INDEX idx_vm_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='車両管理記録（車検・保険・オイル・タイヤ交換）';

-- 車両管理記録 変更履歴
CREATE TABLE vehicle_maintenance_history (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_maintenance_id INT UNSIGNED NOT NULL COMMENT '対象の車両管理記録',
    action                 ENUM('create','update','delete') NOT NULL COMMENT '操作種別',
    changed_by             INT UNSIGNED NOT NULL COMMENT '操作した従業員/管理者',
    changed_by_role        ENUM('staff','admin') NOT NULL COMMENT '操作時の権限（employees.roleに合わせる）',
    before_data            JSON NULL COMMENT '変更前スナップショット',
    after_data             JSON NULL COMMENT '変更後スナップショット（削除時はNULL）',
    changed_at             DATETIME NOT NULL COMMENT '操作日時',
    CONSTRAINT fk_vmh_maintenance FOREIGN KEY (vehicle_maintenance_id) REFERENCES vehicle_maintenance(id),
    CONSTRAINT fk_vmh_changed_by  FOREIGN KEY (changed_by)             REFERENCES employees(id),
    INDEX idx_vmh_maintenance (vehicle_maintenance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='車両管理記録（vehicle_maintenance）の追加・修正・削除履歴';

-- アラート閾値設定マスタ
CREATE TABLE vehicle_alert_settings (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_type     ENUM('shaken','jibaiseki','ninni','oil','tire','battery') NOT NULL COMMENT '警告種別',
    threshold_days INT UNSIGNED NOT NULL COMMENT '警告を出す閾値（日数）',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_vas_alert_type (alert_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='車両アラート閾値設定マスタ';

-- 連絡掲示板（ツリー無し。注意事項周知のためのシンプルな掲示板）
CREATE TABLE board_posts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_type  ENUM('driver','laundry') NOT NULL COMMENT '掲示板種別（driver=集荷ドライバー連絡掲示板／laundry=洗濯スタッフ連絡掲示板）',
    content     TEXT NOT NULL COMMENT '本文',
    created_by  INT UNSIGNED NOT NULL COMMENT '投稿した従業員/管理者',
    updated_by  INT UNSIGNED NULL COMMENT '最終編集した従業員/管理者（未編集ならNULL）',
    deleted_at  DATETIME NULL COMMENT '論理削除日時（管理者・従業員どちらでも削除可、NULLなら有効）',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_board_posts_created_by FOREIGN KEY (created_by) REFERENCES employees(id),
    CONSTRAINT fk_board_posts_updated_by FOREIGN KEY (updated_by) REFERENCES employees(id),
    INDEX idx_board_posts_type (board_type, deleted_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ダッシュボード掲示板（集荷ドライバー連絡／洗濯スタッフ連絡）。管理者・従業員どちらも追加・編集・削除可能';
