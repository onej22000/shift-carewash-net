<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

const EDITABLE_FIELDS = ['clock_in_at', 'clock_out_at', 'break_start_at', 'break_end_at'];
const LOG_FIELDS = ['category', 'clock_in_at', 'clock_out_at', 'break_start_at', 'break_end_at'];
const FIELD_LABELS = [
    'category' => '区分',
    'clock_in_at' => '出勤時刻',
    'clock_out_at' => '退勤時刻',
    'break_start_at' => '休憩開始時刻',
    'break_end_at' => '休憩終了時刻',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attendanceId = (int) ($_POST['id'] ?? 0);
} else {
    $attendanceId = (int) ($_GET['id'] ?? 0);
}

$stmt = $pdo->prepare('SELECT * FROM attendance WHERE id = :id');
$stmt->execute([':id' => $attendanceId]);
$record = $stmt->fetch();

$errorMessage = '';
$blocked = null;

if ($record === false) {
    $blocked = '打刻記録が見つかりません。';
} elseif ((int) $record['employee_id'] !== (int) $staff['id']) {
    $blocked = 'この打刻記録を編集する権限がありません。';
} elseif ($record['deleted_at'] !== null) {
    $blocked = 'この打刻記録は既に取り消し済みです。';
} else {
    $recordDate = (new DateTime($record['clock_in_at']))->format('Y-m-d');
    $yearMonth = substr($recordDate, 0, 7);
    if (is_month_confirmed($pdo, (int) $staff['id'], $yearMonth)) {
        $blocked = 'この日（' . $recordDate . '）が属する月は賃金確定済みのため編集できません。修正が必要な場合は管理者にご連絡ください。';
    }
}

