<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

const VEHICLE_CHECK_PENDING_TIMEOUT_SECONDS = 15 * 60;

function pending_clock_in_valid(): bool
{
    if (!isset($_SESSION['pending_clock_in']['requested_at'])) {
        return false;
    }
    $requestedAt = new DateTime($_SESSION['pending_clock_in']['requested_at']);
    $elapsed = (new DateTime())->getTimestamp() - $requestedAt->getTimestamp();

    return $elapsed <= VEHICLE_CHECK_PENDING_TIMEOUT_SECONDS;
}

if (!pending_clock_in_valid()) {
    unset($_SESSION['pending_clock_in']);
    set_flash('error', '出勤手続きが時間切れになりました。もう一度「出勤する」からやり直してください。');
    header('Location: /staff/clock.php');
    exit;
}

$pending = $_SESSION['pending_clock_in'];

$vehiclesStmt = $pdo->query('SELECT id, plate_number, vehicle_name FROM vehicles WHERE is_active = 1 ORDER BY plate_number');
$vehicles = $vehiclesStmt->fetchAll();
$validVehicleIds = array_map('intval', array_column($vehicles, 'id'));

$itemsStmt = $pdo->query(
    'SELECT id, category, label, requires_value
     FROM vehicle_check_items
     WHERE is_active = 1
     ORDER BY sort_order, id'
);
$items = $itemsStmt->fetchAll();
$validItemIds = array_map('intval', array_column($items, 'id'));

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        $notes = $notesRaw === '' ? null : $notesRaw;

        if (!in_array($vehicleId, $validVehicleIds, true)) {
            $errorMessage = '車両を選択してください。';
        } else {
            $results = [];
            $alcoholValue = null;
            $parseError = false;

            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $result = (string) ($_POST['result_' . $itemId] ?? '');

                if (!in_array($result, ['ok', 'issue'], true)) {
                    $parseError = true;
                    break;
                }

                $issueNoteRaw = trim((string) ($_POST['issue_note_' . $itemId] ?? ''));
                $issueNote = $issueNoteRaw === '' ? null : $issueNoteRaw;

                if ((int) $item['requires_value'] === 1) {
                    $valueRaw = trim((string) ($_POST['value_' . $itemId] ?? ''));
                    if ($valueRaw !== '') {
                        if (!is_numeric($valueRaw)) {
                            $parseError = true;
                            break;
                        }
                        $alcoholValue = round((float) $valueRaw, 2);
                    }
                }

                $results[] = [
                    'item_id' => $itemId,
                    'result' => $result,
                    'issue_note' => $issueNote,
                ];
            }

            if ($parseError) {
                $errorMessage = 'すべての点検項目に回答してください。';
            } else {
                $overallStatus = 'ok';
                foreach ($results as $r) {
                    if ($r['result'] === 'issue') {
                        $overallStatus = 'issue';
                        break;
                    }
                }

                $now = new DateTime();

                try {
                    $pdo->beginTransaction();

                    $checkStmt = $pdo->prepare(
                        'INSERT INTO vehicle_checks
                            (employee_id, vehicle_id, check_date, checked_at, alcohol_value, overall_status, notes, created_by)
                         VALUES
                            (:employee_id, :vehicle_id, :check_date, :checked_at, :alcohol_value, :overall_status, :notes, :created_by)'
                    );
                    $checkStmt->execute([
                        ':employee_id' => $staff['id'],
                        ':vehicle_id' => $vehicleId,
                        ':check_date' => $now->format('Y-m-d'),
                        ':checked_at' => $now->format('Y-m-d H:i:s'),
                        ':alcohol_value' => $alcoholValue,
                        ':overall_status' => $overallStatus,
                        ':notes' => $notes,
                        ':created_by' => $staff['id'],
                    ]);
                    $vehicleCheckId = (int) $pdo->lastInsertId();

                    $resultStmt = $pdo->prepare(
                        'INSERT INTO vehicle_check_results (vehicle_check_id, item_id, result, issue_note)
                         VALUES (:vehicle_check_id, :item_id, :result, :issue_note)'
                    );
                    foreach ($results as $r) {
                        $resultStmt->execute([
                            ':vehicle_check_id' => $vehicleCheckId,
                            ':item_id' => $r['item_id'],
                            ':result' => $r['result'],
                            ':issue_note' => $r['issue_note'],
                        ]);
                    }

                    record_vehicle_check_history(
                        $pdo,
                        $vehicleCheckId,
                        'create',
                        (int) $staff['id'],
                        'staff',
                        null,
                        build_vehicle_check_snapshot($pdo, $vehicleCheckId)
                    );

                    $attendanceStmt = $pdo->prepare(
                        "INSERT INTO attendance (employee_id, category, clock_in_at, clock_in_lat, clock_in_lng, status)
                         VALUES (:employee_id, :category, :clock_in_at, :lat, :lng, 'working')"
                    );
                    $attendanceStmt->execute([
                        ':employee_id' => $pending['employee_id'],
                        ':category' => $pending['category'],
                        ':clock_in_at' => $now->format('Y-m-d H:i:s'),
                        ':lat' => $pending['lat'],
                        ':lng' => $pending['lng'],
                    ]);
                    $attendanceId = (int) $pdo->lastInsertId();

                    $linkStmt = $pdo->prepare('UPDATE vehicle_checks SET attendance_id = :attendance_id WHERE id = :id');
                    $linkStmt->execute([':attendance_id' => $attendanceId, ':id' => $vehicleCheckId]);

                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                unset($_SESSION['pending_clock_in']);
                set_flash('success', '集荷前車両等チェックを記録し、出勤を記録しました。');
                header('Location: /staff/dashboard.php');
                exit;
            }
        }
    }
}

