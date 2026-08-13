<?php
require_once __DIR__ . '/../includes/auth.php';

$staff = require_login('staff');
$pdo = getPdo();

// 作業状況は「まだ施設へ返却していない集荷サイクル」の進行状況を、サイクル単位で直接表示する
// （2026-08-14、施設単位の間接集計＝work_records.php由来の個人記録person_countを代用値として
// 使う方式から、collection_cycle_idに直結した作業登録（work_stage_records）の有無で直接判定する
// 方式に置き換えた。同一施設に複数の未返却サイクルがあっても混線しない）。
$cyclesStmt = $pdo->query(
    "SELECT cc.id, cc.facility_id, cc.pickup_date, cc.arrival_bag_count, cc.return_ready_laundry_net_count,
            f.name AS facility_name, f.is_active,
            EXISTS(
                SELECT 1 FROM work_stage_records wsr
                WHERE wsr.collection_cycle_id = cc.id AND wsr.deleted_at IS NULL
            ) AS work_done
     FROM collection_cycles cc
     INNER JOIN facilities f ON f.id = cc.facility_id
     WHERE f.facility_type = '介護施設'
       AND cc.pickup_bag_count IS NOT NULL
       AND cc.arrival_bag_count IS NOT NULL
       AND cc.return_bag_count IS NULL
       AND cc.deleted_at IS NULL
     ORDER BY f.is_active DESC, f.name, cc.pickup_date"
);
$cycles = $cyclesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>作業状況 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        table.status { border-collapse: collapse; width: 100%; }
        table.status th, table.status td { border: 1px solid #ccc; padding: 8px; text-align: right; }
        table.status th:first-child, table.status td:first-child,
        table.status th:nth-child(2), table.status td:nth-child(2) { text-align: left; }
        table.status th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.85em; padding: 2px 10px; border-radius: 10px; }
        .status-complete { background: #e6f4ea; color: #1e7e34; }
        .status-pending { background: #fff3cd; color: #856404; }
        .facility-disabled { color: #999; }
    </style>
</head>
<body>
<header>
    <h1>作業状況</h1>
    <nav>ログイン中: <?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?>さん | <a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if (empty($cycles)): ?>
    <p class="notice">未発送の作業はありません。</p>
<?php else: ?>
    <table class="status">
        <thead>
            <tr>
                <th>施設</th>
                <th>集荷日</th>
                <th>リネン袋数</th>
                <th>洗濯ネット数</th>
                <th>洗濯累計</th>
                <th>未完了数</th>
                <th>状態</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cycles as $cycle): ?>
                <?php
                $netCount = $cycle['return_ready_laundry_net_count'] !== null ? (int) $cycle['return_ready_laundry_net_count'] : 0;
                $workDone = (int) $cycle['work_done'] === 1;
                $washed = $workDone ? $netCount : 0;
                $notCompleted = $netCount - $washed;
                ?>
                <tr>
                    <td class="<?= (int) $cycle['is_active'] === 1 ? '' : 'facility-disabled' ?>">
                        <?= htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') ?><?= (int) $cycle['is_active'] === 1 ? '' : '（無効）' ?>
                    </td>
                    <td><?= htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $cycle['arrival_bag_count'] ?>袋</td>
                    <td><?= $netCount ?>枚</td>
                    <td><?= $washed ?>枚</td>
                    <td><?= $notCompleted ?>枚</td>
                    <td>
                        <?php if ($workDone): ?>
                            <span class="status-badge status-complete">完了</span>
                        <?php else: ?>
                            <span class="status-badge status-pending">未処理</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="notice">未完了数は洗濯ネット数から洗濯累計を差し引いたものです。このサイクルに対応する作業登録（staff/collection_headcount.php）が完了すると「完了」になります。発送入力済みの分は表示されません。</p>
<?php endif; ?>
</body>
</html>
