<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

const ATTENDANCE_EDITABLE_FIELDS = ['clock_in_at', 'clock_out_at', 'break_start_at', 'break_end_at'];
const ATTENDANCE_LOG_FIELDS = ['category', 'clock_in_at', 'clock_out_at', 'break_start_at', 'break_end_at'];
const ATTENDANCE_FIELD_LABELS = [
    'category' => '区分',
    'clock_in_at' => '出勤時刻',
    'clock_out_at' => '退勤時刻',
    'break_start_at' => '休憩開始時刻',
    'break_end_at' => '休憩終了時刻',
];

function validate_attendance_chronology(array $merged): ?string
{
    if ($merged['clock_out_at'] !== null && $merged['clock_out_at'] <= $merged['clock_in_at']) {
        return '退勤時刻は出勤時刻より後にしてください。';
    }
    if ($merged['break_start_at'] !== null && $merged['break_start_at'] < $merged['clock_in_at']) {
        return '休憩開始時刻は出勤時刻以降にしてください。';
    }
    if ($merged['break_end_at'] !== null && $merged['break_start_at'] !== null && $merged['break_end_at'] <= $merged['break_start_at']) {
        return '休憩終了時刻は休憩開始時刻より後にしてください。';
    }
    if ($merged['clock_out_at'] !== null && $merged['break_end_at'] !== null && $merged['break_end_at'] > $merged['clock_out_at']) {
        return '休憩終了時刻は退勤時刻以前にしてください。';
    }

    return null;
}

function parse_attendance_datetime_fields(array $post): array
{
    $values = [];
    $errors = [];
    foreach (ATTENDANCE_EDITABLE_FIELDS as $field) {
        $raw = (string) ($post[$field] ?? '');
        if ($raw === '') {
            $values[$field] = null;
            continue;
        }
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw);
        if ($dt === false) {
            $errors[] = ATTENDANCE_FIELD_LABELS[$field] . 'の形式が正しくありません。';
            continue;
        }
        $values[$field] = $dt->format('Y-m-d H:i:s');
    }

    return [$values, $errors];
}

// ---- 対象月の決定 ----
$yearMonth = (string) ($_GET['month'] ?? (new DateTime())->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
    $yearMonth = (new DateTime())->format('Y-m');
}

$monthStart = DateTime::createFromFormat('Y-m-d', $yearMonth . '-01');
$monthEnd = (clone $monthStart)->modify('last day of this month');
$monthStartStr = $monthStart->format('Y-m-d');
$monthEndStr = $monthEnd->format('Y-m-d');
$todayStr = (new DateTime('today'))->format('Y-m-d');
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');
$pageUrl = '/admin/attendance_monthly.php?month=' . $yearMonth;

