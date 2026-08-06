<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

// この画面で新規作成・修正するwork_stage_recordsのフィールド一覧（監査ログ用）。
const HEADCOUNT_LOG_FIELDS = ['category', 'facility_id', 'collection_cycle_id', 'stage', 'person_count', 'record_date', 'record_time'];
// 修正時にユーザーが変更できるのはこの3項目のみ（施設・工程・紐づくサイクルは確認記録の性質上変えない）。
const HEADCOUNT_EDITABLE_FIELDS = ['person_count', 'record_date', 'record_time'];

// 返却リネン袋数登録で書き込むcollection_cyclesのフィールド一覧（監査ログ用）。
const RETURN_LOG_FIELDS = ['return_bag_count', 'return_date', 'return_time', 'return_employee_id'];

/**
 * 到着記録済み（arrival_bag_count入力済み）だが、まだ人数確認（work_stage_records、
 * collection_cycle_idで紐づく有効な行）が無い集荷サイクルを、古い順に返す。
 * 一度確認済みのサイクルは、その確認記録が論理削除されない限り再度は出てこない（二重入力防止）。
 */
function find_unconfirmed_cycles(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT cc.id, cc.facility_id, cc.pickup_date, cc.pickup_bag_count, cc.arrival_bag_count, cc.arrival_time,
                f.name AS facility_name
         FROM collection_cycles cc
         INNER JOIN facilities f ON f.id = cc.facility_id
         LEFT JOIN work_stage_records wsr ON wsr.collection_cycle_id = cc.id AND wsr.deleted_at IS NULL
         WHERE cc.arrival_bag_count IS NOT NULL AND cc.deleted_at IS NULL AND wsr.id IS NULL
         ORDER BY cc.pickup_date ASC, cc.id ASC"
    );
    return $stmt->fetchAll();
}

/**
 * クリーニング所発送済み（dispatch_bag_count入力済み）だが、まだ返却リネン袋数
 * （collection_cycles.return_bag_count）が登録されていない集荷サイクルを、古い順に返す。
 * staff/collection_entry.php・admin/collection_records.phpの「返却」欄と同じ対象条件
 * （dispatch_bag_count IS NOT NULL AND return_bag_count IS NULL）を使うため、
 * どちらの画面で先に登録しても、もう一方の一覧からは自動的に消える。
 */
function find_unreturned_cycles(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT cc.id, cc.facility_id, cc.pickup_date, cc.dispatch_bag_count, cc.dispatch_date,
                f.name AS facility_name
         FROM collection_cycles cc
         INNER JOIN facilities f ON f.id = cc.facility_id
         WHERE cc.dispatch_bag_count IS NOT NULL AND cc.return_bag_count IS NULL AND cc.deleted_at IS NULL
         ORDER BY cc.pickup_date ASC, cc.id ASC"
    );
    return $stmt->fetchAll();
}

function parse_return_bag_count($raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int) $raw;
}

/**
 * 返却の日付・時刻・担当者は「今すぐ・自分名義」を既定値としつつ、上書きできるようにする
 * （staff/collection_entry.phpのクイック入力と同じ考え方）。空欄なら既定値を使う。
 */
function resolve_return_date($raw, string $default): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    return $dt !== false ? $dt->format('Y-m-d') : false;
}

function resolve_return_time($raw, string $default): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt !== false ? $dt->format('H:i:s') : false;
}

function resolve_return_employee_id($raw, int $default, array $validEmployeeIds)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    $id = (int) $raw;
    return in_array($id, $validEmployeeIds, true) ? $id : false;
}

