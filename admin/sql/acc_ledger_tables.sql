-- 帳簿機能（総勘定元帳・試算表・決算書・取引入力・領収書の添付）用テーブル
-- acc_tables.sql の後に実行する。既存テーブルへの変更は acc_manual の列追加（kind / counterparty / updated_at）のみ。何度実行しても同じ結果になる。
-- 作成日: 2026-10-10

-- 帳簿ごとの設定（記帳開始年度・会社情報）
CREATE TABLE IF NOT EXISTS acc_book_settings (
  book_id INT UNSIGNED NOT NULL PRIMARY KEY,
  opening_fy SMALLINT UNSIGNED NULL COMMENT 'このシステムで記帳を始める最初の事業年度（開始年）。期首残高はこの年度の期首のもの',
  profile_json MEDIUMTEXT NULL COMMENT '会社・個人の基本情報（申告書・決算書の見出しに使う）',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_acc_book_settings_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='帳簿の設定';

-- 勘定科目マスタ（決算書の表示区分・勘定科目内訳書の種類を持つ）。空のときは includes/accounting_ledger.php の標準科目を取り込む
CREATE TABLE IF NOT EXISTS acc_chart (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  category ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  section VARCHAR(30) NOT NULL COMMENT '決算書の表示区分（流動資産・販売費及び一般管理費 など）',
  uchiwake VARCHAR(20) NULL COMMENT '勘定科目内訳書の種類（預貯金・売掛金・借入金 など）',
  is_contra TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=評価勘定（減価償却累計額・貸倒引当金など。資産の控除）',
  default_tax VARCHAR(40) NOT NULL DEFAULT '対象外',
  sort_no INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_acc_chart (book_id, name),
  CONSTRAINT fk_acc_chart_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='勘定科目マスタ';

-- 期首残高（記帳開始年度の期首の貸借対照表）。借方残高を正、貸方残高を負で持つ
CREATE TABLE IF NOT EXISTS acc_opening (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  account VARCHAR(60) NOT NULL,
  sub VARCHAR(60) NOT NULL DEFAULT '',
  amount BIGINT NOT NULL COMMENT '借方残高=正 / 貸方残高=負',
  UNIQUE KEY uq_acc_opening (book_id, account, sub),
  CONSTRAINT fk_acc_opening_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='期首残高';

-- 手入力の仕訳に、入力の種類と取引先を持たせる
ALTER TABLE acc_manual ADD COLUMN IF NOT EXISTS kind VARCHAR(10) NOT NULL DEFAULT 'journal' COMMENT 'in=入金 out=出金・経費 xfer=口座間振替 journal=振替伝票';
ALTER TABLE acc_manual ADD COLUMN IF NOT EXISTS counterparty VARCHAR(100) NULL COMMENT '取引先';
ALTER TABLE acc_manual ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

-- 領収書・請求書などの証憑（電子帳簿保存法の検索項目: 取引年月日・取引金額・取引先を持つ。削除は無効化のみ）
CREATE TABLE IF NOT EXISTS acc_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  manual_id INT UNSIGNED NULL COMMENT '紐づく手入力の仕訳（acc_manual.id）',
  doc_date DATE NULL COMMENT '取引年月日',
  doc_amount BIGINT NULL COMMENT '取引金額',
  counterparty VARCHAR(100) NULL COMMENT '取引先',
  memo VARCHAR(200) NULL,
  original_name VARCHAR(200) NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  KEY idx_acc_attachments_book (book_id, doc_date),
  KEY idx_acc_attachments_manual (manual_id),
  CONSTRAINT fk_acc_attachments_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='証憑ファイル';

-- 手入力の仕訳・証憑の追加／訂正／削除の履歴
CREATE TABLE IF NOT EXISTS acc_audit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  target VARCHAR(20) NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  action VARCHAR(10) NOT NULL,
  detail MEDIUMTEXT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acc_audit (book_id, target, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂正削除履歴';

-- 固定資産台帳（減価償却の計算元。年末の減価償却費・売却除却の仕訳は includes/accounting_assets.php が自動で作る）
CREATE TABLE IF NOT EXISTS acc_assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  asset_account VARCHAR(60) NOT NULL COMMENT '資産の勘定科目（建物・工具器具備品など）',
  ledger_sub VARCHAR(60) NULL COMMENT '仕訳の補助科目（空なら補助なし）',
  acquired_date DATE NOT NULL COMMENT '取得年月日',
  service_date DATE NOT NULL COMMENT '事業の用に供した日（償却開始）',
  cost BIGINT NOT NULL COMMENT '取得価額（帳簿の経理方式のまま。税込経理なら税込）',
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  method ENUM('sl','db','lump','small','small_expensed','manual','none') NOT NULL DEFAULT 'sl' COMMENT 'sl=定額法 db=定率法(200%) lump=一括償却(3年) small=少額減価償却資産(全額) small_expensed=少額(取得時に経費計上済み・台帳のみ) manual=償却額を指定 none=非償却(土地など)',
  life TINYINT UNSIGNED NULL COMMENT '耐用年数',
  manual_annual BIGINT NULL COMMENT 'method=manual の年間償却額',
  prior_accum BIGINT NOT NULL DEFAULT 0 COMMENT '記帳開始前の償却累計額（method=manual のとき計算の起点。ほかは確認用）',
  business_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT '事業専用割合（%）。個人の家事按分用',
  location VARCHAR(100) NULL,
  memo VARCHAR(200) NULL,
  status ENUM('active','disposed') NOT NULL DEFAULT 'active',
  disposed_date DATE NULL,
  disposal_kind ENUM('sale','scrap') NULL,
  disposal_price BIGINT NULL,
  disposal_account VARCHAR(60) NULL COMMENT '売却代金の入金先科目',
  source_manual_id INT UNSIGNED NULL COMMENT '取得の取引（acc_manual.id）',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acc_assets_book (book_id),
  CONSTRAINT fk_acc_assets_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='固定資産台帳';

-- 勘定科目内訳書の補足情報（金融機関の支店名・口座番号、取引先の所在地、借入金の利率・担保など）。決算書の数字そのものは持たない
CREATE TABLE IF NOT EXISTS acc_sub_info (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id INT UNSIGNED NOT NULL,
  form_code VARCHAR(10) NOT NULL COMMENT '内訳書の番号（uc01 など）',
  account VARCHAR(60) NOT NULL,
  sub VARCHAR(60) NOT NULL DEFAULT '',
  data_json MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acc_sub_info (book_id, form_code, account, sub),
  CONSTRAINT fk_acc_sub_info_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内訳書の補足情報';

-- 税務の設定・調整（事業年度ごと。消費税の方式、中間納付額、地方税の税率、別表四の調整項目、繰越欠損金など）
CREATE TABLE IF NOT EXISTS acc_tax_year (
  book_id INT UNSIGNED NOT NULL,
  fiscal_year SMALLINT UNSIGNED NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (book_id, fiscal_year),
  CONSTRAINT fk_acc_tax_year_book FOREIGN KEY (book_id) REFERENCES acc_books (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='税務の設定・調整';
