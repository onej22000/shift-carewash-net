<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

const VM_EDITABLE_FIELDS = [
    'vehicle_id', 'shaken_date', 'shaken_expiry',
    'jibaiseki_company', 'jibaiseki_start', 'jibaiseki_end',
    'ninni_company', 'ninni_start', 'ninni_end',
    'oil_change_date', 'battery_change_date', 'battery_type',
    'tire_change_date_front_right', 'tire_change_date_front_left',
    'tire_change_date_rear_left', 'tire_change_date_rear_right',
    'notes',
];

function vm_parse_date($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);

    return $dt !== false ? $dt->format('Y-m-d') : false;
}

function parse_vehicle_maintenance_input(array $post, array $validVehicleIds): array
{
    $vehicleId = (int) ($post['vehicle_id'] ?? 0);
    $shakenDate = vm_parse_date($post['shaken_date'] ?? '');
    $shakenExpiry = vm_parse_date($post['shaken_expiry'] ?? '');
    $jibaisekiStart = vm_parse_date($post['jibaiseki_start'] ?? '');
    $jibaisekiEnd = vm_parse_date($post['jibaiseki_end'] ?? '');
    $ninniStart = vm_parse_date($post['ninni_start'] ?? '');
    $ninniEnd = vm_parse_date($post['ninni_end'] ?? '');
    $oilChangeDate = vm_parse_date($post['oil_change_date'] ?? '');
    $batteryChangeDate = vm_parse_date($post['battery_change_date'] ?? '');
    $batteryTypeRaw = trim((string) ($post['battery_type'] ?? ''));
    $batteryType = $batteryTypeRaw === '' ? null : $batteryTypeRaw;
    $tireChangeDateFrontRight = vm_parse_date($post['tire_change_date_front_right'] ?? '');
    $tireChangeDateFrontLeft = vm_parse_date($post['tire_change_date_front_left'] ?? '');
    $tireChangeDateRearLeft = vm_parse_date($post['tire_change_date_rear_left'] ?? '');
    $tireChangeDateRearRight = vm_parse_date($post['tire_change_date_rear_right'] ?? '');

    $jibaisekiCompanyRaw = trim((string) ($post['jibaiseki_company'] ?? ''));
    $jibaisekiCompany = $jibaisekiCompanyRaw === '' ? null : $jibaisekiCompanyRaw;
    $ninniCompanyRaw = trim((string) ($post['ninni_company'] ?? ''));
    $ninniCompany = $ninniCompanyRaw === '' ? null : $ninniCompanyRaw;
    $notesRaw = trim((string) ($post['notes'] ?? ''));
    $notes = $notesRaw === '' ? null : $notesRaw;

    $errors = [];
    if (!in_array($vehicleId, $validVehicleIds, true)) {
        $errors[] = '車両を選択してください。';
    }
    foreach ([
        '車検日' => $shakenDate, '次回車検期限' => $shakenExpiry,
        '自賠責保険契約開始日' => $jibaisekiStart, '自賠責保険契約終了日' => $jibaisekiEnd,
        '任意保険契約開始日' => $ninniStart, '任意保険契約終了日' => $ninniEnd,
        'オイル交換日' => $oilChangeDate,
        'バッテリー交換日' => $batteryChangeDate,
        'タイヤ交換日（前輪右）' => $tireChangeDateFrontRight, 'タイヤ交換日（前輪左）' => $tireChangeDateFrontLeft,
        'タイヤ交換日（後輪左）' => $tireChangeDateRearLeft, 'タイヤ交換日（後輪右）' => $tireChangeDateRearRight,
    ] as $label => $value) {
        if ($value === false) {
            $errors[] = $label . 'の形式が正しくありません。';
        }
    }

    return [
        [
            'vehicle_id' => $vehicleId,
            'shaken_date' => $shakenDate === false ? null : $shakenDate,
            'shaken_expiry' => $shakenExpiry === false ? null : $shakenExpiry,
            'jibaiseki_company' => $jibaisekiCompany,
            'jibaiseki_start' => $jibaisekiStart === false ? null : $jibaisekiStart,
            'jibaiseki_end' => $jibaisekiEnd === false ? null : $jibaisekiEnd,
            'ninni_company' => $ninniCompany,
            'ninni_start' => $ninniStart === false ? null : $ninniStart,
            'ninni_end' => $ninniEnd === false ? null : $ninniEnd,
            'oil_change_date' => $oilChangeDate === false ? null : $oilChangeDate,
            'battery_change_date' => $batteryChangeDate === false ? null : $batteryChangeDate,
            'battery_type' => $batteryType,
            'tire_change_date_front_right' => $tireChangeDateFrontRight === false ? null : $tireChangeDateFrontRight,
            'tire_change_date_front_left' => $tireChangeDateFrontLeft === false ? null : $tireChangeDateFrontLeft,
            'tire_change_date_rear_left' => $tireChangeDateRearLeft === false ? null : $tireChangeDateRearLeft,
            'tire_change_date_rear_right' => $tireChangeDateRearRight === false ? null : $tireChangeDateRearRight,
            'notes' => $notes,
        ],
        $errors,
    ];
}

