<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$stageLabels = [
    'wash' => '洗濯',
    'dry' => '乾燥',
    'fold' => '畳み',
];

// 集荷は集荷・配送記録簿（collection_cycles、staff/collection_entry.php）で管理するため、
// 作業実績（work_stage_records）の対象からは外れた。退勤時に全工程必須となるのも
// 洗濯代行区分のみ（集荷ドライバーはこの3工程を必ずしも行わないため）。
const CATEGORIES_REQUIRING_ALL_STAGES = ['洗濯代行'];

$openStmt = $pdo->prepare(
    "SELECT id, category, clock_in_at, break_start_at, break_end_at, total_break_minutes
     FROM attendance
     WHERE employee_id = :employee_id AND status = 'working' AND DATE(clock_in_at) = CURDATE()
       AND deleted_at IS NULL
     ORDER BY clock_in_at DESC
     LIMIT 1"
);
$openStmt->execute([':employee_id' => $staff['id']]);
$openRecord = $openStmt->fetch();

if ($openRecord === false) {
    // 出勤中のレコードがない場合はここで処理することがない
    header('Location: /staff/dashboard.php');
    exit;
}

$defaultCategory = (string) ($openRecord['category'] ?? '');
$stagesRequired = in_array($defaultCategory, CATEGORIES_REQUIRING_ALL_STAGES, true);

$isOnBreak = $openRecord['break_start_at'] !== null && $openRecord['break_end_at'] === null;
if ($isOnBreak) {
    // 休憩中は退勤できない（UI側でも非表示にしているが、直接POSTされた場合の保険）
    set_flash('error', '休憩中は退勤できません。休憩から戻ってから退勤してください。');
    header('Location: /staff/dashboard.php');
    exit;
}

$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities WHERE is_active = 1 ORDER BY name');
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));

$errorMessage = '';

/**
 * 人数は0も有効な入力として扱う（「実績なし」を明示的に記録するため）。
 * facility_idが未選択、またはperson_count欄が空欄のままの行は「未入力」として単に無視する。
 * work_stage_recordsは洗濯・乾燥・畳みのみが対象のため、区分は常に「洗濯代行」で固定する。
 *
 * @return list<array{stage:string, facility_id:int, person_count:int, category:string}>
 */
