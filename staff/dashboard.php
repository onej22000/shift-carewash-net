<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$flash = pop_flash();

$today = (new DateTime())->format('Y-m-d');

// 自分が使用したことのある車両（vehicle_checksの記録から判定。専用の割当管理は無いため）の警告のみ表示する。
$myVehicleIdsStmt = $pdo->prepare('SELECT DISTINCT vehicle_id FROM vehicle_checks WHERE employee_id = :employee_id AND deleted_at IS NULL');
$myVehicleIdsStmt->execute([':employee_id' => $staff['id']]);
$myVehicleIds = array_map('intval', array_column($myVehicleIdsStmt->fetchAll(), 'vehicle_id'));
$vehicleAlerts = array_values(array_filter(
    calc_vehicle_alerts($pdo, $today),
    static fn (array $alert): bool => in_array($alert['vehicle_id'], $myVehicleIds, true)
));

const MISSED_CLOCK_DISPLAY_LIMIT = 5;
$missedClockDates = find_missed_clock_dates($pdo, (int) $staff['id'], $today);
$missedClockDatesDisplayed = array_slice($missedClockDates, 0, MISSED_CLOCK_DISPLAY_LIMIT);
$missedClockDatesHiddenCount = max(0, count($missedClockDates) - MISSED_CLOCK_DISPLAY_LIMIT);

$wageRateStmt = $pdo->prepare(
    'SELECT hourly_wage_weekday, hourly_wage_holiday FROM employees WHERE id = :id'
);
$wageRateStmt->execute([':id' => $staff['id']]);
$wageRates = $wageRateStmt->fetch();
$employeeForEstimate = [
    'id' => $staff['id'],
    'hourly_wage_weekday' => $wageRates['hourly_wage_weekday'],
    'hourly_wage_holiday' => $wageRates['hourly_wage_holiday'],
];

$currentYearMonth = (new DateTime())->format('Y-m');
$nextYearMonth = (new DateTime())->modify('+1 month')->format('Y-m');

$shiftEstimates = [
    $currentYearMonth => calc_shift_wage_estimate($pdo, $employeeForEstimate, $currentYearMonth),
    $nextYearMonth => calc_shift_wage_estimate($pdo, $employeeForEstimate, $nextYearMonth),
];

$shiftsStmt = $pdo->prepare(
    'SELECT start_time, end_time, break_minutes, note
     FROM shifts
     WHERE employee_id = :employee_id AND work_date = :work_date
     ORDER BY start_time'
);
$shiftsStmt->execute([':employee_id' => $staff['id'], ':work_date' => $today]);
$todayShifts = $shiftsStmt->fetchAll();

$openStmt = $pdo->prepare(
    "SELECT id, clock_in_at, break_start_at, break_end_at
     FROM attendance
     WHERE employee_id = :employee_id AND status = 'working' AND DATE(clock_in_at) = CURDATE()
       AND deleted_at IS NULL
     ORDER BY clock_in_at DESC
     LIMIT 1"
);
$openStmt->execute([':employee_id' => $staff['id']]);
$openRecord = $openStmt->fetch();

$historyStmt = $pdo->prepare(
    "SELECT id, clock_in_at, clock_out_at, work_minutes, status, total_break_minutes
     FROM attendance
     WHERE employee_id = :employee_id AND DATE(clock_in_at) = CURDATE()
       AND deleted_at IS NULL
     ORDER BY clock_in_at"
);
$historyStmt->execute([':employee_id' => $staff['id']]);
$todayAttendance = $historyStmt->fetchAll();

