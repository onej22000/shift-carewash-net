<?php
/**
 * 消耗品在庫管理（admin/consumable_stock.php・staff/consumable_stock.php）で共有する
 * 品目一覧・在庫計算・登録処理。認証（管理者/従業員のセッション区別）はここでは扱わず、
 * 呼び出し元が require_login('admin'|'staff') 済みであることを前提とする。
 * 品目そのものの一覧取得は includes/functions.php の get_consumable_items()/
 * get_consumable_item_labels()（consumable_itemsテーブル参照）を両画面からそのまま使う。
 */

const CONSUMABLE_STOCK_LOCATION_LABELS = [
    'warehouse' => '倉庫＋車',
    'jiro' => 'フトン巻きのジロー',
];

const JIRO_FACILITY_NAME = 'フトン巻きのジロー';

const CONSUMABLE_REASON_LABELS = [
    'purchase' => '購入',
    'return_from_facility' => '施設等からの返却',
    'disposal' => '廃棄',
    'loss' => '紛失',
    'issuance_to_facility' => '施設等への交付',
    'stock_adjustment' => '実在庫への補正',
];

// この理由の場合のみ対象施設等の選択を必須にする（購入・廃棄・紛失は施設に紐づかない）
const CONSUMABLE_REASONS_REQUIRING_FACILITY = ['return_from_facility', 'issuance_to_facility'];

// 増減理由ごとに許される増減数の符号。矛盾する符号での登録（例：「施設等への交付」を選びながら
// プラスの数量を入力し、在庫が誤って増える方向に記録される）を防ぐための整合性チェックに使う。
const CONSUMABLE_REASON_SIGN = [
    'purchase' => 'positive',
    'return_from_facility' => 'positive',
    'disposal' => 'negative',
    'loss' => 'negative',
    'issuance_to_facility' => 'negative',
];

// consumable_items.usage_type の表示ラベル。admin/staffの品目管理画面（consumable_items.php）
// からも参照する。2026-09-14〜の未コミット状態ではこの定数の定義が漏れており、
// 両画面ともFatal errorで開けなくなっていた（今回の対応で合わせて解消）。
const CONSUMABLE_ITEM_USAGE_TYPE_LABELS = [
    'pickup' => '集荷用',
    'return' => '返却用',
    'none' => '該当なし',
];

// employees.role の表示ラベル。在庫増減履歴に「操作した人（管理者か従業員か）」を
// 表示するために使う（employees.role・created_by/canceled_byは既存カラムのみで足りるため
// ALTERは不要）。
const CONSUMABLE_STOCK_ROLE_LABELS = [
    'admin' => '管理者',
    'staff' => '従業員',
];

/**
 * 在庫場所×品目の現在庫を1件だけ計算する（実在庫補正フォームの表示用）。
 * 「倉庫＋車」からフトン巻きのジローへの交付・返却は、ジロー側では仮想的な在庫移動として
 * 効かせる（stock_locationカラム上は'warehouse'のまま、facility名がジローの行だけ符号反転して
 * 合算する）。
 */
function get_effective_consumable_stock(PDO $pdo, string $stockLocation, string $itemType): int
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(delta), 0) FROM (
             SELECT quantity AS delta FROM consumable_stock_transactions
             WHERE stock_location = ? AND item_type = ? AND canceled_at IS NULL
             UNION ALL
             SELECT -t.quantity AS delta FROM consumable_stock_transactions t
             INNER JOIN facilities f ON f.id = t.facility_id
             WHERE ? = 'jiro' AND t.stock_location = 'warehouse' AND t.item_type = ?
               AND t.reason IN ('issuance_to_facility', 'return_from_facility')
               AND f.name = ? AND t.canceled_at IS NULL
         ) effective_stock"
    );
    $stmt->execute([$stockLocation, $itemType, $stockLocation, $itemType, JIRO_FACILITY_NAME]);
    return (int) $stmt->fetchColumn();
}

/**
 * 現在庫表（在庫場所×品目の一覧）をまとめて計算する。$itemTypeKeys は集計対象の品目キー一覧
 * （通常は get_consumable_item_labels($pdo, true) のキー＝有効品目のみ）。
 */
function calc_consumable_stock_totals(PDO $pdo, array $itemTypeKeys): array
{
    $stockTotals = [];
    foreach (CONSUMABLE_STOCK_LOCATION_LABELS as $locationKey => $_locationLabel) {
        $stockTotals[$locationKey] = array_fill_keys($itemTypeKeys, 0);
    }

    $totalsStmt = $pdo->prepare(
        "SELECT stock_location, item_type, SUM(quantity) AS total FROM (
             SELECT stock_location, item_type, quantity FROM consumable_stock_transactions WHERE canceled_at IS NULL
             UNION ALL
             SELECT 'jiro', t.item_type, -t.quantity FROM consumable_stock_transactions t
             INNER JOIN facilities f ON f.id = t.facility_id
             WHERE t.stock_location = 'warehouse'
               AND t.reason IN ('issuance_to_facility', 'return_from_facility')
               AND f.name = ? AND t.canceled_at IS NULL
         ) effective_stock GROUP BY stock_location, item_type"
    );
    $totalsStmt->execute([JIRO_FACILITY_NAME]);
    foreach ($totalsStmt->fetchAll() as $row) {
        if (isset($stockTotals[$row['stock_location']][$row['item_type']])) {
            $stockTotals[$row['stock_location']][$row['item_type']] = (int) $row['total'];
        }
    }

    return $stockTotals;
}