function parse_headcount_edit_input(array $post): array
{
    $personCountRaw = trim((string) ($post['person_count'] ?? ''));
    $personCount = ($personCountRaw !== '' && ctype_digit($personCountRaw)) ? (int) $personCountRaw : null;
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
    if ($personCount === null) {
        $errors[] = '人数は0以上の整数を入力してください。';
    }
    if ($recordDate === false || $recordDate === null) {
        $errors[] = '確認日の形式が正しくありません。';
    }
    if ($recordTime === false || $recordTime === null) {
        $errors[] = '確認時刻の形式が正しくありません。';
    }

    return [
        ['person_count' => $personCount, 'record_date' => $recordDate, 'record_time' => $recordTime],
        $errors,
    ];
}

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'register_return') {
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);

            // 再表示までの間に他のスタッフが登録済みにした可能性があるため、直前に候補を再取得して検証する。
            $unreturnedCycles = find_unreturned_cycles($pdo);
            $unreturnedById = [];
            foreach ($unreturnedCycles as $cycle) {
                $unreturnedById[(int) $cycle['id']] = $cycle;
            }

            $now = new DateTime();
            $returnBagCount = parse_return_bag_count($_POST['return_bag_count'] ?? '');
            $returnDate = resolve_return_date($_POST['return_date'] ?? '', $now->format('Y-m-d'));
            $returnTime = resolve_return_time($_POST['return_time'] ?? '', $now->format('H:i:s'));
            $returnEmployeeId = resolve_return_employee_id($_POST['return_employee_id'] ?? '', (int) $staff['id'], $validEmployeeIds);

            if (!isset($unreturnedById[$cycleId])) {
                $errorMessage = '対象のサイクルは既に返却登録済みか、無効です。もう一度やり直してください。';
            } elseif ($returnBagCount === null) {
                $errorMessage = '返却リネン袋数は0以上の整数を入力してください。';
            } elseif ($returnDate === false || $returnTime === false || $returnEmployeeId === false) {
                $errorMessage = '返却日・時間・担当者の入力内容が正しくありません。';
            } else {
                $cycle = $unreturnedById[$cycleId];

                try {
                    $pdo->beginTransaction();

                    $newValues = [
                        'return_bag_count' => $returnBagCount,
                        'return_date' => $returnDate,
                        'return_time' => $returnTime,
                        'return_employee_id' => $returnEmployeeId,
                    ];

                    $logStmt = $pdo->prepare(
                        'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:cycle_id, :edited_by, :action, :field_name, NULL, :new_value)'
                    );
                    foreach (RETURN_LOG_FIELDS as $field) {
                        $logStmt->execute([
                            ':cycle_id' => $cycleId,
                            ':edited_by' => $staff['id'],
                            ':action' => 'update',
                            ':field_name' => $field,
                            ':new_value' => $newValues[$field],
                        ]);
                    }

                    $updateStmt = $pdo->prepare(
                        'UPDATE collection_cycles
                         SET return_bag_count = :return_bag_count, return_date = :return_date,
                             return_time = :return_time, return_employee_id = :return_employee_id
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        ':return_bag_count' => $returnBagCount,
                        ':return_date' => $returnDate,
                        ':return_time' => $returnTime,
                        ':return_employee_id' => $returnEmployeeId,
                        ':id' => $cycleId,
                    ]);

                    $pdo->commit();
                    set_flash('success', htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $cycle['pickup_date'] . '集荷分）の返却リネン袋数（' . $returnBagCount . '袋）を登録しました。');
                    header('Location: /staff/collection_headcount.php');
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'confirm_headcount') {
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
            $personCountRaw = trim((string) ($_POST['person_count'] ?? ''));
            $personCount = $personCountRaw === '' ? null : ($personCountRaw === '0' || ctype_digit($personCountRaw) ? (int) $personCountRaw : null);

            // 再表示までの間に他のスタッフが確認済みにした可能性があるため、直前に候補を再取得して検証する。
            $unconfirmedCycles = find_unconfirmed_cycles($pdo);
            $unconfirmedById = [];
            foreach ($unconfirmedCycles as $cycle) {
                $unconfirmedById[(int) $cycle['id']] = $cycle;
            }

            if (!isset($unconfirmedById[$cycleId])) {
                $errorMessage = '対象のサイクルは既に確認済みか、無効です。もう一度やり直してください。';
            } elseif ($personCount === null || $personCountRaw === '' || !ctype_digit($personCountRaw)) {
                $errorMessage = '人数は0以上の整数を入力してください。';
            } else {
                $cycle = $unconfirmedById[$cycleId];
                $now = new DateTime();

                $insertStmt = $pdo->prepare(
                    "INSERT INTO work_stage_records
                        (employee_id, category, facility_id, collection_cycle_id, stage, person_count, record_date, record_time)
                     VALUES
                        (:employee_id, '洗濯代行', :facility_id, :collection_cycle_id, 'wash', :person_count, :record_date, :record_time)"
                );
                $insertStmt->execute([
                    ':employee_id' => $staff['id'],
                    ':facility_id' => $cycle['facility_id'],
                    ':collection_cycle_id' => $cycleId,
                    ':person_count' => $personCount,
                    ':record_date' => $now->format('Y-m-d'),
                    ':record_time' => $now->format('H:i:s'),
                ]);
                $newRecordId = (int) $pdo->lastInsertId();

                $logStmt = $pdo->prepare(
                    'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                     VALUES (:record_id, :edited_by, :action, :field_name, NULL, :new_value)'
                );
                $newValues = [
                    'category' => '洗濯代行',
                    'facility_id' => $cycle['facility_id'],
                    'collection_cycle_id' => $cycleId,
                    'stage' => 'wash',
                    'person_count' => $personCount,
                    'record_date' => $now->format('Y-m-d'),
                    'record_time' => $now->format('H:i:s'),
                ];
                foreach (HEADCOUNT_LOG_FIELDS as $field) {
                    $logStmt->execute([
                        ':record_id' => $newRecordId,
                        ':edited_by' => $staff['id'],
                        ':action' => 'create',
                        ':field_name' => $field,
                        ':new_value' => $newValues[$field],
                    ]);
                }

                set_flash('success', htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $cycle['pickup_date'] . '集荷分）の人数（' . $personCount . '人）を確認・登録しました。');
                header('Location: /staff/collection_headcount.php');
                exit;
            }
        } elseif ($action === 'delete_headcount') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NOT NULL AND deleted_at IS NULL');
            $recordStmt->execute([':id' => $recordId]);
            $record = $recordStmt->fetch();

            if ($record === false) {
                $errorMessage = '対象の確認記録が見つかりません。';
            } else {
                try {
                    $pdo->beginTransaction();

                    $logStmt = $pdo->prepare(
                        'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:record_id, :edited_by, :action, :field_name, :old_value, NULL)'
                    );
                    foreach (HEADCOUNT_LOG_FIELDS as $field) {
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

                    $deleteStmt = $pdo->prepare('UPDATE work_stage_records SET deleted_at = :deleted_at WHERE id = :id');
                    $deleteStmt->execute([':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $recordId]);

                    $pdo->commit();
                    set_flash('success', '確認記録を削除しました。対象のサイクルは未確認一覧に戻ります。');
                    header('Location: /staff/collection_headcount.php');
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'edit_headcount') {
            $recordId = (int) ($_POST['id'] ?? 0);
            [$values, $parseErrors] = parse_headcount_edit_input($_POST);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } else {
                $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NOT NULL AND deleted_at IS NULL');
                $recordStmt->execute([':id' => $recordId]);
                $record = $recordStmt->fetch();

                if ($record === false) {
                    $errorMessage = '対象の確認記録が見つかりません。';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $logStmt = $pdo->prepare(
                            'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                             VALUES (:record_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                        );
                        $changedCount = 0;
                        foreach (HEADCOUNT_EDITABLE_FIELDS as $field) {
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
                            'UPDATE work_stage_records SET person_count = :person_count, record_date = :record_date, record_time = :record_time WHERE id = :id'
                        );
                        $updateStmt->execute([
                            ':person_count' => $values['person_count'],
                            ':record_date' => $values['record_date'],
                            ':record_time' => $values['record_time'],
                            ':id' => $recordId,
                        ]);

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '確認記録を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: /staff/collection_headcount.php');
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

// ---- 編集対象の読み込み（チーム共有データのため、確認した本人以外でも修正・削除できる） ----
$editingRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare(
        "SELECT wsr.*, f.name AS facility_name, cc.pickup_date
         FROM work_stage_records wsr
         INNER JOIN facilities f ON f.id = wsr.facility_id
         LEFT JOIN collection_cycles cc ON cc.id = wsr.collection_cycle_id
         WHERE wsr.id = :id AND wsr.collection_cycle_id IS NOT NULL AND wsr.deleted_at IS NULL"
    );
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingRecord = $row;
    }
}

$editFormValues = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && (string) ($_POST['action'] ?? '') === 'edit_headcount') {
    $editFormValues = [
        'id' => (int) ($_POST['id'] ?? 0),
        'person_count' => (string) ($_POST['person_count'] ?? ''),
        'record_date' => (string) ($_POST['record_date'] ?? ''),
        'record_time' => (string) ($_POST['record_time'] ?? ''),
        'facility_name' => $editingRecord['facility_name'] ?? '',
        'pickup_date' => $editingRecord['pickup_date'] ?? '',
    ];
} elseif ($editingRecord !== null) {
    $editFormValues = [
        'id' => (int) $editingRecord['id'],
        'person_count' => (string) $editingRecord['person_count'],
        'record_date' => $editingRecord['record_date'],
        'record_time' => $editingRecord['record_time'] !== null ? substr($editingRecord['record_time'], 0, 5) : '',
        'facility_name' => $editingRecord['facility_name'],
        'pickup_date' => $editingRecord['pickup_date'],
    ];
}

$unconfirmedCycles = find_unconfirmed_cycles($pdo);
$unreturnedCycles = find_unreturned_cycles($pdo);

// ---- 直近に確認した人数（自分以外の入力も含めて確認できるようにする） ----
$recentStmt = $pdo->query(
    "SELECT wsr.id, wsr.record_date, wsr.record_time, wsr.person_count, f.name AS facility_name,
            cc.pickup_date, cc.arrival_bag_count
     FROM work_stage_records wsr
     INNER JOIN facilities f ON f.id = wsr.facility_id
     LEFT JOIN collection_cycles cc ON cc.id = wsr.collection_cycle_id
     WHERE wsr.collection_cycle_id IS NOT NULL AND wsr.deleted_at IS NULL
     ORDER BY wsr.id DESC
     LIMIT 30"
);
$recentConfirmations = $recentStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>到着リネン袋の確認・返却リネン袋数の登録 | シフト管理</title>
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
        table.cycles { border-collapse: collapse; width: 100%; }
        table.cycles th, table.cycles td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.cycles th { background: #f5f5f5; }
        table.cycles input[type="number"] { width: 90px; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>到着リネン袋の確認・返却リネン袋数の登録</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($editFormValues !== null): ?>
<section class="edit-form">
    <h2>確認記録の修正（<?= htmlspecialchars($editFormValues['facility_name'], ENT_QUOTES, 'UTF-8') ?>　<?= htmlspecialchars($editFormValues['pickup_date'], ENT_QUOTES, 'UTF-8') ?>集荷分）</h2>
    <fieldset>
        <form method="post" action="/staff/collection_headcount.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_headcount">
            <input type="hidden" name="id" value="<?= (int) $editFormValues['id'] ?>">

            <div class="form-row">
                <label for="e_person_count">確認人数</label>
                <input type="number" id="e_person_count" name="person_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['person_count'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-row">
                <label for="e_record_date">確認日</label>
                <input type="date" id="e_record_date" name="record_date" value="<?= htmlspecialchars($editFormValues['record_date'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-row">
                <label for="e_record_time">確認時刻</label>
                <input type="time" id="e_record_time" name="record_time" value="<?= htmlspecialchars($editFormValues['record_time'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit">更新する</button>
            <a href="/staff/collection_headcount.php">キャンセル</a>
        </form>
    </fieldset>
</section>
<?php endif; ?>

<section class="unconfirmed-list">
    <h2>人数未確認の集荷サイクル</h2>
    <p class="notice">クリーニング所に到着済みのリネン袋のうち、中身（人数）がまだ確認されていないものです。袋を開けて確認した人数を入力してください。入力すると作業実績（洗濯工程）として登録され、この一覧から消えます。</p>

    <?php if (empty($unconfirmedCycles)): ?>
        <p class="notice">現在、人数未確認の集荷サイクルはありません。</p>
    <?php else: ?>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設</th>
                    <th>集荷日</th>
                    <th>集荷リネン袋数</th>
                    <th>到着リネン袋数</th>
                    <th>到着時刻</th>
                    <th>確認人数</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unconfirmedCycles as $cycle): ?>
                    <tr>
                        <form method="post" action="/staff/collection_headcount.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="confirm_headcount">
                            <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                            <td><?= htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $cycle['pickup_bag_count'] !== null ? (int) $cycle['pickup_bag_count'] . '袋' : '-' ?></td>
                            <td><?= (int) $cycle['arrival_bag_count'] ?>袋</td>
                            <td><?= $cycle['arrival_time'] !== null ? htmlspecialchars(substr($cycle['arrival_time'], 0, 5), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                            <td><input type="number" name="person_count" min="0" step="1" required></td>
                            <td><button type="submit">確認して登録</button></td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="unreturned-list">
    <h2>返却リネン袋数の登録</h2>
    <p class="notice">クリーニング所から発送済みのリネン袋のうち、施設へ返却する袋数がまだ登録されていないものです。</p>

    <?php if (empty($unreturnedCycles)): ?>
        <p class="notice">現在、返却未登録の集荷サイクルはありません。</p>
    <?php else: ?>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設</th>
                    <th>集荷日</th>
                    <th>発送リネン袋数</th>
                    <th>発送日</th>
                    <th>返却リネン袋数</th>
                    <th>返却日</th>
                    <th>返却時間</th>
                    <th>返却担当者</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unreturnedCycles as $cycle): ?>
                    <tr>
                        <form method="post" action="/staff/collection_headcount.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="register_return">
                            <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                            <td><?= htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $cycle['dispatch_bag_count'] ?>袋</td>
                            <td><?= $cycle['dispatch_date'] !== null ? htmlspecialchars($cycle['dispatch_date'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                            <td><input type="number" name="return_bag_count" min="0" step="1" required></td>
                            <td><input type="date" name="return_date" value="<?= (new DateTime())->format('Y-m-d') ?>"></td>
                            <td><input type="time" name="return_time" value="<?= (new DateTime())->format('H:i') ?>"></td>
                            <td>
                                <select name="return_employee_id">
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === (int) $staff['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><button type="submit">返却を登録</button></td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="recent-list">
    <h2>直近に確認した人数</h2>
    <p class="notice">この記録簿はチーム共有のため、確認した本人以外でも修正・削除できます。変更内容は履歴に記録されます。</p>
    <?php if (empty($recentConfirmations)): ?>
        <p class="notice">まだ確認記録はありません。</p>
    <?php else: ?>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設</th>
                    <th>集荷日</th>
                    <th>確認人数</th>
                    <th>確認日時</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentConfirmations as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['pickup_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $record['person_count'] ?>人</td>
                        <td><?= htmlspecialchars($record['record_date'], ENT_QUOTES, 'UTF-8') ?> <?= $record['record_time'] !== null ? htmlspecialchars(substr($record['record_time'], 0, 5), ENT_QUOTES, 'UTF-8') : '' ?></td>
                        <td>
                            <a href="/staff/collection_headcount.php?edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="/staff/collection_headcount.php" class="inline-form" onsubmit="return confirm('この確認記録を削除しますか？対象のサイクルは未確認一覧に戻ります。');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_headcount">
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
