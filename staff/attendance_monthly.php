<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attendance_monthly_common.php';

$staff = require_login('staff');
$pdo = getPdo();

// 共用アカウントは「本人」の打刻を持たない（出退勤は一覧から人を選んで代理打刻する）ため対象外。
if ((int) ($staff['is_shared_account'] ?? 0) === 1) {
    set_flash('error', '共用アカウントでは月間打刻実績は表示できません。');
    header('Location: /staff/dashboard.php');
    exit;
}

// 本人（session上のemployee_id）の記録のみを対象とする。employee_idを外部から受け取ることはない。
$employeeId = (int) $staff['id'];

$month = resolve_attendance_month($_GET['month'] ?? null);
$yearMonth = $month['year_month'];
$todayStr = (new DateTime('today'))->format('Y-m-d');
$currentMonth = (new DateTime())->format('Y-m');

$shiftsByDate = fetch_monthly_shifts_by_employee_date($pdo, $month['start_str'], $month['end_str'], $employeeId)[$employeeId] ?? [];
[$attendanceByEmployeeDate, $totalWorkMinutes, $totalBreakMinutes] = fetch_monthly_attendance_by_employee_date($pdo, $month['start_str'], $month['end_str'], $employeeId);
$attendanceByDate = $attendanceByEmployeeDate[$employeeId] ?? [];
$workDayCount = count($attendanceByDate);

$holidayDates = fetch_holiday_dates($pdo, $month['start_str'], $month['end_str']);
$weekdayLabels = ['月', '火', '水', '木', '金', '土', '日'];

// 月曜始まりのカレンダーにするため、月初の曜日まで空セルを詰める
$leadingBlankCount = (int) $month['month_start']->format('N') - 1;
$calendarCells = array_merge(array_fill(0, $leadingBlankCount, null), $month['dates']);
while (count($calendarCells) % 7 !== 0) {
    $calendarCells[] = null;
}
$calendarWeeks = array_chunk($calendarCells, 7);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>月間打刻実績 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.error { background: #fdecea; color: #b3261e; }
        .month-nav { margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .month-nav a { padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; text-decoration: none; color: #222; }
        .month-nav form { display: inline-flex; gap: 6px; align-items: center; }
        .summary { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; font-weight: bold; }
        table.attendance-calendar { border-collapse: collapse; width: 100%; table-layout: fixed; }
        table.attendance-calendar th, table.attendance-calendar td { border: 1px solid #ccc; vertical-align: top; padding: 4px; }
        table.attendance-calendar th { background: #f5f5f5; }
        table.attendance-calendar th.sat, .day-number.sat { color: #0b5ed7; }
        table.attendance-calendar th.sun-holiday, .day-number.sun-holiday { color: #d9362e; }
        table.attendance-calendar td.date-cell { height: 80px; }
        table.attendance-calendar td.today { background: #eef6ff; }
        table.attendance-calendar td.blank { background: #fafafa; }
        .day-number { font-weight: bold; margin-bottom: 3px; }
        .day-weekday { display: none; }
        .plan-entry { border: 1px dashed #99a; border-radius: 4px; padding: 3px; margin-bottom: 3px; font-size: 0.8em; background: #f4f6ff; color: #444; }
        .actual-entry { border: 1px solid #ccc; border-radius: 4px; padding: 3px; margin-bottom: 3px; font-size: 0.8em; background: #fff; }
        .entry-label { display: inline-block; font-size: 0.85em; font-weight: bold; color: #666; margin-right: 4px; }
        .entry-sub { font-size: 0.9em; color: #555; }
        .missing-punch { font-size: 0.8em; color: #b3261e; margin-bottom: 3px; }
        .working-badge { display: inline-block; font-size: 0.75em; background: #0b5ed7; color: #fff; border-radius: 3px; padding: 1px 5px; margin-left: 2px; }
        .category-badge { display: inline-block; font-size: 0.75em; color: #fff; border-radius: 3px; padding: 1px 4px; margin-left: 2px; }

        /* スマホ幅では7列のカレンダーが潰れるため、日付ごとの縦並びリストに切り替える */
        @media (max-width: 640px) {
            table.attendance-calendar, table.attendance-calendar tbody, table.attendance-calendar tr, table.attendance-calendar td { display: block; width: auto; }
            table.attendance-calendar thead, table.attendance-calendar td.blank { display: none; }
            table.attendance-calendar td.date-cell { height: auto; border-width: 0 0 1px 0; padding: 8px 4px; }
            .day-weekday { display: inline; }
        }
    </style>
</head>
<body>
<header>
    <h1>月間打刻実績</h1>
    <nav>ログイン中: <?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?>さん | <a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<div class="month-nav">
    <a href="?month=<?= htmlspecialchars($month['prev_month'], ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <a href="?month=<?= htmlspecialchars($currentMonth, ENT_QUOTES, 'UTF-8') ?>">今月</a>
    <a href="?month=<?= htmlspecialchars($month['next_month'], ENT_QUOTES, 'UTF-8') ?>">次月 →</a>
    <form method="get" action="/staff/attendance_monthly.php">
        <input type="month" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">表示</button>
    </form>
</div>

<h2><?= (int) $month['month_start']->format('Y') ?>年<?= (int) $month['month_start']->format('n') ?>月の打刻実績</h2>

<div class="summary">
    <span>出勤日数: <?= $workDayCount ?>日</span>
    <span>合計休憩: <?= htmlspecialchars(format_minutes_as_hours($totalBreakMinutes), ENT_QUOTES, 'UTF-8') ?></span>
    <span>合計実働: <?= htmlspecialchars(format_minutes_as_hours($totalWorkMinutes), ENT_QUOTES, 'UTF-8') ?></span>
</div>

<table class="attendance-calendar">
    <thead>
        <tr>
            <?php foreach ($weekdayLabels as $i => $label): ?>
                <th class="<?= $i === 5 ? 'sat' : ($i === 6 ? 'sun-holiday' : '') ?>"><?= $label ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($calendarWeeks as $week): ?>
            <tr>
                <?php foreach ($week as $d): ?>
                    <?php if ($d === null): ?>
                        <td class="blank"></td>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php
                    $dateStr = $d->format('Y-m-d');
                    $weekdayIndex = (int) $d->format('N');
                    $dayClass = '';
                    if ($weekdayIndex === 6) {
                        $dayClass = 'sat';
                    } elseif ($weekdayIndex === 7 || is_holiday_in_set($dateStr, $holidayDates)) {
                        $dayClass = 'sun-holiday';
                    }
                    ?>
                    <td class="date-cell<?= $dateStr === $todayStr ? ' today' : '' ?>">
                        <div class="day-number <?= $dayClass ?>"><?= (int) $d->format('j') ?>日<span class="day-weekday">（<?= $weekdayLabels[$weekdayIndex - 1] ?>）</span></div>
                        <?php render_attendance_day_cell($shiftsByDate[$dateStr] ?? [], $attendanceByDate[$dateStr] ?? []); ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
