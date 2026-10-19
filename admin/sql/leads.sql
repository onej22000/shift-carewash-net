-- 営業先一覧（admin/leads.php・lead_view.php）用テーブル
-- 見積書（quotations）の発行時に宛先から営業先を自動作成・紐付けする。既存の facilities 等には触れない。
-- 作成日: 2026-10-19

CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL COMMENT '施設名・会社名（最初の見積書の宛先から）',
  address VARCHAR(255) NULL COMMENT '住所',
  contact_person VARCHAR(100) NULL COMMENT '担当者名',
  name_key VARCHAR(100) NOT NULL COMMENT '重複判定用：施設名を正規化したもの（全角/半角・空白を統一）',
  address_key VARCHAR(255) NOT NULL DEFAULT '' COMMENT '重複判定用：住所を正規化したもの',
  facility_id INT UNSIGNED NULL COMMENT 'facilities.id（成約後に施設登録した場合など、任意）',
  status ENUM('提案済み','検討中','成約','失注') NOT NULL DEFAULT '提案済み' COMMENT '商談ステータス（手動でのみ変更）',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_leads_match (name_key, address_key),
  KEY idx_leads_facility (facility_id),
  KEY idx_leads_status (status),
  CONSTRAINT fk_leads_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_created_by FOREIGN KEY (created_by) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='営業先（見積書の宛先から自動作成）';

CREATE TABLE IF NOT EXISTS lead_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NOT NULL,
  kind ENUM('memo','status','quotation') NOT NULL DEFAULT 'memo' COMMENT 'memo=手入力メモ（修正・削除可）／status=ステータス変更の自動記録／quotation=見積書発行の自動記録（自動記録は修正・削除不可）',
  content TEXT NOT NULL COMMENT 'メモ本文、または「検討中 → 成約」「Q-20261019-001 を発行（¥297,000）」',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL COMMENT 'メモを最後に修正した管理者（未修正ならNULL）',
  updated_at DATETIME NULL COMMENT 'メモの最終修正日時（未修正ならNULL）',
  KEY idx_lead_notes_lead (lead_id, created_at),
  CONSTRAINT fk_lead_notes_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lead_notes_created_by FOREIGN KEY (created_by) REFERENCES employees(id),
  CONSTRAINT fk_lead_notes_updated_by FOREIGN KEY (updated_by) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='営業先のメモ・ステータス変更・見積発行の時系列';

-- 1つの営業先に複数の見積書を紐づける。営業先を削除しても見積書は残り、紐付けだけ外れる
ALTER TABLE quotations
  ADD COLUMN lead_id INT UNSIGNED NULL COMMENT 'leads.id' AFTER facility_id,
  ADD KEY idx_quotations_lead (lead_id),
  ADD CONSTRAINT fk_quotations_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL;
