<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

const EDITABLE_SHIFT_FIELDS = ['work_date', 'start_time', 'end_time', 'note', 'categories'];
// 従業員が自分でシフトを新規作成・編集する際に選択できる区分。店舗区分は管理者のみが
// 管理するため、フォームの選択肢自体に含めない（選べないようにする）。
const STAFF_SELECTABLE_SHIFT_CATEGORIES = ['洗濯代行', '集荷'];
const SHIFT_FIELD_LABELS = [
    'work_date' => '勤務日',
    'start_time' => '開始時刻',
    'end_time' => '終了時刻',
    'note' => '備考',
    'categories' => '業務種別',
];

function sanitize_categories(array $rawCategories): array
{
    return array_values(array_intersect(SHIFT_CATEGORIES, $rawCategories));
}

function validate_shift_input(string $workDate, string $startTime, string $endTime, string $todayStr): ?string
{
    $parsedDate = DateTime::createFromFormat('Y-m-d', $workDate);
    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $workDate) {
        return '勤務日の形式が正しくありません。';
    }
    if ($workDate < $todayStr) {
        return '過去の日付にはシフトを登録・変更できません。';
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime)) {
        return '開始時刻の形式が正しくありません。';
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $endTime)) {
        return '終了時刻の形式が正しくありません。';
    }
    if (!preg_match('/:(00|30)$/', $startTime) || !preg_match('/:(00|30)$/', $endTime)) {
        return '開始時刻・終了時刻は00分または30分で入力してください。';
    }

    return null;
}

function shift_summary_text(array $shift): string
{
    $categories = categories_from_value($shift['categories']);
    $parts = [
        $shift['work_date'],
        substr($shift['start_time'], 0, 5) . '-' . substr($shift['end_time'], 0, 5),
    ];
    if (!empty($categories)) {
        $parts[] = implode('/', $categories);
    }
    if (!empty($shift['note'])) {
        $parts[] = $shift['note'];
    }

    return substr(implode(' ', $parts), 0, 100);
}

function log_shift_action(PDO $pdo, ?int $shiftId, int $editedBy, string $action, ?string $fieldName, ?string $oldValue, ?string $newValue): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO shift_edit_logs (shift_id, edited_by, action, field_name, old_value, new_value)
         VALUES (:shift_id, :edited_by, :action, :field_name, :old_value, :new_value)'
    );
    $stmt->execute([
        ':shift_id' => $shiftId,
        ':edited_by' => $editedBy,
        ':action' => $action,
        ':field_name' => $fieldName,
        ':old_value' => $oldValue !== null ? substr($oldValue, 0, 100) : null,
        ':new_value' => $newValue !== null ? substr($newValue, 0, 100) : null,
    ]);
}

$todayStr = (new DateTime('today'))->format('Y-m-d');
$backUrl = (string) ($_GET['back'] ?? $_POST['back'] ?? '/staff/team_shifts.php');
if (!preg_match('#^/staff/team_shifts\.php(\?.*)?$#', $backUrl)) {
    $backUrl = '/staff/team_shifts.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shiftId = (int) ($_POST['shift_id'] ?? 0);
} else {
    $shiftId = (int) ($_GET['id'] ?? 0);
}

