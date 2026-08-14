<?php
require_once __DIR__ . '/../includes/auth.php';

$staff = require_login('staff');
$pdo = getPdo();

// 賃金・時給に関するテーブル/カラムはこのページでは一切参照しない
// （employees.hourly_wage_weekday/holiday, monthly_wages等へのアクセスなし）

$periodLabels = [
    'all' => '全期間',
    '7' => '直近7日',
    '30' => '直近30日',
];

$period = (string) ($_GET['period'] ?? 'all');
if (!isset($periodLabels[$period])) {
    $period = 'all';
}

$attendanceParams = [];
$attendanceDateCondition = '';
$stageParams = [];
$stageDateCondition = '';
if ($period !== 'all') {
    $end = (new DateTime())->format('Y-m-d');
    $start = (new DateTime())->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d');
    $attendanceDateCondition = 'AND DATE(clock_in_at) BETWEEN :start AND :end';
    $attendanceParams = [':start' => $start, ':end' => $end];
    $stageDateCondition = 'AND record_date BETWEEN :start AND :end';
    $stageParams = [':start' => $start, ':end' => $end];
}

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' AND is_shared_account = 0 ORDER BY name");
$employees = $employeesStmt->fetchAll();

// ---- 効率算出用：従業員別・日次の実働時間(分) ----
$attendanceStmt = $pdo->prepare(
    "SELECT employee_id, DATE(clock_in_at) AS work_day, SUM(work_minutes) AS day_minutes
     FROM attendance
     WHERE status = 'done' AND deleted_at IS NULL $attendanceDateCondition
     GROUP BY employee_id, DATE(clock_in_at)"
);
$attendanceStmt->execute($attendanceParams);
$minutesByEmployeeDay = [];
foreach ($attendanceStmt->fetchAll() as $row) {
    $minutesByEmployeeDay[(int) $row['employee_id']][$row['work_day']] = (int) $row['day_minutes'];
}

// ---- 効率算出用：従業員別・日次の洗濯代行人数 ----
// 洗濯・乾燥・畳みは2026-08-06に「洗濯」1工程へ統合したため、stage='wash'のみで洗濯代行の全実績を表す。
$stagePeopleStmt = $pdo->prepare(
    "SELECT employee_id, record_date, SUM(person_count) AS day_people
     FROM work_stage_records
     WHERE stage = 'wash' AND deleted_at IS NULL $stageDateCondition
     GROUP BY employee_id, record_date"
);
$stagePeopleStmt->execute($stageParams);
$peopleByEmployeeDay = [];
foreach ($stagePeopleStmt->fetchAll() as $row) {
    $peopleByEmployeeDay[(int) $row['employee_id']][$row['record_date']] = (int) $row['day_people'];
}

// ---- 従業員別: 洗濯代行合計人数 ----
$laundryStmt = $pdo->prepare(
    "SELECT employee_id, SUM(person_count) AS total
     FROM work_stage_records
     WHERE stage = 'wash' AND deleted_at IS NULL $stageDateCondition
     GROUP BY employee_id"
);
$laundryStmt->execute($stageParams);
$laundryTotalByEmployee = [];
foreach ($laundryStmt->fetchAll() as $row) {
    $laundryTotalByEmployee[(int) $row['employee_id']] = (int) $row['total'];
}

// ---- 従業員別サマリー組み立て ----
$summary = [];
foreach ($employees as $employee) {
    $employeeId = (int) $employee['id'];
    $totalMinutes = 0;
    $totalPeople = 0;

    if (isset($minutesByEmployeeDay[$employeeId])) {
        foreach ($minutesByEmployeeDay[$employeeId] as $day => $minutes) {
            if (isset($peopleByEmployeeDay[$employeeId][$day])) {
                $totalMinutes += $minutes;
                $totalPeople += $peopleByEmployeeDay[$employeeId][$day];
            }
        }
    }

    $summary[] = [
        'id' => $employeeId,
        'name' => $employee['name'],
        'laundry_total' => $laundryTotalByEmployee[$employeeId] ?? 0,
        'efficiency_minutes' => $totalMinutes,
        'efficiency_people' => $totalPeople,
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>作業実績比較 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
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
    <h1>作業実績比較</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<div class="period-nav">
    <?php foreach ($periodLabels as $key => $label): ?>
        <a href="?period=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="<?= $period === $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<section class="by-employee">
    <h2>従業員別 比較サマリー</h2>
    <p class="notice">実働時間と洗濯代行の作業記録が同じ日に存在する日のみを対象に、効率（1人あたり平均所要時間）を算出しています。</p>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。</p>
    <?php else: ?>
        <table class="speed">
            <thead>
                <tr>
                    <th>従業員</th>
                    <th>洗濯代行実績（合計人数）</th>
                    <th>効率（1人あたり平均所要時間）</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary as $data): ?>
                    <tr>
                        <td><?= htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $data['laundry_total'] ?>人</td>
                        <td class="total-col">
                            <?= $data['efficiency_people'] > 0 ? number_format($data['efficiency_minutes'] / $data['efficiency_people'], 2) . '分' : '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
</body>
</html>
