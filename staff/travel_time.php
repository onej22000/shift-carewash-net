<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$employeeId = (int) ($_GET['employee_id'] ?? 0);
if (!in_array($employeeId, $validEmployeeIds, true)) {
    $employeeId = 0;
}

$yearMonth = (string) ($_GET['month'] ?? (new DateTime())->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
    $yearMonth = (new DateTime())->format('Y-m');
}
[$rangeStartStr, $rangeEndStr] = get_month_range($yearMonth);
$monthStart = DateTime::createFromFormat('Y-m-d', $rangeStartStr);
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');

$segments = calc_travel_segments($pdo, $rangeStartStr, $rangeEndStr, $employeeId > 0 ? $employeeId : null);

$totalsByEmployee = [];
foreach ($segments as $segment) {
    $name = $segment['employee_name'];
    if (!isset($totalsByEmployee[$name])) {
        $totalsByEmployee[$name] = ['count' => 0, 'travel_minutes' => 0];
    }
    $totalsByEmployee[$name]['count']++;
    $totalsByEmployee[$name]['travel_minutes'] += $segment['travel_minutes'];
}
ksort($totalsByEmployee);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>移動時間 | シフト管理</title>
<style>
    body { font-family: sans-serif; margin: 16px; color: #222; }
    header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
    h1 { font-size: 1.3em; margin: 0; }
    .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
    .filter-row { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .month-nav a { margin-right: 8px; }
    section { margin-bottom: 28px; }
    table { border-collapse: collapse; width: 100%; font-size: 0.85em; }
    table th, table td { border: 1px solid #ccc; padding: 6px 8px; text-align: center; }
    table th { background: #f5f5f5; }
    table.detail td:nth-child(2), table.detail td:nth-child(3), table.detail td:nth-child(4) { text-align: left; }
    .total-col { font-weight: bold; }
</style>
</head>
<body>
<header>
    <h1>移動時間</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<p class="notice">集荷・配送記録簿（集荷/クリーニング所到着/発送/返却）の日時・担当者・場所から、同一従業員が同一日に異なる施設で連続して記録した工程間の移動時間を算出しています。途中で休憩を取っていた場合はその時間を差し引いています。日付・時刻・担当者・場所のいずれかが未記録の工程は算出対象外です。</p>

<form method="get" action="/staff/travel_time.php" class="filter-row">
    <label for="employee_id">従業員:</label>
    <select id="employee_id" name="employee_id" onchange="this.form.submit()">
        <option value="0">全員</option>
        <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === $employeeId ? 'selected' : '' ?>>
                <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
</form>

<div class="month-nav">
    <a href="?employee_id=<?= $employeeId ?>&month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <strong><?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?></strong>
    <a href="?employee_id=<?= $employeeId ?>&month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">次月 →</a>
</div>

<section class="summary">
    <h2>従業員別合計</h2>
    <?php if (empty($totalsByEmployee)): ?>
        <p class="notice">対象月の移動区間はありません。</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>従業員</th><th>移動区間数</th><th>合計移動時間</th></tr>
            </thead>
            <tbody>
                <?php foreach ($totalsByEmployee as $name => $totals): ?>
                    <tr>
                        <td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $totals['count'] ?></td>
                        <td class="total-col"><?= format_minutes_as_hours((int) $totals['travel_minutes']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="detail">
    <h2>移動区間の明細</h2>
    <?php if (empty($segments)): ?>
        <p class="notice">対象月の移動区間はありません。</p>
    <?php else: ?>
        <table class="detail">
            <thead>
                <tr>
                    <th>日付</th><th>従業員</th><th>出発</th><th>到着</th>
                    <th>経過時間</th><th>休憩差引</th><th>移動時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($segments as $segment): ?>
                    <tr>
                        <td><?= htmlspecialchars($segment['date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($segment['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($segment['from_time'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($segment['from_facility'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars($segment['from_stage'], ENT_QUOTES, 'UTF-8') ?>）</td>
                        <td><?= htmlspecialchars($segment['to_time'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($segment['to_facility'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars($segment['to_stage'], ENT_QUOTES, 'UTF-8') ?>）</td>
                        <td><?= (int) $segment['raw_minutes'] ?>分</td>
                        <td><?= $segment['break_minutes'] > 0 ? '-' . (int) $segment['break_minutes'] . '分' : '-' ?></td>
                        <td class="total-col"><?= (int) $segment['travel_minutes'] ?>分</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
</body>
</html>
