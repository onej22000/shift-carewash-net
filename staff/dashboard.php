<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 共用アカウント（複数人が1つのログインを使い回す）は「本人」という単一の状態を持たないため、
// 賃金見込み・本日のシフト・打刻漏れ警告・車両警告など個人向けセクションは非表示にし、
// 出退勤・休憩は「本日出勤中の全員」一覧から人を選ぶ形に差し替える（2026-08-08）。
$isSharedAccount = (int) ($staff['is_shared_account'] ?? 0) === 1;

$flash = pop_flash();

$now = new DateTime();
$today = $now->format('Y-m-d');

$jiroChecklist = build_jiro_checklist_data($pdo, $now);
$jiroTodayFacilityCount = count($jiroChecklist['today_rows']);
$jiroTodayBagTotal = array_sum(array_column($jiroChecklist['today_rows'], 'row_total'));

// 自分が使用したことのある車両（vehicle_checksの記録から判定。専用の割当管理は無いため）の警告のみ表示する。
$myVehicleIdsStmt = $pdo->prepare('SELECT DISTINCT vehicle_id FROM vehicle_checks WHERE employee_id = :employee_id AND deleted_at IS NULL');
$myVehicleIdsStmt->execute([':employee_id' => $staff['id']]);
$myVehicleIds = array_map('intval', array_column($myVehicleIdsStmt->fetchAll(), 'vehicle_id'));
$vehicleAlerts = array_values(array_filter(
    calc_vehicle_alerts($pdo, $today),
    static fn (array $alert): bool => in_array($alert['vehicle_id'], $myVehicleIds, true)
));

// 施設とスタッフの間に担当紐付けが無く、全スタッフに全施設の状況を知ってほしいため、
// admin側と同じ全件をフィルタなしで表示する（共用アカウントでも非表示にしない）。
$laundryNeededAlerts = calc_laundry_needed_alerts($pdo);
$returnNeededAlerts = calc_return_needed_alerts($pdo, $today);
$pickupNeededAlerts = calc_pickup_needed_alerts($pdo, $now);

// 出勤忘れ・退勤忘れも全施設アラートと同様、全スタッフに全員分を表示する（自分以外の未打刻も分かるように）。
$clockInNeededAlerts = calc_clock_in_needed_alerts($pdo, $now);
$clockOutNeededAlerts = calc_clock_out_needed_alerts($pdo, $now);

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

$openAttendanceToday = $isSharedAccount ? find_open_attendance_today($pdo) : [];

