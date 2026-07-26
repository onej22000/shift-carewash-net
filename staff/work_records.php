<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

// 集荷は集荷・配送記録簿（collection_cycles、staff/collection_entry.php）に一本化したため、
// 作業実績（work_stage_records）の工程からは外した。残る洗濯・乾燥・畳みはすべて洗濯代行の
// 作業のため、区分（category）は選択させず常に「洗濯代行」で固定する。
const STAGE_LABELS = [
    'wash' => '洗濯',
    'dry' => '乾燥',
    'fold' => '畳み',
];

const WORK_RECORD_CATEGORY = '洗濯代行';

// employee_idは常に本人（$staff['id']）に固定するため対象外。他人の記録は一覧にも出さず、編集・削除もできない。
const WSR_EDITABLE_FIELDS = ['category', 'facility_id', 'stage', 'person_count', 'record_date', 'record_time'];

function parse_work_stage_record_input(array $post, array $validFacilityIds): array
{
    $facilityId = (int) ($post['facility_id'] ?? 0);
    $stage = (string) ($post['stage'] ?? '');
    $personCountRaw = trim((string) ($post['person_count'] ?? ''));
    $personCount = $personCountRaw === '' ? null : (int) $personCountRaw;
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
    if (!in_array($facilityId, $validFacilityIds, true)) {
        $errors[] = '施設を選択してください。';
    }
    if (!array_key_exists($stage, STAGE_LABELS)) {
        $errors[] = '工程を選択してください。';
    }
    if ($personCount === null || $personCount < 0) {
        $errors[] = '人数は0以上の数値を入力してください。';
    }
    if ($recordDate === false || $recordDate === null) {
        $errors[] = '作業日の形式が正しくありません。';
    }
    if ($recordTime === false || $recordTime === null) {
        $errors[] = '作業時刻の形式が正しくありません。';
    }

    return [
        [
            'category' => WORK_RECORD_CATEGORY,
            'facility_id' => $facilityId,
            'stage' => $stage,
            'person_count' => $personCount,
            'record_date' => $recordDate,
            'record_time' => $recordTime,
        ],
        $errors,
    ];
}

$today = (new DateTime())->format('Y-m-d');
$nowTime = (new DateTime())->format('H:i');

$periodLabels = [
    'all' => '全期間',
    '7' => '直近7日',
    '30' => '直近30日',
];
$period = (string) ($_GET['period'] ?? '30');
if (!isset($periodLabels[$period])) {
    $period = '30';
}

$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities WHERE is_active = 1 ORDER BY name');
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
            // employee_id = 本人 の条件を必ず付け、他の従業員の記録を削除できないようにする。
            $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL');
            $recordStmt->execute([':id' => $recordId, ':employee_id' => $staff['id']]);
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
                            ':edited_by' => $staff['id'],
                            ':action' => 'delete',
                            ':field_name' => $field,
                            ':old_value' => $record[$field],
                        ]);
                    }

                    $deleteStmt = $pdo->prepare('UPDATE work_stage_records SET deleted_at = :deleted_at WHERE id = :id AND employee_id = :employee_id');
                    $deleteStmt->execute([
                        ':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'),
                        ':id' => $recordId,
                        ':employee_id' => $staff['id'],
                    ]);

                    $pdo->commit();
                    set_flash('success', '作業実績を削除しました。');
                    header('Location: /staff/work_records.php?period=' . urlencode($period));
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'create' || $action === 'update') {
            [$values, $parseErrors] = parse_work_stage_record_input($_POST, $validFacilityIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } elseif ($action === 'create') {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO work_stage_records (employee_id, category, facility_id, stage, person_count, record_date, record_time)
                     VALUES (:employee_id, :category, :facility_id, :stage, :person_count, :record_date, :record_time)'
                );
                $insertStmt->execute([
                    ':employee_id' => $staff['id'],
                    ':category' => $values['category'],
                    ':facility_id' => $values['facility_id'],
                    ':stage' => $values['stage'],
                    ':person_count' => $values['person_count'],
                    ':record_date' => $values['record_date'],
                    ':record_time' => $values['record_time'],
                ]);
                $newRecordId = (int) $pdo->lastInsertId();

                $logStmt = $pdo->prepare(
                    'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                     VALUES (:record_id, :edited_by, :action, :field_name, NULL, :new_value)'
                );
                foreach (WSR_EDITABLE_FIELDS as $field) {
                    $logStmt->execute([
                        ':record_id' => $newRecordId,
                        ':edited_by' => $staff['id'],
                        ':action' => 'create',
                        ':field_name' => $field,
                        ':new_value' => $values[$field],
                    ]);
                }

                set_flash('success', '作業実績を登録しました。');
                header('Location: /staff/work_records.php?period=' . urlencode($period));
                exit;
            } else {
                $recordId = (int) ($_POST['id'] ?? 0);
                $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL');
                $recordStmt->execute([':id' => $recordId, ':employee_id' => $staff['id']]);
                $record = $recordStmt->fetch();

                if ($record === false) {
                    $errorMessage = '対象の作業実績が見つかりません。';
                } else {
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
                                ':edited_by' => $staff['id'],
                                ':action' => 'update',
                                ':field_name' => $field,
                                ':old_value' => $record[$field],
                                ':new_value' => $values[$field],
                            ]);
                            $changedCount++;
                        }

                        $updateStmt = $pdo->prepare(
                            'UPDATE work_stage_records
                             SET category = :category, facility_id = :facility_id, stage = :stage,
                                 person_count = :person_count, record_date = :record_date, record_time = :record_time
                             WHERE id = :id AND employee_id = :employee_id'
                        );
                        $updateStmt->execute([
                            ':category' => $values['category'],
                            ':facility_id' => $values['facility_id'],
                            ':stage' => $values['stage'],
                            ':person_count' => $values['person_count'],
                            ':record_date' => $values['record_date'],
                            ':record_time' => $values['record_time'],
                            ':id' => $recordId,
                            ':employee_id' => $staff['id'],
                        ]);

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '作業実績を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: /staff/work_records.php?period=' . urlencode($period));
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