if ($blocked === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } elseif ((string) ($_POST['action'] ?? 'update') === 'delete') {
        try {
            $pdo->beginTransaction();

            $logStmt = $pdo->prepare(
                'INSERT INTO attendance_edit_logs (attendance_id, edited_by, action, field_name, old_value, new_value)
                 VALUES (:attendance_id, :edited_by, :action, :field_name, :old_value, NULL)'
            );

            foreach (LOG_FIELDS as $field) {
                if ($record[$field] === null) {
                    continue;
                }
                $logStmt->execute([
                    ':attendance_id' => $attendanceId,
                    ':edited_by' => $staff['id'],
                    ':action' => 'delete',
                    ':field_name' => $field,
                    ':old_value' => $record[$field],
                ]);
            }

            $deleteStmt = $pdo->prepare(
                'UPDATE attendance SET deleted_at = :deleted_at WHERE id = :id AND employee_id = :employee_id'
            );
            $deleteStmt->execute([
                ':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'),
                ':id' => $attendanceId,
                ':employee_id' => $staff['id'],
            ]);

            $pdo->commit();

            set_flash('success', '打刻記録を取り消しました。');
            header('Location: /staff/dashboard.php');
            exit;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $errorMessage = '取り消しに失敗しました。もう一度お試しください。';
        }
    } else {
        // 元々値が無い項目（例: 退勤し忘れの退勤時刻、休憩を取らなかった日の休憩時刻）も
        // 今回の入力で新たに設定できるよう、全フィールドを対象にパースする（null→値、値→nullの両方を許可）。
        $newValues = [];
        $parseErrors = [];

        foreach (EDITABLE_FIELDS as $field) {
            $posted = trim((string) ($_POST[$field] ?? ''));

            if ($posted === '') {
                if ($field === 'clock_in_at') {
                    $parseErrors[] = FIELD_LABELS[$field] . 'を入力してください。';
                } else {
                    $newValues[$field] = null;
                }
                continue;
            }

            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $posted);
            if ($dt === false) {
                $parseErrors[] = FIELD_LABELS[$field] . 'の形式が正しくありません。';
                continue;
            }
            $newValues[$field] = $dt->format('Y-m-d H:i:s');
        }

        $postedCategory = (string) ($_POST['category'] ?? '');

        if (!empty($parseErrors)) {
            $errorMessage = implode(' ', $parseErrors);
        } elseif (!in_array($postedCategory, SHIFT_CATEGORIES, true)) {
            $errorMessage = '区分を選択してください。';
        } else {
            $merged = [
                'category' => $postedCategory,
                'clock_in_at' => $newValues['clock_in_at'],
                'clock_out_at' => $newValues['clock_out_at'],
                'break_start_at' => $newValues['break_start_at'],
                'break_end_at' => $newValues['break_end_at'],
            ];

            if (($merged['break_start_at'] === null) !== ($merged['break_end_at'] === null)) {
                $errorMessage = '休憩開始時刻と休憩終了時刻は両方入力するか、両方空にしてください。';
            } elseif ($merged['clock_out_at'] !== null && $merged['clock_out_at'] <= $merged['clock_in_at']) {
                $errorMessage = '退勤時刻は出勤時刻より後にしてください。';
            } elseif ($merged['break_start_at'] !== null && $merged['break_start_at'] < $merged['clock_in_at']) {
                $errorMessage = '休憩開始時刻は出勤時刻以降にしてください。';
            } elseif ($merged['break_end_at'] !== null && $merged['break_start_at'] !== null && $merged['break_end_at'] <= $merged['break_start_at']) {
                $errorMessage = '休憩終了時刻は休憩開始時刻より後にしてください。';
            } elseif ($merged['clock_out_at'] !== null && $merged['break_end_at'] !== null && $merged['break_end_at'] > $merged['clock_out_at']) {
                $errorMessage = '休憩終了時刻は退勤時刻以前にしてください。';
            } else {
                $totalBreakMinutes = recompute_total_break_minutes(
                    $record['total_break_minutes'] !== null ? (int) $record['total_break_minutes'] : null,
                    $record['break_start_at'],
                    $record['break_end_at'],
                    $merged['break_start_at'],
                    $merged['break_end_at']
                );

                $workMinutes = null;
                if ($merged['clock_out_at'] !== null) {
                    $clockIn = new DateTime($merged['clock_in_at']);
                    $clockOut = new DateTime($merged['clock_out_at']);
                    $rawMinutes = max(0, (int) round(($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60));
                    $workMinutes = max(0, $rawMinutes - ($totalBreakMinutes ?? 0));
                }
                $status = $merged['clock_out_at'] !== null ? 'done' : 'working';

                try {
                    $pdo->beginTransaction();

                    $logStmt = $pdo->prepare(
                        'INSERT INTO attendance_edit_logs (attendance_id, edited_by, field_name, old_value, new_value)
                         VALUES (:attendance_id, :edited_by, :field_name, :old_value, :new_value)'
                    );

                    $changedCount = 0;
                    foreach (LOG_FIELDS as $field) {
                        if ((string) $merged[$field] === (string) $record[$field]) {
                            continue;
                        }
                        $logStmt->execute([
                            ':attendance_id' => $attendanceId,
                            ':edited_by' => $staff['id'],
                            ':field_name' => $field,
                            ':old_value' => $record[$field],
                            ':new_value' => $merged[$field],
                        ]);
                        $changedCount++;
                    }

                    $updateStmt = $pdo->prepare(
                        'UPDATE attendance
                         SET category = :category, clock_in_at = :clock_in_at, clock_out_at = :clock_out_at,
                             break_start_at = :break_start_at, break_end_at = :break_end_at,
                             total_break_minutes = :total_break_minutes, work_minutes = :work_minutes,
                             status = :status
                         WHERE id = :id AND employee_id = :employee_id'
                    );
                    $updateStmt->execute([
                        ':category' => $merged['category'],
                        ':clock_in_at' => $merged['clock_in_at'],
                        ':clock_out_at' => $merged['clock_out_at'],
                        ':break_start_at' => $merged['break_start_at'],
                        ':break_end_at' => $merged['break_end_at'],
                        ':total_break_minutes' => $totalBreakMinutes,
                        ':work_minutes' => $workMinutes,
                        ':status' => $status,
                        ':id' => $attendanceId,
                        ':employee_id' => $staff['id'],
                    ]);

                    $pdo->commit();

                    set_flash('success', $changedCount > 0
                        ? '打刻を修正しました（' . $changedCount . '件のフィールドを変更）。'
                        : '変更点はありませんでした。');
                    header('Location: /staff/dashboard.php');
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '保存に失敗しました。もう一度お試しください。';
                }
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
    <title>打刻の修正 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message.error { padding: 8px 12px; border-radius: 4px; background: #fdecea; color: #b3261e; margin-bottom: 12px; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 10px; }
        .form-row label { display: block; margin-bottom: 4px; }
        .form-row .hint { font-size: 0.85em; color: #666; }
        .danger-form { margin-top: 16px; }
        .danger-form button { background: #b3261e; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1>打刻の修正</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($blocked !== null): ?>
    <p class="message error"><?= htmlspecialchars($blocked, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <?php if ($errorMessage !== ''): ?>
        <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <p class="notice">値の変更履歴はすべて記録されます。実際の時刻を正しく入力してください。空欄で保存すると、その項目は未入力（打刻なし）になります。</p>

    <fieldset>
        <form method="post" action="/staff/attendance_edit.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $attendanceId ?>">

            <div class="form-row">
                <label for="category">区分</label>
                <select id="category" name="category" required>
                    <option value="">選択してください</option>
                    <?php foreach (SHIFT_CATEGORIES as $category): ?>
                        <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($_POST['category'] ?? $record['category']) === $category ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php foreach (EDITABLE_FIELDS as $field): ?>
                <div class="form-row">
                    <label for="<?= $field ?>"><?= htmlspecialchars(FIELD_LABELS[$field], ENT_QUOTES, 'UTF-8') ?><?= $field === 'clock_in_at' ? '' : '（任意）' ?></label>
                    <input type="datetime-local" id="<?= $field ?>" name="<?= $field ?>"
                           value="<?= htmlspecialchars(to_datetime_local($_POST[$field] ?? $record[$field]), ENT_QUOTES, 'UTF-8') ?>"
                           <?= $field === 'clock_in_at' ? 'required' : '' ?>>
                </div>
            <?php endforeach; ?>

            <button type="submit">保存する</button>
        </form>
    </fieldset>

    <form method="post" action="/staff/attendance_edit.php" class="danger-form" onsubmit="return confirm('この打刻記録を削除します。よろしいですか？');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $attendanceId ?>">
        <button type="submit">この打刻記録を取り消す</button>
    </form>
<?php endif; ?>
</body>
</html>
