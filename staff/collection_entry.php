<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

/**
 * 指定施設について、この工程がまだ入力されていない未完了サイクルを、
 * pickup_date昇順（古い順）で返す。前工程が未完了のサイクルは対象にしない
 * （＝そのサイクルはまだこの工程を記録できる状態ではない、という定義そのもの。
 * 工程順を強制ブロックする処理ではなく、単に「この工程の対象になり得るか」の絞り込み）。
 */
function find_candidate_cycles(PDO $pdo, string $stage, int $facilityId): array
{
    switch ($stage) {
        case 'arrival':
            $sql = 'SELECT * FROM collection_cycles
                    WHERE facility_id = :facility_id AND arrival_bag_count IS NULL AND deleted_at IS NULL
                    ORDER BY pickup_date ASC, id ASC';
            break;
        case 'dispatch':
            $sql = 'SELECT * FROM collection_cycles
                    WHERE facility_id = :facility_id AND arrival_bag_count IS NOT NULL AND dispatch_bag_count IS NULL AND deleted_at IS NULL
                    ORDER BY pickup_date ASC, id ASC';
            break;
        case 'return':
            $sql = 'SELECT * FROM collection_cycles
                    WHERE facility_id = :facility_id AND dispatch_bag_count IS NOT NULL AND return_bag_count IS NULL AND deleted_at IS NULL
                    ORDER BY pickup_date ASC, id ASC';
            break;
        default:
            return [];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':facility_id' => $facilityId]);
    return $stmt->fetchAll();
}

function parse_bag_count($raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int) $raw;
}

function insert_pickup(
    PDO $pdo,
    int $facilityId,
    int $bagCount,
    int $employeeId,
    ?int $issuedBagOrange,
    ?int $issuedBagYellow,
    ?int $issuedBagBlue,
    ?int $issuedLaundryNetCount
): void {
    $now = new DateTime();
    $stmt = $pdo->prepare(
        'INSERT INTO collection_cycles
            (facility_id, pickup_date, pickup_bag_count, pickup_time, pickup_employee_id,
             issued_bag_orange, issued_bag_yellow, issued_bag_blue, issued_laundry_net_count)
         VALUES
            (:facility_id, :pickup_date, :bag_count, :pickup_time, :employee_id,
             :issued_bag_orange, :issued_bag_yellow, :issued_bag_blue, :issued_laundry_net_count)'
    );
    $stmt->execute([
        ':facility_id' => $facilityId,
        ':pickup_date' => $now->format('Y-m-d'),
        ':bag_count' => $bagCount,
        ':pickup_time' => $now->format('H:i:s'),
        ':employee_id' => $employeeId,
        ':issued_bag_orange' => $issuedBagOrange,
        ':issued_bag_yellow' => $issuedBagYellow,
        ':issued_bag_blue' => $issuedBagBlue,
        ':issued_laundry_net_count' => $issuedLaundryNetCount,
    ]);
}

function update_return(PDO $pdo, int $cycleId, int $bagCount, int $employeeId): void
{
    $stmt = $pdo->prepare(
        'UPDATE collection_cycles
         SET return_bag_count = :bag_count, return_time = :time, return_employee_id = :employee_id
         WHERE id = :id'
    );
    $stmt->execute([
        ':bag_count' => $bagCount,
        ':time' => (new DateTime())->format('H:i:s'),
        ':employee_id' => $employeeId,
        ':id' => $cycleId,
    ]);
}

function update_arrival(PDO $pdo, int $cycleId, int $bagCount, int $employeeId): void
{
    $stmt = $pdo->prepare(
        'UPDATE collection_cycles
         SET arrival_bag_count = :bag_count, arrival_time = :time, arrival_employee_id = :employee_id
         WHERE id = :id'
    );
    $stmt->execute([
        ':bag_count' => $bagCount,
        ':time' => (new DateTime())->format('H:i:s'),
        ':employee_id' => $employeeId,
        ':id' => $cycleId,
    ]);
}

