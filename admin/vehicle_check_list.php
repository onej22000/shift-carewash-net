<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

function vc_parse_checked_at($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw);

    return $dt !== false ? $dt->format('Y-m-d H:i:00') : false;
}

/**
 * @param array<int,array{id:int|string,requires_value:int|string}> $items
 * @return array{0:?list<array{item_id:int,result:string,issue_note:?string}>, 1:?float, 2:?string, 3:bool}
 */
function vc_parse_checklist_input(array $post, array $items): array
{
    $results = [];
    $alcoholValue = null;

    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $result = (string) ($post['result_' . $itemId] ?? '');

        if (!in_array($result, ['ok', 'issue'], true)) {
            return [null, null, null, true];
        }

        $issueNoteRaw = trim((string) ($post['issue_note_' . $itemId] ?? ''));
        $issueNote = $issueNoteRaw === '' ? null : $issueNoteRaw;

        if ((int) $item['requires_value'] === 1) {
            $valueRaw = trim((string) ($post['value_' . $itemId] ?? ''));
            if ($valueRaw !== '') {
                if (!is_numeric($valueRaw)) {
                    return [null, null, null, true];
                }
                $alcoholValue = round((float) $valueRaw, 2);
            }
        }

        $results[] = ['item_id' => $itemId, 'result' => $result, 'issue_note' => $issueNote];
    }

    $overallStatus = 'ok';
    foreach ($results as $r) {
        if ($r['result'] === 'issue') {
            $overallStatus = 'issue';
            break;
        }
    }

    return [$results, $alcoholValue, $overallStatus, false];
}

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$vehiclesStmt = $pdo->query('SELECT id, plate_number, vehicle_name FROM vehicles WHERE is_active = 1 ORDER BY plate_number');
$vehicles = $vehiclesStmt->fetchAll();
$validVehicleIds = array_map('intval', array_column($vehicles, 'id'));