function collect_stage_rows(string $stage, array $facilityIds, array $personCounts, array $validFacilityIds): array
{
    $rows = [];
    foreach ($facilityIds as $index => $rawFacilityId) {
        $facilityId = (int) $rawFacilityId;
        $rawPersonCount = trim((string) ($personCounts[$index] ?? ''));

        if ($facilityId <= 0 || $rawPersonCount === '') {
            continue;
        }
        if (!in_array($facilityId, $validFacilityIds, true)) {
            continue;
        }
        $personCount = (int) $rawPersonCount;
        if ($personCount < 0) {
            continue;
        }

        $rows[] = ['stage' => $stage, 'facility_id' => $facilityId, 'person_count' => $personCount, 'category' => '洗濯代行'];
    }

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $lat = (isset($_POST['lat']) && $_POST['lat'] !== '') ? (float) $_POST['lat'] : null;
        $lng = (isset($_POST['lng']) && $_POST['lng'] !== '') ? (float) $_POST['lng'] : null;

        $rows = [];
        foreach (array_keys($stageLabels) as $stageKey) {
            $stageRows = collect_stage_rows(
                $stageKey,
                $_POST[$stageKey . '_facility_id'] ?? [],
                $_POST[$stageKey . '_person_count'] ?? [],
                $validFacilityIds
            );
            $rows = array_merge($rows, $stageRows);
        }

        $today = (new DateTime())->format('Y-m-d');
        $clockOutAt = new DateTime();
        $clockInAt = new DateTime($openRecord['clock_in_at']);
        $rawMinutes = max(0, (int) round(($clockOutAt->getTimestamp() - $clockInAt->getTimestamp()) / 60));
        $requiredBreakMinutes = calc_legal_break_minutes($rawMinutes);

        if ($stagesRequired) {
            $presentStages = array_unique(array_column($rows, 'stage'));
            $missingStages = array_diff(array_keys($stageLabels), $presentStages);
            if (!empty($missingStages)) {
                $missingLabels = array_map(static fn (string $s): string => $stageLabels[$s], $missingStages);
                $errorMessage = implode('・', $missingLabels) . 'の人数を入力してください（実績が無い場合も0を入力してください）。';
            }
        }

        // 休憩開始・終了を一度も手動打刻していない日（total_break_minutesがNULL）のみ、
        // 法定基準に基づき自動で休憩時間をセットする。手動打刻済み（0分含む）はその実測値を優先し上書きしない。
        $hadManualBreak = $openRecord['total_break_minutes'] !== null;
        $autoBreakApplied = !$hadManualBreak && $requiredBreakMinutes > 0;
        $totalBreakMinutes = $autoBreakApplied ? $requiredBreakMinutes : (int) ($openRecord['total_break_minutes'] ?? 0);
        $workMinutes = max(0, $rawMinutes - $totalBreakMinutes);

        if ($errorMessage === '') {
        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare(
                'INSERT INTO work_stage_records (employee_id, category, facility_id, stage, person_count, record_date)
                 VALUES (:employee_id, :category, :facility_id, :stage, :person_count, :record_date)'
            );
            foreach ($rows as $row) {
                $insertStmt->execute([
                    ':employee_id' => $staff['id'],
                    ':category' => $row['category'],
                    ':facility_id' => $row['facility_id'],
                    ':stage' => $row['stage'],
                    ':person_count' => $row['person_count'],
                    ':record_date' => $today,
                ]);
            }

            $updateStmt = $pdo->prepare(
                "UPDATE attendance
                 SET clock_out_at = :clock_out_at, clock_out_lat = :lat, clock_out_lng = :lng,
                     total_break_minutes = :total_break_minutes, work_minutes = :work_minutes, status = 'done'
                 WHERE id = :id"
            );
            $updateStmt->execute([
                ':clock_out_at' => $clockOutAt->format('Y-m-d H:i:s'),
                ':lat' => $lat,
                ':lng' => $lng,
                ':total_break_minutes' => $totalBreakMinutes,
                ':work_minutes' => $workMinutes,
                ':id' => $openRecord['id'],
            ]);

            if ($autoBreakApplied) {
                $logStmt = $pdo->prepare(
                    'INSERT INTO attendance_edit_logs (attendance_id, edited_by, action, field_name, old_value, new_value)
                     VALUES (:attendance_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                );
                $logStmt->execute([
                    ':attendance_id' => $openRecord['id'],
                    ':edited_by' => $staff['id'],
                    ':action' => 'auto_break',
                    ':field_name' => 'total_break_minutes',
                    ':old_value' => $openRecord['total_break_minutes'],
                    ':new_value' => $totalBreakMinutes,
                ]);
            }

            $pdo->commit();

            $message = '退勤を記録しました（作業実績 ' . count($rows) . '件）。';
            if ($autoBreakApplied) {
                $message .= ' 休憩の打刻がなかったため、労働基準法に基づき休憩' . $totalBreakMinutes . '分を自動で設定しました。';
            } elseif ($totalBreakMinutes < $requiredBreakMinutes) {
                $message .= ' ⚠ 本日の休憩は' . $totalBreakMinutes . '分でした。労働基準法上、'
                    . format_minutes_as_hours($rawMinutes) . 'の勤務には' . $requiredBreakMinutes . '分以上の休憩が必要です。';
            }
            set_flash('success', $message);
            header('Location: /staff/dashboard.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = '保存に失敗しました。入力内容をご確認のうえ、もう一度お試しください。';
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
    <title>退勤・作業実績入力 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; border: 1px solid #ccc; border-radius: 6px; padding: 12px; }
        table.rows-table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
        table.rows-table th, table.rows-table td { border: 1px solid #ccc; padding: 6px; }
        table.rows-table select { width: 100%; }
        table.rows-table input[type="number"] { width: 80px; }
        #submit-button { font-size: 1.1em; padding: 12px 32px; border-radius: 6px; border: none; color: #fff; background: #b3261e; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1>退勤・本日の作業実績入力</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($stagesRequired): ?>
    <p class="notice">区分「<?= htmlspecialchars($defaultCategory, ENT_QUOTES, 'UTF-8') ?>」での退勤のため、洗濯・乾燥・畳みの3項目すべてに人数の入力が必須です。実績が無い項目には「0」を入力してください。送信すると退勤が確定します。</p>
<?php else: ?>
    <p class="notice">退勤する前に、本日の洗濯・乾燥・畳みの実績を入力してください（実績がない工程は未入力のままで構いません）。送信すると退勤が確定します。</p>
<?php endif; ?>

<?php if (empty($facilities)): ?>
    <p class="notice">有効な施設が登録されていません。管理者にお問い合わせください。</p>
<?php endif; ?>

<form id="clock-out-form" method="post" action="/staff/clock_out.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="lat" id="clock-lat" value="">
    <input type="hidden" name="lng" id="clock-lng" value="">

    <?php foreach ($stageLabels as $stageKey => $stageLabel): ?>
        <section>
            <h2><?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8') ?><?= $stagesRequired ? '（必須）' : '' ?></h2>
            <table class="rows-table" id="rows-table-<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>">
                <thead>
                    <tr>
                        <th>施設</th>
                        <th>人数</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <select name="<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>_facility_id[]">
                                <option value="">選択してください</option>
                                <?php foreach ($facilities as $facility): ?>
                                    <option value="<?= (int) $facility['id'] ?>"><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" name="<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>_person_count[]" min="0" step="1"></td>
                        <td><button type="button" onclick="removeRow(this, '<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>')">削除</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" onclick="addRow('<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>')">行を追加</button>
        </section>
    <?php endforeach; ?>

    <button type="submit" id="submit-button">退勤する</button>
</form>

<script>
function addRow(stage) {
    var tbody = document.querySelector('#rows-table-' + stage + ' tbody');
    var template = tbody.querySelector('tr');
    var clone = template.cloneNode(true);
    clone.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
    tbody.appendChild(clone);
}

function removeRow(button, stage) {
    var tbody = document.querySelector('#rows-table-' + stage + ' tbody');
    if (tbody.querySelectorAll('tr').length > 1) {
        button.closest('tr').remove();
    }
}

document.getElementById('clock-out-form').addEventListener('submit', function (e) {
    var form = this;
    var button = document.getElementById('submit-button');

    if (button.dataset.located === '1') {
        return;
    }

    e.preventDefault();
    button.disabled = true;
    button.textContent = '処理中...';

    function submitForm() {
        button.dataset.located = '1';
        form.submit();
    }

    if (!navigator.geolocation) {
        submitForm();
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('clock-lat').value = position.coords.latitude;
            document.getElementById('clock-lng').value = position.coords.longitude;
            submitForm();
        },
        function () {
            submitForm();
        },
        { timeout: 5000 }
    );
});
</script>
</body>
</html>
