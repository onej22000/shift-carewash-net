<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

const ADD_FIELDS = ['clock_in_at', 'clock_out_at', 'break_start_at', 'break_end_at'];
const ADD_LOG_FIELDS = ['category', 'clock_in_at', 'clock_out_at', 'break_start_at', 'break_end_at'];
const ADD_FIELD_LABELS = [
    'category' => '区分',
    'clock_in_at' => '出勤時刻',
    'clock_out_at' => '退勤時刻',
    'break_start_at' => '休憩開始時刻',
    'break_end_at' => '休憩終了時刻',
];

$requestedDate = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['work_date'] ?? '')
    : (string) ($_GET['date'] ?? '');
$dateObj = DateTime::createFromFormat('Y-m-d', $requestedDate);
if ($dateObj === false) {
    $dateObj = new DateTime('today');
}
$workDate = $dateObj->format('Y-m-d');

$errorMessage = '';
$blocked = null;

$yearMonth = substr($workDate, 0, 7);
if (is_month_confirmed($pdo, (int) $staff['id'], $yearMonth)) {
    $blocked = 'この日（' . $workDate . '）が属する月は賃金確定済みのため追加できません。修正が必要な場合は管理者にご連絡ください。';
}

// ---- 対象日のシフトから区分の初期値を提案する ----
$dateShiftsStmt = $pdo->prepare('SELECT categories FROM shifts WHERE employee_id = :employee_id AND work_date = :work_date');
$dateShiftsStmt->execute([':employee_id' => $staff['id'], ':work_date' => $workDate]);
$dateCategories = [];
foreach ($dateShiftsStmt->fetchAll() as $shift) {
    foreach (categories_from_value($shift['categories']) as $category) {
        if (!in_array($category, $dateCategories, true)) {
            $dateCategories[] = $category;
        }
    }
}
$suggestedCategory = resolve_shift_category($dateCategories);

if ($blocked === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $values = [];
        $parseErrors = [];

        foreach (ADD_FIELDS as $field) {
            $posted = trim((string) ($_POST[$field] ?? ''));

            if ($posted === '') {
                if ($field === 'clock_in_at') {
                    $parseErrors[] = ADD_FIELD_LABELS[$field] . 'を入力してください。';
                } else {
                    $values[$field] = null;
                }
                continue;
            }

            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $posted);
            if ($dt === false) {
                $parseErrors[] = ADD_FIELD_LABELS[$field] . 'の形式が正しくありません。';
                continue;
            }
            $values[$field] = $dt->format('Y-m-d H:i:s');
        }

        $values['category'] = (string) ($_POST['category'] ?? '');

        if (!empty($parseErrors)) {
            $errorMessage = implode(' ', $parseErrors);
        } elseif (!in_array($values['category'], SHIFT_CATEGORIES, true)) {
            $errorMessage = '区分を選択してください。';
        } elseif (substr($values['clock_in_at'], 0, 10) !== $workDate) {
            $errorMessage = '出勤時刻は対象日（' . $workDate . '）の日付にしてください。';
        } elseif (($values['break_start_at'] === null) !== ($values['break_end_at'] === null)) {
            $errorMessage = '休憩開始時刻と休憩終了時刻は両方入力するか、両方空にしてください。';
        } elseif ($values['clock_out_at'] !== null && $values['clock_out_at'] <= $values['clock_in_at']) {
            $errorMessage = '退勤時刻は出勤時刻より後にしてください。';
        } elseif ($values['break_start_at'] !== null && $values['break_start_at'] < $values['clock_in_at']) {
            $errorMessage = '休憩開始時刻は出勤時刻以降にしてください。';
        } elseif ($values['break_end_at'] !== null && $values['break_start_at'] !== null && $values['break_end_at'] <= $values['break_start_at']) {
            $errorMessage = '休憩終了時刻は休憩開始時刻より後にしてください。';
        } elseif ($values['clock_out_at'] !== null && $values['break_end_at'] !== null && $values['break_end_at'] > $values['clock_out_at']) {
            $errorMessage = '休憩終了時刻は退勤時刻以前にしてください。';
        } else {
            $totalBreakMinutes = null;
            if ($values['break_start_at'] !== null && $values['break_end_at'] !== null) {
                $totalBreakMinutes = max(0, (int) round(
                    ((new DateTime($values['break_end_at']))->getTimestamp() - (new DateTime($values['break_start_at']))->getTimestamp()) / 60
                ));
            }

            $workMinutes = null;
            if ($values['clock_out_at'] !== null) {
                $rawMinutes = max(0, (int) round(
                    ((new DateTime($values['clock_out_at']))->getTimestamp() - (new DateTime($values['clock_in_at']))->getTimestamp()) / 60
                ));
                $workMinutes = max(0, $rawMinutes - ($totalBreakMinutes ?? 0));
            }
            $status = $values['clock_out_at'] !== null ? 'done' : 'working';

            try {
                $pdo->beginTransaction();

                $insertStmt = $pdo->prepare(
                    'INSERT INTO attendance
                        (employee_id, category, clock_in_at, break_start_at, break_end_at, total_break_minutes, clock_out_at, work_minutes, status)
                     VALUES
                        (:employee_id, :category, :clock_in_at, :break_start_at, :break_end_at, :total_break_minutes, :clock_out_at, :work_minutes, :status)'
                );
                $insertStmt->execute([
                    ':employee_id' => $staff['id'],
                    ':category' => $values['category'],
                    ':clock_in_at' => $values['clock_in_at'],
                    ':break_start_at' => $values['break_start_at'],
                    ':break_end_at' => $values['break_end_at'],
                    ':total_break_minutes' => $totalBreakMinutes,
                    ':clock_out_at' => $values['clock_out_at'],
                    ':work_minutes' => $workMinutes,
                    ':status' => $status,
                ]);
                $newAttendanceId = (int) $pdo->lastInsertId();

                $logStmt = $pdo->prepare(
                    'INSERT INTO attendance_edit_logs (attendance_id, edited_by, action, field_name, old_value, new_value)
                     VALUES (:attendance_id, :edited_by, :action, :field_name, NULL, :new_value)'
                );
                foreach (ADD_LOG_FIELDS as $field) {
                    if ($values[$field] === null) {
                        continue;
                    }
                    $logStmt->execute([
                        ':attendance_id' => $newAttendanceId,
                        ':edited_by' => $staff['id'],
                        ':action' => 'create',
                        ':field_name' => $field,
                        ':new_value' => $values[$field],
                    ]);
                }

                $pdo->commit();

                set_flash('success', $workDate . 'の打刻記録を追加しました。');
                header('Location: /staff/dashboard.php');
                exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $errorMessage = '保存に失敗しました。もう一度お試しください。';
            }
        }
    }
}

