-- 見積書：汚物miniオプション（入居者数 × 500円・税抜）の列を追加
-- 作成日: 2026-10-19
ALTER TABLE quotations
  ADD COLUMN has_odor_mini_option TINYINT(1) NOT NULL DEFAULT 0 COMMENT '汚物miniオプションの有無' AFTER unit_price,
  ADD COLUMN odor_mini_unit_price INT UNSIGNED NULL COMMENT '汚物miniオプション単価（税抜・円/人・月）、既定500' AFTER has_odor_mini_option;