function update_dispatch(PDO $pdo, int $cycleId, int $bagCount, int $employeeId): void
{
    $now = new DateTime();
    $stmt = $pdo->prepare(
        'UPDATE collection_cycles
         SET dispatch_bag_count = :bag_count, dispatch_date = :dispatch_date, dispatch_time = :time, dispatch_employee_id = :employee_id
         WHERE id = :id'
    );
    $stmt->execute([
        ':bag_count' => $bagCount,
        ':dispatch_date' => $now->format('Y-m-d'),
        ':time' => $now->format('H:i:s'),
        ':employee_id' => $employeeId,
        ':id' => $cycleId,
    ]);
}

function format_cycle_candidate_label(array $cycle): string
{
    $parts = ['集荷日 ' . htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8')];
    if ($cycle['pickup_bag_count'] !== null) {
        $parts[] = '集荷' . (int) $cycle['pickup_bag_count'] . '袋';
    }
    if ($cycle['arrival_bag_count'] !== null) {
        $parts[] = '到着' . (int) $cycle['arrival_bag_count'] . '袋';
    }
    if ($cycle['dispatch_bag_count'] !== null) {
        $parts[] = '発送' . (int) $cycle['dispatch_bag_count'] . '袋';
    }
    return $parts[0] . '（' . implode('／', array_slice($parts, 1)) . '）';
}

// ---- 過去サイクルの修正・削除（従業員・管理者とも可能。工程を跨いだ多人数作業のため、
//      自分が記録した項目だけに絞らずチーム共有データとして扱う） ----
const CC_EDITABLE_FIELDS = [
    'facility_id', 'pickup_date', 'pickup_bag_count', 'pickup_time', 'pickup_employee_id',
    'issued_bag_orange', 'issued_bag_yellow', 'issued_bag_blue', 'issued_laundry_net_count',
    'arrival_bag_count', 'arrival_time', 'arrival_employee_id',
    'dispatch_bag_count', 'dispatch_date', 'dispatch_time', 'dispatch_employee_id',
    'return_bag_count', 'return_time', 'return_employee_id', 'remarks',
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

function parse_collection_cycle_input(array $post, array $validFacilityIds, array $validEmployeeIds): array
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
    $arrivalTime = cc_parse_time($post['arrival_time'] ?? '');
    $arrivalEmployeeId = cc_parse_employee_id($post['arrival_employee_id'] ?? '', $validEmployeeIds);
    $dispatchBagCount = cc_parse_bag_count($post['dispatch_bag_count'] ?? '');
    $dispatchDate = cc_parse_date($post['dispatch_date'] ?? '');
    $dispatchTime = cc_parse_time($post['dispatch_time'] ?? '');
    $dispatchEmployeeId = cc_parse_employee_id($post['dispatch_employee_id'] ?? '', $validEmployeeIds);
    $returnBagCount = cc_parse_bag_count($post['return_bag_count'] ?? '');
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
    if ($arrivalTime === false) {
        $errors[] = '到着時間の形式が正しくありません。';
    }
    if ($arrivalEmployeeId === false) {
        $errors[] = '到着担当者が正しくありません。';
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
    if ($returnBagCount === false) {
        $errors[] = '返却リネン袋数は0以上の整数を入力してください。';
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
            'arrival_time' => $arrivalTime,
            'arrival_employee_id' => $arrivalEmployeeId,
            'dispatch_bag_count' => $dispatchBagCount,
            'dispatch_date' => $dispatchDate,
            'dispatch_time' => $dispatchTime,
            'dispatch_employee_id' => $dispatchEmployeeId,
            'return_bag_count' => $returnBagCount,
            'return_time' => $returnTime,
            'return_employee_id' => $returnEmployeeId,
            'remarks' => $remarks,
        ],
        $errors,
    ];
}

$facilitiesStmt = $pdo->query('SELECT id, name, facility_type FROM facilities WHERE is_active = 1 ORDER BY name');
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));
$facilityNamesById = array_column($facilities, 'name', 'id');
$facilityTypesById = array_column($facilities, 'facility_type', 'id');

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$errorMessage = '';
// ステップ2（袋数入力）を描画するための状態。resolve_locationが成功した時だけセットする。
// 施設側は「集荷」「返却」を、クリーニング所側は「到着」「発送」を、対象の有無に関わらず
// 常に両方セクション表示する（対象が無い場合はその項目だけ入力欄なしの注記にする）。
$step2 = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'resolve_location') {
            $facilityId = (int) ($_POST['location'] ?? 0);
            $isCleaningSite = ($facilityTypesById[$facilityId] ?? null) === 'クリーニング所';

            if (!in_array($facilityId, $validFacilityIds, true)) {
                $errorMessage = '施設を選択してください。';
            } elseif ($isCleaningSite) {
                $step2 = [
                    'facility_id' => $facilityId,
                    'is_cleaning_site' => true,
                    'arrival_candidates' => find_candidate_cycles($pdo, 'arrival', $facilityId),
                    'dispatch_candidates' => find_candidate_cycles($pdo, 'dispatch', $facilityId),
                ];
            } else {
                $step2 = [
                    'facility_id' => $facilityId,
                    'is_cleaning_site' => false,
                    'return_candidates' => find_candidate_cycles($pdo, 'return', $facilityId),
                ];
            }
        } elseif ($action === 'finalize') {
            $facilityId = (int) ($_POST['facility_id'] ?? 0);
            $isCleaningSite = ($_POST['is_cleaning_site'] ?? '') === '1';

            if (!in_array($facilityId, $validFacilityIds, true)) {
                $errorMessage = '施設を選択してください。最初からやり直してください。';
            } else {
                $facilityName = $facilityNamesById[$facilityId];
                $employeeId = (int) $staff['id'];

                if ($isCleaningSite) {
                    // 他ドライバーの更新と競合していないか確認するため、ここで状態を再計算する
                    // （ステップ1描画時の状態をそのまま信用しない）。
                    $arrivalCandidates = find_candidate_cycles($pdo, 'arrival', $facilityId);
                    $dispatchCandidates = find_candidate_cycles($pdo, 'dispatch', $facilityId);
                    $validArrivalIds = array_map('intval', array_column($arrivalCandidates, 'id'));
                    $validDispatchIds = array_map('intval', array_column($dispatchCandidates, 'id'));

                    $arrivalBagCount = parse_bag_count($_POST['arrival_bag_count'] ?? '');
                    $dispatchBagCount = parse_bag_count($_POST['dispatch_bag_count'] ?? '');
                    $arrivalCycleId = (int) ($_POST['arrival_cycle_id'] ?? 0);
                    $dispatchCycleId = (int) ($_POST['dispatch_cycle_id'] ?? 0);

                    // 対象サイクルが存在し、かつ袋数が入力されている項目だけを「今回記録する」とみなす。
                    // 対象が無い項目は入力欄自体を出していないので、常にnullのまま＝スキップされる。
                    $wantsArrival = !empty($arrivalCandidates) && $arrivalBagCount !== null;
                    $wantsDispatch = !empty($dispatchCandidates) && $dispatchBagCount !== null;

                    if ($wantsArrival && !in_array($arrivalCycleId, $validArrivalIds, true)) {
                        $errorMessage = '到着対象のサイクルが無効です（既に他の記録で更新された可能性があります）。もう一度やり直してください。';
                    } elseif ($wantsDispatch && !in_array($dispatchCycleId, $validDispatchIds, true)) {
                        $errorMessage = '発送対象のサイクルが無効です（既に他の記録で更新された可能性があります）。もう一度やり直してください。';
                    } elseif (!$wantsArrival && !$wantsDispatch) {
                        $errorMessage = '到着・発送のいずれかにリネン袋数を入力してください。';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            if ($wantsDispatch) {
                                update_dispatch($pdo, $dispatchCycleId, $dispatchBagCount, $employeeId);
                            }
                            if ($wantsArrival) {
                                update_arrival($pdo, $arrivalCycleId, $arrivalBagCount, $employeeId);
                            }
                            $pdo->commit();
                        } catch (\Throwable $e) {
                            $pdo->rollBack();
                            throw $e;
                        }
                        $parts = [];
                        if ($wantsDispatch) {
                            $parts[] = 'クリーニング所発送（' . $dispatchBagCount . '袋）';
                        }
                        if ($wantsArrival) {
                            $parts[] = 'クリーニング所到着（' . $arrivalBagCount . '袋）';
                        }
                        set_flash('success', implode('と', $parts) . 'を記録しました（' . $facilityName . '）。');
                        header('Location: /staff/collection_entry.php');
                        exit;
                    }
                } else {
                    $returnCandidates = find_candidate_cycles($pdo, 'return', $facilityId);
                    $validReturnIds = array_map('intval', array_column($returnCandidates, 'id'));

                    $pickupBagCount = parse_bag_count($_POST['pickup_bag_count'] ?? '');
                    $returnBagCount = parse_bag_count($_POST['return_bag_count'] ?? '');
                    $returnCycleId = (int) ($_POST['return_cycle_id'] ?? 0);
                    $issuedBagOrange = parse_bag_count($_POST['issued_bag_orange'] ?? '');
                    $issuedBagYellow = parse_bag_count($_POST['issued_bag_yellow'] ?? '');
                    $issuedBagBlue = parse_bag_count($_POST['issued_bag_blue'] ?? '');
                    $issuedLaundryNetCount = parse_bag_count($_POST['issued_laundry_net_count'] ?? '');

                    $wantsPickup = $pickupBagCount !== null;
                    $wantsReturn = !empty($returnCandidates) && $returnBagCount !== null;

                    if ($wantsReturn && !in_array($returnCycleId, $validReturnIds, true)) {
                        $errorMessage = '返却対象のサイクルが無効です（既に他の記録で更新された可能性があります）。もう一度やり直してください。';
                    } elseif (!$wantsPickup && !$wantsReturn) {
                        $errorMessage = '集荷・返却のいずれかにリネン袋数を入力してください。';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            if ($wantsReturn) {
                                update_return($pdo, $returnCycleId, $returnBagCount, $employeeId);
                            }
                            if ($wantsPickup) {
                                insert_pickup(
                                    $pdo,
                                    $facilityId,
                                    $pickupBagCount,
                                    $employeeId,
                                    $issuedBagOrange,
                                    $issuedBagYellow,
                                    $issuedBagBlue,
                                    $issuedLaundryNetCount
                                );
                            }
                            $pdo->commit();
                        } catch (\Throwable $e) {
                            $pdo->rollBack();
                            throw $e;
                        }
                        $parts = [];
                        if ($wantsReturn) {
                            $parts[] = '返却（' . $returnBagCount . '袋）';
                        }
                        if ($wantsPickup) {
                            $parts[] = '集荷（' . $pickupBagCount . '袋）';
                        }
                        set_flash('success', implode('と', $parts) . 'を記録しました（' . $facilityName . '）。');
                        header('Location: /staff/collection_entry.php');
                        exit;
                    }
                }
            }
        } elseif ($action === 'delete_cycle') {
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
                            ':edited_by' => $staff['id'],
                            ':action' => 'delete',
                            ':field_name' => $field,
                            ':old_value' => $cycle[$field],
                        ]);
                    }

                    $deleteStmt = $pdo->prepare('UPDATE collection_cycles SET deleted_at = :deleted_at WHERE id = :id');
                    $deleteStmt->execute([':deleted_at' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $cycleId]);

                    $pdo->commit();
                    set_flash('success', '集荷・配送記録を削除しました。');
                    header('Location: /staff/collection_entry.php');
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'edit_cycle') {
            $cycleId = (int) ($_POST['id'] ?? 0);
            [$values, $parseErrors] = parse_collection_cycle_input($_POST, $validFacilityIds, $validEmployeeIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } else {
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
                                ':edited_by' => $staff['id'],
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
                                arrival_bag_count = :arrival_bag_count, arrival_time = :arrival_time, arrival_employee_id = :arrival_employee_id,
                                dispatch_bag_count = :dispatch_bag_count, dispatch_date = :dispatch_date, dispatch_time = :dispatch_time, dispatch_employee_id = :dispatch_employee_id,
                                return_bag_count = :return_bag_count, return_time = :return_time, return_employee_id = :return_employee_id,
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
                            ':arrival_time' => $values['arrival_time'],
                            ':arrival_employee_id' => $values['arrival_employee_id'],
                            ':dispatch_bag_count' => $values['dispatch_bag_count'],
                            ':dispatch_date' => $values['dispatch_date'],
                            ':dispatch_time' => $values['dispatch_time'],
                            ':dispatch_employee_id' => $values['dispatch_employee_id'],
                            ':return_bag_count' => $values['return_bag_count'],
                            ':return_time' => $values['return_time'],
                            ':return_employee_id' => $values['return_employee_id'],
                            ':remarks' => $values['remarks'],
                            ':id' => $cycleId,
                        ]);

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '集荷・配送記録を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: /staff/collection_entry.php');
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