// ---- 従業員一覧（過去の実績を追える監査用画面のため、状態を問わず全staffを対象とする） ----
$employeesStmt = $pdo->query("SELECT id, name, status FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$errorMessage = '';

// ---- POST処理（新規追加・修正・削除） ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $attendanceId = (int) ($_POST['id'] ?? 0);
            $deleteReason = trim((string) ($_POST['delete_reason'] ?? ''));

            $targetStmt = $pdo->prepare('SELECT * FROM attendance WHERE id = :id');
            $targetStmt->execute([':id' => $attendanceId]);
            $targetRecord = $targetStmt->fetch();

            if ($targetRecord === false) {
                set_flash('error', '対象の打刻記録が見つかりません。');
            } else {
                try {
                    $pdo->beginTransaction();

                    // 修正履歴（attendance_edit_logs）の有無に関わらず常に削除できる。
                    // attendanceは論理削除（deleted_at）のみで、行自体は物理削除しないため、
                    // fk_edit_logs_attendance（attendance_edit_logs.attendance_id）の外部キー制約には抵触せず、
                    // 過去の修正履歴もそのままDBに残り続ける。
                    $logStmt = $pdo->prepare(
                        'INSERT INTO attendance_edit_logs (attendance_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:attendance_id, :edited_by, :action, :field_name, :old_value, NULL)'
                    );

                    foreach (ATTENDANCE_LOG_FIELDS as $field) {
                        if ($targetRecord[$field] === null) {
                            continue;
                        }
                        $logStmt->execute([
                            ':attendance_id' => $attendanceId,
                            ':edited_by' => $admin['id'],
                            ':action' => 'delete',
                            ':field_name' => $field,
                            ':old_value' => $targetRecord[$field],
                        ]);
                    }

                    if ($deleteReason !== '') {
                        $logStmt->execute([
                            ':attendance_id' => $attendanceId,
                            ':edited_by' => $admin['id'],
                            ':action' => 'delete',
                            ':field_name' => 'reason',
                            ':old_value' => $deleteReason,
                        ]);
                    }

                    $deleteStmt = $pdo->prepare(
                        'UPDATE attendance SET deleted_at = :deleted_at WHERE id = :id'
                    );
                    $deleteStmt->execute([
                        ':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'),
                        ':id' => $attendanceId,
                    ]);

                    $pdo->commit();

                    set_flash('success', '打刻記録を削除しました。');
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    set_flash('error', '削除に失敗しました。もう一度お試しください。');
                }
            }

            header('Location: ' . $pageUrl);
            exit;
        }

        if ($action === 'create' || $action === 'update') {
            [$values, $parseErrors] = parse_attendance_datetime_fields($_POST);
            $values['category'] = (string) ($_POST['category'] ?? '');

            if ($action === 'create' && !in_array((int) ($_POST['employee_id'] ?? 0), $validEmployeeIds, true)) {
                $errorMessage = '選択された従業員が見つかりません。';
            } elseif ($values['clock_in_at'] === null) {
                $errorMessage = '出勤時刻を入力してください。';
            } elseif (!in_array($values['category'], SHIFT_CATEGORIES, true)) {
                $errorMessage = '区分を選択してください。';
            } elseif (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } else {
                $chronoError = validate_attendance_chronology($values);

                if ($chronoError !== null) {
                    $errorMessage = $chronoError;
                } elseif ($action === 'create') {
                    $employeeId = (int) $_POST['employee_id'];
                    $totalBreakMinutes = recompute_total_break_minutes(null, null, null, $values['break_start_at'], $values['break_end_at']);

                    $workMinutes = null;
                    $status = 'working';
                    if ($values['clock_out_at'] !== null) {
                        $ci = new DateTime($values['clock_in_at']);
                        $co = new DateTime($values['clock_out_at']);
                        $rawMinutes = max(0, (int) round(($co->getTimestamp() - $ci->getTimestamp()) / 60));
                        $workMinutes = max(0, $rawMinutes - ($totalBreakMinutes ?? 0));
                        $status = 'done';
                    }

                    $insertStmt = $pdo->prepare(
                        'INSERT INTO attendance (employee_id, category, clock_in_at, clock_out_at, break_start_at, break_end_at, total_break_minutes, work_minutes, status)
                         VALUES (:employee_id, :category, :clock_in_at, :clock_out_at, :break_start_at, :break_end_at, :total_break_minutes, :work_minutes, :status)'
                    );
                    $insertStmt->execute([
                        ':employee_id' => $employeeId,
                        ':category' => $values['category'],
                        ':clock_in_at' => $values['clock_in_at'],
                        ':clock_out_at' => $values['clock_out_at'],
                        ':break_start_at' => $values['break_start_at'],
                        ':break_end_at' => $values['break_end_at'],
                        ':total_break_minutes' => $totalBreakMinutes,
                        ':work_minutes' => $workMinutes,
                        ':status' => $status,
                    ]);
                    $newAttendanceId = (int) $pdo->lastInsertId();

                    $logStmt = $pdo->prepare(
                        'INSERT INTO attendance_edit_logs (attendance_id, edited_by, field_name, old_value, new_value)
                         VALUES (:attendance_id, :edited_by, :field_name, NULL, :new_value)'
                    );
                    foreach (ATTENDANCE_LOG_FIELDS as $field) {
                        if ($values[$field] === null) {
                            continue;
                        }
                        $logStmt->execute([
                            ':attendance_id' => $newAttendanceId,
                            ':edited_by' => $admin['id'],
                            ':field_name' => $field,
                            ':new_value' => $values[$field],
                        ]);
                    }

                    set_flash('success', '打刻記録を新規追加しました。');
                    header('Location: ' . $pageUrl);
                    exit;
                } else {
                    $attendanceId = (int) ($_POST['id'] ?? 0);
                    $recordStmt = $pdo->prepare('SELECT * FROM attendance WHERE id = :id');
                    $recordStmt->execute([':id' => $attendanceId]);
                    $record = $recordStmt->fetch();

                    if ($record === false) {
                        $errorMessage = '対象の打刻記録が見つかりません。';
                    } else {
                        $totalBreakMinutes = recompute_total_break_minutes(
                            $record['total_break_minutes'] !== null ? (int) $record['total_break_minutes'] : null,
                            $record['break_start_at'],
                            $record['break_end_at'],
                            $values['break_start_at'],
                            $values['break_end_at']
                        );

                        $workMinutes = null;
                        $status = 'working';
                        if ($values['clock_out_at'] !== null) {
                            $ci = new DateTime($values['clock_in_at']);
                            $co = new DateTime($values['clock_out_at']);
                            $rawMinutes = max(0, (int) round(($co->getTimestamp() - $ci->getTimestamp()) / 60));
                            $workMinutes = max(0, $rawMinutes - ($totalBreakMinutes ?? 0));
                            $status = 'done';
                        }

                        try {
                            $pdo->beginTransaction();

                            $logStmt = $pdo->prepare(
                                'INSERT INTO attendance_edit_logs (attendance_id, edited_by, field_name, old_value, new_value)
                                 VALUES (:attendance_id, :edited_by, :field_name, :old_value, :new_value)'
                            );
                            $changedCount = 0;
                            foreach (ATTENDANCE_LOG_FIELDS as $field) {
                                if ((string) $values[$field] === (string) $record[$field]) {
                                    continue;
                                }
                                $logStmt->execute([
                                    ':attendance_id' => $attendanceId,
                                    ':edited_by' => $admin['id'],
                                    ':field_name' => $field,
                                    ':old_value' => $record[$field],
                                    ':new_value' => $values[$field],
                                ]);
                                $changedCount++;
                            }

                            $updateStmt = $pdo->prepare(
                                'UPDATE attendance
                                 SET category = :category, clock_in_at = :clock_in_at, clock_out_at = :clock_out_at,
                                     break_start_at = :break_start_at, break_end_at = :break_end_at,
                                     total_break_minutes = :total_break_minutes, work_minutes = :work_minutes, status = :status
                                 WHERE id = :id'
                            );
                            $updateStmt->execute([
                                ':category' => $values['category'],
                                ':clock_in_at' => $values['clock_in_at'],
                                ':clock_out_at' => $values['clock_out_at'],
                                ':break_start_at' => $values['break_start_at'],
                                ':break_end_at' => $values['break_end_at'],
                                ':total_break_minutes' => $totalBreakMinutes,
                                ':work_minutes' => $workMinutes,
                                ':status' => $status,
                                ':id' => $attendanceId,
                            ]);

                            $pdo->commit();

                            set_flash('success', $changedCount > 0
                                ? '打刻を修正しました（' . $changedCount . '件のフィールドを変更）。'
                                : '変更点はありませんでした。');
                            header('Location: ' . $pageUrl);
                            exit;
                        } catch (\Throwable $e) {
                            $pdo->rollBack();
                            $errorMessage = '保存に失敗しました。もう一度お試しください。';
                        }
                    }
                }
            }
        }
    }
}