$existingShift = null;
if ($shiftId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM shifts WHERE id = :id');
    $stmt->execute([':id' => $shiftId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $existingShift = $row;
    }
}

$errorMessage = '';
$blocked = null;

// ---- アクセス制御: 本人のシフトのみ、かつ過去日付は編集不可、かつ店舗区分は編集不可 ----
// 店舗区分のシフトは、区分変更を含め一切の更新（削除も）を許可しない。ここで$blockedをセットすると
// 後段の「$blocked === null && POST」の判定によりPOST処理（update/delete）そのものが実行されなくなる
// ため、フォーム非表示だけでなく直接POSTされた場合の保険にもなる。
if ($shiftId > 0) {
    if ($existingShift === null) {
        $blocked = 'シフトが見つかりません。';
    } elseif ((int) $existingShift['employee_id'] !== (int) $staff['id']) {
        $blocked = 'このシフトを編集する権限がありません。';
    } elseif (categories_include_store(categories_from_value($existingShift['categories']))) {
        $blocked = 'このシフトは店舗区分のため編集できません（店舗のシフトは管理者のみが変更できます）。';
    } elseif ($existingShift['work_date'] < $todayStr) {
        $blocked = '過去の日付のシフトは編集できません。閲覧のみ可能です。';
    }
}

if ($blocked === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            if ($existingShift === null) {
                $errorMessage = 'シフトが見つかりません。';
            } else {
                try {
                    $pdo->beginTransaction();

                    log_shift_action(
                        $pdo,
                        (int) $existingShift['id'],
                        (int) $staff['id'],
                        'delete',
                        null,
                        shift_summary_text($existingShift),
                        null
                    );

                    // FIND_IN_SET(...)=0の条件は、上のアクセス制御($blocked)で既に店舗区分は
                    // 弾いているため冗長ではあるが、念のためSQL自体でも店舗区分の行には一切
                    // マッチしないようにする多重防御。
                    $pdo->prepare("DELETE FROM shifts WHERE id = :id AND employee_id = :employee_id AND FIND_IN_SET('店舗', categories) = 0")
                        ->execute([':id' => $shiftId, ':employee_id' => $staff['id']]);

                    recalculate_daily_breaks($pdo, (int) $staff['id'], $existingShift['work_date']);

                    $pdo->commit();

                    set_flash('success', 'シフトを削除しました。');
                    header('Location: ' . $backUrl);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'create' || $action === 'update') {
            $workDate = (string) ($_POST['work_date'] ?? '');
            $startTime = (string) ($_POST['start_time'] ?? '');
            $endTime = (string) ($_POST['end_time'] ?? '');
            $note = trim((string) ($_POST['note'] ?? ''));
            $note = $note === '' ? null : $note;
            $categories = sanitize_categories((array) ($_POST['categories'] ?? []));
            $categoriesValue = implode(',', $categories);

            // 要求された区分（新規作成・更新後の値）に「店舗」が含まれる場合は、理由を問わず拒否する。
            // フォームには店舗チェックボックス自体を出していないが、直接POSTされた場合の保険として
            // フロント側の制御に関わらずここで必ず弾く（update時は既存シフトが店舗でないことは
            // 既に$blockedで保証済みだが、それとは別に「更新後の値」を独立してチェックすることで
            // 非店舗シフトを店舗シフトに書き換える回避策を防ぐ）。
            if (categories_include_store($categories)) {
                $errorMessage = '店舗区分のシフトを登録・変更することはできません。店舗のシフトは管理者にご連絡ください。';
            }

            $validationError = $errorMessage === '' ? validate_shift_input($workDate, $startTime, $endTime, $todayStr) : null;

            if ($errorMessage !== '') {
                // 店舗区分エラーが既にセットされているため、後続のcreate/update処理には進まない
            } elseif ($validationError !== null) {
                $errorMessage = $validationError;
            } elseif ($action === 'create') {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'INSERT INTO shifts (employee_id, work_date, start_time, end_time, note, categories, created_by)
                         VALUES (:employee_id, :work_date, :start_time, :end_time, :note, :categories, :created_by)'
                    );
                    $stmt->execute([
                        ':employee_id' => $staff['id'],
                        ':work_date' => $workDate,
                        ':start_time' => $startTime,
                        ':end_time' => $endTime,
                        ':note' => $note,
                        ':categories' => $categoriesValue,
                        ':created_by' => $staff['id'],
                    ]);
                    $newShiftId = (int) $pdo->lastInsertId();

                    log_shift_action(
                        $pdo,
                        $newShiftId,
                        (int) $staff['id'],
                        'create',
                        null,
                        null,
                        shift_summary_text([
                            'work_date' => $workDate,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'note' => $note,
                            'categories' => $categoriesValue,
                        ])
                    );

                    recalculate_daily_breaks($pdo, (int) $staff['id'], $workDate);

                    $pdo->commit();

                    set_flash('success', 'シフトを登録しました。');
                    header('Location: ' . $backUrl);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            } else {
                // update
                if ($existingShift === null) {
                    $errorMessage = 'シフトが見つかりません。';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $newValues = [
                            'work_date' => $workDate,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'note' => (string) $note,
                            'categories' => $categoriesValue,
                        ];
                        $oldValuesForCompare = [
                            'work_date' => $existingShift['work_date'],
                            'start_time' => substr($existingShift['start_time'], 0, 5),
                            'end_time' => substr($existingShift['end_time'], 0, 5),
                            'note' => (string) $existingShift['note'],
                            'categories' => $existingShift['categories'],
                        ];

                        $changedCount = 0;
                        foreach (EDITABLE_SHIFT_FIELDS as $field) {
                            if ($newValues[$field] === $oldValuesForCompare[$field]) {
                                continue;
                            }
                            log_shift_action(
                                $pdo,
                                (int) $existingShift['id'],
                                (int) $staff['id'],
                                'update',
                                $field,
                                $oldValuesForCompare[$field] === '' ? null : $oldValuesForCompare[$field],
                                $newValues[$field] === '' ? null : $newValues[$field]
                            );
                            $changedCount++;
                        }

                        // WHERE句のFIND_IN_SET(...)=0は、更新前の区分が店舗でないことをSQL自体でも
                        // 保証する多重防御（$blockedによるアクセス制御と合わせて二重チェック）。
                        // 更新後の値に店舗が含まれないことは、この分岐に到達する前の
                        // categories_include_store($categories)チェックで既に保証済み。
                        $updateStmt = $pdo->prepare(
                            "UPDATE shifts
                             SET work_date = :work_date, start_time = :start_time, end_time = :end_time,
                                 note = :note, categories = :categories
                             WHERE id = :id AND employee_id = :employee_id AND FIND_IN_SET('店舗', categories) = 0"
                        );
                        $updateStmt->execute([
                            ':work_date' => $workDate,
                            ':start_time' => $startTime,
                            ':end_time' => $endTime,
                            ':note' => $note,
                            ':categories' => $categoriesValue,
                            ':id' => $shiftId,
                            ':employee_id' => $staff['id'],
                        ]);

                        recalculate_daily_breaks($pdo, (int) $staff['id'], $workDate);
                        if ($existingShift['work_date'] !== $workDate) {
                            recalculate_daily_breaks($pdo, (int) $staff['id'], $existingShift['work_date']);
                        }

                        $pdo->commit();

                        set_flash('success', $changedCount > 0
                            ? 'シフトを更新しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: ' . $backUrl);
                        exit;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $errorMessage = '更新に失敗しました。もう一度お試しください。';
                    }
                }
            }
        }
    }
}