// ---- 編集対象の読み込み（チーム共有データのため、記録した本人以外でも修正・削除できる） ----
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

$editFormValues = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && (string) ($_POST['action'] ?? '') === 'edit_cycle') {
    $editFormValues = ['id' => (int) ($_POST['id'] ?? 0)];
    foreach (CC_EDITABLE_FIELDS as $field) {
        $editFormValues[$field] = (string) ($_POST[$field] ?? '');
    }
} elseif ($editingCycle !== null) {
    $editFormValues = ['id' => (int) $editingCycle['id']];
    foreach (CC_EDITABLE_FIELDS as $field) {
        $value = $editingCycle[$field];
        if ($value === null) {
            $editFormValues[$field] = '';
        } elseif (in_array($field, ['pickup_time', 'arrival_time', 'dispatch_time', 'return_time'], true)) {
            $editFormValues[$field] = substr($value, 0, 5);
        } else {
            $editFormValues[$field] = (string) $value;
        }
    }
}

// ---- 直近の全サイクル状況（施設横断・自分以外の入力も含めて全体の進捗を確認できるようにする） ----
$recentStmt = $pdo->query(
    "SELECT cc.*, f.name AS facility_name
     FROM collection_cycles cc
     INNER JOIN facilities f ON f.id = cc.facility_id
     WHERE cc.pickup_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND cc.deleted_at IS NULL
     ORDER BY cc.pickup_date DESC, cc.id DESC
     LIMIT 100"
);
$recentCycles = $recentStmt->fetchAll();

