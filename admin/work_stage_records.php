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

$periodLabels = [
    'all' => '全期間',
    '7' => '直近7日',
    '30' => '直近30日',
];
$period = (string) ($_GET['period'] ?? '30');
if (!isset($periodLabels[$period])) {
    $period = '30';
}

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' ORDER BY name");
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

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND deleted_at IS NULL');
            $recordStmt->execute([':id' => $recordId]);
            $record = $recordStmt->fetch();

            if ($record === false) {
                $errorMessage = '対象の作業実績が見つかりません。';
            } else {
                try {
                    $pdo->beginTransaction();

                    $logStmt = $pdo->prepare(
                        'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:record_id, :edited_by, :action, :field_name, :old_value, NULL)'
                    );
                    foreach (WSR_EDITABLE_FIELDS as $field) {
                        if ($record[$field] === null) {
                            continue;
                        }
                        $logStmt->execute([
                            ':record_id' => $recordId,
                            ':edited_by' => $admin['id'],
                            ':action' => 'delete',
                            ':field_name' => $field,
                            ':old_value' => $record[$field],
                        ]);
                    }

                    $deleteStmt = $pdo->prepare('UPDATE work_stage_records SET deleted_at = :deleted_at WHERE id = :id');
                    $deleteStmt->execute([':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $recordId]);

                    $pdo->commit();
                    set_flash('success', '作業実績を削除しました。');
                    header('Location: /admin/work_stage_records.php?period=' . urlencode($period));
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'create' || $action === 'update') {
            [$values, $parseErrors] = parse_work_stage_record_input($_POST, $validEmployeeIds, $validFacilityIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } elseif ($action === 'create') {
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
                    header('Location: /admin/work_stage_records.php?period=' . urlencode($period));
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            } else {
                $recordId = (int) ($_POST['id'] ?? 0);
                $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND deleted_at IS NULL');
                $recordStmt->execute([':id' => $recordId]);
                $record = $recordStmt->fetch();

                if ($record === false) {
                    $errorMessage = '対象の作業実績が見つかりません。';
                } else {
                    $oldEmployeeIdsStmt = $pdo->prepare('SELECT employee_id FROM work_stage_record_employees WHERE work_stage_record_id = :id ORDER BY employee_id');
                    $oldEmployeeIdsStmt->execute([':id' => $recordId]);
                    $oldEmployeeIds = array_map('intval', array_column($oldEmployeeIdsStmt->fetchAll(), 'employee_id'));
                    $newEmployeeIds = $values['employee_ids'];
                    sort($oldEmployeeIds);
                    $sortedNewEmployeeIds = $newEmployeeIds;
                    sort($sortedNewEmployeeIds);
                    $employeeIdsChanged = $oldEmployeeIds !== $sortedNewEmployeeIds;
                    // completed_atが変わった場合、既存参加者のstarted_atは古いcompleted_atを起点に
                    // 計算済みのため、参加者に変更が無くても開始時刻を計算し直す必要がある。
                    $completedAtChanged = (string) $values['completed_at'] !== (string) $record['completed_at'];
                    $employeesNeedRecompute = $employeeIdsChanged || $completedAtChanged;

                    try {
                        $pdo->beginTransaction();

                        $logStmt = $pdo->prepare(
                            'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                             VALUES (:record_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                        );
                        $changedCount = 0;
                        foreach (WSR_EDITABLE_FIELDS as $field) {
                            if ((string) $values[$field] === (string) $record[$field]) {
                                continue;
                            }
                            $logStmt->execute([
                                ':record_id' => $recordId,
                                ':edited_by' => $admin['id'],
                                ':action' => 'update',
                                ':field_name' => $field,
                                ':old_value' => $record[$field],
                                ':new_value' => $values[$field],
                            ]);
                            $changedCount++;
                        }
                        if ($employeeIdsChanged) {
                            $logStmt->execute([
                                ':record_id' => $recordId,
                                ':edited_by' => $admin['id'],
                                ':action' => 'update',
                                ':field_name' => 'employee_ids',
                                ':old_value' => implode(',', $oldEmployeeIds),
                                ':new_value' => implode(',', $newEmployeeIds),
                            ]);
                            $changedCount++;
                        }

                        $updateStmt = $pdo->prepare(
                            'UPDATE work_stage_records
                             SET employee_id = :employee_id, category = :category, facility_id = :facility_id,
                                 stage = :stage, person_count = :person_count, record_date = :record_date, record_time = :record_time,
                                 completed_at = :completed_at
                             WHERE id = :id'
                        );
                        $updateStmt->execute([
                            ':employee_id' => $values['employee_id'],
                            ':category' => $values['category'],
                            ':facility_id' => $values['facility_id'],
                            ':stage' => $values['stage'],
                            ':person_count' => $values['person_count'],
                            ':record_date' => $values['record_date'],
                            ':record_time' => $values['record_time'],
                            ':completed_at' => $values['completed_at'],
                            ':id' => $recordId,
                        ]);

                        if ($employeesNeedRecompute) {
                            $deleteEmployeesStmt = $pdo->prepare('DELETE FROM work_stage_record_employees WHERE work_stage_record_id = :id');
                            $deleteEmployeesStmt->execute([':id' => $recordId]);
                            if (!empty($newEmployeeIds)) {
                                record_work_stage_employees($pdo, $recordId, $newEmployeeIds, new DateTime($values['completed_at']));
                            }
                        }

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '作業実績を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: /admin/work_stage_records.php?period=' . urlencode($period));
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

$flash = pop_flash();
$csrfToken = csrf_token();

// ---- 編集対象の読み込み ----
$editingRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingRecord = $row;
    }
}

$formAction = 'create';
$formId = null;
$formEmployeeId = '';
$formFacilityId = '';
$formEmployeeIds = [];
$formLaundryNetCount = '0';
$formRecordDate = (new DateTime())->format('Y-m-d');
$formRecordTime = (new DateTime())->format('H:i');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    $formEmployeeId = (string) ($_POST['employee_id'] ?? '');
    $formFacilityId = (string) ($_POST['facility_id'] ?? '');
    $formEmployeeIds = array_map('intval', (array) ($_POST['employee_ids'] ?? []));
    $formLaundryNetCount = (string) ($_POST['laundry_net_count'] ?? '0');
    $formRecordDate = (string) ($_POST['record_date'] ?? '');
    $formRecordTime = (string) ($_POST['record_time'] ?? '');
} elseif ($editingRecord !== null) {
    $formAction = 'update';
    $formId = (int) $editingRecord['id'];
    $formEmployeeId = (string) $editingRecord['employee_id'];
    $formFacilityId = (string) $editingRecord['facility_id'];
    $editingEmployeeIdsStmt = $pdo->prepare('SELECT employee_id FROM work_stage_record_employees WHERE work_stage_record_id = :id');
    $editingEmployeeIdsStmt->execute([':id' => (int) $editingRecord['id']]);
    $formEmployeeIds = array_map('intval', array_column($editingEmployeeIdsStmt->fetchAll(), 'employee_id'));
    $formLaundryNetCount = (string) $editingRecord['person_count'];
    $formRecordDate = $editingRecord['record_date'];
    $formRecordTime = $editingRecord['record_time'] !== null ? substr($editingRecord['record_time'], 0, 5) : (new DateTime())->format('H:i');
}

// ---- 一覧の取得 ----
$dateCondition = '';
$dateParams = [];
if ($period !== 'all') {
    $end = (new DateTime())->format('Y-m-d');
    $start = (new DateTime())->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d');
    $dateCondition = 'AND w.record_date BETWEEN :start AND :end';
    $dateParams = [':start' => $start, ':end' => $end];
}

$listStmt = $pdo->prepare(
    "SELECT w.id, w.record_date, w.record_time, w.stage, w.person_count, w.created_at,
            e.name AS employee_name, f.name AS facility_name
     FROM work_stage_records w
     INNER JOIN employees e ON e.id = w.employee_id
     INNER JOIN facilities f ON f.id = w.facility_id
     WHERE w.deleted_at IS NULL $dateCondition
     ORDER BY w.record_date DESC, w.id DESC
     LIMIT 300"
);
$listStmt->execute($dateParams);
$records = $listStmt->fetchAll();

// ---- 一覧の参加者名をまとめて取得 ----
$participantsByRecordId = [];
if (!empty($records)) {
    $recordIds = array_column($records, 'id');
    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $participantsStmt = $pdo->prepare(
        "SELECT wse.work_stage_record_id, e.name
         FROM work_stage_record_employees wse
         INNER JOIN employees e ON e.id = wse.employee_id
         WHERE wse.work_stage_record_id IN ($placeholders)
         ORDER BY e.name"
    );
    $participantsStmt->execute($recordIds);
    foreach ($participantsStmt->fetchAll() as $row) {
        $participantsByRecordId[(int) $row['work_stage_record_id']][] = $row['name'];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作業実績の管理 | 管理者</title>
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
        .period-nav { margin-bottom: 12px; }
        .period-nav a { margin-right: 12px; padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; text-decoration: none; color: #222; }
        .period-nav a.active { background: #0b5ed7; color: #fff; border-color: #0b5ed7; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.records th { background: #f5f5f5; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>作業実績の管理</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/work_stage_record_edit_logs.php">修正履歴</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="record-form" id="record-form-section">
    <h2><?= $formAction === 'update' ? '作業実績の修正' : '作業実績の新規登録' ?></h2>
    <fieldset>
        <form method="post" action="/admin/work_stage_records.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <?php endif; ?>

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

            <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($formAction === 'update'): ?>
                <a href="/admin/work_stage_records.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
            <?php endif; ?>
        </form>
    </fieldset>
</section>

<div class="period-nav">
    <?php foreach ($periodLabels as $key => $label): ?>
        <a href="?period=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="<?= $period === $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<section class="record-list">
    <h2>作業実績一覧</h2>
    <?php if (empty($records)): ?>
        <p class="notice">対象期間の作業実績はありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>作業日</th>
                    <th>時刻</th>
                    <th>記録担当</th>
                    <th>参加した従業員</th>
                    <th>施設</th>
                    <th>工程</th>
                    <th>洗濯ネット数</th>
                    <th>登録日時</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['record_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $record['record_time'] !== null ? htmlspecialchars(substr($record['record_time'], 0, 5), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= htmlspecialchars($record['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= !empty($participantsByRecordId[(int) $record['id']]) ? htmlspecialchars(implode('・', $participantsByRecordId[(int) $record['id']]), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(WORK_RECORD_STAGE_LABEL, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $record['person_count'] ?>枚</td>
                        <td><?= htmlspecialchars($record['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="/admin/work_stage_records.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>&edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="/admin/work_stage_records.php" class="inline-form" onsubmit="return confirm('この作業実績を削除しますか？');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                <button type="submit">削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
</body>
</html>