$itemsStmt = $pdo->query('SELECT id, category, label, requires_value FROM vehicle_check_items WHERE is_active = 1 ORDER BY sort_order, id');
$items = $itemsStmt->fetchAll();

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $checkId = (int) ($_POST['id'] ?? 0);
            $checkStmt = $pdo->prepare('SELECT id FROM vehicle_checks WHERE id = :id AND deleted_at IS NULL');
            $checkStmt->execute([':id' => $checkId]);

            if ($checkStmt->fetchColumn() === false) {
                $errorMessage = '対象の点検記録が見つかりません。';
            } else {
                $before = build_vehicle_check_snapshot($pdo, $checkId);
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE vehicle_checks SET deleted_at = :deleted_at WHERE id = :id')
                        ->execute([':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $checkId]);
                    record_vehicle_check_history($pdo, $checkId, 'delete', (int) $admin['id'], 'admin', $before, null);
                    $pdo->commit();
                    set_flash('success', '点検記録を削除しました。');
                    header('Location: /admin/vehicle_check_list.php');
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'create' || $action === 'update') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            $checkedAt = vc_parse_checked_at($_POST['checked_at'] ?? '');
            $notesRaw = trim((string) ($_POST['notes'] ?? ''));
            $notes = $notesRaw === '' ? null : $notesRaw;
            [$results, $alcoholValue, $overallStatus, $checklistError] = vc_parse_checklist_input($_POST, $items);

            if (!in_array($employeeId, $validEmployeeIds, true)) {
                $errorMessage = '従業員を選択してください。';
            } elseif (!in_array($vehicleId, $validVehicleIds, true)) {
                $errorMessage = '車両を選択してください。';
            } elseif ($checkedAt === false) {
                $errorMessage = '点検日時の形式が正しくありません。';
            } elseif ($checklistError) {
                $errorMessage = 'すべての点検項目に回答してください。';
            } elseif ($action === 'create') {
                $checkDate = substr($checkedAt, 0, 10);

                $insertStmt = $pdo->prepare(
                    'INSERT INTO vehicle_checks (employee_id, vehicle_id, check_date, checked_at, alcohol_value, overall_status, notes, created_by)
                     VALUES (:employee_id, :vehicle_id, :check_date, :checked_at, :alcohol_value, :overall_status, :notes, :created_by)'
                );
                $insertStmt->execute([
                    ':employee_id' => $employeeId,
                    ':vehicle_id' => $vehicleId,
                    ':check_date' => $checkDate,
                    ':checked_at' => $checkedAt,
                    ':alcohol_value' => $alcoholValue,
                    ':overall_status' => $overallStatus,
                    ':notes' => $notes,
                    ':created_by' => $admin['id'],
                ]);
                $checkId = (int) $pdo->lastInsertId();

                $resultStmt = $pdo->prepare(
                    'INSERT INTO vehicle_check_results (vehicle_check_id, item_id, result, issue_note)
                     VALUES (:vehicle_check_id, :item_id, :result, :issue_note)'
                );
                foreach ($results as $r) {
                    $resultStmt->execute([
                        ':vehicle_check_id' => $checkId,
                        ':item_id' => $r['item_id'],
                        ':result' => $r['result'],
                        ':issue_note' => $r['issue_note'],
                    ]);
                }

                record_vehicle_check_history($pdo, $checkId, 'create', (int) $admin['id'], 'admin', null, build_vehicle_check_snapshot($pdo, $checkId));

                set_flash('success', '点検記録を登録しました。');
                header('Location: /admin/vehicle_check_list.php');
                exit;
            } else {
                $checkId = (int) ($_POST['id'] ?? 0);
                $checkStmt = $pdo->prepare('SELECT id FROM vehicle_checks WHERE id = :id AND deleted_at IS NULL');
                $checkStmt->execute([':id' => $checkId]);

                if ($checkStmt->fetchColumn() === false) {
                    $errorMessage = '対象の点検記録が見つかりません。';
                } else {
                    $before = build_vehicle_check_snapshot($pdo, $checkId);
                    $checkDate = substr($checkedAt, 0, 10);

                    try {
                        $pdo->beginTransaction();

                        $pdo->prepare(
                            'UPDATE vehicle_checks
                             SET employee_id = :employee_id, vehicle_id = :vehicle_id, check_date = :check_date, checked_at = :checked_at,
                                 alcohol_value = :alcohol_value, overall_status = :overall_status, notes = :notes,
                                 updated_by = :updated_by
                             WHERE id = :id'
                        )->execute([
                            ':employee_id' => $employeeId,
                            ':vehicle_id' => $vehicleId,
                            ':check_date' => $checkDate,
                            ':checked_at' => $checkedAt,
                            ':alcohol_value' => $alcoholValue,
                            ':overall_status' => $overallStatus,
                            ':notes' => $notes,
                            ':updated_by' => $admin['id'],
                            ':id' => $checkId,
                        ]);

                        $pdo->prepare('DELETE FROM vehicle_check_results WHERE vehicle_check_id = :id')->execute([':id' => $checkId]);

                        $resultStmt = $pdo->prepare(
                            'INSERT INTO vehicle_check_results (vehicle_check_id, item_id, result, issue_note)
                             VALUES (:vehicle_check_id, :item_id, :result, :issue_note)'
                        );
                        foreach ($results as $r) {
                            $resultStmt->execute([
                                ':vehicle_check_id' => $checkId,
                                ':item_id' => $r['item_id'],
                                ':result' => $r['result'],
                                ':issue_note' => $r['issue_note'],
                            ]);
                        }

                        record_vehicle_check_history($pdo, $checkId, 'update', (int) $admin['id'], 'admin', $before, build_vehicle_check_snapshot($pdo, $checkId));

                        $pdo->commit();
                        set_flash('success', '点検記録を修正しました。');
                        header('Location: /admin/vehicle_check_list.php');
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

// ---- フィルタ ----
$filterEmployeeId = (int) ($_GET['employee_id'] ?? 0);
$filterVehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$filterDate = (string) ($_GET['date'] ?? '');
if ($filterDate !== '' && DateTime::createFromFormat('Y-m-d', $filterDate) === false) {
    $filterDate = '';
}

// ---- 編集対象の読み込み ----
$editingRecord = null;
$editingResultsByItemId = [];
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM vehicle_checks WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingRecord = $row;
        $resultsStmt = $pdo->prepare('SELECT item_id, result, issue_note FROM vehicle_check_results WHERE vehicle_check_id = :id');
        $resultsStmt->execute([':id' => $editId]);
        foreach ($resultsStmt->fetchAll() as $r) {
            $editingResultsByItemId[(int) $r['item_id']] = $r;
        }
    }
}

$formAction = 'create';
$formId = null;
$formEmployeeId = '';
$formVehicleId = '';
$formCheckedAt = to_datetime_local((new DateTime())->format('Y-m-d H:i:s'));
$formNotes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    $formEmployeeId = (string) ($_POST['employee_id'] ?? '');
    $formVehicleId = (string) ($_POST['vehicle_id'] ?? '');
    $formCheckedAt = (string) ($_POST['checked_at'] ?? '');
    $formNotes = (string) ($_POST['notes'] ?? '');
} elseif ($editingRecord !== null) {
    $formAction = 'update';
    $formId = (int) $editingRecord['id'];
    $formEmployeeId = (string) $editingRecord['employee_id'];
    $formVehicleId = (string) $editingRecord['vehicle_id'];
    $formCheckedAt = to_datetime_local($editingRecord['checked_at']);
    $formNotes = (string) ($editingRecord['notes'] ?? '');
}

// ---- 一覧の取得 ----
$whereConditions = ['vc.deleted_at IS NULL'];
$listParams = [];
if ($filterEmployeeId > 0) {
    $whereConditions[] = 'vc.employee_id = :employee_id';
    $listParams[':employee_id'] = $filterEmployeeId;
}
if ($filterVehicleId > 0) {
    $whereConditions[] = 'vc.vehicle_id = :vehicle_id';
    $listParams[':vehicle_id'] = $filterVehicleId;
}
if ($filterDate !== '') {
    $whereConditions[] = 'vc.check_date = :check_date';
    $listParams[':check_date'] = $filterDate;
}

$listStmt = $pdo->prepare(
    'SELECT vc.id, vc.check_date, vc.checked_at, vc.alcohol_value, vc.overall_status, vc.notes,
            v.plate_number, v.vehicle_name, e.name AS employee_name
     FROM vehicle_checks vc
     INNER JOIN vehicles v ON v.id = vc.vehicle_id
     INNER JOIN employees e ON e.id = vc.employee_id
     WHERE ' . implode(' AND ', $whereConditions) . '
     ORDER BY vc.check_date DESC, vc.id DESC
     LIMIT 300'
);
$listStmt->execute($listParams);
$records = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>車両等チェック記録 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 6px; padding: 16px; max-width: 500px; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; margin-bottom: 4px; font-weight: bold; }
        .form-row select, .form-row textarea, .form-row input { width: 100%; font-size: 1em; padding: 6px; box-sizing: border-box; }
        .check-category { font-weight: bold; margin: 12px 0 6px; color: #0b5ed7; }
        .check-item { border-bottom: 1px solid #eee; padding: 8px 0; }
        .check-item .label { margin-bottom: 6px; }
        .check-item .radios label { display: inline-block; font-weight: normal; margin-right: 16px; }
        .check-item .value-input { margin-top: 6px; max-width: 160px; }
        .check-item .issue-note { margin-top: 6px; display: none; }
        .check-item.show-issue-note .issue-note { display: block; }
        .filter-row { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.records th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-ok { background: #e6f4ea; color: #1e7e34; }
        .status-issue { background: #fdecea; color: #b3261e; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>車両等チェック記録</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/vehicles.php">車両マスタ管理</a> | <a href="/admin/vehicle_maintenance_list.php">車両管理記録</a> | <a href="/admin/vehicle_alert_settings.php">アラート設定</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="check-form">
    <h2><?= $formAction === 'update' ? '点検記録の修正' : '点検記録の新規追加' ?></h2>
    <?php if (empty($vehicles) || empty($employees)): ?>
        <p class="notice">車両または従業員が登録されていません。</p>
    <?php else: ?>
    <form method="post" action="/admin/vehicle_check_list.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
        <?php if ($formAction === 'update'): ?>
            <input type="hidden" name="id" value="<?= (int) $formId ?>">
        <?php endif; ?>

        <fieldset>
            <div class="form-row">
                <label for="employee_id">従業員</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">選択してください</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formEmployeeId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="vehicle_id">使用車両</label>
                <select id="vehicle_id" name="vehicle_id" required>
                    <option value="">選択してください</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= (int) $vehicle['id'] ?>" <?= (string) $vehicle['id'] === $formVehicleId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($vehicle['plate_number'], ENT_QUOTES, 'UTF-8') ?><?= $vehicle['vehicle_name'] !== null ? '（' . htmlspecialchars($vehicle['vehicle_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="checked_at">点検日時</label>
                <input type="datetime-local" id="checked_at" name="checked_at" value="<?= htmlspecialchars($formCheckedAt, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
        </fieldset>

        <?php $currentCategory = null; ?>
        <?php foreach ($items as $item): ?>
            <?php
            $itemId = (int) $item['id'];
            $existing = $editingResultsByItemId[$itemId] ?? null;
            ?>
            <?php if ($item['category'] !== $currentCategory): ?>
                <?php $currentCategory = $item['category']; ?>
                <div class="check-category"><?= htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="check-item <?= ($existing['result'] ?? '') === 'issue' ? 'show-issue-note' : '' ?>" data-item-id="<?= $itemId ?>">
                <div class="label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="radios">
                    <label><input type="radio" name="result_<?= $itemId ?>" value="ok" <?= ($existing['result'] ?? '') === 'ok' ? 'checked' : '' ?> required> 問題なし</label>
                    <label><input type="radio" name="result_<?= $itemId ?>" value="issue" <?= ($existing['result'] ?? '') === 'issue' ? 'checked' : '' ?>> 異常あり</label>
                </div>
                <?php if ((int) $item['requires_value'] === 1): ?>
                    <div class="value-input">
                        <input type="number" name="value_<?= $itemId ?>" step="0.01" min="0" placeholder="測定値" value="<?= $editingRecord !== null && $editingRecord['alcohol_value'] !== null ? htmlspecialchars((string) $editingRecord['alcohol_value'], ENT_QUOTES, 'UTF-8') : '' ?>">
                    </div>
                <?php endif; ?>
                <div class="issue-note">
                    <textarea name="issue_note_<?= $itemId ?>" rows="2" placeholder="異常の内容を入力してください"><?= htmlspecialchars($existing['issue_note'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="form-row" style="margin-top:16px;">
            <label for="notes">全体備考</label>
            <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($formNotes, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
        <?php if ($formAction === 'update'): ?>
            <a href="/admin/vehicle_check_list.php">キャンセル</a>
        <?php endif; ?>
    </form>
    <?php endif; ?>
</section>

<form method="get" action="/admin/vehicle_check_list.php" class="filter-row">
    <label for="f_employee_id">従業員:</label>
    <select id="f_employee_id" name="employee_id" onchange="this.form.submit()">
        <option value="0">全員</option>
        <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === $filterEmployeeId ? 'selected' : '' ?>>
                <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label for="f_vehicle_id">車両:</label>
    <select id="f_vehicle_id" name="vehicle_id" onchange="this.form.submit()">
        <option value="0">全車両</option>
        <?php foreach ($vehicles as $vehicle): ?>
            <option value="<?= (int) $vehicle['id'] ?>" <?= (int) $vehicle['id'] === $filterVehicleId ? 'selected' : '' ?>>
                <?= htmlspecialchars($vehicle['plate_number'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label for="f_date">日付:</label>
    <input type="date" id="f_date" name="date" value="<?= htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
    <a href="/admin/vehicle_check_list.php">条件クリア</a>
</form>

<section class="record-list">
    <h2>点検記録一覧</h2>
    <?php if (empty($records)): ?>
        <p class="notice">対象の点検記録はありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>点検日時</th>
                    <th>従業員</th>
                    <th>車両</th>
                    <th>判定</th>
                    <th>酒気帯び値</th>
                    <th>備考</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars(substr($record['checked_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['plate_number'], ENT_QUOTES, 'UTF-8') ?><?= $record['vehicle_name'] !== null ? '（' . htmlspecialchars($record['vehicle_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?></td>
                        <td>
                            <?php if ($record['overall_status'] === 'ok'): ?>
                                <span class="status-badge status-ok">問題なし</span>
                            <?php else: ?>
                                <span class="status-badge status-issue">異常あり</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $record['alcohol_value'] !== null ? htmlspecialchars((string) $record['alcohol_value'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $record['notes'] !== null ? htmlspecialchars($record['notes'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td>
                            <a href="/admin/vehicle_check_list.php?edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="/admin/vehicle_check_list.php" class="inline-form" onsubmit="return confirm('この点検記録を削除しますか？');">
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

<script>
document.querySelectorAll('.check-item').forEach(function (item) {
    var radios = item.querySelectorAll('input[type="radio"]');
    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            item.classList.toggle('show-issue-note', radio.value === 'issue' && radio.checked);
        });
    });
});
</script>
</body>
</html>