function format_stage_cell($bagCount, $time): string
{
    if ($bagCount === null) {
        return '未';
    }
    $label = (int) $bagCount . '袋';
    if ($time !== null) {
        $label .= '（' . substr($time, 0, 5) . '）';
    }
    return $label;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集荷・配送記録の入力 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; margin-bottom: 12px; }
        fieldset legend { font-weight: bold; padding: 0 6px; }
        fieldset.disabled { background: #f5f5f5; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 130px; }
        .form-row select, .form-row input[type="number"] { width: 220px; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.85em; }
        table.records th { background: #f5f5f5; }
        .done { color: #1e7e34; }
        .pending { color: #999; }
        .candidate-row { border: 1px solid #ccc; border-radius: 4px; padding: 8px; margin-bottom: 6px; }
        .candidate-row label { display: block; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 16px; }
        .form-grid .form-row label { display: block; width: auto; font-size: 0.85em; margin-bottom: 2px; }
        .form-grid .form-row input, .form-grid .form-row select { width: 100%; box-sizing: border-box; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>集荷・配送記録の入力</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if (empty($facilities)): ?>
    <p class="notice">有効な施設が登録されていません。管理者にお問い合わせください。</p>
<?php elseif ($step2 !== null): ?>
    <?php $facilityName = htmlspecialchars($facilityNamesById[$step2['facility_id']], ENT_QUOTES, 'UTF-8'); ?>
    <section class="entry-form">
        <h2><?= $facilityName ?>の記録</h2>
        <form method="post" action="/staff/collection_entry.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="finalize">
            <input type="hidden" name="facility_id" value="<?= (int) $step2['facility_id'] ?>">
            <input type="hidden" name="is_cleaning_site" value="<?= $step2['is_cleaning_site'] ? '1' : '0' ?>">

            <?php if ($step2['is_cleaning_site']): ?>
                <fieldset<?= empty($step2['dispatch_candidates']) ? ' class="disabled"' : '' ?>>
                    <legend>発送（前回持ち込み分）</legend>
                    <?php if (empty($step2['dispatch_candidates'])): ?>
                        <p class="notice">現在、発送待ちのサイクルはありません。</p>
                    <?php else: ?>
                        <?php if (count($step2['dispatch_candidates']) === 1): ?>
                            <input type="hidden" name="dispatch_cycle_id" value="<?= (int) $step2['dispatch_candidates'][0]['id'] ?>">
                            <p class="notice">発送対象: <?= format_cycle_candidate_label($step2['dispatch_candidates'][0]) ?></p>
                        <?php else: ?>
                            <p class="notice">発送待ちのサイクルが複数あります。対象を選んでください。</p>
                            <?php foreach ($step2['dispatch_candidates'] as $index => $cycle): ?>
                                <div class="candidate-row">
                                    <label>
                                        <input type="radio" name="dispatch_cycle_id" value="<?= (int) $cycle['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                        <?= format_cycle_candidate_label($cycle) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="form-row">
                            <label for="dispatch_bag_count">発送リネン袋数</label>
                            <input type="number" id="dispatch_bag_count" name="dispatch_bag_count" min="0" step="1">
                        </div>
                    <?php endif; ?>
                </fieldset>
                <fieldset<?= empty($step2['arrival_candidates']) ? ' class="disabled"' : '' ?>>
                    <legend>到着（今回持ち込み分）</legend>
                    <?php if (empty($step2['arrival_candidates'])): ?>
                        <p class="notice">現在、到着待ちのサイクル（未到着の集荷）はありません。</p>
                    <?php else: ?>
                        <?php if (count($step2['arrival_candidates']) === 1): ?>
                            <input type="hidden" name="arrival_cycle_id" value="<?= (int) $step2['arrival_candidates'][0]['id'] ?>">
                            <p class="notice">到着対象: <?= format_cycle_candidate_label($step2['arrival_candidates'][0]) ?></p>
                        <?php else: ?>
                            <p class="notice">到着待ちのサイクルが複数あります。対象を選んでください。</p>
                            <?php foreach ($step2['arrival_candidates'] as $index => $cycle): ?>
                                <div class="candidate-row">
                                    <label>
                                        <input type="radio" name="arrival_cycle_id" value="<?= (int) $cycle['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                        <?= format_cycle_candidate_label($cycle) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="form-row">
                            <label for="arrival_bag_count">到着リネン袋数</label>
                            <input type="number" id="arrival_bag_count" name="arrival_bag_count" min="0" step="1">
                        </div>
                    <?php endif; ?>
                </fieldset>
            <?php else: ?>
                <fieldset<?= empty($step2['return_candidates']) ? ' class="disabled"' : '' ?>>
                    <legend>返却（前回分）</legend>
                    <?php if (empty($step2['return_candidates'])): ?>
                        <p class="notice">現在、返却待ちのサイクルはありません（初回訪問、または前回分は既に返却済みです）。</p>
                    <?php else: ?>
                        <?php if (count($step2['return_candidates']) === 1): ?>
                            <input type="hidden" name="return_cycle_id" value="<?= (int) $step2['return_candidates'][0]['id'] ?>">
                            <p class="notice">返却対象: <?= format_cycle_candidate_label($step2['return_candidates'][0]) ?></p>
                        <?php else: ?>
                            <p class="notice">返却待ちのサイクルが複数あります。対象を選んでください。</p>
                            <?php foreach ($step2['return_candidates'] as $index => $cycle): ?>
                                <div class="candidate-row">
                                    <label>
                                        <input type="radio" name="return_cycle_id" value="<?= (int) $cycle['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                        <?= format_cycle_candidate_label($cycle) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="form-row">
                            <label for="return_bag_count">返却リネン袋数</label>
                            <input type="number" id="return_bag_count" name="return_bag_count" min="0" step="1">
                        </div>
                    <?php endif; ?>
                </fieldset>
                <fieldset>
                    <legend>集荷（今回分・新規サイクル）</legend>
                    <div class="form-row">
                        <label for="pickup_bag_count">集荷リネン袋数</label>
                        <input type="number" id="pickup_bag_count" name="pickup_bag_count" min="0" step="1">
                    </div>
                    <p class="notice">集荷時に施設へ渡した交換用の空袋・ネットの数（任意）</p>
                    <div class="form-row">
                        <label for="issued_bag_orange">リネン袋交付数（オレンジ）</label>
                        <input type="number" id="issued_bag_orange" name="issued_bag_orange" min="0" step="1">
                    </div>
                    <div class="form-row">
                        <label for="issued_bag_yellow">リネン袋交付数（黄）</label>
                        <input type="number" id="issued_bag_yellow" name="issued_bag_yellow" min="0" step="1">
                    </div>
                    <div class="form-row">
                        <label for="issued_bag_blue">リネン袋交付数（青）</label>
                        <input type="number" id="issued_bag_blue" name="issued_bag_blue" min="0" step="1">
                    </div>
                    <div class="form-row">
                        <label for="issued_laundry_net_count">洗濯ネット交付数</label>
                        <input type="number" id="issued_laundry_net_count" name="issued_laundry_net_count" min="0" step="1">
                    </div>
                </fieldset>
            <?php endif; ?>

            <p class="notice">送信すると、時刻は現在時刻、担当者はログイン中のご本人（<?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?>）で自動記録されます。空欄のままの項目は今回記録されません。</p>
            <button type="submit">記録する</button>
            <a href="/staff/collection_entry.php">最初からやり直す</a>
        </form>
    </section>
<?php else: ?>
    <section class="entry-form">
        <h2>場所を選択</h2>
        <fieldset>
            <form method="post" action="/staff/collection_entry.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="resolve_location">

                <div class="form-row">
                    <label for="location">場所</label>
                    <select id="location" name="location" required>
                        <option value="">選択してください</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>"><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">次へ</button>
            </form>
        </fieldset>
    </section>
<?php endif; ?>

<?php if ($editFormValues !== null): ?>
<section class="edit-form">
    <h2>集荷・配送記録の修正（ID: <?= (int) $editFormValues['id'] ?>）</h2>
    <p class="notice">この記録簿はチーム共有のため、記録した本人以外でも修正・削除できます。変更内容は履歴に記録されます。</p>
    <fieldset>
        <form method="post" action="/staff/collection_entry.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_cycle">
            <input type="hidden" name="id" value="<?= (int) $editFormValues['id'] ?>">

            <div class="form-grid">
                <div class="form-row">
                    <label for="e_facility_id">施設</label>
                    <select id="e_facility_id" name="facility_id" required>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $editFormValues['facility_id'] ? 'selected' : '' ?>><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="e_pickup_date">集荷日</label>
                    <input type="date" id="e_pickup_date" name="pickup_date" value="<?= htmlspecialchars($editFormValues['pickup_date'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="form-row">
                    <label for="e_pickup_bag_count">集荷リネン袋数</label>
                    <input type="number" id="e_pickup_bag_count" name="pickup_bag_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['pickup_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_pickup_time">集荷時間</label>
                    <input type="time" id="e_pickup_time" name="pickup_time" value="<?= htmlspecialchars($editFormValues['pickup_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_pickup_employee_id">集荷担当者</label>
                    <select id="e_pickup_employee_id" name="pickup_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $editFormValues['pickup_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="e_issued_bag_orange">リネン袋交付数（オレンジ）</label>
                    <input type="number" id="e_issued_bag_orange" name="issued_bag_orange" min="0" step="1" value="<?= htmlspecialchars($editFormValues['issued_bag_orange'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_issued_bag_yellow">リネン袋交付数（黄）</label>
                    <input type="number" id="e_issued_bag_yellow" name="issued_bag_yellow" min="0" step="1" value="<?= htmlspecialchars($editFormValues['issued_bag_yellow'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_issued_bag_blue">リネン袋交付数（青）</label>
                    <input type="number" id="e_issued_bag_blue" name="issued_bag_blue" min="0" step="1" value="<?= htmlspecialchars($editFormValues['issued_bag_blue'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_issued_laundry_net_count">洗濯ネット交付数</label>
                    <input type="number" id="e_issued_laundry_net_count" name="issued_laundry_net_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['issued_laundry_net_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-row">
                    <label for="e_arrival_bag_count">到着リネン袋数</label>
                    <input type="number" id="e_arrival_bag_count" name="arrival_bag_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['arrival_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_arrival_time">到着時間</label>
                    <input type="time" id="e_arrival_time" name="arrival_time" value="<?= htmlspecialchars($editFormValues['arrival_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_arrival_employee_id">到着担当者</label>
                    <select id="e_arrival_employee_id" name="arrival_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $editFormValues['arrival_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="e_dispatch_bag_count">発送リネン袋数</label>
                    <input type="number" id="e_dispatch_bag_count" name="dispatch_bag_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['dispatch_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_dispatch_date">発送日</label>
                    <input type="date" id="e_dispatch_date" name="dispatch_date" value="<?= htmlspecialchars($editFormValues['dispatch_date'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_dispatch_time">発送時間</label>
                    <input type="time" id="e_dispatch_time" name="dispatch_time" value="<?= htmlspecialchars($editFormValues['dispatch_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_dispatch_employee_id">発送担当者</label>
                    <select id="e_dispatch_employee_id" name="dispatch_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $editFormValues['dispatch_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="e_return_bag_count">返却リネン袋数</label>
                    <input type="number" id="e_return_bag_count" name="return_bag_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['return_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_return_time">返却時間</label>
                    <input type="time" id="e_return_time" name="return_time" value="<?= htmlspecialchars($editFormValues['return_time'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_return_employee_id">返却担当者</label>
                    <select id="e_return_employee_id" name="return_employee_id">
                        <option value="">未設定</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $editFormValues['return_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row" style="grid-column: 1 / -1;">
                    <label for="e_remarks">備考</label>
                    <input type="text" id="e_remarks" name="remarks" maxlength="255" value="<?= htmlspecialchars($editFormValues['remarks'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <p style="margin-top:10px;">
                <button type="submit">更新する</button>
                <a href="/staff/collection_entry.php">キャンセル</a>
            </p>
        </form>
    </fieldset>
</section>
<?php endif; ?>

<section class="record-list">
    <h2>直近30日間の全施設サイクル状況</h2>
    <?php if (empty($recentCycles)): ?>
        <p class="notice">直近30日間の記録はありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>施設</th>
                    <th>集荷日</th>
                    <th>集荷</th>
                    <th>交付袋・ネット</th>
                    <th>到着</th>
                    <th>発送</th>
                    <th>返却</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentCycles as $cycle): ?>
                    <?php
                    $issuedParts = [];
                    if ($cycle['issued_bag_orange'] !== null) {
                        $issuedParts[] = 'オレンジ' . (int) $cycle['issued_bag_orange'];
                    }
                    if ($cycle['issued_bag_yellow'] !== null) {
                        $issuedParts[] = '黄' . (int) $cycle['issued_bag_yellow'];
                    }
                    if ($cycle['issued_bag_blue'] !== null) {
                        $issuedParts[] = '青' . (int) $cycle['issued_bag_blue'];
                    }
                    if ($cycle['issued_laundry_net_count'] !== null) {
                        $issuedParts[] = 'ネット' . (int) $cycle['issued_laundry_net_count'];
                    }
                    $issuedLabel = empty($issuedParts) ? '-' : implode('・', $issuedParts);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="done"><?= htmlspecialchars(format_stage_cell($cycle['pickup_bag_count'], $cycle['pickup_time']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($issuedLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= $cycle['arrival_bag_count'] !== null ? 'done' : 'pending' ?>">
                            <?= htmlspecialchars(format_stage_cell($cycle['arrival_bag_count'], $cycle['arrival_time']), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= $cycle['dispatch_bag_count'] !== null ? 'done' : 'pending' ?>">
                            <?= htmlspecialchars(format_stage_cell($cycle['dispatch_bag_count'], $cycle['dispatch_time']), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= $cycle['return_bag_count'] !== null ? 'done' : 'pending' ?>">
                            <?= htmlspecialchars(format_stage_cell($cycle['return_bag_count'], $cycle['return_time']), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <a href="/staff/collection_entry.php?edit=<?= (int) $cycle['id'] ?>">編集</a>
                            <form method="post" action="/staff/collection_entry.php" class="inline-form" onsubmit="return confirm('この集荷・配送記録を削除しますか？');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_cycle">
                                <input type="hidden" name="id" value="<?= (int) $cycle['id'] ?>">
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