$flash = pop_flash();

// ---- 編集対象の読み込み ----
$editingRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingRecord = $row;
    }
}

// ---- フォームの初期値決定 ----
$formAction = 'create';
$formId = null;
$formEmployeeId = '';
$formCategory = '';
$formClockIn = '';
$formClockOut = '';
$formBreakStart = '';
$formBreakEnd = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    $formEmployeeId = (string) ($_POST['employee_id'] ?? '');
    $formCategory = (string) ($_POST['category'] ?? '');
    $formClockIn = (string) ($_POST['clock_in_at'] ?? '');
    $formClockOut = (string) ($_POST['clock_out_at'] ?? '');
    $formBreakStart = (string) ($_POST['break_start_at'] ?? '');
    $formBreakEnd = (string) ($_POST['break_end_at'] ?? '');
} elseif ($editingRecord !== null) {
    $formAction = 'update';
    $formId = (int) $editingRecord['id'];
    $formEmployeeId = (string) $editingRecord['employee_id'];
    $formCategory = (string) ($editingRecord['category'] ?? '');
    $formClockIn = to_datetime_local($editingRecord['clock_in_at']);
    $formClockOut = to_datetime_local($editingRecord['clock_out_at']);
    $formBreakStart = to_datetime_local($editingRecord['break_start_at']);
    $formBreakEnd = to_datetime_local($editingRecord['break_end_at']);
} elseif (isset($_GET['new_employee'], $_GET['new_date'])) {
    $formAction = 'create';
    $formEmployeeId = (string) $_GET['new_employee'];
    $formClockIn = (string) $_GET['new_date'] . 'T09:00';
}

$formEmployeeName = '';
foreach ($employees as $employee) {
    if ((string) $employee['id'] === $formEmployeeId) {
        $formEmployeeName = $employee['name'];
        break;
    }
}

