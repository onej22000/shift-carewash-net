-- 月次請求書 自動作成機能（admin/invoice*.php）用テーブル
-- 既存テーブルには触れない。inv_ プレフィックスのテーブルのみ作成する。
-- 作成日: 2026-10-02

CREATE TABLE IF NOT EXISTS inv_issuer (
  id TINYINT PRIMARY KEY DEFAULT 1,
  company_name VARCHAR(100) NOT NULL,
  representative VARCHAR(100),
  postal VARCHAR(10), address1 VARCHAR(200), address2 VARCHAR(200),
  tel VARCHAR(20),
  registration_no VARCHAR(14) NULL,        -- 適格請求書発行事業者登録番号 T+13桁
  bank_name VARCHAR(100), bank_branch VARCHAR(100),
  account_type VARCHAR(10), account_no VARCHAR(20), account_holder VARCHAR(100),
  next_invoice_no INT NOT NULL DEFAULT 10,
  payment_terms VARCHAR(200) NULL,          -- 任意（例：翌月末日までにお振込ください）
  updated_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_code VARCHAR(10),                  -- 請求書左の (003)
  name VARCHAR(100) NOT NULL, honorific VARCHAR(10) DEFAULT '御中',
  postal VARCHAR(10), address1 VARCHAR(200), address2 VARCHAR(200),
  tel VARCHAR(20),
  is_active TINYINT(1) DEFAULT 1,
  updated_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 単価は履歴管理。対象月の初日時点で有効な最新 effective_from を採用
CREATE TABLE IF NOT EXISTS inv_unit_prices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  facility_id INT NOT NULL,                 -- 既存施設マスタ（facilities）のID
  product_code VARCHAR(10) DEFAULT '004',
  item_name VARCHAR(200) NOT NULL,          -- 例：CareWash洗濯代行業務委託料（アルク平野長吉）
  unit VARCHAR(10) DEFAULT '人',
  unit_price INT NOT NULL,                  -- 税込単価
  effective_from DATE NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME,
  UNIQUE KEY uq_price (facility_id, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no INT NULL UNIQUE,               -- 確定時に採番。下書きはNULL
  billing_month CHAR(7) NOT NULL,           -- '2026-09'
  client_id INT NOT NULL,
  issue_date DATE NOT NULL,                 -- 売上日（請求書上の表示）
  status ENUM('draft','issued','void') NOT NULL DEFAULT 'draft',
  issuer_snapshot TEXT NULL,                -- 確定時の当方情報JSON
  client_snapshot TEXT NULL,                -- 確定時の請求先情報JSON
  total_incl INT NOT NULL DEFAULT 0,
  tax INT NOT NULL DEFAULT 0,
  total_excl INT NOT NULL DEFAULT 0,
  note TEXT NULL,
  issued_at DATETIME NULL, voided_at DATETIME NULL, void_reason TEXT NULL,
  created_at DATETIME, updated_at DATETIME,
  KEY idx_month (billing_month, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_invoice_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  sort_order INT NOT NULL,
  line_type ENUM('usage','adjustment','manual') NOT NULL,
  product_code VARCHAR(10),
  description VARCHAR(200) NOT NULL,
  quantity INT NOT NULL,                    -- 負数可（訂正）
  unit VARCHAR(10),
  unit_price INT NULL,                      -- 負数可（値引き）。NULL＝単価未登録（下書きのみ）
  amount INT NULL,                          -- quantity * unit_price。単価未登録なら NULL
  tax_rate DECIMAL(4,1) NOT NULL DEFAULT 10.0,
  facility_id INT NULL,
  adjustment_id INT NULL,
  KEY idx_inv (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  adj_type ENUM('correction','discount') NOT NULL,
  apply_month CHAR(7) NOT NULL,             -- 反映する請求月 '2026-09'
  target_month CHAR(7) NULL,                -- 訂正元の月 '2026-08'
  facility_id INT NULL,
  description VARCHAR(200) NOT NULL,        -- 請求書の商品名欄に出る文言
  quantity INT NOT NULL,
  unit_price INT NOT NULL,
  amount INT NOT NULL,
  reason TEXT,                              -- 社内メモ（請求書には出さない）
  applied_invoice_id INT NULL,              -- 確定した請求書ID（確定後は編集不可）
  created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2026-10-02 追記：単価未登録の施設も下書きに行を作るため、明細の単価・金額を NULL 可にする
-- （上の CREATE で作成済みの環境向け。何度実行しても同じ結果になる）
ALTER TABLE inv_invoice_lines MODIFY unit_price INT NULL, MODIFY amount INT NULL;

-- ---------------------------------------------------------------
-- 初期データ（2026年8月分請求書 No.00000009 より）
-- facilities.id: 12 = アルク枚方長尾, 13 = アルク平野長吉
-- ---------------------------------------------------------------
INSERT IGNORE INTO inv_issuer
  (id, company_name, representative, postal, address1, address2, tel, registration_no,
   bank_name, bank_branch, account_type, account_no, account_holder, next_invoice_no, payment_terms, updated_at)
VALUES
  (1, '合同会社BITBASE', '代表社員 西科潤一', '606-0856', '京都府京都市左京区下鴨塚本町1-101', NULL, '090-6249-9612', NULL,
   'ドコモSMBCネット銀行', '法人第一支店', '普通', '1284631', '合同会社BITBASE 代表社員 西科潤一', 10, NULL, NOW());

INSERT INTO inv_clients (client_code, name, honorific, postal, address1, address2, tel, is_active, updated_at)
SELECT '003', '株式会社CareWash', '御中', '320-0051', '栃木県宇都宮市上戸祭町', '3014番地3', '028-680-4970', 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM inv_clients WHERE client_code = '003');

INSERT IGNORE INTO inv_unit_prices
  (client_id, facility_id, product_code, item_name, unit, unit_price, effective_from, is_active, created_at)
SELECT c.id, 13, '004', 'CareWash洗濯代行業務委託料（アルク平野長吉）', '人', 3325, '2026-08-01', 1, NOW()
FROM inv_clients c WHERE c.client_code = '003';

INSERT IGNORE INTO inv_unit_prices
  (client_id, facility_id, product_code, item_name, unit, unit_price, effective_from, is_active, created_at)
SELECT c.id, 12, '004', 'CareWash洗濯代行業務委託料（アルク枚方長尾）', '人', 3325, '2026-08-01', 1, NOW()
FROM inv_clients c WHERE c.client_code = '003';
