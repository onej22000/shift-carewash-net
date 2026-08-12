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

const CONSUMABLE_REASON_LABELS = [
    'purchase' => '購入',
    'return_from_facility' => '施設等からの返却',
    'disposal' => '廃棄',
    'loss' => '紛失',
    'issuance_to_facility' => '施設等への交付',
];

// ---- 現在庫の集計 ----
$stockTotals = array_fill_keys(array_keys(CONSUMABLE_ITEM_LABELS), 0);
$totalsStmt = $pdo->query(
    'SELECT item_type, SUM(quantity) AS total
     FROM consumable_stock_transactions
     WHERE canceled_at IS NULL
     GROUP BY item_type'
);
foreach ($totalsStmt->fetchAll() as $row) {
    $stockTotals[$row['item_type']] = (int) $row['total'];
}

// ---- 一覧の取得（取り消し済みの記録は従業員には表示しない） ----
$listStmt = $pdo->query(
    "SELECT t.id, t.item_type, t.quantity, t.reason, t.facility_id, t.transaction_date, t.note, t.created_at,
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
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        .stock-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
        .stock-card { border: 1px solid #ccc; border-radius: 8px; padding: 12px; background: #fff; }
        .stock-card .label { font-size: 0.85em; color: #555; }
        .stock-card .value { font-size: 1.6em; font-weight: bold; margin-top: 4px; }
        .stock-card .value.negative { color: #b3261e; }
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

<section class="stock-overview">
    <h2>現在庫</h2>
    <div class="stock-summary">
        <?php foreach (CONSUMABLE_ITEM_LABELS as $itemType => $label): ?>
            <div class="stock-card">
                <div class="label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="value <?= $stockTotals[$itemType] < 0 ? 'negative' : '' ?>"><?= (int) $stockTotals[$itemType] ?>枚</div>
            </div>
        <?php endforeach; ?>
    </div>
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
</body>
</html>
