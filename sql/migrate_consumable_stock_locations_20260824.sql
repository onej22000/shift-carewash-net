-- 消耗品在庫を「倉庫」「フトン巻きのジロー」に分ける。
-- 既存履歴と、stock_locationを指定しない既存の自動記録は warehouse（倉庫）として扱う。
ALTER TABLE consumable_stock_transactions
    ADD COLUMN stock_location ENUM('warehouse','jiro') NOT NULL DEFAULT 'warehouse'
        COMMENT '在庫場所（倉庫／フトン巻きのジロー）' AFTER item_type,
    MODIFY COLUMN reason ENUM(
        'purchase',
        'return_from_facility',
        'disposal',
        'loss',
        'issuance_to_facility',
        'stock_adjustment'
    ) NOT NULL COMMENT '増減理由（購入／施設等からの返却／廃棄／紛失／施設等への交付／実在庫補正）',
    ADD INDEX idx_cst_location_item (stock_location, item_type);