function vm_format_tire_cell(array $record): string
{
    $parts = [];
    foreach ([
        '前輪右' => $record['tire_change_date_front_right'],
        '前輪左' => $record['tire_change_date_front_left'],
        '後輪左' => $record['tire_change_date_rear_left'],
        '後輪右' => $record['tire_change_date_rear_right'],
    ] as $label => $date) {
        if ($date !== null) {
            $parts[] = $label . ': ' . $date;
        }
    }

    return empty($parts) ? '-' : implode("\n", $parts);
}

$vehiclesStmt = $pdo->query('SELECT id, plate_number, vehicle_name FROM vehicles WHERE is_active = 1 ORDER BY plate_number');
$vehicles = $vehiclesStmt->fetchAll();
$validVehicleIds = array_map('intval', array_column($vehicles, 'id'));
$vehicleLabelsById = [];
foreach ($vehicles as $vehicle) {
    $vehicleLabelsById[(int) $vehicle['id']] = $vehicle['plate_number'] . ($vehicle['vehicle_name'] !== null ? '（' . $vehicle['vehicle_name'] . '）' : '');
}

$filterVehicleId = (int) ($_GET['vehicle_id'] ?? 0);

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $recordStmt = $pdo->prepare('SELECT id FROM vehicle_maintenance WHERE id = :id AND deleted_at IS NULL');
            $recordStmt->execute([':id' => $recordId]);

            if ($recordStmt->fetchColumn() === false) {
                $errorMessage = '対象の記録が見つかりません。';
            } else {
                $before = build_vehicle_maintenance_snapshot($pdo, $recordId);
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE vehicle_maintenance SET deleted_at = :deleted_at WHERE id = :id')
                        ->execute([':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $recordId]);
                    record_vehicle_maintenance_history($pdo, $recordId, 'delete', (int) $staff['id'], 'staff', $before, null);
                    $pdo->commit();
                    set_flash('success', '車両管理記録を削除しました。');
                    header('Location: /staff/vehicle_maintenance_list.php?vehicle_id=' . $filterVehicleId);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'create' || $action === 'update') {
            [$values, $parseErrors] = parse_vehicle_maintenance_input($_POST, $validVehicleIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } elseif ($action === 'create') {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO vehicle_maintenance
                        (vehicle_id, shaken_date, shaken_expiry, jibaiseki_company, jibaiseki_start, jibaiseki_end,
                         ninni_company, ninni_start, ninni_end, oil_change_date, battery_change_date, battery_type,
                         tire_change_date_front_right, tire_change_date_front_left,
                         tire_change_date_rear_left, tire_change_date_rear_right, notes, created_by)
                     VALUES
                        (:vehicle_id, :shaken_date, :shaken_expiry, :jibaiseki_company, :jibaiseki_start, :jibaiseki_end,
                         :ninni_company, :ninni_start, :ninni_end, :oil_change_date, :battery_change_date, :battery_type,
                         :tire_change_date_front_right, :tire_change_date_front_left,
                         :tire_change_date_rear_left, :tire_change_date_rear_right, :notes, :created_by)'
                );
                $insertStmt->execute([
                    ':vehicle_id' => $values['vehicle_id'],
                    ':shaken_date' => $values['shaken_date'],
                    ':shaken_expiry' => $values['shaken_expiry'],
                    ':jibaiseki_company' => $values['jibaiseki_company'],
                    ':jibaiseki_start' => $values['jibaiseki_start'],
                    ':jibaiseki_end' => $values['jibaiseki_end'],
                    ':ninni_company' => $values['ninni_company'],
                    ':ninni_start' => $values['ninni_start'],
                    ':ninni_end' => $values['ninni_end'],
                    ':oil_change_date' => $values['oil_change_date'],
                    ':battery_change_date' => $values['battery_change_date'],
                    ':battery_type' => $values['battery_type'],
                    ':tire_change_date_front_right' => $values['tire_change_date_front_right'],
                    ':tire_change_date_front_left' => $values['tire_change_date_front_left'],
                    ':tire_change_date_rear_left' => $values['tire_change_date_rear_left'],
                    ':tire_change_date_rear_right' => $values['tire_change_date_rear_right'],
                    ':notes' => $values['notes'],
                    ':created_by' => $staff['id'],
                ]);
                $recordId = (int) $pdo->lastInsertId();
                record_vehicle_maintenance_history($pdo, $recordId, 'create', (int) $staff['id'], 'staff', null, build_vehicle_maintenance_snapshot($pdo, $recordId));

                set_flash('success', '車両管理記録を登録しました。');
                header('Location: /staff/vehicle_maintenance_list.php?vehicle_id=' . $values['vehicle_id']);
                exit;
            } else {
                $recordId = (int) ($_POST['id'] ?? 0);
                $recordStmt = $pdo->prepare('SELECT id FROM vehicle_maintenance WHERE id = :id AND deleted_at IS NULL');
                $recordStmt->execute([':id' => $recordId]);

                if ($recordStmt->fetchColumn() === false) {
                    $errorMessage = '対象の記録が見つかりません。';
                } else {
                    $before = build_vehicle_maintenance_snapshot($pdo, $recordId);
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare(
                            'UPDATE vehicle_maintenance SET
                                vehicle_id = :vehicle_id, shaken_date = :shaken_date, shaken_expiry = :shaken_expiry,
                                jibaiseki_company = :jibaiseki_company, jibaiseki_start = :jibaiseki_start, jibaiseki_end = :jibaiseki_end,
                                ninni_company = :ninni_company, ninni_start = :ninni_start, ninni_end = :ninni_end,
                                oil_change_date = :oil_change_date, battery_change_date = :battery_change_date,
                                battery_type = :battery_type,
                                tire_change_date_front_right = :tire_change_date_front_right,
                                tire_change_date_front_left = :tire_change_date_front_left,
                                tire_change_date_rear_left = :tire_change_date_rear_left,
                                tire_change_date_rear_right = :tire_change_date_rear_right,
                                notes = :notes, updated_by = :updated_by
                             WHERE id = :id'
                        )->execute([
                            ':vehicle_id' => $values['vehicle_id'],
                            ':shaken_date' => $values['shaken_date'],
                            ':shaken_expiry' => $values['shaken_expiry'],
                            ':jibaiseki_company' => $values['jibaiseki_company'],
                            ':jibaiseki_start' => $values['jibaiseki_start'],
                            ':jibaiseki_end' => $values['jibaiseki_end'],
                            ':ninni_company' => $values['ninni_company'],
                            ':ninni_start' => $values['ninni_start'],
                            ':ninni_end' => $values['ninni_end'],
                            ':oil_change_date' => $values['oil_change_date'],
                            ':battery_change_date' => $values['battery_change_date'],
                            ':battery_type' => $values['battery_type'],
                            ':tire_change_date_front_right' => $values['tire_change_date_front_right'],
                            ':tire_change_date_front_left' => $values['tire_change_date_front_left'],
                            ':tire_change_date_rear_left' => $values['tire_change_date_rear_left'],
                            ':tire_change_date_rear_right' => $values['tire_change_date_rear_right'],
                            ':notes' => $values['notes'],
                            ':updated_by' => $staff['id'],
                            ':id' => $recordId,
                        ]);
                        record_vehicle_maintenance_history($pdo, $recordId, 'update', (int) $staff['id'], 'staff', $before, build_vehicle_maintenance_snapshot($pdo, $recordId));
                        $pdo->commit();
                        set_flash('success', '車両管理記録を修正しました。');
                        header('Location: /staff/vehicle_maintenance_list.php?vehicle_id=' . $values['vehicle_id']);
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

$editingRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM vehicle_maintenance WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingRecord = $row;
    }
}

$formAction = 'create';
$formId = null;
$formValues = array_fill_keys(VM_EDITABLE_FIELDS, '');
if ($filterVehicleId > 0) {
    $formValues['vehicle_id'] = (string) $filterVehicleId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    foreach (VM_EDITABLE_FIELDS as $field) {
        $formValues[$field] = (string) ($_POST[$field] ?? '');
    }
} elseif ($editingRecord !== null) {
    $formAction = 'update';
    $formId = (int) $editingRecord['id'];
    foreach (VM_EDITABLE_FIELDS as $field) {
        $formValues[$field] = (string) ($editingRecord[$field] ?? '');
    }
}

$records = [];
if ($filterVehicleId > 0) {
    $listStmt = $pdo->prepare(
        'SELECT * FROM vehicle_maintenance WHERE vehicle_id = :vehicle_id AND deleted_at IS NULL ORDER BY id DESC'
    );
    $listStmt->execute([':vehicle_id' => $filterVehicleId]);
    $records = $listStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>車両管理記録 | シフト管理</title>
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
        .filter-row { margin-bottom: 12px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 16px; }
        .form-row label { display: block; font-size: 0.85em; margin-bottom: 2px; }
        .form-row input, .form-row select { width: 100%; box-sizing: border-box; }
        table.records { border-collapse: collapse; width: 100%; font-size: 0.85em; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        table.records td.tire-cell { white-space: nowrap; }
        table.records th { background: #f5f5f5; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>車両管理記録</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="get" action="/staff/vehicle_maintenance_list.php" class="filter-row">
    <label for="vehicle_id_filter">車両:</label>
    <select id="vehicle_id_filter" name="vehicle_id" onchange="this.form.submit()">
        <option value="">選択してください</option>
        <?php foreach ($vehicles as $vehicle): ?>
            <option value="<?= (int) $vehicle['id'] ?>" <?= (int) $vehicle['id'] === $filterVehicleId ? 'selected' : '' ?>>
                <?= htmlspecialchars($vehicleLabelsById[(int) $vehicle['id']], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<section class="maintenance-form">
    <h2><?= $formAction === 'update' ? '車両管理記録の修正' : '車両管理記録の新規追加' ?></h2>
    <?php if (empty($vehicles)): ?>
        <p class="notice">車両が登録されていません。管理者にお問い合わせください。</p>
    <?php else: ?>
    <fieldset>
        <form method="post" action="/staff/vehicle_maintenance_list.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-row">
                    <label for="m_vehicle_id">車両</label>
                    <select id="m_vehicle_id" name="vehicle_id" required>
                        <option value="">選択してください</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= (int) $vehicle['id'] ?>" <?= (string) $vehicle['id'] === $formValues['vehicle_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicleLabelsById[(int) $vehicle['id']], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="m_shaken_date">車検日</label>
                    <input type="date" id="m_shaken_date" name="shaken_date" value="<?= htmlspecialchars($formValues['shaken_date'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_shaken_expiry">次回車検期限</label>
                    <input type="date" id="m_shaken_expiry" name="shaken_expiry" value="<?= htmlspecialchars($formValues['shaken_expiry'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_jibaiseki_company">自賠責保険会社</label>
                    <input type="text" id="m_jibaiseki_company" name="jibaiseki_company" maxlength="100" value="<?= htmlspecialchars($formValues['jibaiseki_company'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_jibaiseki_start">自賠責保険契約開始日</label>
                    <input type="date" id="m_jibaiseki_start" name="jibaiseki_start" value="<?= htmlspecialchars($formValues['jibaiseki_start'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_jibaiseki_end">自賠責保険契約終了日</label>
                    <input type="date" id="m_jibaiseki_end" name="jibaiseki_end" value="<?= htmlspecialchars($formValues['jibaiseki_end'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_ninni_company">任意保険会社</label>
                    <input type="text" id="m_ninni_company" name="ninni_company" maxlength="100" value="<?= htmlspecialchars($formValues['ninni_company'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_ninni_start">任意保険契約開始日</label>
                    <input type="date" id="m_ninni_start" name="ninni_start" value="<?= htmlspecialchars($formValues['ninni_start'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_ninni_end">任意保険契約終了日</label>
                    <input type="date" id="m_ninni_end" name="ninni_end" value="<?= htmlspecialchars($formValues['ninni_end'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_oil_change_date">オイル交換日</label>
                    <input type="date" id="m_oil_change_date" name="oil_change_date" value="<?= htmlspecialchars($formValues['oil_change_date'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_battery_change_date">バッテリー交換日</label>
                    <input type="date" id="m_battery_change_date" name="battery_change_date" value="<?= htmlspecialchars($formValues['battery_change_date'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_battery_type">バッテリー種類</label>
                    <input type="text" id="m_battery_type" name="battery_type" maxlength="20" placeholder="例: 750D23L" value="<?= htmlspecialchars($formValues['battery_type'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_tire_change_date_front_right">タイヤ交換日（前輪右）</label>
                    <input type="date" id="m_tire_change_date_front_right" name="tire_change_date_front_right" value="<?= htmlspecialchars($formValues['tire_change_date_front_right'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_tire_change_date_front_left">タイヤ交換日（前輪左）</label>
                    <input type="date" id="m_tire_change_date_front_left" name="tire_change_date_front_left" value="<?= htmlspecialchars($formValues['tire_change_date_front_left'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_tire_change_date_rear_left">タイヤ交換日（後輪左）</label>
                    <input type="date" id="m_tire_change_date_rear_left" name="tire_change_date_rear_left" value="<?= htmlspecialchars($formValues['tire_change_date_rear_left'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="m_tire_change_date_rear_right">タイヤ交換日（後輪右）</label>
                    <input type="date" id="m_tire_change_date_rear_right" name="tire_change_date_rear_right" value="<?= htmlspecialchars($formValues['tire_change_date_rear_right'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row" style="grid-column: 1 / -1;">
                    <label for="m_notes">備考</label>
                    <input type="text" id="m_notes" name="notes" maxlength="255" value="<?= htmlspecialchars($formValues['notes'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <p style="margin-top:10px;">
                <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
                <?php if ($formAction === 'update'): ?>
                    <a href="/staff/vehicle_maintenance_list.php?vehicle_id=<?= $filterVehicleId ?>">キャンセル</a>
                <?php endif; ?>
            </p>
        </form>
    </fieldset>
    <?php endif; ?>
</section>

<section class="record-list">
    <h2>車両管理記録一覧</h2>
    <?php if ($filterVehicleId <= 0): ?>
        <p class="notice">車両を選択してください。</p>
    <?php elseif (empty($records)): ?>
        <p class="notice">対象車両の記録はありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>車検日</th>
                    <th>次回車検期限</th>
                    <th>自賠責保険会社</th>
                    <th>自賠責保険期間</th>
                    <th>任意保険会社</th>
                    <th>任意保険期間</th>
                    <th>オイル交換日</th>
                    <th>バッテリー交換日</th>
                    <th>バッテリー種類</th>
                    <th>タイヤ交換日（前右／前左／後左／後右）</th>
                    <th>備考</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['shaken_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['shaken_expiry'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['jibaiseki_company'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(($record['jibaiseki_start'] ?? '-') . ' 〜 ' . ($record['jibaiseki_end'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['ninni_company'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(($record['ninni_start'] ?? '-') . ' 〜 ' . ($record['ninni_end'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['oil_change_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['battery_change_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['battery_type'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="tire-cell"><?= nl2br(htmlspecialchars(vm_format_tire_cell($record), ENT_QUOTES, 'UTF-8')) ?></td>
                        <td><?= htmlspecialchars($record['notes'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="/staff/vehicle_maintenance_list.php?vehicle_id=<?= $filterVehicleId ?>&edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="/staff/vehicle_maintenance_list.php" class="inline-form" onsubmit="return confirm('この記録を削除しますか？');">
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