// ---- 月次確定済みのemployee_id×year_month一覧（JS側での確認ダイアログ表示に使用） ----
$confirmedMonthsStmt = $pdo->query('SELECT employee_id, `year_month` FROM monthly_wages');
$confirmedMonthsByEmployee = [];
foreach ($confirmedMonthsStmt->fetchAll() as $row) {
    $confirmedMonthsByEmployee[(int) $row['employee_id']][] = $row['year_month'];
}

// ---- 日付リスト ----
$dates = [];
$cursor = clone $monthStart;
while ($cursor <= $monthEnd) {
    $dates[] = clone $cursor;
    $cursor->modify('+1 day');
}

// ---- シフト（予定） ----
$shiftsByEmployeeDate = [];
if (!empty($employees)) {
    $stmt = $pdo->prepare(
        'SELECT employee_id, work_date, start_time, end_time, categories
         FROM shifts
         WHERE work_date BETWEEN :start AND :end
         ORDER BY work_date, start_time'
    );
    $stmt->execute([':start' => $monthStartStr, ':end' => $monthEndStr]);
    foreach ($stmt->fetchAll() as $row) {
        $shiftsByEmployeeDate[(int) $row['employee_id']][$row['work_date']][] = $row;
    }
}

// ---- 打刻（実績） ----
$attendanceByEmployeeDate = [];
$totalWorkMinutes = 0;
if (!empty($employees)) {
    $stmt = $pdo->prepare(
        'SELECT id, employee_id, clock_in_at, clock_out_at, work_minutes, total_break_minutes, status
         FROM attendance
         WHERE DATE(clock_in_at) BETWEEN :start AND :end
           AND deleted_at IS NULL
         ORDER BY clock_in_at'
    );
    $stmt->execute([':start' => $monthStartStr, ':end' => $monthEndStr]);
    foreach ($stmt->fetchAll() as $row) {
        $workDate = substr($row['clock_in_at'], 0, 10);
        $attendanceByEmployeeDate[(int) $row['employee_id']][$workDate][] = $row;
        if ($row['status'] === 'done' && $row['work_minutes'] !== null) {
            $totalWorkMinutes += (int) $row['work_minutes'];
        }
    }
}

$holidayDates = fetch_holiday_dates($pdo, $monthStartStr, $monthEndStr);
$weekdayLabels = ['月', '火', '水', '木', '金', '土', '日'];
$csrfToken = csrf_token();

