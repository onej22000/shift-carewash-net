<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

const HEADCOUNT_LOG_FIELDS = ['category', 'facility_id', 'collection_cycle_id', 'stage', 'person_count', 'record_date', 'record_time'];
const HEADCOUNT_EDITABLE_FIELDS = ['person_count', 'record_date', 'record_time'];

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

$periodLabels = [
    'all' => '全期間',
    '7' => '直近7日',
    '30' => '直近30日',
];
$period = (string) ($_GET['period'] ?? '30');
if (!isset($periodLabels[$period])) {
    $period = '30';
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'confirm_headcount') {
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
            $personCountRaw = trim((string) ($_POST['person_count'] ?? ''));
            $personCount = $personCountRaw === '' ? null : ($personCountRaw === '0' || ctype_digit($personCountRaw) ? (int) $personCountRaw : null);

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
                    ':employee_id' => $admin['id'],
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
                        ':edited_by' => $admin['id'],
                        ':action' => 'create',
                        ':field_name' => $field,
                        ':new_value' => $newValues[$field],
                    ]);
                }

                set_flash('success', htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $cycle['pickup_date'] . '集荷分）の人数（' . $personCount . '人）を確認・登録しました。');
                header('Location: /admin/collection_headcount.php?period=' . urlencode($period));
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
                            ':edited_by' => $admin['id'],
                            ':action' => 'delete',
                            ':field_name' => $field,
                            ':old_value' => $record[$field],
                        ]);
                    }

                    $deleteStmt = $pdo->prepare('UPDATE work_stage_records SET deleted_at = :deleted_at WHERE id = :id');
                    $deleteStmt->execute([':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $recordId]);

                    $pdo->commit();
                    set_flash('success', '確認記録を削除しました。対象のサイクルは未確認一覧に戻ります。');
                    header('Location: /admin/collection_headcount.php?period=' . urlencode($period));
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
                                ':edited_by' => $admin['id'],
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
                        header('Location: /admin/collection_headcount.php?period=' . urlencode($period));
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

// ---- 確認済み一覧（対象期間、全従業員・管理者分） ----
$dateCondition = '';
$dateParams = [];
if ($period !== 'all') {
    $end = (new DateTime())->format('Y-m-d');
    $start = (new DateTime())->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d');
    $dateCondition = 'AND wsr.record_date BETWEEN :start AND :end';
    $dateParams = [':start' => $start, ':end' => $end];
}

$listStmt = $pdo->prepare(
    "SELECT wsr.id, wsr.record_date, wsr.record_time, wsr.person_count, f.name AS facility_name,
            e.name AS employee_name, cc.pickup_date, cc.arrival_bag_count
     FROM work_stage_records wsr
     INNER JOIN facilities f ON f.id = wsr.facility_id
     INNER JOIN employees e ON e.id = wsr.employee_id
     LEFT JOIN collection_cycles cc ON cc.id = wsr.collection_cycle_id
     WHERE wsr.collection_cycle_id IS NOT NULL AND wsr.deleted_at IS NULL $dateCondition
     ORDER BY wsr.id DESC
     LIMIT 300"
);
$listStmt->execute($dateParams);
$confirmations = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集荷人数の確認 | 管理者</title>
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
        table.cycles { border-collapse: collapse; width: 100%; }
        table.cycles th, table.cycles td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.cycles th { background: #f5f5f5; }
        table.cycles input[type="number"] { width: 90px; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>集荷人数の確認</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
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
        <form method="post" action="/admin/collection_headcount.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">
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
            <a href="/admin/collection_headcount.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
        </form>
    </fieldset>
</section>
<?php endif; ?>

<section class="unconfirmed-list">
    <h2>人数未確認の集荷サイクル</h2>
    <p class="notice">クリーニング所に到着済みのリネン袋のうち、中身（人数）がまだ確認されていないものです。</p>

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
                        <form method="post" action="/admin/collection_headcount.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">
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

<div class="period-nav">
    <?php foreach ($periodLabels as $key => $label): ?>
        <a href="?period=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="<?= $period === $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<section class="confirmed-list">
    <h2>確認済み一覧</h2>
    <?php if (empty($confirmations)): ?>
        <p class="notice">対象期間の確認記録はありません。</p>
    <?php else: ?>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設</th>
                    <th>集荷日</th>
                    <th>確認人数</th>
                    <th>確認者</th>
                    <th>確認日時</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($confirmations as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['pickup_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $record['person_count'] ?>人</td>
                        <td><?= htmlspecialchars($record['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['record_date'], ENT_QUOTES, 'UTF-8') ?> <?= $record['record_time'] !== null ? htmlspecialchars(substr($record['record_time'], 0, 5), ENT_QUOTES, 'UTF-8') : '' ?></td>
                        <td>
                            <a href="/admin/collection_headcount.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>&edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="/admin/collection_headcount.php?period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この確認記録を削除しますか？対象のサイクルは未確認一覧に戻ります。');">
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
