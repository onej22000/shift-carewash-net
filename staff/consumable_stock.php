<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

const CONSUMABLE_ITEM_LABELS = [
    'linen_bag_orange' => 'リネン袋（オレンジ／集荷用）',
    'linen_bag_yellow' => 'リネン袋（黄／集荷用）',
    'linen_bag_blue' => 'リネン袋（青／返却用）',
    'laundry_net' => '洗濯ネット',
];

const CONSUMABLE_STOCK_LOCATION_LABELS = [
    'warehouse' => '倉庫＋車',
    'jiro' => 'フトン巻きのジロー',
];

const JIRO_FACILITY_NAME = 'フトン巻きのジロー';

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

const CONSUMABLE_REASON_LABELS = [
    'purchase' => '購入',
    'return_from_facility' => '施設等からの返却',
    'disposal' => '廃棄',
    'loss' => '紛失',
    'issuance_to_facility' => '施設等への交付',
    'stock_adjustment' => '実在庫への補正',
];

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } elseif ((string) ($_POST['action'] ?? '') === 'adjust_stock') {
        $itemType = (string) ($_POST['item_type'] ?? '');
        $stockLocation = (string) ($_POST['stock_location'] ?? '');
        $actualQuantityRaw = trim((string) ($_POST['actual_quantity'] ?? ''));
        $actualQuantity = preg_match('/^\d+$/', $actualQuantityRaw) ? (int) $actualQuantityRaw : null;
        $adjustmentNote = trim((string) ($_POST['note'] ?? ''));

        if (!array_key_exists($itemType, CONSUMABLE_ITEM_LABELS)
            || !array_key_exists($stockLocation, CONSUMABLE_STOCK_LOCATION_LABELS)
            || $actualQuantity === null) {
            $errorMessage = '在庫場所・品目・現在の実数を正しく入力してください。';
        } else {
            $currentQuantity = get_effective_consumable_stock($pdo, $stockLocation, $itemType);
            $delta = $actualQuantity - $currentQuantity;

            if ($delta !== 0) {
                $note = '実在庫への補正（' . $currentQuantity . '枚 → ' . $actualQuantity . '枚）';
                if ($adjustmentNote !== '') {
                    $note .= '：' . $adjustmentNote;
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO consumable_stock_transactions
                        (item_type, stock_location, quantity, reason, transaction_date, note, created_by)
                     VALUES
                        (:item_type, :stock_location, :quantity, 'stock_adjustment', :transaction_date, :note, :created_by)"
                );
                $stmt->execute([
                    ':item_type' => $itemType,
                    ':stock_location' => $stockLocation,
                    ':quantity' => $delta,
                    ':transaction_date' => (new DateTime())->format('Y-m-d'),
                    ':note' => $note,
                    ':created_by' => $staff['id'],
                ]);
                set_flash('success', CONSUMABLE_STOCK_LOCATION_LABELS[$stockLocation] . 'の実在庫に補正しました。');
            } else {
                set_flash('success', '入力された実数は現在庫と同じため、変更はありません。');
            }
            header('Location: /staff/consumable_stock.php');
            exit;
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

// ---- 現在庫の集計 ----
$stockTotals = [];
foreach (CONSUMABLE_STOCK_LOCATION_LABELS as $locationKey => $_locationLabel) {
    $stockTotals[$locationKey] = array_fill_keys(array_keys(CONSUMABLE_ITEM_LABELS), 0);
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
    $stockTotals[$row['stock_location']][$row['item_type']] = (int) $row['total'];
}

// ---- 一覧の取得（取り消し済みの記録は従業員には表示しない） ----
$listStmt = $pdo->query(
    "SELECT t.id, t.item_type, t.stock_location, t.quantity, t.reason, t.facility_id, t.transaction_date, t.note, t.created_at,
            creator.name AS created_by_name, f.name AS facility_name
     FROM consumable_stock_transactions t
     INNER JOIN employees creator ON creator.id = t.created_by
     LEFT JOIN facilities f ON f.id = t.facility_id
     WHERE t.canceled_at IS NULL
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 300"
);
$records = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>消耗品在庫管理 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 10px; }
        .form-row label { display: block; margin-bottom: 3px; font-weight: bold; }
        .form-row input, .form-row select { box-sizing: border-box; width: 100%; max-width: 360px; padding: 7px; }
        table.stock-table { border-collapse: collapse; width: 100%; }
        table.stock-table th, table.stock-table td { border: 1px solid #ccc; padding: 7px 6px; text-align: right; font-size: 0.88em; }
        table.stock-table th:first-child, table.stock-table td:first-child { text-align: left; }
        table.stock-table th { background: #f5f5f5; }
        .stock-value { font-weight: bold; }
        .stock-value.negative { color: #b3261e; }
        .stock-display { white-space: nowrap; }
        .stock-edit-button { margin-left: 5px; font-size: 0.8em; font-weight: normal; }
        .stock-edit-form { display: none; align-items: center; justify-content: flex-end; gap: 3px; }
        .stock-edit-form input[type="number"] { width: 65px; padding: 4px; text-align: right; }
        .stock-editing .stock-display { display: none; }
        .stock-editing .stock-edit-form { display: inline-flex; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.records th { background: #f5f5f5; }
        .qty-positive { color: #1e7e34; }
        .qty-negative { color: #b3261e; }
    </style>
</head>
<body>
<header>
    <h1>消耗品在庫管理</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="stock-overview">
    <h2>現在庫</h2>
    <table class="stock-table">
        <thead>
            <tr><th>品目</th><th>倉庫＋車在庫</th><th>フトン巻きのジロー在庫</th><th>合計在庫</th></tr>
        </thead>
        <tbody>
        <?php foreach (CONSUMABLE_ITEM_LABELS as $itemType => $label): ?>
            <?php
            $warehouseStock = $stockTotals['warehouse'][$itemType];
            $jiroStock = $stockTotals['jiro'][$itemType];
            $combinedStock = $warehouseStock + $jiroStock;
            ?>
            <tr>
                <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                <?php foreach (['warehouse' => $warehouseStock, 'jiro' => $jiroStock] as $locationKey => $locationStock): ?>
                    <td class="stock-value <?= $locationStock < 0 ? 'negative' : '' ?>">
                        <span class="stock-display"><?= (int) $locationStock ?>枚 <button type="button" class="stock-edit-button" onclick="toggleStockEdit(this, true)">編集</button></span>
                        <form method="post" action="/staff/consumable_stock.php" class="stock-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="adjust_stock">
                            <input type="hidden" name="stock_location" value="<?= htmlspecialchars($locationKey, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="item_type" value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="note" value="現在庫表から修正">
                            <input type="number" name="actual_quantity" min="0" step="1" value="<?= (int) $locationStock ?>" required aria-label="<?= htmlspecialchars($label . '・' . CONSUMABLE_STOCK_LOCATION_LABELS[$locationKey] . 'の実数', ENT_QUOTES, 'UTF-8') ?>">
                            <span>枚</span><button type="submit">保存</button><button type="button" onclick="toggleStockEdit(this, false)">取消</button>
                        </form>
                    </td>
                <?php endforeach; ?>
                <td class="stock-value <?= $combinedStock < 0 ? 'negative' : '' ?>"><?= (int) $combinedStock ?>枚</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="record-list">
    <h2>在庫増減履歴</h2>
    <?php if (empty($records)): ?>
        <p class="notice">在庫記録がありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>発生日</th>
                    <th>在庫場所</th>
                    <th>品目</th>
                    <th>増減数</th>
                    <th>理由</th>
                    <th>対象施設等</th>
                    <th>備考</th>
                    <th>登録者</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['transaction_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php
                        $locationLabel = CONSUMABLE_STOCK_LOCATION_LABELS[$record['stock_location']] ?? $record['stock_location'];
                        if ($record['stock_location'] === 'warehouse' && $record['facility_name'] === JIRO_FACILITY_NAME) {
                            $locationLabel = $record['reason'] === 'issuance_to_facility'
                                ? '倉庫＋車 → フトン巻きのジロー'
                                : ($record['reason'] === 'return_from_facility' ? 'フトン巻きのジロー → 倉庫＋車' : $locationLabel);
                        }
                        ?>
                        <td><?= htmlspecialchars($locationLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(CONSUMABLE_ITEM_LABELS[$record['item_type']] ?? $record['item_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= (int) $record['quantity'] >= 0 ? 'qty-positive' : 'qty-negative' ?>">
                            <?= (int) $record['quantity'] >= 0 ? '+' : '' ?><?= (int) $record['quantity'] ?>
                        </td>
                        <td><?= htmlspecialchars(CONSUMABLE_REASON_LABELS[$record['reason']] ?? $record['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $record['facility_name'] !== null ? htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $record['note'] !== null ? htmlspecialchars($record['note'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= htmlspecialchars($record['created_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<script>
function toggleStockEdit(button, editing) {
    const cell = button.closest('td');
    cell.classList.toggle('stock-editing', editing);
    if (editing) cell.querySelector('input[name="actual_quantity"]').select();
}
</script>
</body>
</html>
