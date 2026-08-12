<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

// 集荷は集荷・配送記録簿（collection_cycles、staff/collection_entry.php）で管理するため、
// 作業実績（work_stage_records）の対象からは外れた。洗濯・乾燥・畳みは2026-08-06に
// 「洗濯」1工程へ統合した（stage ENUM自体は互換のため dry/fold を残しているが、以後は使わない）。
$stageLabels = [
    'wash' => '洗濯',
];

// 退勤時に入力必須となるのも洗濯代行区分のみ（集荷ドライバーはこの工程を必ずしも行わないため）。
const CATEGORIES_REQUIRING_ALL_STAGES = ['洗濯代行'];

// 共用アカウントは「本人」という単一の状態を持たないため、employee_idではなくattendance_idで
// 対象を明示的に指定させる（ダッシュボードの一覧からリンクされるほか、未指定・不正なIDの場合は
// ここで選択画面を表示する）。休憩中のレコードは選択肢から除外する（退勤できないため）。
$isSharedAccount = (int) ($staff['is_shared_account'] ?? 0) === 1;
$openRecord = false;

if ($isSharedAccount) {
    $attendanceId = (int) ($_GET['attendance_id'] ?? $_POST['attendance_id'] ?? 0);
    if ($attendanceId > 0) {
        $recordStmt = $pdo->prepare(
            "SELECT a.id, a.employee_id, a.category, a.clock_in_at, a.break_start_at, a.break_end_at, a.total_break_minutes,
                    e.name AS employee_name
             FROM attendance a
             INNER JOIN employees e ON e.id = a.employee_id
             WHERE a.id = :id AND a.status = 'working' AND a.deleted_at IS NULL"
        );
        $recordStmt->execute([':id' => $attendanceId]);
        $record = $recordStmt->fetch();
        if ($record !== false && !($record['break_start_at'] !== null && $record['break_end_at'] === null)) {
            $openRecord = $record;
        }
    }

    if ($openRecord === false) {
        $pickableRecords = array_values(array_filter(
            find_open_attendance_today($pdo),
            static fn (array $r): bool => $r['break_start_at'] === null || $r['break_end_at'] !== null
        ));
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>退勤する人を選択 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .picker-list { list-style: none; padding: 0; margin: 16px 0; }
        .picker-list li { margin-bottom: 8px; }
        .picker-list a { display: block; padding: 14px 16px; border: 1px solid #ccc; border-radius: 6px; text-decoration: none; color: #222; font-size: 1.1em; }
        .picker-list a:hover, .picker-list a:focus-visible { border-color: #0b5ed7; }
    </style>
</head>
<body>
<header>
    <h1>退勤する人を選択</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>
<?php if (empty($pickableRecords)): ?>
    <p class="notice">退勤できる出勤記録がありません（休憩中の人は休憩から戻ってから退勤してください）。</p>
<?php else: ?>
    <p>退勤する人を選んでください。</p>
    <ul class="picker-list">
        <?php foreach ($pickableRecords as $rec): ?>
            <li><a href="/staff/clock_out.php?attendance_id=<?= (int) $rec['id'] ?>"><?= htmlspecialchars($rec['employee_name'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars(substr($rec['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?>〜出勤）</a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
</body>
</html>
        <?php
        exit;
    }
} else {
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

    $isOnBreak = $openRecord['break_start_at'] !== null && $openRecord['break_end_at'] === null;
    if ($isOnBreak) {
        // 休憩中は退勤できない（UI側でも非表示にしているが、直接POSTされた場合の保険）
        set_flash('error', '休憩中は退勤できません。休憩から戻ってから退勤してください。');
        header('Location: /staff/dashboard.php');
        exit;
    }
}

// work_stage_records.employee_id（「誰が記録したか」）と自動休憩補正ログのedited_byは、
// 共用アカウントでは共用アカウント自身ではなく実際に退勤する従業員を記録する。
$recorderId = $isSharedAccount ? (int) $openRecord['employee_id'] : (int) $staff['id'];

$defaultCategory = (string) ($openRecord['category'] ?? '');
$stagesRequired = in_array($defaultCategory, CATEGORIES_REQUIRING_ALL_STAGES, true);

$facilitiesStmt = $pdo->query("SELECT id, name FROM facilities WHERE is_active = 1 AND facility_type = '介護施設' ORDER BY name");
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$errorMessage = '';

/**
 * facility_idが未選択の行は「未入力」として単に無視する。参加者0人（誰も選択しない）は
 * 「実績なし」を明示的に記録するための有効な入力として扱う。
 * work_stage_recordsは洗濯代行の作業実績のみが対象のため、区分は常に「洗濯代行」で固定する。
 *
 * @param list<list<string>> $employeeIdGroups 行ごとの選択済み従業員IDリスト（同じ添字で対応）
 * @return list<array{stage:string, facility_id:int, employee_ids:list<int>, category:string}>
 */
function collect_stage_rows(string $stage, array $facilityIds, array $employeeIdGroups, array $validFacilityIds, array $validEmployeeIds): array
{
    $rows = [];
    foreach ($facilityIds as $index => $rawFacilityId) {
        $facilityId = (int) $rawFacilityId;

        if ($facilityId <= 0 || !in_array($facilityId, $validFacilityIds, true)) {
            continue;
        }

        $rawEmployeeIds = $employeeIdGroups[$index] ?? [];
        $employeeIds = [];
        foreach ((array) $rawEmployeeIds as $rawEmployeeId) {
            $employeeId = (int) $rawEmployeeId;
            if (in_array($employeeId, $validEmployeeIds, true)) {
                $employeeIds[] = $employeeId;
            }
        }
        $employeeIds = array_values(array_unique($employeeIds));

        $rows[] = ['stage' => $stage, 'facility_id' => $facilityId, 'employee_ids' => $employeeIds, 'category' => '洗濯代行'];
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
                $_POST[$stageKey . '_employee_ids'] ?? [],
                $validFacilityIds,
                $validEmployeeIds
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
                $errorMessage = implode('・', $missingLabels) . 'の施設を入力してください（実績が無い場合も施設のみ選択し、参加者は未選択のままで構いません）。';
            }
        }

        // 休憩開始・終了を一度も手動打刻していない日（total_break_minutesがNULL）のみ、
        // 法定基準に基づき自動で休憩時間をセットする。手動打刻済み（0分含む）はその実測値を優先し上書きしない。
        // 退勤時は区分を問わず、本人が実際に入力した休憩時間をそのまま保存する。
        // 法定休憩への不足補正は月次チェックで店舗勤務がある日に限って行う。
        $autoBreakApplied = false;
        $totalBreakMinutes = (int) ($openRecord['total_break_minutes'] ?? 0);
        $workMinutes = max(0, $rawMinutes - $totalBreakMinutes);

        if ($errorMessage === '') {
        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare(
                'INSERT INTO work_stage_records (employee_id, category, facility_id, stage, person_count, record_date, completed_at)
                 VALUES (:employee_id, :category, :facility_id, :stage, :person_count, :record_date, :completed_at)'
            );
            foreach ($rows as $row) {
                $insertStmt->execute([
                    ':employee_id' => $recorderId,
                    ':category' => $row['category'],
                    ':facility_id' => $row['facility_id'],
                    ':stage' => $row['stage'],
                    ':person_count' => count($row['employee_ids']),
                    ':record_date' => $today,
                    ':completed_at' => $clockOutAt->format('Y-m-d H:i:s'),
                ]);
                if (!empty($row['employee_ids'])) {
                    record_work_stage_employees($pdo, (int) $pdo->lastInsertId(), $row['employee_ids'], $clockOutAt);
                }
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
                    ':edited_by' => $recorderId,
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
            } elseif ($openRecord['category'] === '店舗' && $totalBreakMinutes < $requiredBreakMinutes) {
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
    <h1>退勤・本日の作業実績入力<?= $isSharedAccount ? '（' . htmlspecialchars($openRecord['employee_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?></h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($stagesRequired): ?>
    <p class="notice">区分「<?= htmlspecialchars($defaultCategory, ENT_QUOTES, 'UTF-8') ?>」での退勤のため、洗濯の施設入力が必須です。実績が無い場合は施設のみ選択し、参加した従業員は未選択のままで送信してください。送信すると退勤が確定します。</p>
<?php else: ?>
    <p class="notice">退勤する前に、本日の洗濯の実績を入力してください（実績がなければ未入力のままで構いません）。送信すると退勤が確定します。</p>
<?php endif; ?>

<?php if (empty($facilities)): ?>
    <p class="notice">有効な施設が登録されていません。管理者にお問い合わせください。</p>
<?php endif; ?>

<form id="clock-out-form" method="post" action="/staff/clock_out.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="lat" id="clock-lat" value="">
    <input type="hidden" name="lng" id="clock-lng" value="">
    <?php if ($isSharedAccount): ?>
        <input type="hidden" name="attendance_id" value="<?= (int) $openRecord['id'] ?>">
    <?php endif; ?>

    <?php foreach ($stageLabels as $stageKey => $stageLabel): ?>
        <section>
            <h2><?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8') ?><?= $stagesRequired ? '（必須）' : '' ?></h2>
            <table class="rows-table" id="rows-table-<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>">
                <thead>
                    <tr>
                        <th>施設</th>
                        <th>参加した従業員（複数選択可）</th>
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
                        <td>
                            <select class="employee-select" multiple size="<?= max(2, min(6, count($employees))) ?>">
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === $recorderId ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
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
var stageKeys = <?= json_encode(array_keys($stageLabels)) ?>;

// employee-select（複数選択）は行の追加・削除で位置がずれるため、送信直前にDOM上の並び順で
// stage_employee_ids[行番号][] という名前を振り直す（nameを常に固定にすると、PHP側で
// 同じ行のselectで選んだ複数値がバラバラの行として展開されてしまうため、行ごとに明示的な番号が必要）。
function renumberEmployeeSelects() {
    stageKeys.forEach(function (stage) {
        var rows = document.querySelectorAll('#rows-table-' + stage + ' tbody tr');
        rows.forEach(function (row, index) {
            var select = row.querySelector('.employee-select');
            if (select) {
                select.name = stage + '_employee_ids[' + index + '][]';
            }
        });
    });
}

function addRow(stage) {
    var tbody = document.querySelector('#rows-table-' + stage + ' tbody');
    var template = tbody.querySelector('tr');
    var clone = template.cloneNode(true);
    clone.querySelectorAll('select').forEach(function (el) {
        if (el.multiple) {
            Array.prototype.forEach.call(el.options, function (opt) { opt.selected = false; });
        } else {
            el.value = '';
        }
    });
    clone.querySelectorAll('input').forEach(function (el) { el.value = ''; });
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
    renumberEmployeeSelects();
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
