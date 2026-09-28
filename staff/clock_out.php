<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

// 退勤時の施設・参加従業員の入力（work_stage_records / work_stage_record_employeesへの保存）は
// 2026-09-28に全区分で廃止した。作業速度分析（work_speed.php）の作業時間・作業氏名は
// 洗濯代行区分の打刻（attendance）から直接集計するため、退勤は他の区分と同じく
// 「退勤する」ボタンのみで確定する。過去に保存された作業実績データは削除せず残している。

// 共用アカウントは「本人」という単一の状態を持たないため、employee_idではなくattendance_idで
// 対象を明示的に指定させる（ダッシュボードの一覧からリンクされるほか、未指定・不正なIDの場合は
// ここで選択画面を表示する）。休憩中のレコードは選択肢から除外する（退勤できないため）。
$isSharedAccount = (int) ($staff['is_shared_account'] ?? 0) === 1;
$openRecord = false;

if ($isSharedAccount) {
    $attendanceId = (int) ($_GET['attendance_id'] ?? $_POST['attendance_id'] ?? 0);
    if ($attendanceId > 0) {
        $recordStmt = $pdo->prepare(
            "SELECT a.id, a.employee_id, a.category, a.clock_in_at, a.break_start_at, a.break_end_at, a.total_break_minutes,
                    e.name AS employee_name
             FROM attendance a
             INNER JOIN employees e ON e.id = a.employee_id
             WHERE a.id = :id AND a.status = 'working' AND a.deleted_at IS NULL"
        );
        $recordStmt->execute([':id' => $attendanceId]);
        $record = $recordStmt->fetch();
        if ($record !== false && !($record['break_start_at'] !== null && $record['break_end_at'] === null)) {
            $openRecord = $record;
        }
    }

    if ($openRecord === false) {
        $pickableRecords = array_values(array_filter(
            find_open_attendance_today($pdo),
            static fn (array $r): bool => $r['break_start_at'] === null || $r['break_end_at'] !== null
        ));
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>退勤する人を選択 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .picker-list { list-style: none; padding: 0; margin: 16px 0; }
        .picker-list li { margin-bottom: 8px; }
        .picker-list a { display: block; padding: 14px 16px; border: 1px solid #ccc; border-radius: 6px; text-decoration: none; color: #222; font-size: 1.1em; }
        .picker-list a:hover, .picker-list a:focus-visible { border-color: #0b5ed7; }
    </style>
</head>
<body>
<header>
    <h1>退勤する人を選択</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>
<?php if (empty($pickableRecords)): ?>
    <p class="notice">退勤できる出勤記録がありません（休憩中の人は休憩から戻ってから退勤してください）。</p>
<?php else: ?>
    <p>退勤する人を選んでください。</p>
    <ul class="picker-list">
        <?php foreach ($pickableRecords as $rec): ?>
            <li><a href="/staff/clock_out.php?attendance_id=<?= (int) $rec['id'] ?>"><?= htmlspecialchars($rec['employee_name'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars(substr($rec['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?>〜出勤）</a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
</body>
</html>
        <?php
        exit;
    }
} else {
    $openStmt = $pdo->prepare(
        "SELECT id, category, clock_in_at, break_start_at, break_end_at, total_break_minutes
         FROM attendance
         WHERE employee_id = :employee_id AND status = 'working' AND DATE(clock_in_at) = CURDATE()
           AND deleted_at IS NULL
         ORDER BY clock_in_at DESC
         LIMIT 1"
    );
    $openStmt->execute([':employee_id' => $staff['id']]);
    $openRecord = $openStmt->fetch();

    if ($openRecord === false) {
        // 出勤中のレコードがない場合はここで処理することがない
        header('Location: /staff/dashboard.php');
        exit;
    }

    $isOnBreak = $openRecord['break_start_at'] !== null && $openRecord['break_end_at'] === null;
    if ($isOnBreak) {
        // 休憩中は退勤できない（UI側でも非表示にしているが、直接POSTされた場合の保険）
        set_flash('error', '休憩中は退勤できません。休憩から戻ってから退勤してください。');
        header('Location: /staff/dashboard.php');
        exit;
    }
}

// 自動休憩補正ログのedited_byは、共用アカウントでは共用アカウント自身ではなく実際に退勤する従業員を記録する。
$recorderId = $isSharedAccount ? (int) $openRecord['employee_id'] : (int) $staff['id'];

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $lat = (isset($_POST['lat']) && $_POST['lat'] !== '') ? (float) $_POST['lat'] : null;
        $lng = (isset($_POST['lng']) && $_POST['lng'] !== '') ? (float) $_POST['lng'] : null;

        $clockOutAt = new DateTime();
        $clockInAt = new DateTime($openRecord['clock_in_at']);
        $rawMinutes = max(0, (int) round(($clockOutAt->getTimestamp() - $clockInAt->getTimestamp()) / 60));
        $requiredBreakMinutes = calc_legal_break_minutes($rawMinutes);

        // 休憩開始・終了を一度も手動打刻していない日（total_break_minutesがNULL）のみ、
        // 法定基準に基づき自動で休憩時間をセットする。手動打刻済み（0分含む）はその実測値を優先し上書きしない。
        // 退勤時は区分を問わず、本人が実際に入力した休憩時間をそのまま保存する。
        // 法定休憩への不足補正は月次チェックで店舗勤務がある日に限って行う。
        $autoBreakApplied = false;
        $totalBreakMinutes = (int) ($openRecord['total_break_minutes'] ?? 0);
        $workMinutes = max(0, $rawMinutes - $totalBreakMinutes);

        if ($errorMessage === '') {
        try {
            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare(
                "UPDATE attendance
                 SET clock_out_at = :clock_out_at, clock_out_lat = :lat, clock_out_lng = :lng,
                     total_break_minutes = :total_break_minutes, work_minutes = :work_minutes, status = 'done'
                 WHERE id = :id"
            );
            $updateStmt->execute([
                ':clock_out_at' => $clockOutAt->format('Y-m-d H:i:s'),
                ':lat' => $lat,
                ':lng' => $lng,
                ':total_break_minutes' => $totalBreakMinutes,
                ':work_minutes' => $workMinutes,
                ':id' => $openRecord['id'],
            ]);

            if ($autoBreakApplied) {
                $logStmt = $pdo->prepare(
                    'INSERT INTO attendance_edit_logs (attendance_id, edited_by, action, field_name, old_value, new_value)
                     VALUES (:attendance_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                );
                $logStmt->execute([
                    ':attendance_id' => $openRecord['id'],
                    ':edited_by' => $recorderId,
                    ':action' => 'auto_break',
                    ':field_name' => 'total_break_minutes',
                    ':old_value' => $openRecord['total_break_minutes'],
                    ':new_value' => $totalBreakMinutes,
                ]);
            }

            $pdo->commit();

            $message = '退勤を記録しました。';
            if ($autoBreakApplied) {
                $message .= ' 休憩の打刻がなかったため、労働基準法に基づき休憩' . $totalBreakMinutes . '分を自動で設定しました。';
            } elseif ($openRecord['category'] === '店舗' && $totalBreakMinutes < $requiredBreakMinutes) {
                $message .= ' ⚠ 本日の休憩は' . $totalBreakMinutes . '分でした。労働基準法上、'
                    . format_minutes_as_hours($rawMinutes) . 'の勤務には' . $requiredBreakMinutes . '分以上の休憩が必要です。';
            }
            set_flash('success', $message);
            header('Location: /staff/dashboard.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = '保存に失敗しました。もう一度お試しください。';
        }
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>退勤 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .clock-out-summary { margin: 16px 0; font-size: 1.1em; }
        #submit-button { font-size: 1.1em; padding: 12px 32px; border-radius: 6px; border: none; color: #fff; background: #b3261e; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1>退勤<?= $isSharedAccount ? '（' . htmlspecialchars($openRecord['employee_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?></h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<p class="clock-out-summary">
    区分: <?= htmlspecialchars((string) ($openRecord['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?> /
    出勤: <?= htmlspecialchars(substr($openRecord['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?>
</p>

<form id="clock-out-form" method="post" action="/staff/clock_out.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="lat" id="clock-lat" value="">
    <input type="hidden" name="lng" id="clock-lng" value="">
    <?php if ($isSharedAccount): ?>
        <input type="hidden" name="attendance_id" value="<?= (int) $openRecord['id'] ?>">
    <?php endif; ?>

    <button type="submit" id="submit-button">退勤する</button>
</form>

<script>
document.getElementById('clock-out-form').addEventListener('submit', function (e) {
    var form = this;
    var button = document.getElementById('submit-button');

    if (button.dataset.located === '1') {
        return;
    }

    e.preventDefault();
    button.disabled = true;
    button.textContent = '処理中...';

    function submitForm() {
        button.dataset.located = '1';
        form.submit();
    }

    if (!navigator.geolocation) {
        submitForm();
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('clock-lat').value = position.coords.latitude;
            document.getElementById('clock-lng').value = position.coords.longitude;
            submitForm();
        },
        function () {
            submitForm();
        },
        { timeout: 5000 }
    );
});
</script>
</body>
</html>
