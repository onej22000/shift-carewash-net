<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

const CC_EDITABLE_FIELDS = [
    'facility_id', 'pickup_date', 'pickup_bag_count', 'pickup_time', 'pickup_employee_id',
    'issued_bag_orange', 'issued_bag_yellow', 'issued_bag_blue', 'issued_laundry_net_count',
    'arrival_bag_count', 'arrival_date', 'arrival_time', 'arrival_employee_id', 'arrival_facility_id',
    'dispatch_bag_count', 'dispatch_date', 'dispatch_time', 'dispatch_employee_id', 'dispatch_facility_id',
    'return_bag_count', 'return_date', 'return_time', 'return_employee_id', 'remarks',
];

function cc_parse_bag_count($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    return ctype_digit($raw) ? (int) $raw : false;
}

function cc_parse_date($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    return $dt !== false ? $dt->format('Y-m-d') : false;
}

function cc_parse_time($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt !== false ? $dt->format('H:i:s') : false;
}

function cc_parse_employee_id($raw, array $validEmployeeIds)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $id = (int) $raw;
    return in_array($id, $validEmployeeIds, true) ? $id : false;
}

function cc_parse_facility_id($raw, array $validFacilityIds)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $id = (int) $raw;
    return in_array($id, $validFacilityIds, true) ? $id : false;
}

/**
 * 集荷・配送記録簿の入力をまとめてパースする。日付・時刻・担当者・袋数はいずれも任意項目
 * （工程順を強制しないため、どの組み合わせでも保存できるようにする）。facility_idとpickup_dateのみ必須。
 */