// ---- 編集対象の読み込み（本人の記録のみ） ----
$editingRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL');
    $stmt->execute([':id' => $editId, ':employee_id' => $staff['id']]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingRecord = $row;
    }
}

$formAction = 'create';
$formId = null;
$formFacilityId = '';
$formStage = '';
$formPersonCount = '';
$formRecordDate = $today;
$formRecordTime = $nowTime;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && in_array((string) ($_POST['action'] ?? ''), ['create', 'update'], true)) {
    $formAction = (string) $_POST['action'];
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    $formFacilityId = (string) ($_POST['facility_id'] ?? '');
    $formStage = (string) ($_POST['stage'] ?? '');
    $formPersonCount = (string) ($_POST['person_count'] ?? '');
    $formRecordDate = (string) ($_POST['record_date'] ?? '');
    $formRecordTime = (string) ($_POST['record_time'] ?? '');
} elseif ($editingRecord !== null) {
    $formAction = 'update';
    $formId = (int) $editingRecord['id'];
    $formFacilityId = (string) $editingRecord['facility_id'];
    $formStage = $editingRecord['stage'];
    $formPersonCount = (string) $editingRecord['person_count'];
    $formRecordDate = $editingRecord['record_date'];
    $formRecordTime = $editingRecord['record_time'] !== null ? substr($editingRecord['record_time'], 0, 5) : $nowTime;
}

// ---- 自分の作業実績一覧（本人分のみ） ----
$dateCondition = '';
$dateParams = [':employee_id' => $staff['id']];
if ($period !== 'all') {
    $end = (new DateTime())->format('Y-m-d');
    $start = (new DateTime())->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d');
    $dateCondition = 'AND w.record_date BETWEEN :start AND :end';
    $dateParams[':start'] = $start;
    $dateParams[':end'] = $end;
}

$listStmt = $pdo->prepare(
    "SELECT w.id, w.record_date, w.record_time, w.stage, w.person_count, w.created_at, f.name AS facility_name
     FROM work_stage_records w
     INNER JOIN facilities f ON f.id = w.facility_id
     WHERE w.employee_id = :employee_id AND w.deleted_at IS NULL $dateCondition
     ORDER BY w.record_date DESC, w.id DESC
     LIMIT 300"
);
$listStmt->execute($dateParams);
$records = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作業実績の入力・編集 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
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
    <h1>作業実績の入力・編集</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="record-form" id="record-form-section">
    <h2><?= $formAction === 'update' ? '作業実績の修正' : '作業実績を登録（過去日も入力可）' ?></h2>
    <fieldset>
        <form method="post" action="/staff/work_records.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <?php endif; ?>

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
                <label for="stage">工程</label>
                <select id="stage" name="stage" required>
                    <option value="">選択してください</option>
                    <?php foreach (STAGE_LABELS as $stageKey => $stageLabel): ?>
                        <option value="<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>" <?= $formStage === $stageKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="person_count">人数</label>
                <input type="number" id="person_count" name="person_count" min="0" step="1" value="<?= htmlspecialchars($formPersonCount, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($formAction === 'update'): ?>
                <a href="/staff/work_records.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
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
    <h2>自分の作業実績一覧</h2>
    <p class="notice">追加・修正・削除の操作はすべて履歴に記録され、管理者が確認できます。</p>
    <?php if (empty($records)): ?>
        <p class="notice">対象期間の作業実績はありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>作業日</th>
                    <th>時刻</th>
                    <th>施設</th>
                    <th>工程</th>
                    <th>人数</th>
                    <th>登録日時</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['record_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $record['record_time'] !== null ? htmlspecialchars(substr($record['record_time'], 0, 5), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(STAGE_LABELS[$record['stage']] ?? $record['stage'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $record['person_count'] ?>人</td>
                        <td><?= htmlspecialchars($record['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="/staff/work_records.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>&edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="/staff/work_records.php" class="inline-form" onsubmit="return confirm('この作業実績を削除しますか？');">
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
