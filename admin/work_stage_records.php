<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

// 集荷は集荷・配送記録簿（collection_cycles、admin/collection_records.php）に一本化したため、
// 作業実績（work_stage_records）の工程からは外した。洗濯・乾燥・畳みは2026-08-06に「洗濯」
// 1工程へ統合したため、区分（category）・工程（stage）とも選択させず固定値で登録する。
const WORK_RECORD_CATEGORY = '洗濯代行';
const WORK_RECORD_STAGE = 'wash';
const WORK_RECORD_STAGE_LABEL = '洗濯';

// employee_idsはwork_stage_records自体のカラムではなくwork_stage_record_employees側の
// 参加者一覧のため、汎用の差分ログ処理（$recordのカラム値と比較する処理）とは別に個別でログを記録する。
// person_countは従業員画面では参加者選択数を初期値として登録されるが、管理者画面では
// 到着リネン袋内の洗濯ネット数として個別に修正できる。completed_atは日時から算出する。
const WSR_EDITABLE_FIELDS = ['employee_id', 'category', 'facility_id', 'stage', 'person_count', 'record_date', 'record_time', 'completed_at'];

function parse_work_stage_record_input(array $post, array $validEmployeeIds, array $validFacilityIds): array
{
    $employeeId = (int) ($post['employee_id'] ?? 0);
    $facilityId = (int) ($post['facility_id'] ?? 0);
    $participantIds = [];
    foreach ((array) ($post['employee_ids'] ?? []) as $rawEmployeeId) {
        $participantId = (int) $rawEmployeeId;
        if (in_array($participantId, $validEmployeeIds, true)) {
            $participantIds[] = $participantId;
        }
    }
    $participantIds = array_values(array_unique($participantIds));
    $laundryNetCountRaw = trim((string) ($post['laundry_net_count'] ?? ''));
    $personCount = filter_var($laundryNetCountRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $recordDateRaw = trim((string) ($post['record_date'] ?? ''));
    $recordDate = null;
    if ($recordDateRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $recordDateRaw);
        $recordDate = $dt !== false ? $dt->format('Y-m-d') : false;
    }
    $recordTimeRaw = trim((string) ($post['record_time'] ?? ''));
    $recordTime = null;
    if ($recordTimeRaw !== '') {
        $dt = DateTime::createFromFormat('H:i', $recordTimeRaw);
        $recordTime = $dt !== false ? $dt->format('H:i:s') : false;
    }

    $errors = [];
    if (!in_array($employeeId, $validEmployeeIds, true)) {
        $errors[] = '従業員を選択してください。';
    }
    if (!in_array($facilityId, $validFacilityIds, true)) {
        $errors[] = '施設を選択してください。';
    }
    if ($personCount === false) {
        $errors[] = '洗濯ネット数は0以上の整数を入力してください。';
    }
    if ($recordDate === false || $recordDate === null) {
        $errors[] = '作業日の形式が正しくありません。';
    }
    if ($recordTime === false || $recordTime === null) {
        $errors[] = '作業時刻の形式が正しくありません。';
    }

    $completedAt = ($recordDate !== null && $recordDate !== false && $recordTime !== null && $recordTime !== false)
        ? $recordDate . ' ' . $recordTime
        : null;

    return [
        [
            'employee_id' => $employeeId,
            'category' => WORK_RECORD_CATEGORY,
            'facility_id' => $facilityId,
            'stage' => WORK_RECORD_STAGE,
            'person_count' => $personCount,
            'record_date' => $recordDate,
            'record_time' => $recordTime,
            'completed_at' => $completedAt,
            'employee_ids' => $participantIds,
        ],
        $errors,
    ];
}

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' AND is_shared_account = 0 ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$facilitiesStmt = $pdo->query("SELECT id, name FROM facilities WHERE is_active = 1 AND facility_type = '介護施設' ORDER BY name");
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            [$values, $parseErrors] = parse_work_stage_record_input($_POST, $validEmployeeIds, $validFacilityIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } else {
                try {
                    $pdo->beginTransaction();

                    $insertStmt = $pdo->prepare(
                        'INSERT INTO work_stage_records (employee_id, category, facility_id, stage, person_count, record_date, record_time, completed_at)
                         VALUES (:employee_id, :category, :facility_id, :stage, :person_count, :record_date, :record_time, :completed_at)'
                    );
                    $insertStmt->execute([
                        ':employee_id' => $values['employee_id'],
                        ':category' => $values['category'],
                        ':facility_id' => $values['facility_id'],
                        ':stage' => $values['stage'],
                        ':person_count' => $values['person_count'],
                        ':record_date' => $values['record_date'],
                        ':record_time' => $values['record_time'],
                        ':completed_at' => $values['completed_at'],
                    ]);
                    $newRecordId = (int) $pdo->lastInsertId();

                    if (!empty($values['employee_ids'])) {
                        record_work_stage_employees($pdo, $newRecordId, $values['employee_ids'], new DateTime($values['completed_at']));
                    }

                    $logStmt = $pdo->prepare(
                        'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:record_id, :edited_by, :action, :field_name, NULL, :new_value)'
                    );
                    foreach (WSR_EDITABLE_FIELDS as $field) {
                        $logStmt->execute([
                            ':record_id' => $newRecordId,
                            ':edited_by' => $admin['id'],
                            ':action' => 'create',
                            ':field_name' => $field,
                            ':new_value' => $values[$field],
                        ]);
                    }
                    $logStmt->execute([
                        ':record_id' => $newRecordId,
                        ':edited_by' => $admin['id'],
                        ':action' => 'create',
                        ':field_name' => 'employee_ids',
                        ':new_value' => implode(',', $values['employee_ids']),
                    ]);

                    $pdo->commit();
                    set_flash('success', '作業実績を登録しました。');
                    header('Location: /admin/work_stage_records.php');
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

$formEmployeeId = '';
$formFacilityId = '';
$formEmployeeIds = [];
$formLaundryNetCount = '0';
$formRecordDate = (new DateTime())->format('Y-m-d');
$formRecordTime = (new DateTime())->format('H:i');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formEmployeeId = (string) ($_POST['employee_id'] ?? '');
    $formFacilityId = (string) ($_POST['facility_id'] ?? '');
    $formEmployeeIds = array_map('intval', (array) ($_POST['employee_ids'] ?? []));
    $formLaundryNetCount = (string) ($_POST['laundry_net_count'] ?? '0');
    $formRecordDate = (string) ($_POST['record_date'] ?? '');
    $formRecordTime = (string) ($_POST['record_time'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作業実績の新規登録 | 管理者</title>
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
        .form-row label { display: inline-block; width: 90px; }
    </style>
</head>
<body>
<header>
    <h1>作業実績の新規登録</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/work_stage_record_edit_logs.php">修正履歴</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="record-form" id="record-form-section">
    <h2>作業実績の新規登録</h2>
    <fieldset>
        <form method="post" action="/admin/work_stage_records.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-row">
                <label for="employee_id">記録担当</label>
                <select id="employee_id" name="employee_id" required <?= empty($employees) ? 'disabled' : '' ?>>
                    <option value="">選択してください</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formEmployeeId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="record_date">作業日</label>
                <input type="date" id="record_date" name="record_date" value="<?= htmlspecialchars($formRecordDate, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="record_time">作業時刻</label>
                <input type="time" id="record_time" name="record_time" value="<?= htmlspecialchars($formRecordTime, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="facility_id">施設</label>
                <select id="facility_id" name="facility_id" required <?= empty($facilities) ? 'disabled' : '' ?>>
                    <option value="">選択してください</option>
                    <?php foreach ($facilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $formFacilityId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="employee_ids">参加した従業員</label>
                <select id="employee_ids" name="employee_ids[]" multiple size="<?= max(2, min(6, count($employees))) ?>">
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= in_array((int) $employee['id'], $formEmployeeIds, true) ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="laundry_net_count">洗濯ネット数</label>
                <input type="number" id="laundry_net_count" name="laundry_net_count" min="0" step="1" value="<?= htmlspecialchars($formLaundryNetCount, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit">登録する</button>
        </form>
    </fieldset>
</section>
</body>
</html>