function parse_collection_cycle_input(array $post, array $validFacilityIds, array $validEmployeeIds, array $validCleaningFacilityIds): array
{
    $facilityId = (int) ($post['facility_id'] ?? 0);
    $pickupDate = cc_parse_date($post['pickup_date'] ?? '');
    $pickupBagCount = cc_parse_bag_count($post['pickup_bag_count'] ?? '');
    $pickupTime = cc_parse_time($post['pickup_time'] ?? '');
    $pickupEmployeeId = cc_parse_employee_id($post['pickup_employee_id'] ?? '', $validEmployeeIds);
    $issuedBagOrange = cc_parse_bag_count($post['issued_bag_orange'] ?? '');
    $issuedBagYellow = cc_parse_bag_count($post['issued_bag_yellow'] ?? '');
    $issuedBagBlue = cc_parse_bag_count($post['issued_bag_blue'] ?? '');
    $issuedLaundryNetCount = cc_parse_bag_count($post['issued_laundry_net_count'] ?? '');
    $arrivalBagCount = cc_parse_bag_count($post['arrival_bag_count'] ?? '');
    $arrivalDate = cc_parse_date($post['arrival_date'] ?? '');
    $arrivalTime = cc_parse_time($post['arrival_time'] ?? '');
    $arrivalEmployeeId = cc_parse_employee_id($post['arrival_employee_id'] ?? '', $validEmployeeIds);
    $arrivalFacilityId = cc_parse_facility_id($post['arrival_facility_id'] ?? '', $validCleaningFacilityIds);
    $dispatchBagCount = cc_parse_bag_count($post['dispatch_bag_count'] ?? '');
    $dispatchDate = cc_parse_date($post['dispatch_date'] ?? '');
    $dispatchTime = cc_parse_time($post['dispatch_time'] ?? '');
    $dispatchEmployeeId = cc_parse_employee_id($post['dispatch_employee_id'] ?? '', $validEmployeeIds);
    $dispatchFacilityId = cc_parse_facility_id($post['dispatch_facility_id'] ?? '', $validCleaningFacilityIds);
    $returnBagCount = cc_parse_bag_count($post['return_bag_count'] ?? '');
    $returnDate = cc_parse_date($post['return_date'] ?? '');
    $returnTime = cc_parse_time($post['return_time'] ?? '');
    $returnEmployeeId = cc_parse_employee_id($post['return_employee_id'] ?? '', $validEmployeeIds);
    $remarksRaw = trim((string) ($post['remarks'] ?? ''));
    $remarks = $remarksRaw === '' ? null : mb_substr($remarksRaw, 0, 255);

    $errors = [];
    if (!in_array($facilityId, $validFacilityIds, true)) {
        $errors[] = '施設を選択してください。';
    }
    if ($pickupDate === false || $pickupDate === null) {
        $errors[] = '集荷日の形式が正しくありません。';
    }
    if ($pickupBagCount === false) {
        $errors[] = '集荷リネン袋数は0以上の整数を入力してください。';
    }
    if ($pickupTime === false) {
        $errors[] = '集荷時間の形式が正しくありません。';
    }
    if ($pickupEmployeeId === false) {
        $errors[] = '集荷担当者が正しくありません。';
    }
    if ($issuedBagOrange === false) {
        $errors[] = 'リネン袋交付数（オレンジ）は0以上の整数を入力してください。';
    }
    if ($issuedBagYellow === false) {
        $errors[] = 'リネン袋交付数（黄）は0以上の整数を入力してください。';
    }
    if ($issuedBagBlue === false) {
        $errors[] = 'リネン袋交付数（青）は0以上の整数を入力してください。';
    }
    if ($issuedLaundryNetCount === false) {
        $errors[] = '洗濯ネット交付数は0以上の整数を入力してください。';
    }
    if ($arrivalBagCount === false) {
        $errors[] = '到着リネン袋数は0以上の整数を入力してください。';
    }
    if ($arrivalDate === false) {
        $errors[] = '到着日の形式が正しくありません。';
    }
    if ($arrivalTime === false) {
        $errors[] = '到着時間の形式が正しくありません。';
    }
    if ($arrivalEmployeeId === false) {
        $errors[] = '到着担当者が正しくありません。';
    }
    if ($arrivalFacilityId === false) {
        $errors[] = '到着クリーニング所が正しくありません。';
    }
    if ($dispatchBagCount === false) {
        $errors[] = '発送リネン袋数は0以上の整数を入力してください。';
    }
    if ($dispatchDate === false) {
        $errors[] = '発送日の形式が正しくありません。';
    }
    if ($dispatchTime === false) {
        $errors[] = '発送時間の形式が正しくありません。';
    }
    if ($dispatchEmployeeId === false) {
        $errors[] = '発送担当者が正しくありません。';
    }
    if ($dispatchFacilityId === false) {
        $errors[] = '発送元クリーニング所が正しくありません。';
    }
    if ($returnBagCount === false) {
        $errors[] = '返却リネン袋数は0以上の整数を入力してください。';
    }
    if ($returnDate === false) {
        $errors[] = '返却日の形式が正しくありません。';
    }
    if ($returnTime === false) {
        $errors[] = '返却時間の形式が正しくありません。';
    }
    if ($returnEmployeeId === false) {
        $errors[] = '返却担当者が正しくありません。';
    }

    return [
        [
            'facility_id' => $facilityId,
            'pickup_date' => $pickupDate,
            'pickup_bag_count' => $pickupBagCount,
            'pickup_time' => $pickupTime,
            'pickup_employee_id' => $pickupEmployeeId,
            'issued_bag_orange' => $issuedBagOrange,
            'issued_bag_yellow' => $issuedBagYellow,
            'issued_bag_blue' => $issuedBagBlue,
            'issued_laundry_net_count' => $issuedLaundryNetCount,
            'arrival_bag_count' => $arrivalBagCount,
            'arrival_date' => $arrivalDate,
            'arrival_time' => $arrivalTime,
            'arrival_employee_id' => $arrivalEmployeeId,
            'arrival_facility_id' => $arrivalFacilityId,
            'dispatch_bag_count' => $dispatchBagCount,
            'dispatch_date' => $dispatchDate,
            'dispatch_time' => $dispatchTime,
            'dispatch_employee_id' => $dispatchEmployeeId,
            'dispatch_facility_id' => $dispatchFacilityId,
            'return_bag_count' => $returnBagCount,
            'return_date' => $returnDate,
            'return_time' => $returnTime,
            'return_employee_id' => $returnEmployeeId,
            'remarks' => $remarks,
        ],
        $errors,
    ];
}

