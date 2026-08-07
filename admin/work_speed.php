<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
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

$stageParams = [];
$stageDateCondition = '';
if ($period !== 'all') {
    $end = (new DateTime())->format('Y-m-d');
    $start = (new DateTime())->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d');
    $stageDateCondition = 'AND record_date BETWEEN :start AND :end';
    $stageParams = [':start' => $start, ':end' => $end];
} else {
    $start = '2000-01-01';
    $end = '2099-12-31';
}

$facilityCategoryStats = calc_facility_category_work_stats($pdo, $start, $end);

// ---- 従業員別: 参加セッションごとの実働時間（work_stage_record_employees.started_at〜
// work_stage_records.completed_atの実測）----
// 同じセッションを2名で処理しても1名で処理しても、各自の開始時刻はresolve_work_stage_started_at()で
// 個別に算出済み（本人の直前セッションの完了時刻、無ければ当日の洗濯代行出勤時刻）のため、
// 「セッション単位で1つに丸めて人数按分する」旧方式（work_minutes÷person_count）より正確。
// ただしこの参加者テーブルは2026-08-06以降に登録された記録にしかデータが無いため、
// それ以前の記録や、参加者未選択のまま登録された記録は対象外になる。
$sessionStageCondition = $stageDateCondition !== '' ? str_replace('record_date', 'wsr.record_date', $stageDateCondition) : '';
$sessionStmt = $pdo->prepare(
    "SELECT wse.employee_id, TIMESTAMPDIFF(MINUTE, wse.started_at, wsr.completed_at) AS session_minutes
     FROM work_stage_record_employees wse
     INNER JOIN work_stage_records wsr ON wsr.id = wse.work_stage_record_id
     WHERE wsr.stage = 'wash' AND wsr.deleted_at IS NULL AND wsr.completed_at IS NOT NULL $sessionStageCondition"
);
$sessionStmt->execute($stageParams);

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();

$sessionStatsByEmployee = [];
foreach ($sessionStmt->fetchAll() as $row) {
    $employeeId = (int) $row['employee_id'];
    $minutes = max(0, (int) $row['session_minutes']);
    if (!isset($sessionStatsByEmployee[$employeeId])) {
        $sessionStatsByEmployee[$employeeId] = ['total_minutes' => 0, 'session_count' => 0];
    }
    $sessionStatsByEmployee[$employeeId]['total_minutes'] += $minutes;
    $sessionStatsByEmployee[$employeeId]['session_count']++;
}

$employeeSpeed = [];
foreach ($employees as $employee) {
    $employeeId = (int) $employee['id'];
    $stats = $sessionStatsByEmployee[$employeeId] ?? ['total_minutes' => 0, 'session_count' => 0];

    $employeeSpeed[] = [
        'name' => $employee['name'],
        'total_minutes' => $stats['total_minutes'],
        'session_count' => $stats['session_count'],
    ];
}

// ---- 施設別: 洗濯代行の人数合計 ----
// 洗濯・乾燥・畳みは2026-08-06に「洗濯」1工程へ統合したため、工程別の内訳ではなく施設別合計のみを出す。
$facilityStmt = $pdo->prepare(
    "SELECT w.facility_id, f.name AS facility_name, SUM(w.person_count) AS total
     FROM work_stage_records w
     INNER JOIN facilities f ON f.id = w.facility_id
     WHERE w.stage = 'wash' AND w.deleted_at IS NULL $stageDateCondition
     GROUP BY w.facility_id, f.name"
);
$facilityStmt->execute($stageParams);