$csrfToken = csrf_token();
$formVehicleId = (string) ($_POST['vehicle_id'] ?? '');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集荷前車両等チェック | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        fieldset { border: 1px solid #ccc; border-radius: 6px; padding: 16px; max-width: 500px; margin-bottom: 16px; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; margin-bottom: 4px; font-weight: bold; }
        .form-row select, .form-row textarea, .form-row input[type="number"] { width: 100%; font-size: 1em; padding: 6px; box-sizing: border-box; }
        .check-category { font-weight: bold; margin: 12px 0 6px; color: #0b5ed7; }
        .check-item { border-bottom: 1px solid #eee; padding: 8px 0; }
        .check-item .label { margin-bottom: 6px; }
        .check-item .radios label { display: inline-block; font-weight: normal; margin-right: 16px; }
        .check-item .value-input { margin-top: 6px; max-width: 160px; }
        .check-item .issue-note { margin-top: 6px; display: none; }
        .check-item.show-issue-note .issue-note { display: block; }
        #submit-button { font-size: 1.1em; padding: 12px 32px; border-radius: 6px; border: none; color: #fff; background: #0b5ed7; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1>集荷前車両等チェック</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<p class="notice">集荷での出勤には、車両点検の記録が必要です。すべての項目を確認し、回答してください。</p>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if (empty($vehicles)): ?>
    <p class="notice">車両が登録されていません。管理者にお問い合わせください。</p>
<?php else: ?>
<form method="post" action="/staff/vehicle_check.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <fieldset>
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
    </fieldset>

    <?php $currentCategory = null; ?>
    <?php foreach ($items as $item): ?>
        <?php $itemId = (int) $item['id']; ?>
        <?php if ($item['category'] !== $currentCategory): ?>
            <?php $currentCategory = $item['category']; ?>
            <div class="check-category"><?= htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="check-item" data-item-id="<?= $itemId ?>">
            <div class="label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="radios">
                <label><input type="radio" name="result_<?= $itemId ?>" value="ok" required> 問題なし</label>
                <label><input type="radio" name="result_<?= $itemId ?>" value="issue"> 異常あり</label>
            </div>
            <?php if ((int) $item['requires_value'] === 1): ?>
                <div class="value-input">
                    <input type="number" name="value_<?= $itemId ?>" step="0.01" min="0" placeholder="測定値">
                </div>
            <?php endif; ?>
            <div class="issue-note">
                <textarea name="issue_note_<?= $itemId ?>" rows="2" placeholder="異常の内容を入力してください"></textarea>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="form-row" style="margin-top:16px;">
        <label for="notes">全体備考</label>
        <textarea id="notes" name="notes" rows="2"></textarea>
    </div>

    <button type="submit" id="submit-button">点検を記録して出勤する</button>
</form>
<?php endif; ?>

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