$unreadBoardStmt = $pdo->prepare(
    'SELECT EXISTS(
         SELECT 1 FROM board_posts p
         WHERE p.deleted_at IS NULL
           AND p.id > COALESCE(
               (SELECT last_seen_post_id FROM board_read_status WHERE employee_id = :employee_id),
               0
           )
     )'
);
$unreadBoardStmt->execute([':employee_id' => $staff['id']]);
$hasUnreadBoardPosts = (bool) $unreadBoardStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/brand-header.css">
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
        .shared-attendance-status { text-align: left; margin-bottom: 12px; }
        .shared-attendance-table { margin-bottom: 12px; }
        .shared-attendance-table th, .shared-attendance-table td { font-size: 0.9em; }
        .shared-attendance-actions { white-space: nowrap; }
        .shared-attendance-actions form { display: inline-block; margin: 0 4px 0 0; }
        .shared-attendance-actions button, .shared-attendance-actions .clock-out-link { display: inline-block; padding: 6px 12px; border-radius: 4px; border: none; color: #fff; cursor: pointer; text-decoration: none; font-size: 0.9em; }
        .shared-attendance-actions .break-start { background: #856404; }
        .shared-attendance-actions .break-end { background: #1e7e34; }
        .shared-attendance-actions .clock-out-link { background: #b3261e; }
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
        .laundry-status-panel { padding: 12px 16px; background: linear-gradient(145deg, #f2faff 0%, #d8efff 100%); border: 1px solid #78bde8; border-radius: 6px; color: #0b4a6f; margin-bottom: 16px; }
        .laundry-status-panel h2 { margin: 0 0 8px; font-size: 1.05em; color: #1687c8; }
        .laundry-status-panel > ul { margin: 0; padding-left: 20px; }
        .laundry-status-panel > ul > li { margin-bottom: 4px; }
        .laundry-status-panel h3 { margin: 12px 0 6px; font-size: 0.95em; color: #1687c8; }
        .laundry-status-panel h3:first-of-type { margin-top: 0; }
        .pickup-status-panel { padding: 12px 16px; background: linear-gradient(145deg, #fff9e8 0%, #ffedb0 100%); border: 1px solid #e2bd52; border-radius: 6px; color: #7a5b00; margin-bottom: 16px; }
        .pickup-status-panel h2 { margin: 0 0 8px; font-size: 1.05em; color: #d89b00; }
        .pickup-status-panel ul { margin: 0; padding-left: 20px; }
        .pickup-status-panel li { margin-bottom: 4px; }
        .clock-status-panel { padding: 12px 16px; background: linear-gradient(145deg, #eceeff 0%, #d4d9ff 100%); border: 1px solid #8b93d6; border-radius: 6px; color: #33366e; margin-bottom: 16px; }
        .clock-status-panel h2 { margin: 0 0 8px; font-size: 1.05em; color: #4a4fb0; }
        .clock-status-panel > ul { margin: 0; padding-left: 20px; }
        .clock-status-panel > ul > li { margin-bottom: 4px; }
        .clock-status-panel h3 { margin: 12px 0 6px; font-size: 0.95em; color: #4a4fb0; }
        .clock-status-panel h3:first-of-type { margin-top: 0; }
        .estimate-cards { display: flex; gap: 12px; flex-wrap: wrap; }
        .estimate-card { flex: 1; min-width: 220px; border: 1px solid #ccc; border-radius: 8px; padding: 12px 16px; }
        .estimate-card h3 { margin: 0 0 8px; font-size: 1em; }
        .estimate-card .estimate-minutes { font-size: 1.1em; }
        .estimate-card .estimate-wage { font-size: 1.3em; font-weight: bold; color: #0b5ed7; }
        .estimate-category-breakdown { list-style: none; margin-top: 6px; font-size: 0.85em; color: #555; }
        .estimate-category-breakdown li { padding: 1px 0; }
        .estimate-category-breakdown .estimate-category-total { font-weight: bold; border-top: 1px solid #ddd; margin-top: 4px; padding-top: 4px; color: #222; }
        .estimate-note { font-size: 0.8em; color: #555; margin-top: 8px; }
        .staff-menu-group { margin-top: 20px; }
        .staff-menu-group h3 { margin: 0 0 12px; font-size: 1em; }
        .nav-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .nav-card { display: block; position: relative; overflow: hidden; border: 1px solid #aeb6c1; border-radius: 14px; padding: 18px; text-decoration: none; color: #222; background: linear-gradient(145deg, #f4f6f8 0%, #d6dce3 100%); box-shadow: 0 7px 16px rgba(30, 55, 90, 0.13), 0 2px 4px rgba(30, 55, 90, 0.08), inset 0 1px 0 rgba(255,255,255,0.95); transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease; }
        .nav-card::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 4px; background: linear-gradient(90deg, #0b5ed7, #52a3ff); }
        .pickup-menu-group .nav-card, .nav-card.pickup-related { background: linear-gradient(145deg, #fff9e8 0%, #ffedb0 100%); border-color: #e2bd52; }
        .pickup-menu-group .nav-card::before, .nav-card.pickup-related::before { background: linear-gradient(90deg, #d89b00, #ffc83d); }
        .laundry-menu-group .nav-card, .nav-card.laundry-related { background: linear-gradient(145deg, #f2faff 0%, #d8efff 100%); border-color: #78bde8; }
        .laundry-menu-group .nav-card::before, .nav-card.laundry-related::before { background: linear-gradient(90deg, #1687c8, #62c6f5); }
        .nav-card:hover, .nav-card:focus-visible { border-color: #0b5ed7; box-shadow: 0 12px 24px rgba(30, 80, 140, 0.18), 0 4px 8px rgba(30, 55, 90, 0.12); transform: translateY(-3px); outline: none; }
        .nav-card:active { transform: translateY(1px); box-shadow: 0 3px 8px rgba(30, 55, 90, 0.16); }
        .nav-card h3 { font-size: 1.05em; margin: 0 0 8px; color: #0b5ed7; }
        .nav-card p { margin: 0; font-size: 0.9em; color: #555; }
        .new-badge { display: inline-block; margin-left: 8px; padding: 2px 7px; border-radius: 999px; background: #d93025; color: #fff; font-size: 0.72em; vertical-align: middle; }
        @media (max-width: 900px) {
            body { margin: 8px; font-size: 17px; }
            header { align-items: flex-start; gap: 10px; }
            header nav { width: 100%; }
            .clock-section { padding: 12px 8px; }
            .nav-cards { grid-template-columns: minmax(0, 1fr); gap: 14px; width: 100%; }
            .nav-card {
                box-sizing: border-box;
                width: 100%;
                min-height: 150px;
                padding: 32px 18px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .nav-card h3 { margin: 0; font-size: 3.9em; line-height: 1.15; overflow-wrap: anywhere; }
            .nav-card p { font-size: 3em; line-height: 1.2; }
            .staff-menu-group > h3 { font-size: 1.15em; }
            .staff-menu-group .nav-card h3 { margin: 0; font-size: 3.9em; line-height: 1.15; overflow-wrap: anywhere; }
            #clock-button, #break-button { box-sizing: border-box; min-height: 110px; width: 100%; font-size: 3.45em; }
            .estimate-cards { display: block; }
            .estimate-card { box-sizing: border-box; width: 100%; margin-bottom: 12px; }
            .shift-estimates > h2,
            .today-shifts > h2,
            .today-attendance > h2 { font-size: 3em; line-height: 1.2; }
            .estimate-card h3 { font-size: 3em; line-height: 1.2; }
            .estimate-card .estimate-minutes { font-size: 3.3em; line-height: 1.2; }
            .estimate-card .estimate-wage { font-size: 3.9em; line-height: 1.2; }
            .estimate-category-breakdown { font-size: 2.55em; line-height: 1.35; padding-left: 0; }
            .estimate-category-breakdown li { padding: 6px 0; }
            .estimate-category-breakdown .estimate-category-total { margin-top: 8px; padding-top: 8px; }
            .today-shifts table,
            .today-attendance table { font-size: 3em; }
            .shared-attendance-status { overflow-x: auto; }
            .shared-attendance-table { font-size: 1.3em; }
            .shared-attendance-actions button,
            .shared-attendance-actions .clock-out-link { font-size: 1em; padding: 10px 14px; }
            .today-empty-notice,
            .attendance-history-link { font-size: 3em; line-height: 1.25; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../includes/brand_header.php'; ?>
<header>
    <h1><?= $isSharedAccount ? '業務メニュー' : 'こんにちは、' . htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') . 'さん' ?></h1>
    <nav><a href="/staff/change_password.php">パスワード変更</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if (!$isSharedAccount && !empty($vehicleAlerts)): ?>
    <div class="vehicle-alert-banner">
        <h2>⚠ 車両の期限・交換時期に関する警告</h2>
        <ul>
            <?php foreach ($vehicleAlerts as $alert): ?>
                <li><?= htmlspecialchars($alert['vehicle_label'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['label'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?>）</li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($laundryNeededAlerts) || !empty($returnNeededAlerts)): ?>
    <div class="laundry-status-panel">
        <h2>集荷サイクルの状況</h2>
        <?php if (!empty($laundryNeededAlerts)): ?>
            <h3>要洗濯</h3>
            <ul>
                <?php foreach ($laundryNeededAlerts as $alert): ?>
                    <li><?= htmlspecialchars($alert['facility_name'], ENT_QUOTES, 'UTF-8') ?>：集荷日 <?= htmlspecialchars($alert['pickup_date'], ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($returnNeededAlerts)): ?>
            <h3>要返却</h3>
            <ul>
                <?php foreach ($returnNeededAlerts as $alert): ?>
                    <li><?= htmlspecialchars($alert['facility_name'], ENT_QUOTES, 'UTF-8') ?>：集荷日 <?= htmlspecialchars($alert['pickup_date'], ENT_QUOTES, 'UTF-8') ?>（返却予定日 <?= htmlspecialchars($alert['expected_return_date'], ENT_QUOTES, 'UTF-8') ?>）</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($pickupNeededAlerts)): ?>
    <div class="pickup-status-panel">
        <h2>未集荷</h2>
        <ul>
            <?php foreach ($pickupNeededAlerts as $alert): ?>
                <li><?= htmlspecialchars($alert['facility_name'], ENT_QUOTES, 'UTF-8') ?>：集荷予定日 <?= htmlspecialchars($alert['pickup_date'], ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($clockInNeededAlerts) || !empty($clockOutNeededAlerts)): ?>
    <div class="clock-status-panel">
        <h2>打刻の注意喚起</h2>
        <?php if (!empty($clockInNeededAlerts)): ?>
            <h3>出勤忘れ</h3>
            <ul>
                <?php foreach ($clockInNeededAlerts as $alert): ?>
                    <li><?= htmlspecialchars($alert['employee_name'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['work_date'], ENT_QUOTES, 'UTF-8') ?>（シフト開始 <?= htmlspecialchars($alert['shift_start_time'], ENT_QUOTES, 'UTF-8') ?>）</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($clockOutNeededAlerts)): ?>
            <h3>退勤忘れ</h3>
            <ul>
                <?php foreach ($clockOutNeededAlerts as $alert): ?>
                    <li><?= htmlspecialchars($alert['employee_name'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['work_date'], ENT_QUOTES, 'UTF-8') ?>（シフト終了 <?= htmlspecialchars($alert['shift_end_time'], ENT_QUOTES, 'UTF-8') ?>）</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$isSharedAccount && !empty($missedClockDates)): ?>
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
    <?php if ($isSharedAccount): ?>
        <div class="shared-attendance-status">
            <h2 class="staff-menu-title">本日出勤中のスタッフ</h2>
            <?php if (empty($openAttendanceToday)): ?>
                <p class="notice">本日出勤中のスタッフはいません。</p>
            <?php else: ?>
                <table class="simple shared-attendance-table">
                    <thead>
                        <tr>
                            <th>氏名</th>
                            <th>区分</th>
                            <th>出勤</th>
                            <th>状態</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openAttendanceToday as $rec): ?>
                            <?php $onBreak = $rec['break_start_at'] !== null && $rec['break_end_at'] === null; ?>
                            <tr>
                                <td><?= htmlspecialchars($rec['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($rec['category'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(substr($rec['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($onBreak): ?>
                                        休憩中（<?= htmlspecialchars(substr($rec['break_start_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?>〜）
                                    <?php else: ?>
                                        勤務中
                                    <?php endif; ?>
                                </td>
                                <td class="shared-attendance-actions">
                                    <?php if ($onBreak): ?>
                                        <form method="post" action="/staff/break.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="end">
                                            <input type="hidden" name="attendance_id" value="<?= (int) $rec['id'] ?>">
                                            <button type="submit" class="break-end">休憩戻り</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/staff/break.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="start">
                                            <input type="hidden" name="attendance_id" value="<?= (int) $rec['id'] ?>">
                                            <button type="submit" class="break-start">休憩入り</button>
                                        </form>
                                        <a href="/staff/clock_out.php?attendance_id=<?= (int) $rec['id'] ?>" class="clock-out-link">退勤する</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <a href="/staff/clock.php" id="clock-button" class="clock-in">出勤する</a>
        </div>
    <?php else: ?>
    <div class="clock-status">
        <?php if ($clockState === 'working'): ?>
            <?= htmlspecialchars(substr($openRecord['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?> から勤務中です
        <?php elseif ($clockState === 'on_break'): ?>
            <?= htmlspecialchars(substr($openRecord['break_start_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?> から休憩中です
        <?php elseif ($clockState === 'done'): ?>
            本日は完了した勤務があります（別の区分でさらに出勤することもできます）
        <?php else: ?>
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
    <?php endif; ?>

    <h2 class="staff-menu-title">業務メニュー</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/staff/boards.php">
            <h3>掲示板<?php if ($hasUnreadBoardPosts): ?><span class="new-badge">NEW</span><?php endif; ?></h3>
        </a>
    </div>
    <div class="staff-menu-group pickup-menu-group">
        <h3>集荷スタッフ用</h3>
        <div class="nav-cards">
            <a class="nav-card" href="/staff/collection_entry.php">
                <h3>集荷記録簿</h3>
            </a>
            <a class="nav-card" href="/staff/jiro_dashboard.php">
                <h3>本日の集荷予定</h3>
                <?php if ($jiroChecklist['today_schedule_label'] === null): ?>
                    <p>本日は集荷予定日ではありません。</p>
                <?php elseif ($jiroTodayFacilityCount === 0): ?>
                <?php else: ?>
                    <p><?= $jiroTodayFacilityCount ?>施設・合計<?= $jiroTodayBagTotal ?>袋の予定があります。</p>
                <?php endif; ?>
            </a>
        </div>
    </div>
    <div class="staff-menu-group laundry-menu-group">
        <h3>洗濯代行スタッフ用</h3>
        <div class="nav-cards">
            <a class="nav-card" href="/staff/collection_headcount.php">
                <h3>作業登録</h3>
            </a>
            <a class="nav-card" href="/admin/linen_trends.php">
                <h3>洗濯ネット推移予測</h3>
            </a>
        </div>
    </div>
</section>

<?php if (!$isSharedAccount): ?>
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
</section>

<section class="today-shifts">
    <h2>本日のシフト</h2>
    <?php if (empty($todayShifts)): ?>
        <p class="notice today-empty-notice">なし</p>
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
        <p class="notice today-empty-notice">なし</p>
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
    <p class="attendance-history-link" style="margin-top:8px;"><a href="/staff/history.php">過去の打刻履歴</a></p>
</section>
<?php endif; ?>

<section class="team-links">
    <h2>その他機能</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/staff/team_shifts.php"><h3>シフト表</h3></a>
        <a class="nav-card laundry-related" href="/staff/work_speed.php"><h3>作業速度分析</h3></a>
        <a class="nav-card" href="/staff/facilities.php"><h3>施設一覧</h3></a>
        <a class="nav-card pickup-related" href="/staff/collection_records.php"><h3>集荷記録簿</h3></a>
        <a class="nav-card pickup-related" href="/staff/travel_time.php"><h3>移動時間</h3></a>
        <a class="nav-card" href="/staff/consumable_stock.php"><h3>消耗品在庫管理</h3></a>
        <a class="nav-card pickup-related" href="/staff/vehicle_check_list.php"><h3>車両チェック記録簿</h3></a>
        <a class="nav-card pickup-related" href="/staff/vehicle_maintenance_list.php"><h3>車両管理記録</h3></a>
    </div>
</section>

<?php if (!$isSharedAccount): ?>
<section class="calendar-link">
    <h2>カレンダー連携</h2>
    <p><a href="/staff/calendar.php">シフトをGoogleカレンダー・iPhoneカレンダーに自動反映させる →</a></p>
</section>
<?php endif; ?>

</body>
</html>
