<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();
// 適用日を迎えた時給履歴（pay_wage_history）を employees の表示用時給に反映する
sync_employee_wages_from_history($pdo);

$now = new DateTime();
$vehicleAlerts = calc_vehicle_alerts($pdo, $now->format('Y-m-d'));
$laundryNeededAlerts = calc_laundry_needed_alerts($pdo);
$returnNeededAlerts = calc_return_needed_alerts($pdo, $now->format('Y-m-d'));
$pickupNeededAlerts = calc_pickup_needed_alerts($pdo, $now);
// 従業員ダッシュボードと同じ共通関数（確認済みの行は除外済み）
$activeAttendanceAlerts = calc_active_attendance_alerts($pdo, $now);
$clockInNeededAlerts = $activeAttendanceAlerts['clock_in'];
$clockOutNeededAlerts = $activeAttendanceAlerts['clock_out'];

// 打刻の注意喚起の「確認済み」：管理者ダッシュボードの表示だけを消す（attendance・shiftsは変更しない）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。再度お試しください。');
    } elseif ($action === 'dismiss_clock_alert' || $action === 'dismiss_all_clock_alerts') {
        // 今この時点で表示対象（条件を満たし、まだ確認済みでない）の行だけを対象にする
        $currentAlerts = [];
        foreach (['clock_in' => $clockInNeededAlerts, 'clock_out' => $clockOutNeededAlerts] as $alertType => $alerts) {
            foreach ($alerts as $alert) {
                $currentAlerts[attendance_alert_key($alertType, $alert['employee_id'], $alert['work_date'])] = [
                    'alert_type' => $alertType,
                    'employee_id' => $alert['employee_id'],
                    'target_date' => $alert['work_date'],
                ];
            }
        }

        $postedKeys = $action === 'dismiss_clock_alert'
            ? [(string) ($_POST['alert_key'] ?? '')]
            : array_map('strval', (array) ($_POST['alert_keys'] ?? []));
        $items = [];
        foreach ($postedKeys as $key) {
            if (isset($currentAlerts[$key])) {
                $items[$key] = $currentAlerts[$key];
            }
        }

        $dismissedCount = $items === [] ? 0 : dismiss_attendance_alerts($pdo, array_values($items), (int) $admin['id']);
        if ($dismissedCount === 0) {
            set_flash('error', '対象の注意喚起が見つかりませんでした（既に解消または確認済みの可能性があります）。');
        } else {
            set_flash('success', $dismissedCount . '件の打刻の注意喚起を確認済みにしました。');
        }
    }
    header('Location: /admin/dashboard.php');
    exit;
}