$csrfToken = csrf_token();

$isNew = $shiftId === 0;
$formWorkDate = $existingShift['work_date'] ?? (string) ($_GET['date'] ?? $todayStr);
$formStartTime = $existingShift !== null ? substr($existingShift['start_time'], 0, 5) : '09:00';
$formEndTime = $existingShift !== null ? substr($existingShift['end_time'], 0, 5) : '18:00';
$formNote = $existingShift['note'] ?? '';
$formCategories = $existingShift !== null ? categories_from_value($existingShift['categories']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formWorkDate = (string) ($_POST['work_date'] ?? $formWorkDate);
    $formStartTime = (string) ($_POST['start_time'] ?? $formStartTime);
    $formEndTime = (string) ($_POST['end_time'] ?? $formEndTime);
    $formNote = (string) ($_POST['note'] ?? $formNote);
    $formCategories = sanitize_categories((array) ($_POST['categories'] ?? []));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isNew ? 'シフトの新規登録' : 'シフトの編集' ?> | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message.error { padding: 8px 12px; border-radius: 4px; background: #fdecea; color: #b3261e; margin-bottom: 12px; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 10px; }
        .form-row label { display: block; margin-bottom: 4px; }
        label.category-checkbox { display: inline-flex; align-items: center; font-weight: normal; width: auto; margin-right: 12px; }
        .danger-form { margin-top: 16px; }
        .danger-form button { background: #b3261e; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1><?= $isNew ? 'シフトの新規登録' : 'シフトの編集' ?></h1>
    <nav><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">シフト表に戻る</a></nav>
</header>

<?php if ($blocked !== null): ?>
    <p class="message error"><?= htmlspecialchars($blocked, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <?php if ($errorMessage !== ''): ?>
        <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <p class="notice">今日以降の自分のシフトのみ登録・編集・削除できます。変更履歴はすべて記録されます。</p>

    <fieldset>
        <form method="post" action="/staff/shift_edit.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
            <input type="hidden" name="shift_id" value="<?= (int) $shiftId ?>">
            <input type="hidden" name="back" value="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-row">
                <label for="work_date">勤務日</label>
                <input type="date" id="work_date" name="work_date" min="<?= htmlspecialchars($todayStr, ENT_QUOTES, 'UTF-8') ?>"
                       value="<?= htmlspecialchars($formWorkDate, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="start_time">開始時刻</label>
                <input type="time" id="start_time" name="start_time" step="1800"
                       value="<?= htmlspecialchars($formStartTime, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="end_time">終了時刻</label>
                <input type="time" id="end_time" name="end_time" step="1800"
                       value="<?= htmlspecialchars($formEndTime, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label>業務種別</label>
                <?php foreach (STAFF_SELECTABLE_SHIFT_CATEGORIES as $category): ?>
                    <label class="category-checkbox">
                        <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"
                               <?= in_array($category, $formCategories, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="form-row">
                <label for="note">備考</label>
                <input type="text" id="note" name="note" maxlength="255" value="<?= htmlspecialchars($formNote, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit"><?= $isNew ? '登録する' : '更新する' ?></button>
        </form>
    </fieldset>

    <?php if (!$isNew): ?>
        <form method="post" action="/staff/shift_edit.php" class="danger-form" onsubmit="return confirm('このシフトを削除します。よろしいですか？');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="shift_id" value="<?= (int) $shiftId ?>">
            <input type="hidden" name="back" value="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">このシフトを削除する</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
