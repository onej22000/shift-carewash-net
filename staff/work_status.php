<?php
require_once __DIR__ . '/../includes/auth.php';

$staff = require_login('staff');
$pdo = getPdo();

$periodLabels = [
    'all' => '全期間',
    '7' => '直近7日',
    '30' => '直近30日',
];

$period = (string) ($_GET['period'] ?? 'all');
if (!isset($periodLabels[$period])) {
    $period = 'all';
}

$params = [];
$dateCondition = '';
if ($period !== 'all') {
    $end = (new DateTime())->format('Y-m-d');
    $start = (new DateTime())->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d');
    $dateCondition = 'AND record_date BETWEEN :start AND :end';
    $params[':start'] = $start;
    $params[':end'] = $end;
}

$facilitiesStmt = $pdo->query("SELECT id, name, is_active FROM facilities WHERE facility_type = '介護施設' ORDER BY is_active DESC, name");
$facilities = $facilitiesStmt->fetchAll();

// 集荷人数（work_stage_records.stage='wash' かつ collection_cycle_id が設定された「人数確認」記録）と、
// 実際の洗濯・乾燥・畳みの作業実績（collection_cycle_id が無い通常の作業実績）を分けて集計する。
// 両方ともstage='wash'で記録されるため、集荷人数を洗濯累計に混同しないようここで明確に分離する。
$stageStmt = $pdo->prepare(
    "SELECT facility_id,
            SUM(CASE WHEN stage = 'wash' AND collection_cycle_id IS NOT NULL THEN person_count ELSE 0 END) AS collected,
            SUM(CASE WHEN stage = 'wash' AND collection_cycle_id IS NULL THEN person_count ELSE 0 END) AS washed,
            SUM(CASE WHEN stage = 'dry' THEN person_count ELSE 0 END) AS dried,
            SUM(CASE WHEN stage = 'fold' THEN person_count ELSE 0 END) AS folded
     FROM work_stage_records
     WHERE deleted_at IS NULL $dateCondition
     GROUP BY facility_id"
);
$stageStmt->execute($params);

$stageTotalsByFacility = [];
foreach ($stageStmt->fetchAll() as $row) {
    $stageTotalsByFacility[(int) $row['facility_id']] = [
        'collected' => (int) $row['collected'],
        'washed' => (int) $row['washed'],
        'dried' => (int) $row['dried'],
        'folded' => (int) $row['folded'],
    ];
}

// リネン袋数：従業員の「集荷・配送記録を入力する」画面で記録された集荷リネン袋数（collection_cycles.pickup_bag_count）を反映する。
$bagDateCondition = str_replace('record_date', 'pickup_date', $dateCondition);
$bagStmt = $pdo->prepare(
    "SELECT facility_id, SUM(pickup_bag_count) AS total
     FROM collection_cycles
     WHERE deleted_at IS NULL $bagDateCondition
     GROUP BY facility_id"
);
$bagStmt->execute($params);

$bagCountByFacility = [];
foreach ($bagStmt->fetchAll() as $row) {
    $bagCountByFacility[(int) $row['facility_id']] = (int) $row['total'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作業状況・残数確認 | シフト管理</title>
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
    <h1>作業状況・残数確認</h1>
    <nav>ログイン中: <?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?>さん | <a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<div class="period-nav">
    <?php foreach ($periodLabels as $key => $label): ?>
        <a href="?period=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="<?= $period === $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($facilities)): ?>
    <p class="notice">施設が登録されていません。</p>
<?php else: ?>
    <table class="status">
        <thead>
            <tr>
                <th>施設</th>
                <th>リネン袋数</th>
                <th>集荷人数</th>
                <th>洗濯累計</th>
                <th>乾燥累計</th>
                <th>畳み累計</th>
                <th>未洗濯</th>
                <th>未乾燥</th>
                <th>未完了数</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($facilities as $facility): ?>
                <?php
                $facilityId = (int) $facility['id'];
                $totals = $stageTotalsByFacility[$facilityId] ?? [];
                $collected = $totals['collected'] ?? 0;
                $washed = $totals['washed'] ?? 0;
                $dried = $totals['dried'] ?? 0;
                $folded = $totals['folded'] ?? 0;

                $notWashed = $collected - $washed;
                $notDried = $collected - $dried;
                $notCompleted = $collected - $folded;

                $bagCount = $bagCountByFacility[$facilityId] ?? 0;
                ?>
                <tr>
                    <td class="<?= (int) $facility['is_active'] === 1 ? '' : 'facility-disabled' ?>">
                        <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?><?= (int) $facility['is_active'] === 1 ? '' : '（無効）' ?>
                    </td>
                    <td><?= $bagCount ?>袋</td>
                    <td><?= $collected ?>人</td>
                    <td><?= $washed ?>人</td>
                    <td><?= $dried ?>人</td>
                    <td><?= $folded ?>人</td>
                    <td><?= $notWashed ?>人</td>
                    <td><?= $notDried ?>人</td>
                    <td><?= $notCompleted ?>人</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="notice">リネン袋数は「集荷・配送記録を入力する」画面で記録された集荷リネン袋数の合計（参考値、他の数値には影響しません）。集荷人数は「集荷人数の確認」画面での確認記録に基づきます。未洗濯・未乾燥・未完了数はいずれも集荷人数を基準に各工程の累計を差し引いたものです。</p>
<?php endif; ?>
</body>
</html>
