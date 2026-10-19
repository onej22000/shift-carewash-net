-- 見積書機能（admin/quotation*.php）用テーブル
-- 既存テーブルには触れない。quotations テーブルのみ作成する。
-- 作成日: 2026-10-19

CREATE TABLE IF NOT EXISTS quotations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_no VARCHAR(20) NOT NULL COMMENT '見積番号 Q-YYYYMMDD-001（発行日ごとの連番）',
  facility_id INT UNSIGNED NULL COMMENT 'facilities.id（手入力の宛先はNULL）',
  recipient_name VARCHAR(100) NOT NULL COMMENT '宛先（施設名・会社名）',
  recipient_honorific VARCHAR(10) NOT NULL DEFAULT '御中' COMMENT '敬称',
  recipient_address VARCHAR(255) NULL COMMENT '宛先住所',
  contact_person VARCHAR(100) NULL COMMENT '宛先の担当者名',
  resident_count INT UNSIGNED NOT NULL COMMENT '入居者数（見積作成時に手入力）',
  unit_price INT UNSIGNED NOT NULL COMMENT '税抜単価（円/人・月）、既定4500',
  subtotal_excl INT UNSIGNED NOT NULL COMMENT '税抜金額 = 入居者数 × 単価',
  tax_rate TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT '消費税率（%）',
  tax_amount INT UNSIGNED NOT NULL COMMENT '消費税（1円未満切り捨て）',
  total_incl INT UNSIGNED NOT NULL COMMENT '税込金額',
  issue_date DATE NOT NULL COMMENT '発行日',
  valid_until DATE NOT NULL COMMENT '有効期限（既定：発行日の1か月後）',
  note TEXT NULL COMMENT '備考',
  issuer_snapshot TEXT NOT NULL COMMENT '発行時の発行元情報JSON（inv_issuerとは独立）',
  created_by INT UNSIGNED NOT NULL COMMENT 'employees.id（作成した管理者）',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_quote_no (quote_no),
  KEY idx_issue_date (issue_date),
  KEY idx_facility (facility_id),
  CONSTRAINT fk_quotations_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL,
  CONSTRAINT fk_quotations_created_by FOREIGN KEY (created_by) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='見積書';