$csrfToken = csrf_token();
$postedValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>打刻の追加 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message.error { padding: 8px 12px; border-radius: 4px; background: #fdecea; color: #b3261e; margin-bottom: 12px; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 10px; }
        .form-row label { display: block; margin-bottom: 4px; }
        button[type="submit"] { background: #0b5ed7; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1>打刻の追加</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($blocked !== null): ?>
    <p class="message error"><?= htmlspecialchars($blocked, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <?php if ($errorMessage !== ''): ?>
        <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <p class="notice">打刻を忘れた日の記録をあとから追加できます。追加内容は履歴に記録されます。</p>

    <fieldset>
        <form method="post" action="/staff/attendance_add.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-row">
                <label for="work_date">対象日</label>
                <input type="date" id="work_date" name="work_date"
                       value="<?= htmlspecialchars($postedValues['work_date'] ?? $workDate, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="category">区分</label>
                <select id="category" name="category" required>
                    <option value="">選択してください</option>
                    <?php foreach (SHIFT_CATEGORIES as $category): ?>
                        <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($postedValues['category'] ?? $suggestedCategory) === $category ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php foreach (ADD_FIELDS as $field): ?>
                <div class="form-row">
                    <label for="<?= $field ?>"><?= htmlspecialchars(ADD_FIELD_LABELS[$field], ENT_QUOTES, 'UTF-8') ?><?= $field === 'clock_in_at' ? '' : '（任意）' ?></label>
                    <input type="datetime-local" id="<?= $field ?>" name="<?= $field ?>"
                           value="<?= htmlspecialchars(to_datetime_local($postedValues[$field] ?? null), ENT_QUOTES, 'UTF-8') ?>"
                           <?= $field === 'clock_in_at' ? 'required' : '' ?>>
                </div>
            <?php endforeach; ?>

            <button type="submit">この内容で追加する</button>
        </form>
    </fieldset>
<?php endif; ?>
</body>
</html>