if ($openRecord !== false) {
    $isOnBreak = $openRecord['break_start_at'] !== null && $openRecord['break_end_at'] === null;
    $clockState = $isOnBreak ? 'on_break' : 'working';
} else {
    $hasDoneToday = false;
    foreach ($todayAttendance as $record) {
        if ($record['status'] === 'done') {
            $hasDoneToday = true;
            break;
        }
    }
    $clockState = $hasDoneToday ? 'done' : 'not_started';
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>従業員ダッシュボード | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        section { margin-bottom: 24px; }
        .clock-section { text-align: center; padding: 20px; border: 1px solid #ccc; border-radius: 8px; }
        .clock-status { margin-bottom: 12px; font-size: 0.95em; color: #555; }
        #clock-button, #break-button { display: inline-block; font-size: 1.1em; padding: 12px 32px; border-radius: 6px; border: none; color: #fff; cursor: pointer; text-decoration: none; margin: 4px; }
        #clock-button.clock-in { background: #0b5ed7; }
        #clock-button.clock-out { background: #b3261e; }
        #break-button.break-start { background: #856404; }
        #break-button.break-end { background: #1e7e34; }
        .inline-form { display: inline; }
        .link-danger { background: none; border: none; padding: 0; color: #b3261e; text-decoration: underline; cursor: pointer; font-size: 1em; }
        table.simple { border-collapse: collapse; width: 100%; }
        table.simple th, table.simple td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        table.simple th { background: #f5f5f5; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .missed-clock-banner { padding: 12px 16px; background: #fdecea; border: 2px solid #b3261e; border-radius: 6px; color: #7a1913; margin-bottom: 16px; }
        .missed-clock-banner h2 { margin: 0 0 8px; font-size: 1.05em; color: #b3261e; }
        .missed-clock-banner ul { margin: 0 0 8px; padding-left: 20px; }
        .missed-clock-banner li { margin-bottom: 4px; }
        .missed-clock-banner .missed-clock-more { font-size: 0.9em; color: #7a1913; }
        .missed-clock-banner a { color: #0b5ed7; font-weight: bold; }
        .vehicle-alert-banner { padding: 12px 16px; background: #fdecea; border: 2px solid #b3261e; border-radius: 6px; color: #7a1913; margin-bottom: 16px; }
        .vehicle-alert-banner h2 { margin: 0 0 8px; font-size: 1.05em; color: #b3261e; }
        .vehicle-alert-banner ul { margin: 0; padding-left: 20px; }
        .vehicle-alert-banner li { margin-bottom: 4px; }
        .estimate-cards { display: flex; gap: 12px; flex-wrap: wrap; }
        .estimate-card { flex: 1; min-width: 220px; border: 1px solid #ccc; border-radius: 8px; padding: 12px 16px; }
        .estimate-card h3 { margin: 0 0 8px; font-size: 1em; }
        .estimate-card .estimate-minutes { font-size: 1.1em; }
        .estimate-card .estimate-wage { font-size: 1.3em; font-weight: bold; color: #0b5ed7; }
        .estimate-category-breakdown { list-style: none; margin-top: 6px; font-size: 0.85em; color: #555; }
        .estimate-category-breakdown li { padding: 1px 0; }
        .estimate-category-breakdown .estimate-category-total { font-weight: bold; border-top: 1px solid #ddd; margin-top: 4px; padding-top: 4px; color: #222; }
        .estimate-note { font-size: 0.8em; color: #555; margin-top: 8px; }
        table.staff-menu-table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        table.staff-menu-table th, table.staff-menu-table td { border: 1px solid #ccc; padding: 8px; text-align: left; vertical-align: middle; }
        table.staff-menu-table thead th { background: #f5f5f5; text-align: left; }
        table.staff-menu-table tbody th { background: #f5f5f5; width: 160px; }
        table.staff-menu-table td a { display: block; padding: 6px 4px; color: #0b5ed7; text-decoration: none; }
        table.staff-menu-table td a:hover { text-decoration: underline; }
        @media (max-width: 600px) {
            table.staff-menu-table, table.staff-menu-table tbody, table.staff-menu-table tr, table.staff-menu-table th, table.staff-menu-table td {
                display: block;
                width: 100%;
                box-sizing: border-box;
            }
            table.staff-menu-table thead { display: none; }
            table.staff-menu-table tr { border: none; }
            table.staff-menu-table tbody th { background: #f5f5f5; border: 1px solid #ccc; border-bottom: none; margin-top: 12px; }
            table.staff-menu-table tbody td { border: 1px solid #ccc; border-top: none; }
            table.staff-menu-table td a { padding: 10px 8px; font-size: 1.05em; }
        }
    </style>
</head>
<body>
<header>
    <h1>こんにちは、<?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?>さん</h1>
    <nav><a href="/staff/change_password.php">パスワード変更</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if (!empty($vehicleAlerts)): ?>
    <div class="vehicle-alert-banner">
        <h2>⚠ 車両の期限・交換時期に関する警告</h2>
        <ul>
            <?php foreach ($vehicleAlerts as $alert): ?>
                <li><?= htmlspecialchars($alert['vehicle_label'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['label'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?>）</li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($missedClockDates)): ?>
    <div class="missed-clock-banner">
        <h2>⚠ 打刻漏れの可能性があります</h2>
        <ul>
            <?php foreach ($missedClockDatesDisplayed as $missedItem): ?>
                <?php
                $missedDateLabel = (new DateTime($missedItem['date']))->format('n月j日');
                $missedYearMonth = substr($missedItem['date'], 0, 7);
                $missedMonthLocked = is_month_confirmed($pdo, (int) $staff['id'], $missedYearMonth);
                ?>
                <li>
                    <?= htmlspecialchars($missedDateLabel, ENT_QUOTES, 'UTF-8') ?>のシフトで打刻が確認できません
                    <?php if ($missedMonthLocked): ?>
                        （賃金確定済みの月のため、管理者にご連絡ください）
                    <?php elseif ($missedItem['attendance_id'] !== null): ?>
                        （<a href="/staff/attendance_edit.php?id=<?= (int) $missedItem['attendance_id'] ?>">打刻を修正する</a>）
                    <?php else: ?>
                        （<a href="/staff/attendance_add.php?date=<?= htmlspecialchars($missedItem['date'], ENT_QUOTES, 'UTF-8') ?>">打刻を追加する</a>）
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($missedClockDatesHiddenCount > 0): ?>
            <p class="missed-clock-more">他<?= (int) $missedClockDatesHiddenCount ?>件</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="clock-section">
    <div class="clock-status">
        <?php if ($clockState === 'working'): ?>
            <?= htmlspecialchars(substr($openRecord['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?> から勤務中です
        <?php elseif ($clockState === 'on_break'): ?>
            <?= htmlspecialchars(substr($openRecord['break_start_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?> から休憩中です
        <?php elseif ($clockState === 'done'): ?>
            本日は完了した勤務があります（別の区分でさらに出勤することもできます）
        <?php else: ?>
            本日はまだ出勤していません
        <?php endif; ?>
    </div>

    <?php if ($clockState === 'not_started' || $clockState === 'done'): ?>
        <a href="/staff/clock.php" id="clock-button" class="clock-in">出勤する</a>
    <?php elseif ($clockState === 'working'): ?>
        <a href="/staff/clock_out.php" id="clock-button" class="clock-out">退勤する</a>
        <form method="post" action="/staff/break.php" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="start">
            <button type="submit" id="break-button" class="break-start">休憩入り</button>
        </form>
    <?php elseif ($clockState === 'on_break'): ?>
        <form method="post" action="/staff/break.php" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="end">
            <button type="submit" id="break-button" class="break-end">休憩戻り</button>
        </form>
        <p class="notice" style="margin-top:8px;">休憩中は退勤できません。休憩から戻ってから退勤してください。</p>
    <?php endif; ?>

    <table class="staff-menu-table">
        <thead>
            <tr>
                <th>対象スタッフ</th>
                <th>メニュー</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <th rowspan="3">集荷スタッフ用</th>
                <td><a href="/staff/vehicle_check_list.php">集荷前車両等チェック記録を見る・登録する</a></td>
            </tr>
            <tr>
                <td><a href="/staff/collection_entry.php">集荷・配送記録を入力する</a></td>
            </tr>
            <tr>
                <td><a href="/staff/vehicle_maintenance_list.php">車両管理記録を見る・登録する</a></td>
            </tr>
            <tr>
                <th rowspan="2">洗濯代行スタッフ用</th>
                <td><a href="/staff/collection_headcount.php">到着リネン袋の洗濯ネット数（集荷人数）を確認・登録</a></td>
            </tr>
            <tr>
                <td><a href="/staff/work_records.php">作業実績を入力・編集する</a></td>
            </tr>
        </tbody>
    </table>
</section>

<section class="shift-estimates">
    <h2>今月・来月のシフト予定</h2>
    <div class="estimate-cards">
        <?php foreach ([$currentYearMonth, $nextYearMonth] as $ym): ?>
            <?php
            $estimate = $shiftEstimates[$ym];
            [$ymYear, $ymMonth] = explode('-', $ym);
            ?>
            <div class="estimate-card">
                <h3><?= (int) $ymYear ?>年<?= (int) $ymMonth ?>月の予定</h3>
                <?php if (empty($estimate['daily'])): ?>
                    <p class="notice">この月のシフト登録はまだありません。</p>
                <?php else: ?>
                    <p class="estimate-minutes">勤務時間: <?= htmlspecialchars(format_minutes_as_hours($estimate['total_minutes']), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="estimate-wage">推定賃金: <?= number_format($estimate['total_wage']) ?>円（残業含む見込み）</p>
                    <?php if (!empty($estimate['category_wage'])): ?>
                    <ul class="estimate-category-breakdown">
                        <?php foreach (SHIFT_CATEGORIES as $category): ?>
                        <li><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>：<?= number_format($estimate['category_wage'][$category] ?? 0) ?>円</li>
                        <?php endforeach; ?>
                        <li class="estimate-category-total">合計：<?= number_format($estimate['total_wage']) ?>円</li>
                    </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="estimate-note">※シフト予定に基づく見込みです。実際の打刻・時給変更により金額は変動する場合があります。</p>
</section>

<section class="today-shifts">
    <h2>本日のシフト</h2>
    <?php if (empty($todayShifts)): ?>
        <p class="notice">本日のシフト登録はありません。</p>
    <?php else: ?>
        <table class="simple">
            <thead>
                <tr>
                    <th>開始</th>
                    <th>終了</th>
                    <th>休憩</th>
                    <th>備考</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($todayShifts as $shift): ?>
                    <tr>
                        <td><?= htmlspecialchars(substr($shift['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(substr($shift['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $shift['break_minutes'] ?>分</td>
                        <td><?= htmlspecialchars($shift['note'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="today-attendance">
    <h2>本日の打刻履歴</h2>
    <?php if (empty($todayAttendance)): ?>
        <p class="notice">本日の打刻はまだありません。</p>
    <?php else: ?>
        <table class="simple">
            <thead>
                <tr>
                    <th>出勤</th>
                    <th>退勤</th>
                    <th>休憩</th>
                    <th>実働</th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($todayAttendance as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars(substr($record['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $record['clock_out_at'] !== null ? htmlspecialchars(substr($record['clock_out_at'], 11, 5), ENT_QUOTES, 'UTF-8') : '(勤務中)' ?></td>
                        <td><?= $record['total_break_minutes'] !== null ? (int) $record['total_break_minutes'] . '分' : '-' ?></td>
                        <td><?= $record['work_minutes'] !== null ? htmlspecialchars(format_minutes_as_hours((int) $record['work_minutes']), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><a href="/staff/attendance_edit.php?id=<?= (int) $record['id'] ?>">編集</a></td>
                        <td>
                            <form method="post" action="/staff/attendance_edit.php" class="inline-form"
                                  onsubmit="return confirm('この打刻記録を削除します。よろしいですか？');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                <button type="submit" class="link-danger">取り消し</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <p style="margin-top:8px;"><a href="/staff/history.php">過去の打刻履歴を日付指定で見る →</a></p>
</section>

<section class="team-links">
    <h2>チーム情報</h2>
    <p><a href="/staff/team_shifts.php">全員のシフト表を見る</a></p>
    <p><a href="/staff/team_stats.php">施設別・作業実績を比較する</a></p>
    <p><a href="/staff/work_status.php">作業状況・残数を確認する</a></p>
    <p><a href="/staff/facilities.php">施設一覧を見る</a></p>
    <p><a href="/staff/collection_records.php">集荷・配送記録簿を見る</a></p>
    <p><a href="/staff/travel_time.php">施設間移動時間を確認する</a></p>
    <p><a href="/staff/consumable_stock.php">消耗品在庫管理を見る</a></p>
</section>

<section class="calendar-link">
    <h2>カレンダー連携</h2>
    <p><a href="/staff/calendar.php">シフトをGoogleカレンダー・iPhoneカレンダーに自動反映させる →</a></p>
</section>

</body>
</html>
