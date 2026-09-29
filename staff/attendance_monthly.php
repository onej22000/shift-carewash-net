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

// ---- 月間集計（管理者の賃金確認 admin/wages.php と同じ calc_wage_summary() で本人分のみ計算。表示専用） ----
// 交通費・手当・合計・確定状態の扱いは build_monthly_wage_overview() で admin/attendance_monthly.php と共通化している。
$wageEmployeeStmt = $pdo->prepare(
    'SELECT id, hourly_wage_weekday, hourly_wage_holiday, commute_allowance_type, commute_allowance_amount
     FROM employees WHERE id = :id'
);
$wageEmployeeStmt->execute([':id' => $employeeId]);
$wageEmployee = $wageEmployeeStmt->fetch();

$wageOverview = build_monthly_wage_overview($pdo, $wageEmployee, $yearMonth);
$wageSummary = $wageOverview['summary'];
// 区分別（金額込み）: admin/wages.php の区分別集計と同じ build_category_wage_rows() で本人分のみ。
// 交通費・手当は確定済みの月なら確定時の値（build_monthly_wage_overview() の display_*）を使う
$categoryWageRows = build_category_wage_rows(
    $wageSummary,
    $wageOverview['display_commute_total'],
    $wageOverview['display_allowance_total'],
    $employeeId
);
$wageBreakdownRows = [
    [
        'label' => '平日',
        'days' => $wageSummary['weekday_attendance_days'],
        'total_minutes' => $wageSummary['weekday_total_minutes'],
        'overtime_minutes' => $wageSummary['weekday_overtime_minutes'],
        'night_minutes' => $wageSummary['weekday_night_minutes'],
        'hourly_wage' => (int) $wageEmployee['hourly_wage_weekday'],
        'base_wage' => $wageSummary['weekday_wage'],
        'overtime_wage' => $wageSummary['weekday_overtime_wage'],
        'night_wage' => $wageSummary['weekday_night_wage'],
        'total_wage' => $wageSummary['weekday_total_wage'],
    ],
    [
        'label' => '土日祝',
        'days' => $wageSummary['holiday_attendance_days'],
        'total_minutes' => $wageSummary['holiday_total_minutes'],
        'overtime_minutes' => $wageSummary['holiday_overtime_minutes'],
        'night_minutes' => $wageSummary['holiday_night_minutes'],
        'hourly_wage' => (int) $wageEmployee['hourly_wage_holiday'],
        'base_wage' => $wageSummary['holiday_wage'],
        'overtime_wage' => $wageSummary['holiday_overtime_wage'],
        'night_wage' => $wageSummary['holiday_night_wage'],
        'total_wage' => $wageSummary['holiday_total_wage'],
    ],
];
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
        .wage-summary { border: 1px solid #ccc; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
        .wage-summary h3 { margin: 0 0 8px; font-size: 1.05em; }
        .wage-summary dl { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px 16px; margin: 0 0 12px; }
        .wage-summary dt { font-size: 0.8em; color: #555; }
        .wage-summary dd { margin: 0; font-weight: bold; }
        .wage-summary dd.grand-total { color: #0b5ed7; font-size: 1.15em; }
        .wage-table-scroll { overflow-x: auto; }
        table.wage-breakdown { border-collapse: collapse; width: 100%; font-size: 0.9em; }
        table.wage-breakdown th, table.wage-breakdown td { border: 1px solid #ccc; padding: 6px; text-align: right; white-space: nowrap; }
        table.wage-breakdown th:first-child, table.wage-breakdown td:first-child { text-align: left; }
        table.wage-breakdown th { background: #f5f5f5; }
        .wage-note { font-size: 0.8em; color: #555; margin: 8px 0 0; }
        .wage-subheading { margin: 16px 0 6px; font-size: 0.95em; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; font-weight: normal; }
        .status-provisional { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #e6f4ea; color: #1e7e34; }
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

<section class="wage-summary">
    <h3>月間集計
        <?php if ($wageOverview['is_confirmed']): ?>
            <span class="status-badge status-confirmed">確定済み</span>
        <?php else: ?>
            <span class="status-badge status-provisional">未確定</span>
        <?php endif; ?>
    </h3>
    <dl>
        <div><dt>出勤日数</dt><dd><?= $wageSummary['attendance_days'] ?>日</dd></div>
        <div><dt>労働時間</dt><dd><?= htmlspecialchars(format_minutes_as_hours($wageSummary['total_minutes']), ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>残業時間</dt><dd><?= htmlspecialchars(format_minutes_as_hours($wageSummary['overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>深夜労働時間</dt><dd><?= htmlspecialchars(format_minutes_as_hours($wageSummary['night_minutes']), ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>基本給</dt><dd><?= number_format($wageSummary['base_wage']) ?>円</dd></div>
        <div><dt>残業手当</dt><dd><?= number_format($wageSummary['overtime_wage']) ?>円</dd></div>
        <div><dt>深夜手当</dt><dd><?= number_format($wageSummary['night_wage']) ?>円</dd></div>
        <div><dt>交通費</dt><dd><?= number_format($wageOverview['display_commute_total']) ?>円</dd></div>
        <div><dt>手当</dt><dd><?= number_format($wageOverview['display_allowance_total']) ?>円</dd></div>
        <div><dt>合計</dt><dd class="grand-total"><?= number_format($wageOverview['display_grand_total']) ?>円</dd></div>
    </dl>

    <div class="wage-table-scroll">
        <table class="wage-breakdown">
            <thead>
                <tr>
                    <th>区分</th>
                    <th>出勤日数</th>
                    <th>労働時間</th>
                    <th>残業時間</th>
                    <th>深夜労働時間</th>
                    <th>時給</th>
                    <th>基本給</th>
                    <th>残業手当</th>
                    <th>深夜手当</th>
                    <th>合計</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wageBreakdownRows as $breakdown): ?>
                    <tr>
                        <td><?= htmlspecialchars($breakdown['label'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $breakdown['days'] ?>日</td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($breakdown['total_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($breakdown['overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($breakdown['night_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format($breakdown['hourly_wage']) ?>円</td>
                        <td><?= number_format($breakdown['base_wage']) ?>円</td>
                        <td><?= number_format($breakdown['overtime_wage']) ?>円</td>
                        <td><?= number_format($breakdown['night_wage']) ?>円</td>
                        <td><?= number_format($breakdown['total_wage']) ?>円</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <h4 class="wage-subheading">区分別</h4>
    <div class="wage-table-scroll">
        <table class="wage-breakdown">
            <thead>
                <tr>
                    <th>区分</th>
                    <th>出勤日数</th>
                    <th>労働時間</th>
                    <th>残業時間</th>
                    <th>深夜労働時間</th>
                    <th>基本給</th>
                    <th>残業手当</th>
                    <th>深夜手当</th>
                    <th>交通費</th>
                    <th>手当</th>
                    <th>合計</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categoryWageRows as $categoryLabel => $categoryStats): ?>
                    <tr>
                        <td>
                            <?php if (isset(CATEGORY_COLORS[$categoryLabel])): ?>
                                <span class="category-badge" style="background:<?= htmlspecialchars(CATEGORY_COLORS[$categoryLabel], ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $categoryStats['attendance_days'] ?>日</td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($categoryStats['total_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($categoryStats['overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($categoryStats['night_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format($categoryStats['base_wage']) ?>円</td>
                        <td><?= number_format($categoryStats['overtime_wage']) ?>円</td>
                        <td><?= number_format($categoryStats['night_wage']) ?>円</td>
                        <td><?= number_format($categoryStats['commute_allowance']) ?>円</td>
                        <td><?= number_format($categoryStats['allowance']) ?>円</td>
                        <td><?= number_format($categoryStats['grand_total']) ?>円</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="wage-note">区分別の出勤日数は延べ日数です（同じ日に複数の区分で打刻した日は、それぞれの区分に1日ずつ数えるため、合計が月の出勤日数より多くなることがあります）。1日8時間を超えた残業時間は、打刻の時刻順で8時間を超えた後に働いていた区分に計上しています。</p>
    <p class="wage-note">区分別の基本給・残業手当・深夜手当は、その日の適用時給（平日/土日祝）を区分別の時間に掛けて按分したものです（端数は各項目の合計が上の月間集計と1円単位で一致するよう調整）。交通費は通勤1回（同じ日でも退勤〜再出勤が<?= COMMUTE_SEPARATE_TRIP_GAP_MINUTES ?>分以上空けば別の1回）ごとに、その間に打刻した区分へ計上しています（1回の中で複数の区分に打刻した場合は、手当と同じ区分に計上）。手当は按分せず、<?= htmlspecialchars(allowance_category_for_employee($employeeId), ENT_QUOTES, 'UTF-8') ?>に全額計上しています。</p>
    <p class="wage-note">退勤済みの打刻のみを集計しています（勤務中の打刻は含みません）。未確定の月は、打刻の修正などにより金額が変わることがあります。</p>
</section>

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