$facilityData = [];
foreach ($facilityStmt->fetchAll() as $row) {
    $facilityId = (int) $row['facility_id'];
    $facilityData[$facilityId] = ['name' => $row['facility_name'], 'total' => (int) $row['total']];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作業速度分析 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .period-nav { margin-bottom: 16px; }
        .period-nav a { margin-right: 12px; padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; text-decoration: none; color: #222; }
        .period-nav a.active { background: #0b5ed7; color: #fff; border-color: #0b5ed7; }
        section { margin-bottom: 32px; }
        table.speed { border-collapse: collapse; width: 100%; }
        table.speed th, table.speed td { border: 1px solid #ccc; padding: 8px; text-align: right; }
        table.speed th:first-child, table.speed td:first-child { text-align: left; }
        table.speed th { background: #f5f5f5; }
        .total-col { font-weight: bold; }
    </style>
</head>
<body>
<header>
    <h1>作業速度分析</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<div class="period-nav">
    <?php foreach ($periodLabels as $key => $label): ?>
        <a href="?period=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="<?= $period === $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<section class="by-employee">
    <h2>従業員別 1人あたり平均所要時間</h2>
    <p class="notice">参加者ごとの開始時刻（本人の直前セッションの完了時刻、無ければ当日の洗濯代行出勤時刻）〜作業完了時刻の実測に基づく集計です。
        同じセッションを複数名で処理しても、各自の実際の開始時刻をもとに個別に計算するため、以前の「実働時間合計÷作業人数合計」による按分より正確です。
        参加した従業員を選択して登録された記録（2026-08-06以降）のみが対象です。</p>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。</p>
    <?php else: ?>
        <table class="speed">
            <thead>
                <tr>
                    <th>従業員</th>
                    <th>実働時間合計</th>
                    <th>セッション数</th>
                    <th>1セッションあたり平均</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employeeSpeed as $data): ?>
                    <tr>
                        <td><?= htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $data['total_minutes'] ?>分</td>
                        <td><?= $data['session_count'] ?>件</td>
                        <td class="total-col">
                            <?= $data['session_count'] > 0 ? number_format($data['total_minutes'] / $data['session_count'], 2) . '分' : '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="by-facility-category">
    <h2>施設別・区分別 作業時間・作業効率</h2>
    <p class="notice">
        出退勤実績（実働時間）と作業実績（施設・区分・人数）が同じ従業員・同じ日に存在する分のみを対象に、
        その日の実働時間を、その日の作業実績（区分問わず）の人数比で各施設・区分に按分して算出しています。
        区分は work_stage_records 側（工程ごとの実際の区分。集荷は集荷・配送記録簿に移行したため、
        現在の作業実績は洗濯・乾燥・畳みのみで区分は常に「洗濯代行」）を基準にしており、
        打刻時の区分（その日の主な区分）とは一致しない場合があります。区分の記録が無い古い作業実績は対象外です。
    </p>

    <?php if (empty($facilityCategoryStats)): ?>
        <p class="notice">対象期間に、区分付きの出退勤実績と作業実績が両方そろっているデータがありません。</p>
    <?php else: ?>
        <table class="speed">
            <thead>
                <tr>
                    <th>施設</th>
                    <th>区分</th>
                    <th>合計作業時間</th>
                    <th>合計人数</th>
                    <th>作業効率（1人あたり平均）</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facilityCategoryStats as $facilityName => $categories): ?>
                    <?php foreach ($categories as $category => $data): ?>
                        <tr>
                            <td><?= htmlspecialchars($facilityName, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $data['total_minutes'] ?>分</td>
                            <td><?= $data['total_people'] ?>人</td>
                            <td class="total-col">
                                <?= $data['efficiency_minutes_per_person'] !== null ? number_format($data['efficiency_minutes_per_person'], 2) . '分' : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="by-facility">
    <h2>施設別 作業人数合計</h2>
    <p class="notice">洗濯代行（洗濯・乾燥・畳みを2026-08-06に統合）の人数合計です。区分の紐付けが無い作業実績も含みます。</p>

    <?php if (empty($facilityData)): ?>
        <p class="notice">対象期間に洗濯代行の記録がありません。</p>
    <?php else: ?>
        <table class="speed">
            <thead>
                <tr>
                    <th>施設</th>
                    <th>洗濯代行 合計</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facilityData as $data): ?>
                    <tr>
                        <td><?= htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="total-col"><?= $data['total'] ?>人</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
</body>
</html>
