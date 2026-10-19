-- 弥生会計 連携機能（admin/accounting*.php）用テーブル
-- 既存テーブルには触れない。acc_ プレフィックスのテーブルのみ作成する。何度実行しても同じ結果になる。
-- 作成日: 2026-10-10

-- 帳簿（会社・個人ごとに弥生会計のデータファイルが別になるため、仕訳の出力先を分ける）
CREATE TABLE IF NOT EXISTS acc_books (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  kind ENUM('corporate','personal') NOT NULL DEFAULT 'corporate',
  fiscal_start_month TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '事業年度の開始月（1〜12）',
  use_invoice TINYINT(1) NOT NULL DEFAULT 0 COMMENT '請求書（inv_invoices）から売上仕訳を作る',
  use_payroll TINYINT(1) NOT NULL DEFAULT 0 COMMENT '給与（pay_runs/pay_slips）から給与仕訳を作る',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acc_books_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弥生会計の帳簿';

-- 弥生の勘定科目一覧（汎用形式のCSVを取り込む）。科目名の存在確認・手入力の候補表示に使う
CREATE TABLE IF NOT EXISTS acc_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  code VARCHAR(10) NULL,
  side VARCHAR(10) NULL COMMENT '貸借区分（借方/貸方）',
  tax_class VARCHAR(40) NULL COMMENT '弥生側の既定の税区分',
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_acc_accounts (book_id, name),
  CONSTRAINT fk_acc_accounts_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弥生の勘定科目';

-- 仕訳設定（請求書・給与の各項目をどの勘定科目・補助科目・税区分で仕訳するか）。保存が無い項目は includes/accounting.php の初期値
CREATE TABLE IF NOT EXISTS acc_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  event_key VARCHAR(30) NOT NULL,
  account VARCHAR(60) NOT NULL,
  sub VARCHAR(60) NULL COMMENT '補助科目。{client}=請求先名 {employee}=従業員名',
  tax_class VARCHAR(40) NOT NULL DEFAULT '対象外',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acc_map (book_id, event_key),
  CONSTRAINT fk_acc_map_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仕訳設定';

-- 定型仕訳（毎月の概算計上・戻しなど。決算整理仕訳にもなる）
CREATE TABLE IF NOT EXISTS acc_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  debit_account VARCHAR(60) NOT NULL, debit_sub VARCHAR(60) NULL, debit_tax VARCHAR(40) NOT NULL DEFAULT '対象外',
  credit_account VARCHAR(60) NOT NULL, credit_sub VARCHAR(60) NULL, credit_tax VARCHAR(40) NOT NULL DEFAULT '対象外',
  amount INT NOT NULL COMMENT '税込金額（毎回同額）',
  months VARCHAR(30) NOT NULL DEFAULT 'all' COMMENT 'all または 1,2,3 のように計上する月（暦月）',
  day_rule VARCHAR(4) NOT NULL DEFAULT 'last' COMMENT 'last=月末 または 1〜28の日',
  from_month CHAR(7) NOT NULL COMMENT '計上開始月 YYYY-MM',
  to_month CHAR(7) NULL COMMENT '計上終了月 YYYY-MM（空=継続）',
  reverse_rule ENUM('none','next_month','fy_start') NOT NULL DEFAULT 'none' COMMENT 'none=戻さない next_month=翌月末に同額を戻す fy_start=翌期首月の末日に前期の累計を戻す',
  is_settlement TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=弥生の「決算」欄を「本期」にする（決算整理仕訳）',
  description VARCHAR(100) NULL COMMENT '摘要（空なら名称）',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acc_templates_book (book_id),
  CONSTRAINT fk_acc_templates_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定型仕訳';

-- 手入力の仕訳（個人の不動産収入・暗号資産の雑所得、システムに無い会社の取引など）
CREATE TABLE IF NOT EXISTS acc_manual (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  debit_account VARCHAR(60) NOT NULL, debit_sub VARCHAR(60) NULL, debit_tax VARCHAR(40) NOT NULL DEFAULT '対象外', debit_tax_amount INT NULL,
  credit_account VARCHAR(60) NOT NULL, credit_sub VARCHAR(60) NULL, credit_tax VARCHAR(40) NOT NULL DEFAULT '対象外', credit_tax_amount INT NULL,
  amount INT NOT NULL,
  description VARCHAR(100) NULL,
  is_settlement TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acc_manual_book (book_id, entry_date),
  CONSTRAINT fk_acc_manual_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手入力の仕訳';

-- 出力履歴
CREATE TABLE IF NOT EXISTS acc_exports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  period_from CHAR(7) NOT NULL,
  period_to CHAR(7) NOT NULL,
  mode ENUM('new','all') NOT NULL DEFAULT 'new',
  voucher_start INT UNSIGNED NOT NULL DEFAULT 1,
  entry_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_debit BIGINT NOT NULL DEFAULT 0,
  file_name VARCHAR(100) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acc_exports_book (book_id, created_at),
  CONSTRAINT fk_acc_exports_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弥生会計への仕訳CSV出力履歴';

-- 出力済みの仕訳（キー・版数ごと）。元データが変わった／無くなったときに逆仕訳を出すための記録
CREATE TABLE IF NOT EXISTS acc_export_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  entry_key VARCHAR(40) NOT NULL COMMENT 'I=請求書 P=給与 T=定型仕訳 M=手入力 ＋ID',
  revision SMALLINT UNSIGNED NOT NULL,
  is_reversal TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=この版を取り消す逆仕訳を出力した',
  row_hash CHAR(40) NOT NULL,
  entry_json MEDIUMTEXT NOT NULL COMMENT '出力した仕訳（逆仕訳の行も元の向きで保存）',
  export_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_acc_export_items (book_id, entry_key, revision, is_reversal),
  KEY idx_acc_export_items_export (export_id),
  CONSTRAINT fk_acc_export_items_book FOREIGN KEY (book_id) REFERENCES acc_books (id),
  CONSTRAINT fk_acc_export_items_export FOREIGN KEY (export_id) REFERENCES acc_exports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='出力済みの仕訳';

-- 初期の帳簿。シフトシステムの請求書・給与は合同会社BITBASE（inv_issuer の発行元）のものなので、BITBASEだけ自動作成を有効にする
INSERT INTO acc_books (code, name, kind, fiscal_start_month, use_invoice, use_payroll)
SELECT 'bitbase', '合同会社BITBASE', 'corporate', 1, 1, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acc_books WHERE code = 'bitbase');
INSERT INTO acc_books (code, name, kind, fiscal_start_month, use_invoice, use_payroll)
SELECT 'clearbase', '合同会社クリアベース', 'corporate', 1, 0, 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acc_books WHERE code = 'clearbase');
INSERT INTO acc_books (code, name, kind, fiscal_start_month, use_invoice, use_payroll)
SELECT 'personal', '西科潤一（個人）', 'personal', 1, 0, 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acc_books WHERE code = 'personal');