$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name');
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));
$facilityNamesById = array_column($facilities, 'name', 'id');

$cleaningFacilitiesStmt = $pdo->query("SELECT id, name FROM facilities WHERE facility_type = 'クリーニング所' ORDER BY name");
$cleaningFacilities = $cleaningFacilitiesStmt->fetchAll();
$validCleaningFacilityIds = array_map('intval', array_column($cleaningFacilities, 'id'));

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' AND is_shared_account = 0 ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$facilityId = (int) ($_GET['facility_id'] ?? 0);
if ($facilityId > 0 && !in_array($facilityId, $validFacilityIds, true)) {
    $facilityId = 0;
}

$yearMonth = (string) ($_GET['month'] ?? (new DateTime())->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
    $yearMonth = (new DateTime())->format('Y-m');
}
[$rangeStartStr, $rangeEndStr] = get_month_range($yearMonth);
$monthStart = DateTime::createFromFormat('Y-m-d', $rangeStartStr);
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');
$backToListUrl = '/admin/collection_records.php?facility_id=' . $facilityId . '&month=' . urlencode($yearMonth);

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $cycleId = (int) ($_POST['id'] ?? 0);
            $cycleStmt = $pdo->prepare('SELECT * FROM collection_cycles WHERE id = :id AND deleted_at IS NULL');
            $cycleStmt->execute([':id' => $cycleId]);
            $cycle = $cycleStmt->fetch();

            if ($cycle === false) {
                $errorMessage = '対象のサイクルが見つかりません。';
            } else {
                try {
                    $pdo->beginTransaction();

                    $logStmt = $pdo->prepare(
                        'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:cycle_id, :edited_by, :action, :field_name, :old_value, NULL)'
                    );
                    foreach (CC_EDITABLE_FIELDS as $field) {
                        if ($cycle[$field] === null) {
                            continue;
                        }
                        $logStmt->execute([
                            ':cycle_id' => $cycleId,
                            ':edited_by' => $admin['id'],
                            ':action' => 'delete',
                            ':field_name' => $field,
                            ':old_value' => $cycle[$field],
                        ]);
                    }

                    $deleteStmt = $pdo->prepare('UPDATE collection_cycles SET deleted_at = :deleted_at WHERE id = :id');
                    $deleteStmt->execute([':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $cycleId]);

                    reverse_collection_cycle_facility_issuance($pdo, $cycleId);
                    cancel_collection_cycle_issuance_stock_transactions($pdo, $cycleId, $admin['id']);

                    $pdo->commit();
                    set_flash('success', '集荷記録を削除しました。');
                    header('Location: ' . $backToListUrl);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'create' || $action === 'update') {
            [$values, $parseErrors] = parse_collection_cycle_input($_POST, $validFacilityIds, $validEmployeeIds, $validCleaningFacilityIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } elseif ($action === 'create') {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO collection_cycles
                        (facility_id, pickup_date, pickup_bag_count, pickup_time, pickup_employee_id,
                         issued_bag_orange, issued_bag_yellow, issued_bag_blue, issued_laundry_net_count,
                         arrival_bag_count, arrival_date, arrival_time, arrival_employee_id, arrival_facility_id,
                         dispatch_bag_count, dispatch_date, dispatch_time, dispatch_employee_id, dispatch_facility_id,
                         return_bag_count, return_date, return_time, return_employee_id, remarks)
                     VALUES
                        (:facility_id, :pickup_date, :pickup_bag_count, :pickup_time, :pickup_employee_id,
                         :issued_bag_orange, :issued_bag_yellow, :issued_bag_blue, :issued_laundry_net_count,
                         :arrival_bag_count, :arrival_date, :arrival_time, :arrival_employee_id, :arrival_facility_id,
                         :dispatch_bag_count, :dispatch_date, :dispatch_time, :dispatch_employee_id, :dispatch_facility_id,
                         :return_bag_count, :return_date, :return_time, :return_employee_id, :remarks)'
                );
                $insertStmt->execute([
                    ':facility_id' => $values['facility_id'],
                    ':pickup_date' => $values['pickup_date'],
                    ':pickup_bag_count' => $values['pickup_bag_count'],
                    ':pickup_time' => $values['pickup_time'],
                    ':pickup_employee_id' => $values['pickup_employee_id'],
                    ':issued_bag_orange' => $values['issued_bag_orange'],
                    ':issued_bag_yellow' => $values['issued_bag_yellow'],
                    ':issued_bag_blue' => $values['issued_bag_blue'],
                    ':issued_laundry_net_count' => $values['issued_laundry_net_count'],
                    ':arrival_bag_count' => $values['arrival_bag_count'],
                    ':arrival_date' => $values['arrival_date'],
                    ':arrival_time' => $values['arrival_time'],
                    ':arrival_employee_id' => $values['arrival_employee_id'],
                    ':arrival_facility_id' => $values['arrival_facility_id'],
                    ':dispatch_bag_count' => $values['dispatch_bag_count'],
                    ':dispatch_date' => $values['dispatch_date'],
                    ':dispatch_time' => $values['dispatch_time'],
                    ':dispatch_employee_id' => $values['dispatch_employee_id'],
                    ':dispatch_facility_id' => $values['dispatch_facility_id'],
                    ':return_bag_count' => $values['return_bag_count'],
                    ':return_date' => $values['return_date'],
                    ':return_time' => $values['return_time'],
                    ':return_employee_id' => $values['return_employee_id'],
                    ':remarks' => $values['remarks'],
                ]);
                $newCycleId = (int) $pdo->lastInsertId();

                $logStmt = $pdo->prepare(
                    'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                     VALUES (:cycle_id, :edited_by, :action, :field_name, NULL, :new_value)'
                );
                foreach (CC_EDITABLE_FIELDS as $field) {
                    if ($values[$field] === null) {
                        continue;
                    }
                    $logStmt->execute([
                        ':cycle_id' => $newCycleId,
                        ':edited_by' => $admin['id'],
                        ':action' => 'create',
                        ':field_name' => $field,
                        ':new_value' => $values[$field],
                    ]);
                }

                record_collection_cycle_issuance_stock_adjustment(
                    $pdo, null, $values, $values['facility_id'], $facilityNamesById[$values['facility_id']] ?? '', $newCycleId, $admin['id']
                );

                set_flash('success', '集荷記録を登録しました。');
                header('Location: ' . $backToListUrl);
                exit;
            } else {
                $cycleId = (int) ($_POST['id'] ?? 0);
                $cycleStmt = $pdo->prepare('SELECT * FROM collection_cycles WHERE id = :id AND deleted_at IS NULL');
                $cycleStmt->execute([':id' => $cycleId]);
                $cycle = $cycleStmt->fetch();

                if ($cycle === false) {
                    $errorMessage = '対象のサイクルが見つかりません。';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $logStmt = $pdo->prepare(
                            'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                             VALUES (:cycle_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                        );
                        $changedCount = 0;
                        foreach (CC_EDITABLE_FIELDS as $field) {
                            if ((string) $values[$field] === (string) $cycle[$field]) {
                                continue;
                            }
                            $logStmt->execute([
                                ':cycle_id' => $cycleId,
                                ':edited_by' => $admin['id'],
                                ':action' => 'update',
                                ':field_name' => $field,
                                ':old_value' => $cycle[$field],
                                ':new_value' => $values[$field],
                            ]);
                            $changedCount++;
                        }

                        $updateStmt = $pdo->prepare(
                            'UPDATE collection_cycles SET
                                facility_id = :facility_id, pickup_date = :pickup_date,
                                pickup_bag_count = :pickup_bag_count, pickup_time = :pickup_time, pickup_employee_id = :pickup_employee_id,
                                issued_bag_orange = :issued_bag_orange, issued_bag_yellow = :issued_bag_yellow,
                                issued_bag_blue = :issued_bag_blue, issued_laundry_net_count = :issued_laundry_net_count,
                                arrival_bag_count = :arrival_bag_count, arrival_date = :arrival_date, arrival_time = :arrival_time,
                                arrival_employee_id = :arrival_employee_id, arrival_facility_id = :arrival_facility_id,
                                dispatch_bag_count = :dispatch_bag_count, dispatch_date = :dispatch_date, dispatch_time = :dispatch_time,
                                dispatch_employee_id = :dispatch_employee_id, dispatch_facility_id = :dispatch_facility_id,
                                return_bag_count = :return_bag_count, return_date = :return_date, return_time = :return_time,
                                return_employee_id = :return_employee_id,
                                remarks = :remarks
                             WHERE id = :id'
                        );
                        $updateStmt->execute([
                            ':facility_id' => $values['facility_id'],
                            ':pickup_date' => $values['pickup_date'],
                            ':pickup_bag_count' => $values['pickup_bag_count'],
                            ':pickup_time' => $values['pickup_time'],
                            ':pickup_employee_id' => $values['pickup_employee_id'],
                            ':issued_bag_orange' => $values['issued_bag_orange'],
                            ':issued_bag_yellow' => $values['issued_bag_yellow'],
                            ':issued_bag_blue' => $values['issued_bag_blue'],
                            ':issued_laundry_net_count' => $values['issued_laundry_net_count'],
                            ':arrival_bag_count' => $values['arrival_bag_count'],
                            ':arrival_date' => $values['arrival_date'],
                            ':arrival_time' => $values['arrival_time'],
                            ':arrival_employee_id' => $values['arrival_employee_id'],
                            ':arrival_facility_id' => $values['arrival_facility_id'],
                            ':dispatch_bag_count' => $values['dispatch_bag_count'],
                            ':dispatch_date' => $values['dispatch_date'],
                            ':dispatch_time' => $values['dispatch_time'],
                            ':dispatch_employee_id' => $values['dispatch_employee_id'],
                            ':dispatch_facility_id' => $values['dispatch_facility_id'],
                            ':return_bag_count' => $values['return_bag_count'],
                            ':return_date' => $values['return_date'],
                            ':return_time' => $values['return_time'],
                            ':return_employee_id' => $values['return_employee_id'],
                            ':remarks' => $values['remarks'],
                            ':id' => $cycleId,
                        ]);

                        record_collection_cycle_issuance_stock_adjustment(
                            $pdo, $cycle, $values, $values['facility_id'], $facilityNamesById[$values['facility_id']] ?? '', $cycleId, $admin['id']
                        );

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '集荷記録を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: ' . $backToListUrl);
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
$editingCycle = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM collection_cycles WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingCycle = $row;
    }
}

$formAction = 'create';
$formId = null;
$formValues = [
    'facility_id' => (string) ($facilityId > 0 ? $facilityId : ''),
    'pickup_date' => (new DateTime())->format('Y-m-d'),
    'pickup_bag_count' => '', 'pickup_time' => '', 'pickup_employee_id' => '',
    'issued_bag_orange' => '', 'issued_bag_yellow' => '', 'issued_bag_blue' => '', 'issued_laundry_net_count' => '',
    'arrival_bag_count' => '', 'arrival_date' => '', 'arrival_time' => '', 'arrival_employee_id' => '', 'arrival_facility_id' => '',
    'dispatch_bag_count' => '', 'dispatch_date' => '', 'dispatch_time' => '', 'dispatch_employee_id' => '', 'dispatch_facility_id' => '',
    'return_bag_count' => '', 'return_date' => '', 'return_time' => '', 'return_employee_id' => '',
    'remarks' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && in_array((string) ($_POST['action'] ?? ''), ['create', 'update'], true)) {
    $formAction = (string) $_POST['action'];
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    foreach (array_keys($formValues) as $field) {
        $formValues[$field] = (string) ($_POST[$field] ?? '');
    }
} elseif ($editingCycle !== null) {
    $formAction = 'update';
    $formId = (int) $editingCycle['id'];
    foreach (array_keys($formValues) as $field) {
        $value = $editingCycle[$field];
        if ($value === null) {
            $formValues[$field] = '';
        } elseif (in_array($field, ['pickup_time', 'arrival_time', 'dispatch_time', 'return_time'], true)) {
            $formValues[$field] = substr($value, 0, 5);
        } else {
            $formValues[$field] = (string) $value;
        }
    }
    // 洗濯代行がcollection_headcount.phpで登録した返却準備完了の数（参考値）を、
    // まだ返却が確定していない（return_bag_count未入力の）場合のみ初期値として提案する。
    if ($formValues['return_bag_count'] === '' && $editingCycle['return_ready_bag_count'] !== null) {
        $formValues['return_bag_count'] = (string) $editingCycle['return_ready_bag_count'];
    }
}

$facilitiesStmtAll = $facilities;
$employeeNamesById = array_column($employees, 'name', 'id');

$selectedFacilityName = $facilityNamesById[$facilityId] ?? '';
$recordWhere = 'cc.pickup_date BETWEEN :start_date AND :end_date AND cc.deleted_at IS NULL';
$recordParams = [':start_date' => $rangeStartStr, ':end_date' => $rangeEndStr];
if ($facilityId > 0) {
    $recordWhere .= ' AND cc.facility_id = :facility_id';
    $recordParams[':facility_id'] = $facilityId;
}

$recordsStmt = $pdo->prepare(
        'SELECT cc.*,
                f.name AS facility_name,
                pe.name AS pickup_employee_name,
                ae.name AS arrival_employee_name,
                de.name AS dispatch_employee_name,
                re.name AS return_employee_name,
                af.name AS arrival_facility_name,
                df.name AS dispatch_facility_name
         FROM collection_cycles cc
         INNER JOIN facilities f ON f.id = cc.facility_id
         LEFT JOIN employees pe ON pe.id = cc.pickup_employee_id
         LEFT JOIN employees ae ON ae.id = cc.arrival_employee_id
         LEFT JOIN employees de ON de.id = cc.dispatch_employee_id
         LEFT JOIN employees re ON re.id = cc.return_employee_id
         LEFT JOIN facilities af ON af.id = cc.arrival_facility_id
         LEFT JOIN facilities df ON df.id = cc.dispatch_facility_id
         WHERE ' . $recordWhere . '
         ORDER BY cc.pickup_date ASC, cc.id ASC'
);
$recordsStmt->execute($recordParams);
$records = $recordsStmt->fetchAll();

function cr_bag($count)
{
    return $count === null ? '-' : (int) $count . '袋';
}

function cr_time($time)
{
    return $time === null ? '-' : substr($time, 0, 5);
}

function cr_issued(array $record): string
{
    $parts = [];
    if ($record['issued_bag_orange'] !== null) {
        $parts[] = 'オレンジ' . (int) $record['issued_bag_orange'];
    }
    if ($record['issued_bag_yellow'] !== null) {
        $parts[] = '黄' . (int) $record['issued_bag_yellow'];
    }
    if ($record['issued_bag_blue'] !== null) {
        $parts[] = '青' . (int) $record['issued_bag_blue'];
    }
    if ($record['issued_laundry_net_count'] !== null) {
        $parts[] = 'ネット' . (int) $record['issued_laundry_net_count'];
    }
    return empty($parts) ? '-' : implode('・', $parts);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集荷記録簿 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .filter-row { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .month-nav a { margin-right: 8px; }
        table.record-table { border-collapse: collapse; width: 100%; font-size: 0.8em; }
        table.record-table th, table.record-table td { border: 1px solid #ccc; padding: 4px 6px; text-align: center; }
        table.record-table th { background: #f5f5f5; }
        .actions { margin: 12px 0; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; margin-bottom: 16px; }
        fieldset legend { font-weight: bold; padding: 0 6px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 16px; }
        .form-row label { display: block; font-size: 0.85em; margin-bottom: 2px; }
        .form-row input, .form-row select { width: 100%; box-sizing: border-box; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>集荷記録簿</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/collection_cycle_edit_logs.php">修正履歴</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="get" action="/admin/collection_records.php" class="filter-row">
    <label for="facility_id">施設:</label>
    <select id="facility_id" name="facility_id" onchange="this.form.submit()">
        <option value="0" <?= $facilityId === 0 ? 'selected' : '' ?>>すべての施設</option>
        <?php foreach ($facilities as $facility): ?>
            <option value="<?= (int) $facility['id'] ?>" <?= (int) $facility['id'] === $facilityId ? 'selected' : '' ?>>
                <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
</form>

<div class="month-nav">
    <a href="?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <strong><?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?></strong>
    <a href="?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">次月 →</a>
</div>

<section class="record-form">
    <h2><?= $formAction === 'update' ? '集荷記録の修正' : '集荷記録の新規登録' ?></h2>
    <fieldset>
        <form method="post" action="/admin/collection_records.php?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-row">
                    <label for="f_facility_id">施設</label>
                    <select id="f_facility_id" name="facility_id" required>
                        <option value="">選択してください</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $formValues['facility_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="f_pickup_date">集荷日</label>
                    <input type="date" id="f_pickup_date" name="pickup_date" value="<?= htmlspecialchars($formValues['pickup_date'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="form-row">
                    <label for="f_pickup_bag_count">集荷リネン袋数</label>
                    <input type="number" id="f_pickup_bag_count" name="pickup_bag_count" min="0" step="1" value="<?= htmlspecialchars($formValues['pickup_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_pickup_time">集荷時間</label>
                    <input type="time" id="f_pickup_time" name="pickup_time" value="<?= htmlspecialchars($formValues['pickup_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_pickup_employee_id">集荷担当者</label>
                    <select id="f_pickup_employee_id" name="pickup_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formValues['pickup_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="f_issued_bag_orange">リネン袋交付数（オレンジ）</label>
                    <input type="number" id="f_issued_bag_orange" name="issued_bag_orange" min="0" step="1" value="<?= htmlspecialchars($formValues['issued_bag_orange'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_issued_bag_yellow">リネン袋交付数（黄）</label>
                    <input type="number" id="f_issued_bag_yellow" name="issued_bag_yellow" min="0" step="1" value="<?= htmlspecialchars($formValues['issued_bag_yellow'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_issued_bag_blue">リネン袋交付数（青）</label>
                    <input type="number" id="f_issued_bag_blue" name="issued_bag_blue" min="0" step="1" value="<?= htmlspecialchars($formValues['issued_bag_blue'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_issued_laundry_net_count">洗濯ネット交付数</label>
                    <input type="number" id="f_issued_laundry_net_count" name="issued_laundry_net_count" min="0" step="1" value="<?= htmlspecialchars($formValues['issued_laundry_net_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-row">
                    <label for="f_arrival_bag_count">到着リネン袋数</label>
                    <input type="number" id="f_arrival_bag_count" name="arrival_bag_count" min="0" step="1" value="<?= htmlspecialchars($formValues['arrival_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_arrival_date">到着日</label>
                    <input type="date" id="f_arrival_date" name="arrival_date" value="<?= htmlspecialchars($formValues['arrival_date'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_arrival_time">到着時間</label>
                    <input type="time" id="f_arrival_time" name="arrival_time" value="<?= htmlspecialchars($formValues['arrival_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_arrival_employee_id">到着担当者</label>
                    <select id="f_arrival_employee_id" name="arrival_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formValues['arrival_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="f_arrival_facility_id">到着クリーニング所</label>
                    <select id="f_arrival_facility_id" name="arrival_facility_id">
                        <option value="">未設定</option>
                        <?php foreach ($cleaningFacilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $formValues['arrival_facility_id'] ? 'selected' : '' ?>><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="f_dispatch_bag_count">発送リネン袋数</label>
                    <input type="number" id="f_dispatch_bag_count" name="dispatch_bag_count" min="0" step="1" value="<?= htmlspecialchars($formValues['dispatch_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_dispatch_date">発送日</label>
                    <input type="date" id="f_dispatch_date" name="dispatch_date" value="<?= htmlspecialchars($formValues['dispatch_date'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_dispatch_time">発送時間</label>
                    <input type="time" id="f_dispatch_time" name="dispatch_time" value="<?= htmlspecialchars($formValues['dispatch_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_dispatch_employee_id">発送担当者</label>
                    <select id="f_dispatch_employee_id" name="dispatch_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formValues['dispatch_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="f_dispatch_facility_id">発送元クリーニング所</label>
                    <select id="f_dispatch_facility_id" name="dispatch_facility_id">
                        <option value="">未設定</option>
                        <?php foreach ($cleaningFacilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $formValues['dispatch_facility_id'] ? 'selected' : '' ?>><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="f_return_bag_count">返却リネン袋数</label>
                    <input type="number" id="f_return_bag_count" name="return_bag_count" min="0" step="1" value="<?= htmlspecialchars($formValues['return_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($editingCycle !== null && $editingCycle['return_ready_bag_count'] !== null): ?>
                        <p class="notice">洗濯代行が登録した数（<?= (int) $editingCycle['return_ready_bag_count'] ?>袋）を初期値にしています。実際の数と違う場合は修正してください。</p>
                    <?php endif; ?>
                </div>
                <div class="form-row">
                    <label for="f_return_date">返却日</label>
                    <input type="date" id="f_return_date" name="return_date" value="<?= htmlspecialchars($formValues['return_date'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_return_time">返却時間</label>
                    <input type="time" id="f_return_time" name="return_time" value="<?= htmlspecialchars($formValues['return_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="f_return_employee_id">返却担当者</label>
                    <select id="f_return_employee_id" name="return_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formValues['return_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row" style="grid-column: 1 / -1;">
                    <label for="f_remarks">備考</label>
                    <input type="text" id="f_remarks" name="remarks" maxlength="255" value="<?= htmlspecialchars($formValues['remarks'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <p style="margin-top:10px;">
                <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
                <?php if ($formAction === 'update'): ?>
                    <a href="<?= htmlspecialchars($backToListUrl, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
                <?php endif; ?>
            </p>
        </form>
    </fieldset>
</section>

    <h2><?= $selectedFacilityName !== '' ? htmlspecialchars($selectedFacilityName, ENT_QUOTES, 'UTF-8') : '全施設' ?>　<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>分</h2>

    <?php if ($facilityId > 0): ?>
    <div class="actions">
        <a href="/admin/collection_records_pdf.php?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">PDFで出力する</a>
    </div>
    <?php endif; ?>

    <?php if (empty($records)): ?>
        <p class="notice">対象月の記録はありません。</p>
    <?php else: ?>
        <table class="record-table">
            <thead>
                <tr>
                    <th rowspan="2">施設</th>
                    <th rowspan="2">集荷日</th>
                    <th colspan="4">集荷</th>
                    <th colspan="4">クリーニング所到着</th>
                    <th colspan="4">クリーニング所発送</th>
                    <th colspan="3">返却</th>
                    <th rowspan="2">備考</th>
                    <th rowspan="2">操作</th>
                </tr>
                <tr>
                    <th>リネン袋数</th><th>時間</th><th>担当者</th><th>交付袋・ネット</th>
                    <th>リネン袋数</th><th>到着日</th><th>時間</th><th>担当者・クリーニング所</th>
                    <th>リネン袋数</th><th>発送日</th><th>時間</th><th>担当者・クリーニング所</th>
                    <th>リネン袋数</th><th>返却日</th><th>時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_bag($record['pickup_bag_count']) ?></td>
                        <td><?= cr_time($record['pickup_time']) ?></td>
                        <td><?= htmlspecialchars($record['pickup_employee_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(cr_issued($record), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_bag($record['arrival_bag_count']) ?></td>
                        <td><?= htmlspecialchars($record['arrival_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_time($record['arrival_time']) ?></td>
                        <td><?= htmlspecialchars($record['arrival_employee_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?><?= $record['arrival_facility_name'] !== null ? '（' . htmlspecialchars($record['arrival_facility_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?></td>
                        <td><?= cr_bag($record['dispatch_bag_count']) ?></td>
                        <td><?= htmlspecialchars($record['dispatch_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_time($record['dispatch_time']) ?></td>
                        <td><?= htmlspecialchars($record['dispatch_employee_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?><?= $record['dispatch_facility_name'] !== null ? '（' . htmlspecialchars($record['dispatch_facility_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?></td>
                        <td><?= cr_bag($record['return_bag_count']) ?></td>
                        <td><?= htmlspecialchars($record['return_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_time($record['return_time']) ?></td>
                        <td><?= htmlspecialchars($record['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>&edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="/admin/collection_records.php?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この集荷記録を削除しますか？');">
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
</body>
</html>