/**
 * 在庫記録の追加・編集フォームの入力値を解釈する。$validItemTypes は選択を許す品目キー一覧
 * （呼び出し元が有効品目のみ／全品目のどちらを渡すかを決める）。
 */
function parse_consumable_stock_input(array $post, array $validFacilityIds, array $validItemTypes): array
{
    $itemType = (string) ($post['item_type'] ?? '');
    $stockLocation = (string) ($post['stock_location'] ?? 'warehouse');

    // 選択した場所の在庫について、入力は常に正数（増減の大きさ）のみを受け付け、
    // 実際の符号（＋／－）は増減理由から自動的に決定する（下記 CONSUMABLE_REASON_SIGN 参照）。
    $quantityRaw = trim((string) ($post['quantity'] ?? ''));
    $quantityMagnitude = $quantityRaw === '' || !preg_match('/^\d+$/', $quantityRaw) ? null : (int) $quantityRaw;

    $reason = (string) ($post['reason'] ?? '');
    $reason = array_key_exists($reason, CONSUMABLE_REASON_SIGN) ? $reason : null;

    $facilityIdRaw = trim((string) ($post['facility_id'] ?? ''));
    $facilityId = $facilityIdRaw === '' ? null : (int) $facilityIdRaw;
    if ($facilityId !== null && !in_array($facilityId, $validFacilityIds, true)) {
        $facilityId = false;
    }

    $transactionDateRaw = trim((string) ($post['transaction_date'] ?? ''));
    $transactionDate = null;
    if ($transactionDateRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $transactionDateRaw);
        $transactionDate = $dt !== false ? $dt->format('Y-m-d') : false;
    }

    $note = trim((string) ($post['note'] ?? ''));
    $note = $note === '' ? null : $note;

    $errors = [];
    if (!in_array($itemType, $validItemTypes, true)) {
        $errors[] = '品目を選択してください。';
    }
    if (!array_key_exists($stockLocation, CONSUMABLE_STOCK_LOCATION_LABELS)) {
        $errors[] = '在庫場所を選択してください。';
    }
    if ($quantityMagnitude === null || $quantityMagnitude === 0) {
        $errors[] = '増減数は1以上の整数を正の数で入力してください。';
    }
    if ($reason === null) {
        $errors[] = '増減理由を選択してください。';
    }
    if ($facilityId === false) {
        $errors[] = '対象施設等が正しくありません。';
    } elseif ($reason !== null && $facilityId === null && in_array($reason, CONSUMABLE_REASONS_REQUIRING_FACILITY, true)) {
        $errors[] = '「' . CONSUMABLE_REASON_LABELS[$reason] . '」を選択した場合は対象施設等を選択してください。';
    }
    if ($transactionDate === false || $transactionDate === null) {
        $errors[] = '発生日の形式が正しくありません。';
    }

    // 理由が施設等に紐づかない場合（購入・廃棄・紛失）は施設等の指定を無視する
    if ($reason !== null && !in_array($reason, CONSUMABLE_REASONS_REQUIRING_FACILITY, true)) {
        $facilityId = null;
    }

    // 入力された正数の増減幅に、増減理由に応じた符号を自動で付与する
    $quantity = null;
    if ($quantityMagnitude !== null && $reason !== null && isset(CONSUMABLE_REASON_SIGN[$reason])) {
        $quantity = CONSUMABLE_REASON_SIGN[$reason] === 'negative' ? -$quantityMagnitude : $quantityMagnitude;
    }

    return [
        [
            'item_type' => $itemType,
            'stock_location' => $stockLocation,
            'quantity' => $quantity,
            'reason' => $reason,
            'facility_id' => $facilityId === false ? null : $facilityId,
            'transaction_date' => $transactionDate,
            'note' => $note,
        ],
        $errors,
    ];
}

/**
 * 交付・廃棄・紛失など在庫を減らす方向の登録で、在庫がマイナスになる場合はエラー文言を返す
 * （増える方向＝$signedQuantity >= 0 の場合は常にnull）。新規登録（追加・交付）にのみ適用し、
 * 既存記録の編集（admin側のupdateアクション）には適用しない（対象記録自身の寄与分を除外する
 * 必要が生じ複雑になるため、今回のスコープでは新規登録時のみのガードとする）。
 */
function ensure_consumable_stock_sufficient(PDO $pdo, string $stockLocation, string $itemType, int $signedQuantity): ?string
{
    if ($signedQuantity >= 0) {
        return null;
    }

    $currentStock = get_effective_consumable_stock($pdo, $stockLocation, $itemType);
    if ($currentStock + $signedQuantity < 0) {
        return sprintf(
            '在庫が不足しているため登録できません（現在庫 %d枚に対し %d枚を交付・減算しようとしています）。',
            $currentStock,
            -$signedQuantity
        );
    }

    return null;
}

/**
 * parse_consumable_stock_input() の戻り値をそのまま consumable_stock_transactions に登録する。
 */
function insert_consumable_stock_transaction(PDO $pdo, array $values, int $createdBy): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO consumable_stock_transactions (item_type, stock_location, quantity, reason, facility_id, transaction_date, note, created_by)
         VALUES (:item_type, :stock_location, :quantity, :reason, :facility_id, :transaction_date, :note, :created_by)'
    );
    $stmt->execute([
        ':item_type' => $values['item_type'],
        ':stock_location' => $values['stock_location'],
        ':quantity' => $values['quantity'],
        ':reason' => $values['reason'],
        ':facility_id' => $values['facility_id'],
        ':transaction_date' => $values['transaction_date'],
        ':note' => $values['note'],
        ':created_by' => $createdBy,
    ]);

    return (int) $pdo->lastInsertId();
}