function render_attendance_day_cell(array $shiftsForDay, array $attendanceForDay, string $pageUrl): void
{
    foreach ($shiftsForDay as $shift) {
        $categories = categories_from_value($shift['categories']);
        ?>
        <div class="plan-entry">
            <span class="entry-label">予定</span>
            <?= htmlspecialchars(substr($shift['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>〜<?= htmlspecialchars(substr($shift['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
            <?php foreach ($categories as $category): ?>
                <span class="category-badge" style="background:<?= htmlspecialchars(CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE, ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
        </div>
        <?php
    }

    if (!empty($shiftsForDay) && empty($attendanceForDay)) {
        ?>
        <div class="missing-punch">実績: 未打刻</div>
        <?php
    }

    foreach ($attendanceForDay as $record) {
        $inTime = substr($record['clock_in_at'], 11, 5);
        $outTime = $record['clock_out_at'] !== null ? substr($record['clock_out_at'], 11, 5) : null;
        $editUrl = $pageUrl . '&edit=' . (int) $record['id'];
        ?>
        <div class="actual-entry" onclick="event.stopPropagation(); location.href='<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>';">
            <span class="entry-label">実績</span>
            <?= htmlspecialchars($inTime, ENT_QUOTES, 'UTF-8') ?>〜<?= $outTime !== null ? htmlspecialchars($outTime, ENT_QUOTES, 'UTF-8') : '' ?>
            <?php if ($outTime === null): ?>
                <span class="working-badge">勤務中</span>
            <?php endif; ?>
            <?php if ($record['work_minutes'] !== null): ?>
                <div class="entry-sub">
                    休憩<?= $record['total_break_minutes'] !== null ? (int) $record['total_break_minutes'] : 0 ?>分 /
                    実働<?= htmlspecialchars(format_minutes_as_hours((int) $record['work_minutes']), ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>月間打刻実績 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 110px; }
        .month-nav { margin-bottom: 8px; }
        .month-nav a { margin-right: 8px; }
        .grid-scroll { overflow-x: auto; max-width: 100%; border: 1px solid #ccc; }
        table.attendance-grid { border-collapse: collapse; width: max-content; min-width: 100%; }
        table.attendance-grid th, table.attendance-grid td { border: 1px solid #ccc; vertical-align: top; padding: 4px; }
        table.attendance-grid th { background: #f5f5f5; }
        table.attendance-grid th.date-col, table.attendance-grid td.date-col { min-width: 130px; }
        table.attendance-grid th.employee-col, table.attendance-grid td.employee-col {
            position: sticky; left: 0; background: #fff; z-index: 2; min-width: 130px; text-align: left; font-weight: bold;
        }
        table.attendance-grid th.employee-col { background: #f5f5f5; z-index: 3; }
        table.attendance-grid th.today, table.attendance-grid td.today { background: #eef6ff; }
        table.attendance-grid th.sat { color: #0b5ed7; }
        table.attendance-grid th.sun-holiday { color: #d9362e; }
        table.attendance-grid td.date-cell { height: 60px; cursor: pointer; }
        table.attendance-grid td.date-cell:hover { background: #f8fbff; }
        .plan-entry { border: 1px dashed #99a; border-radius: 4px; padding: 3px; margin-bottom: 3px; font-size: 0.8em; background: #f4f6ff; color: #444; }
        .actual-entry { border: 1px solid #ccc; border-radius: 4px; padding: 3px; margin-bottom: 3px; font-size: 0.8em; background: #fff; cursor: pointer; }
        .entry-label { display: inline-block; font-size: 0.85em; font-weight: bold; color: #666; margin-right: 4px; }
        .entry-sub { font-size: 0.9em; color: #555; }
        .missing-punch { font-size: 0.8em; color: #b3261e; margin-bottom: 3px; }
        .working-badge { display: inline-block; font-size: 0.75em; background: #0b5ed7; color: #fff; border-radius: 3px; padding: 1px 5px; margin-left: 2px; }
        .category-badge { display: inline-block; font-size: 0.75em; color: #fff; border-radius: 3px; padding: 1px 4px; margin-left: 2px; }
        .summary-footer { margin-top: 16px; font-weight: bold; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>月間打刻実績</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/month_end_check.php?month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">月末チェック</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="attendance-form" id="attendance-form-section">
    <h2><?= $formAction === 'update' ? '打刻の修正' : '打刻の新規追加' ?></h2>

    <fieldset>
        <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>" id="attendance-form" onsubmit="return confirmSubmitIfConfirmedMonth();">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <?php endif; ?>

            <div class="form-row">
                <label>従業員</label>
                <?php if ($formAction === 'update'): ?>
                    <input type="hidden" id="employee_id" value="<?= (int) $formEmployeeId ?>">
                    <strong><?= htmlspecialchars($formEmployeeName, ENT_QUOTES, 'UTF-8') ?></strong>
                <?php else: ?>
                    <select id="employee_id" name="employee_id" required <?= empty($employees) ? 'disabled' : '' ?>>
                        <option value="">選択してください</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formEmployeeId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <label for="category">区分</label>
                <select id="category" name="category" required>
                    <option value="">選択してください</option>
                    <?php foreach (SHIFT_CATEGORIES as $category): ?>
                        <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $formCategory === $category ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="clock_in_at">出勤時刻</label>
                <input type="datetime-local" id="clock_in_at" name="clock_in_at" value="<?= htmlspecialchars($formClockIn, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="clock_out_at">退勤時刻</label>
                <input type="datetime-local" id="clock_out_at" name="clock_out_at" value="<?= htmlspecialchars($formClockOut, ENT_QUOTES, 'UTF-8') ?>">
                <span class="notice" style="display:inline-block; padding:2px 6px;">空欄の場合は「勤務中」として保存されます。</span>
            </div>

            <div class="form-row">
                <label for="break_start_at">休憩開始時刻</label>
                <input type="datetime-local" id="break_start_at" name="break_start_at" value="<?= htmlspecialchars($formBreakStart, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="break_end_at">休憩終了時刻</label>
                <input type="datetime-local" id="break_end_at" name="break_end_at" value="<?= htmlspecialchars($formBreakEnd, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit" <?= empty($employees) ? 'disabled' : '' ?>><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($formAction === 'update'): ?>
                <a href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
            <?php endif; ?>
        </form>

        <?php if ($formAction === 'update'): ?>
            <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" style="margin-top:8px;"
                  onsubmit="return confirmDeleteAttendance(<?= (int) $formEmployeeId ?>, '<?= htmlspecialchars(substr($formClockIn, 0, 7), ENT_QUOTES, 'UTF-8') ?>', this);">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
                <input type="hidden" name="delete_reason" value="">
                <button type="submit" style="color:#b3261e;">この打刻記録を削除する</button>
            </form>
        <?php endif; ?>
    </fieldset>
</section>

<div class="month-nav">
    <a href="?month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <strong><?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?></strong>
    <a href="?month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">次月 →</a>
    <form method="get" action="/admin/attendance_monthly.php" style="display:inline-block; margin-left:8px;">
        <input type="month" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">表示</button>
    </form>
    <a href="/admin/month_end_check.php?month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>"
       style="display:inline-block; margin-left:16px; padding:6px 14px; border-radius:6px; background:#0b5ed7; color:#fff; text-decoration:none;">月末チェック</a>
</div>

<?php if (empty($employees)): ?>
    <p class="notice">従業員が登録されていません。</p>
<?php else: ?>
    <div class="grid-scroll">
        <table class="attendance-grid">
            <thead>
                <tr>
                    <th class="employee-col">従業員</th>
                    <?php foreach ($dates as $d): ?>
                        <?php
                        $dateStr = $d->format('Y-m-d');
                        $weekdayIndex = (int) $d->format('N');
                        $classes = ['date-col'];
                        if ($dateStr === $todayStr) {
                            $classes[] = 'today';
                        } elseif ($weekdayIndex === 6) {
                            $classes[] = 'sat';
                        } elseif ($weekdayIndex === 7 || is_holiday_in_set($dateStr, $holidayDates)) {
                            $classes[] = 'sun-holiday';
                        }
                        ?>
                        <th class="<?= implode(' ', $classes) ?>">
                            <?= (int) $d->format('j') ?>日（<?= $weekdayLabels[$weekdayIndex - 1] ?>）
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php $employeeId = (int) $employee['id']; ?>
                    <tr>
                        <td class="employee-col">
                            <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?><?= $employee['status'] !== 'active' ? '（' . htmlspecialchars($employee['status'], ENT_QUOTES, 'UTF-8') . '）' : '' ?>
                        </td>
                        <?php foreach ($dates as $d): ?>
                            <?php $dateStr = $d->format('Y-m-d'); ?>
                            <td class="date-cell<?= $dateStr === $todayStr ? ' today' : '' ?>" onclick="location.href='<?= $pageUrl ?>&new_employee=<?= $employeeId ?>&new_date=<?= $dateStr ?>'">
                                <?php
                                render_attendance_day_cell(
                                    $shiftsByEmployeeDate[$employeeId][$dateStr] ?? [],
                                    $attendanceByEmployeeDate[$employeeId][$dateStr] ?? [],
                                    $pageUrl
                                );
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="summary-footer">
        <?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?> 全従業員合計実働時間: <?= htmlspecialchars(format_minutes_as_hours($totalWorkMinutes), ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<script>
var confirmedMonths = <?= json_encode($confirmedMonthsByEmployee) ?>;

function confirmSubmitIfConfirmedMonth() {
    var employeeField = document.getElementById('employee_id');
    var employeeId = employeeField ? employeeField.value : '';
    var clockInField = document.getElementById('clock_in_at');
    var clockIn = clockInField ? clockInField.value : '';
    if (!employeeId || !clockIn) {
        return true;
    }
    var yearMonth = clockIn.substring(0, 7);
    var months = confirmedMonths[employeeId] || [];
    if (months.indexOf(yearMonth) !== -1) {
        return confirm('この月は賃金確定済みです。編集後、admin/wages.phpで再確定が必要になります。よろしいですか？');
    }
    return true;
}

function confirmDeleteAttendance(employeeId, yearMonth, formEl) {
    var months = confirmedMonths[employeeId] || [];
    var message = 'この打刻記録を削除しますか？';
    if (months.indexOf(yearMonth) !== -1) {
        message = 'この月は賃金確定済みです。削除後、admin/wages.phpで再確定が必要になります。' + message;
    }
    if (!confirm(message)) {
        return false;
    }
    // 削除理由は任意入力。二重打刻の解消など、なぜ削除したかを監査ログに残せる。
    var reason = prompt('削除理由（任意・監査ログに記録されます。未入力可）', '');
    var reasonField = formEl.querySelector('input[name="delete_reason"]');
    if (reasonField) {
        reasonField.value = reason || '';
    }
    return true;
}
</script>
</body>
</html>
