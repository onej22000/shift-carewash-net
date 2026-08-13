<?php
require_once __DIR__ . '/../includes/auth.php';

$admin = require_login('admin');
$pdo = getPdo();

$params = [];
$dateCondition = '';

$facilitiesStmt = $pdo->query(
    "SELECT f.id, f.name, f.is_active
     FROM facilities f
     WHERE f.facility_type = '介護施設'
       AND EXISTS (
           SELECT 1
           FROM collection_cycles cc
           WHERE cc.facility_id = f.id
             AND cc.arrival_bag_count IS NOT NULL
             AND cc.dispatch_bag_count IS NULL
             AND cc.return_bag_count IS NULL
             AND cc.deleted_at IS NULL
       )
     ORDER BY f.is_active DESC, f.name"
);
$facilities = $facilitiesStmt->fetchAll();

// 作業状況は「まだ施設へ返却していない集荷サイクル」の進行状況だけを表示する。
// collection_headcount.php が到着確認用に自動生成する作業実績（collection_cycle_idあり）は
// 洗濯完了の実績ではないため除外し、作業実績登録画面から入力された記録だけを集計する。
$stageStmt = $pdo->prepare(
    "SELECT w.facility_id, SUM(w.person_count) AS washed
     FROM work_stage_records w
     INNER JOIN (
         SELECT facility_id, MIN(COALESCE(arrival_date, pickup_date)) AS open_since
         FROM collection_cycles
         WHERE arrival_bag_count IS NOT NULL
           AND dispatch_bag_count IS NULL
           AND return_bag_count IS NULL
           AND deleted_at IS NULL
         GROUP BY facility_id
     ) active ON active.facility_id = w.facility_id
     WHERE w.stage = 'wash'
       AND w.collection_cycle_id IS NULL
       AND w.deleted_at IS NULL
       AND w.record_date >= active.open_since
     GROUP BY w.facility_id"
);
$stageStmt->execute($params);

$stageTotalsByFacility = [];
foreach ($stageStmt->fetchAll() as $row) {
    $stageTotalsByFacility[(int) $row['facility_id']] = [
        'washed' => (int) $row['washed'],
    ];
}

// リネン袋数・洗濯ネット数は、返却前かつ「本日集荷」の集荷サイクルだけを集計する。
$bagDateCondition = 'AND pickup_date = CURDATE()';
$bagStmt = $pdo->prepare(
    "SELECT facility_id, SUM(COALESCE(arrival_bag_count, pickup_bag_count, 0)) AS total
     FROM collection_cycles
     WHERE arrival_bag_count IS NOT NULL
       AND dispatch_bag_count IS NULL
       AND return_bag_count IS NULL
       AND deleted_at IS NULL $bagDateCondition
     GROUP BY facility_id"
);
$bagStmt->execute($params);

$bagCountByFacility = [];
foreach ($bagStmt->fetchAll() as $row) {
    $bagCountByFacility[(int) $row['facility_id']] = (int) $row['total'];
}

// 洗濯ネット数には、到着リネン袋内で確認した数を使う。
$netStmt = $pdo->prepare(
    "SELECT facility_id, SUM(COALESCE(return_ready_laundry_net_count, 0)) AS total
     FROM collection_cycles
     WHERE arrival_bag_count IS NOT NULL
       AND dispatch_bag_count IS NULL
       AND return_bag_count IS NULL
       AND deleted_at IS NULL $bagDateCondition
     GROUP BY facility_id"
);
$netStmt->execute($params);
$netCountByFacility = [];
foreach ($netStmt->fetchAll() as $row) {
    $netCountByFacility[(int) $row['facility_id']] = (int) $row['total'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作業状況 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .period-nav { margin-bottom: 16px; }
        .period-nav a { margin-right: 12px; padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; text-decoration: none; color: #222; }
        .period-nav a.active { background: #0b5ed7; color: #fff; border-color: #0b5ed7; }
        table.status { border-collapse: collapse; width: 100%; }
        table.status th, table.status td { border: 1px solid #ccc; padding: 8px; text-align: right; }
        table.status th:first-child, table.status td:first-child { text-align: left; }
        table.status th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.85em; padding: 2px 10px; border-radius: 10px; }
        .status-complete { background: #e6f4ea; color: #1e7e34; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-anomaly { background: #fdecea; color: #b3261e; }
        .facility-disabled { color: #999; }
    </style>
</head>
<body>
<header>
    <h1>作業状況</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if (empty($facilities)): ?>
    <p class="notice">未発送の作業はありません。</p>
<?php else: ?>
    <table class="status">
        <thead>
            <tr>
                <th>施設</th>
                <th>リネン袋数</th>
                <th>洗濯ネット数</th>
                <th>洗濯累計</th>
                <th>未完了数</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($facilities as $facility): ?>
                <?php
                $facilityId = (int) $facility['id'];
                $totals = $stageTotalsByFacility[$facilityId] ?? [];
                $collected = $netCountByFacility[$facilityId] ?? 0;
                // 作業状況は進捗表示なので、誤って同じ作業を複数登録しても要作業数を超えて表示しない。
                $washed = min($collected, $totals['washed'] ?? 0);

                $notCompleted = max(0, $collected - $washed);

                $bagCount = $bagCountByFacility[$facilityId] ?? 0;
                ?>
                <tr>
                    <td class="<?= (int) $facility['is_active'] === 1 ? '' : 'facility-disabled' ?>">
                        <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?><?= (int) $facility['is_active'] === 1 ? '' : '（無効）' ?>
                    </td>
                    <td><?= $bagCount ?>袋</td>
                    <td><?= $collected ?>枚</td>
                    <td><?= $washed ?>枚</td>
                    <td><?= $notCompleted ?>枚</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="notice">未完了数は洗濯ネット数から洗濯累計を差し引いたものです。発送入力済みの分は表示されません。</p>
<?php endif; ?>
</body>
</html>