$flash = pop_flash();
$csrfToken = csrf_token();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ダッシュボード | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .greeting { font-size: 1.1em; margin-bottom: 24px; }
        .nav-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .nav-card { display: block; position: relative; overflow: hidden; border: 1px solid #aeb6c1; border-radius: 14px; padding: 18px; text-decoration: none; color: #222; background: linear-gradient(145deg, #f4f6f8 0%, #d6dce3 100%); box-shadow: 0 7px 16px rgba(30, 55, 90, 0.13), 0 2px 4px rgba(30, 55, 90, 0.08), inset 0 1px 0 rgba(255,255,255,0.95); transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease; }
        .nav-card::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 4px; background: linear-gradient(90deg, #0b5ed7, #52a3ff); }
        .work-related .nav-card { background: linear-gradient(145deg, #f2faff 0%, #d8efff 100%); border-color: #78bde8; }
        .work-related .nav-card::before { background: linear-gradient(90deg, #1687c8, #62c6f5); }
        .pickup-related .nav-card { background: linear-gradient(145deg, #fff9e8 0%, #ffedb0 100%); border-color: #e2bd52; }
        .pickup-related .nav-card::before { background: linear-gradient(90deg, #d89b00, #ffc83d); }
        .wage-related .nav-card { background: linear-gradient(145deg, #f1faf4 0%, #d2efdc 100%); border-color: #7cc495; }
        .wage-related .nav-card::before { background: linear-gradient(90deg, #1e8e4e, #5cc98a); }
        .nav-card:hover, .nav-card:focus-visible { border-color: #0b5ed7; box-shadow: 0 12px 24px rgba(30, 80, 140, 0.18), 0 4px 8px rgba(30, 55, 90, 0.12); transform: translateY(-3px); outline: none; }
        .nav-card:active { transform: translateY(1px); box-shadow: 0 3px 8px rgba(30, 55, 90, 0.16); }
        .nav-card h2 { font-size: 1.05em; margin: 0; color: #0b5ed7; }
        .nav-card p { margin: 0; font-size: 0.9em; color: #555; }
        .badge { display: inline-block; font-size: 0.75em; padding: 2px 6px; border-radius: 4px; background: #fff3cd; color: #856404; margin-left: 6px; }
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
        .clock-status-panel .panel-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
        .clock-status-panel .panel-head h2 { margin: 0; }
        .clock-status-panel form { display: inline; margin: 0; }
        .clock-status-panel li form { margin-left: 10px; }
        .clock-status-panel button { font-size: 0.8em; padding: 2px 10px; border: 1px solid #8b93d6; border-radius: 4px; background: #fff; color: #33366e; cursor: pointer; }
        .clock-status-panel button:hover { background: #eceeff; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .dashboard-section { margin-top: 32px; }
        .dashboard-section > h2 { font-size: 1.2em; }
    </style>
</head>
<body>
<header>
    <h1>管理者ダッシュボード</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>


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
        <div class="panel-head">
            <h2>打刻の注意喚起</h2>
            <form method="post" action="/admin/dashboard.php" onsubmit="return confirm('表示中の打刻の注意喚起をすべて確認済みにします。よろしいですか？\n（打刻・シフトのデータは変更されません）');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="dismiss_all_clock_alerts">
                <?php foreach ($clockInNeededAlerts as $alert): ?>
                    <input type="hidden" name="alert_keys[]" value="<?= htmlspecialchars(attendance_alert_key('clock_in', $alert['employee_id'], $alert['work_date']), ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
                <?php foreach ($clockOutNeededAlerts as $alert): ?>
                    <input type="hidden" name="alert_keys[]" value="<?= htmlspecialchars(attendance_alert_key('clock_out', $alert['employee_id'], $alert['work_date']), ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
                <button type="submit">すべて確認済みにする</button>
            </form>
        </div>
        <?php if (!empty($clockInNeededAlerts)): ?>
            <h3>出勤忘れ</h3>
            <ul>
                <?php foreach ($clockInNeededAlerts as $alert): ?>
                    <li><?= htmlspecialchars($alert['employee_name'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['work_date'], ENT_QUOTES, 'UTF-8') ?>（シフト開始 <?= htmlspecialchars($alert['shift_start_time'], ENT_QUOTES, 'UTF-8') ?>）<form method="post" action="/admin/dashboard.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="dismiss_clock_alert">
                            <input type="hidden" name="alert_key" value="<?= htmlspecialchars(attendance_alert_key('clock_in', $alert['employee_id'], $alert['work_date']), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit">確認済み</button>
                        </form></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($clockOutNeededAlerts)): ?>
            <h3>退勤忘れ</h3>
            <ul>
                <?php foreach ($clockOutNeededAlerts as $alert): ?>
                    <li><?= htmlspecialchars($alert['employee_name'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['work_date'], ENT_QUOTES, 'UTF-8') ?>（シフト終了 <?= htmlspecialchars($alert['shift_end_time'], ENT_QUOTES, 'UTF-8') ?>）<form method="post" action="/admin/dashboard.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="dismiss_clock_alert">
                            <input type="hidden" name="alert_key" value="<?= htmlspecialchars(attendance_alert_key('clock_out', $alert['employee_id'], $alert['work_date']), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit">確認済み</button>
                        </form></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="greeting">こんにちは、<?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん</p>

<section class="dashboard-section">
    <h2>共通</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/admin/boards.php"><h2>掲示板</h2></a>
        <a class="nav-card" href="/admin/shifts.php"><h2>シフト表作成</h2></a>
        <a class="nav-card" href="/admin/employees.php"><h2>従業員管理</h2></a>
        <a class="nav-card" href="/admin/facilities.php"><h2>施設管理</h2></a>
        <a class="nav-card" href="/admin/attendance_monthly.php"><h2>月間打刻実績</h2></a>
    </div>
</section>

<section class="dashboard-section wage-related">
    <h2>賃金</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/admin/wages.php"><h2>賃金確認</h2></a>
        <a class="nav-card" href="/admin/payroll.php"><h2>給与計算</h2></a>
        <a class="nav-card" href="/admin/payroll_employees.php"><h2>従業員の給与設定</h2></a>
        <a class="nav-card" href="/admin/payroll_settings.php"><h2>給与設定（税額表・料率・最低賃金）</h2></a>
        <a class="nav-card" href="/admin/payroll_ledger.php"><h2>賃金台帳</h2></a>
    </div>
</section>

<section class="dashboard-section">
    <h2>請求</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/admin/invoice.php"><h2>請求書</h2></a>
        <a class="nav-card" href="/admin/invoice_adjustments.php"><h2>訂正・値引き</h2></a>
        <a class="nav-card" href="/admin/invoice_settings.php"><h2>請求設定</h2></a>
        <a class="nav-card" href="/admin/accounting_input.php"><h2>会計（取引入力・決算書）</h2></a>
    </div>
</section>

<section class="dashboard-section work-related">
    <h2>作業関係</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/admin/work_speed.php"><h2>作業速度分析</h2></a>
        <a class="nav-card" href="/admin/collection_headcount.php"><h2>作業登録</h2></a>
        <a class="nav-card" href="/admin/linen_trends.php"><h2>推移予測</h2></a>
        <a class="nav-card" href="/admin/consumable_stock.php"><h2>消耗品在庫管理</h2></a>
    </div>
</section>

<section class="dashboard-section pickup-related">
    <h2>集荷関係</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/admin/jiro_dashboard.php"><h2>本日の集荷予定</h2></a>
        <a class="nav-card" href="/admin/collection_records.php"><h2>集荷記録簿</h2></a>
        <a class="nav-card" href="/admin/travel_time.php"><h2>移動時間</h2></a>
        <a class="nav-card" href="/admin/vehicles.php"><h2>車両マスタ管理</h2></a>
        <a class="nav-card" href="/admin/vehicle_check_list.php"><h2>車両等チェック記録</h2></a>
        <a class="nav-card" href="/admin/vehicle_maintenance_list.php"><h2>車両管理記録</h2></a>
        <a class="nav-card" href="/admin/vehicle_alert_settings.php"><h2>車両アラート設定</h2></a>
    </div>
</section>

<section class="dashboard-section">
    <h2>履歴</h2>
    <div class="nav-cards">
        <a class="nav-card" href="/admin/attendance_edit_logs.php"><h2>打刻修正履歴</h2></a>
        <a class="nav-card" href="/admin/shift_edit_logs.php"><h2>シフト編集履歴</h2></a>
        <a class="nav-card" href="/admin/work_stage_record_edit_logs.php"><h2>作業実績修正履歴</h2></a>
        <a class="nav-card" href="/admin/collection_cycle_edit_logs.php"><h2>集荷記録修正履歴</h2></a>
    </div>
</section>
</body>
</html>
