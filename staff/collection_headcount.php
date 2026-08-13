<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$isAdminView = defined('COLLECTION_HEADCOUNT_ADMIN_VIEW') && COLLECTION_HEADCOUNT_ADMIN_VIEW;
$staff = require_login($isAdminView ? 'admin' : 'staff');
$collectionHeadcountPath = $isAdminView ? '/admin/collection_headcount.php' : '/staff/collection_headcount.php';
$dashboardPath = $isAdminView ? '/admin/dashboard.php' : '/staff/dashboard.php';
$logoutPath = $isAdminView ? '/admin/logout.php' : '/staff/logout.php';
$pdo = getPdo();
$isSharedAccount = !$isAdminView && (int) ($staff['is_shared_account'] ?? 0) === 1;

// この画面で新規作成・修正するwork_stage_recordsのフィールド一覧（監査ログ用）。employee_idsは
// work_stage_records自体のカラムではなくwork_stage_record_employees側の参加者一覧のため、
// 汎用の差分ログ処理（$recordのカラム値と比較する処理）とは別に個別でログを記録する。
const HEADCOUNT_LOG_FIELDS = ['category', 'facility_id', 'collection_cycle_id', 'stage', 'person_count', 'record_date', 'record_time', 'completed_at'];
// 修正時にユーザーが変更できるのはこの項目のみ（施設・工程・紐づくサイクルは確認記録の性質上変えない）。
// person_count・completed_atは参加者選択（employee_ids、別ロジックで扱う）から自動で算出・更新する。
const HEADCOUNT_EDITABLE_FIELDS = ['person_count', 'record_date', 'record_time', 'completed_at'];

// 洗濯ネット数（到着したリネン袋内の枚数確認）と返却リネン袋（青）数（返却梱包の準備）は、
// 別々のタイミングで別々の担当者が登録できるよう、それぞれ独立したフィールド群として扱う
// （2026-08-13、confirm_headcountとregister_returnを分割）。
const NET_COUNT_LOG_FIELD = 'return_ready_laundry_net_count';
const RETURN_READY_LOG_FIELDS = ['return_ready_bag_count', 'return_ready_at', 'return_ready_employee_id'];

/**
 * 返却未完了（return_bag_count IS NULL）の集荷サイクルを、施設（集荷曜日設定含む）・作業実績
 * （work_stage_records、集荷サイクルに紐づく確認記録）・返却準備完了の担当者名まで含めて
 * 1クエリで取得する。1サイクル＝1行のメインテーブルで、集荷→到着→洗濯ネット数→返却袋数（青）
 * の流れをすべてこの1行から組み立てる。
 */
function find_open_cycles(PDO $pdo): array
{
    $sql = 'SELECT cc.*, f.name AS facility_name, f.pickup_schedule AS facility_pickup_schedule,
                   wsr.id AS wsr_id, wsr.person_count AS wsr_person_count,
                   wsr.record_date AS wsr_record_date, wsr.record_time AS wsr_record_time,
                   wsr.completed_at AS wsr_completed_at,
                   rre.name AS return_ready_employee_name
            FROM collection_cycles cc
            INNER JOIN facilities f ON f.id = cc.facility_id
            LEFT JOIN work_stage_records wsr
                   ON wsr.collection_cycle_id = cc.id AND wsr.deleted_at IS NULL
            LEFT JOIN employees rre ON rre.id = cc.return_ready_employee_id
            WHERE cc.return_bag_count IS NULL AND cc.deleted_at IS NULL
            ORDER BY cc.pickup_date ASC, cc.id ASC
            LIMIT 200';
    return $pdo->query($sql)->fetchAll();
}

/**
 * 返却が完了した（return_bag_count入力済みの）集荷サイクルの履歴を、直近順に返す。
 */
function find_returned_cycles(PDO $pdo): array
{
    $sql = 'SELECT cc.*, f.name AS facility_name, f.pickup_schedule AS facility_pickup_schedule,
                   wsr.id AS wsr_id, wsr.person_count AS wsr_person_count,
                   wsr.record_date AS wsr_record_date, wsr.record_time AS wsr_record_time,
                   wsr.completed_at AS wsr_completed_at,
                   rre.name AS return_ready_employee_name
            FROM collection_cycles cc
            INNER JOIN facilities f ON f.id = cc.facility_id
            LEFT JOIN work_stage_records wsr
                   ON wsr.collection_cycle_id = cc.id AND wsr.deleted_at IS NULL
            LEFT JOIN employees rre ON rre.id = cc.return_ready_employee_id
            WHERE cc.return_bag_count IS NOT NULL AND cc.deleted_at IS NULL
            ORDER BY cc.return_date DESC, cc.return_time DESC, cc.id DESC
            LIMIT 100';
    return $pdo->query($sql)->fetchAll();
}

/**
 * 施設ごとの「作業完了数」。staff/work_status.phpの洗濯累計と同じロジック
 * （work_stage_records、collection_cycle_id IS NULL＝作業実績登録画面からの個人記録、の
 * person_countを処理量の代用値として使う）をそのまま流用する。
 */
function find_washed_totals_by_facility(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT w.facility_id, SUM(w.person_count) AS washed
         FROM work_stage_records w
         INNER JOIN (
             SELECT facility_id, MIN(COALESCE(arrival_date, pickup_date)) AS open_since
             FROM collection_cycles
             WHERE arrival_bag_count IS NOT NULL
               AND dispatch_bag_count IS NULL
               AND return_bag_count IS NULL
               AND deleted_at IS NULL
             GROUP BY facility_id
         ) active ON active.facility_id = w.facility_id
         WHERE w.stage = 'wash'
           AND w.collection_cycle_id IS NULL
           AND w.deleted_at IS NULL
           AND w.record_date >= active.open_since
         GROUP BY w.facility_id"
    );
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['facility_id']] = (int) $row['washed'];
    }
    return $result;
}

function parse_return_bag_count($raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int) $raw;
}

function parse_laundry_net_count($raw): ?int
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

function parse_headcount_edit_input(array $post, array $validEmployeeIds): array
{
    $employeeIds = [];
    foreach ((array) ($post['employee_ids'] ?? []) as $rawEmployeeId) {
        $employeeId = (int) $rawEmployeeId;
        if (in_array($employeeId, $validEmployeeIds, true)) {
            $employeeIds[] = $employeeId;
        }
    }
    $employeeIds = array_values(array_unique($employeeIds));
    $personCount = count($employeeIds);

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
    if ($recordDate === false || $recordDate === null) {
        $errors[] = '確認日の形式が正しくありません。';
    }
    if ($recordTime === false || $recordTime === null) {
        $errors[] = '確認時刻の形式が正しくありません。';
    }

    $completedAt = ($recordDate !== null && $recordDate !== false && $recordTime !== null && $recordTime !== false)
        ? $recordDate . ' ' . $recordTime
        : null;

    return [
        [
            'person_count' => $personCount,
            'record_date' => $recordDate,
            'record_time' => $recordTime,
            'completed_at' => $completedAt,
            'employee_ids' => $employeeIds,
        ],
        $errors,
    ];
}

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' AND is_shared_account = 0 ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));
$manualFacilitiesStmt = $pdo->query("SELECT id, name FROM facilities WHERE is_active = 1 AND facility_type = '介護施設' ORDER BY name");
$manualFacilities = $manualFacilitiesStmt->fetchAll();
$validManualFacilityIds = array_map('intval', array_column($manualFacilities, 'id'));

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'manual_register') {
            $facilityId = (int) ($_POST['facility_id'] ?? 0);
            $pickupDateRaw = trim((string) ($_POST['pickup_date'] ?? ''));
            $pickupDateValue = DateTime::createFromFormat('Y-m-d', $pickupDateRaw);
            $arrivalBagCount = parse_return_bag_count($_POST['arrival_bag_count'] ?? '');
            $laundryNetCount = parse_laundry_net_count($_POST['laundry_net_count'] ?? '');
            $returnBagCount = parse_return_bag_count($_POST['return_bag_count'] ?? '');
            $performedEmployeeIds = [];
            if ($isSharedAccount) {
                foreach ((array) ($_POST['employee_ids'] ?? []) as $rawEmployeeId) {
                    $employeeId = (int) $rawEmployeeId;
                    if (in_array($employeeId, $validEmployeeIds, true)) {
                        $performedEmployeeIds[] = $employeeId;
                    }
                }
                $performedEmployeeIds = array_values(array_unique($performedEmployeeIds));
            } elseif (!$isAdminView && in_array((int) $staff['id'], $validEmployeeIds, true)) {
                $performedEmployeeIds = [(int) $staff['id']];
            }

            if (!in_array($facilityId, $validManualFacilityIds, true)) {
                $errorMessage = '施設を選択してください。';
            } elseif ($pickupDateValue === false || $pickupDateValue->format('Y-m-d') !== $pickupDateRaw) {
                $errorMessage = '集荷日を入力してください。';
            } elseif ($arrivalBagCount === null || $laundryNetCount === null || $returnBagCount === null) {
                $errorMessage = '到着リネン袋数・洗濯ネット数・返却リネン袋数は0以上の整数を入力してください。';
            } elseif ($isSharedAccount && empty($performedEmployeeIds)) {
                $errorMessage = '作業した従業員を1人以上選択してください。';
            } else {
                $now = new DateTime();
                try {
                    $pdo->beginTransaction();
                    $cycleStmt = $pdo->prepare(
                        'SELECT cc.id, cc.pickup_bag_count,
                                EXISTS(SELECT 1 FROM work_stage_records wsr WHERE wsr.collection_cycle_id = cc.id AND wsr.deleted_at IS NULL) AS confirmed
                         FROM collection_cycles cc
                         WHERE cc.facility_id = :facility_id AND cc.pickup_date = :pickup_date AND cc.deleted_at IS NULL
                         ORDER BY cc.id DESC LIMIT 1 FOR UPDATE'
                    );
                    $cycleStmt->execute([':facility_id' => $facilityId, ':pickup_date' => $pickupDateRaw]);
                    $cycle = $cycleStmt->fetch();
                    if ($cycle !== false && (int) $cycle['confirmed'] === 1) {
                        throw new RuntimeException('ALREADY_CONFIRMED');
                    }
                    if ($cycle !== false
                        && $cycle['pickup_bag_count'] !== null
                        && (int) $cycle['pickup_bag_count'] !== $arrivalBagCount
                    ) {
                        throw new RuntimeException('BAG_COUNT_MISMATCH:' . (int) $cycle['pickup_bag_count']);
                    }

                    if ($cycle === false) {
                        $insertCycle = $pdo->prepare(
                            'INSERT INTO collection_cycles
                                (facility_id, pickup_date, arrival_bag_count, arrival_date, arrival_time, arrival_employee_id,
                                 return_ready_bag_count, return_ready_laundry_net_count, return_ready_at, return_ready_employee_id)
                             VALUES
                                (:facility_id, :pickup_date, :arrival_bag_count, :arrival_date, :arrival_time, :employee_id,
                                 :return_bag_count, :net_count, :ready_at, :employee_id)'
                        );
                        $insertCycle->execute([
                            ':facility_id' => $facilityId, ':pickup_date' => $pickupDateRaw,
                            ':arrival_bag_count' => $arrivalBagCount, ':arrival_date' => $now->format('Y-m-d'),
                            ':arrival_time' => $now->format('H:i:s'), ':employee_id' => $staff['id'],
                            ':return_bag_count' => $returnBagCount, ':net_count' => $laundryNetCount,
                            ':ready_at' => $now->format('Y-m-d H:i:s'),
                        ]);
                        $cycleId = (int) $pdo->lastInsertId();
                    } else {
                        $cycleId = (int) $cycle['id'];
                        $updateCycle = $pdo->prepare(
                            'UPDATE collection_cycles
                             SET arrival_bag_count = :arrival_bag_count, arrival_date = :arrival_date,
                                 arrival_time = :arrival_time, arrival_employee_id = :employee_id,
                                 return_ready_bag_count = :return_bag_count,
                                 return_ready_laundry_net_count = :net_count,
                                 return_ready_at = :ready_at, return_ready_employee_id = :employee_id
                             WHERE id = :id'
                        );
                        $updateCycle->execute([
                            ':arrival_bag_count' => $arrivalBagCount, ':arrival_date' => $now->format('Y-m-d'),
                            ':arrival_time' => $now->format('H:i:s'), ':employee_id' => $staff['id'],
                            ':return_bag_count' => $returnBagCount, ':net_count' => $laundryNetCount,
                            ':ready_at' => $now->format('Y-m-d H:i:s'), ':id' => $cycleId,
                        ]);
                    }

                    $insertRecord = $pdo->prepare(
                        "INSERT INTO work_stage_records
                            (employee_id, category, facility_id, collection_cycle_id, stage, person_count, record_date, record_time, completed_at)
                         VALUES (:employee_id, '洗濯代行', :facility_id, :cycle_id, 'wash', :net_count, :record_date, :record_time, :completed_at)"
                    );
                    $insertRecord->execute([
                        ':employee_id' => $staff['id'], ':facility_id' => $facilityId, ':cycle_id' => $cycleId,
                        ':net_count' => $laundryNetCount, ':record_date' => $now->format('Y-m-d'),
                        ':record_time' => $now->format('H:i:s'), ':completed_at' => $now->format('Y-m-d H:i:s'),
                    ]);
                    $newRecordId = (int) $pdo->lastInsertId();
                    if (!empty($performedEmployeeIds)) {
                        record_work_stage_employees($pdo, $newRecordId, $performedEmployeeIds, $now);
                    }
                    $pdo->commit();
                    set_flash('success', '洗濯ネット数・到着リネン袋数・返却リネン袋数を登録しました。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (RuntimeException $e) {
                    $pdo->rollBack();
                    if ($e->getMessage() === 'ALREADY_CONFIRMED') {
                        $errorMessage = '同じ施設・集荷日の記録は登録済みです。要返却リストの編集を使用してください。';
                    } elseif (strpos($e->getMessage(), 'BAG_COUNT_MISMATCH:') === 0) {
                        $driverBagCount = (int) substr($e->getMessage(), strlen('BAG_COUNT_MISMATCH:'));
                        $errorMessage = 'ドライバーの集荷リネン袋数（' . $driverBagCount . '袋）と到着リネン袋数（' . $arrivalBagCount . '袋）が一致しません。入力内容を確認してください。';
                    } else {
                        $errorMessage = '登録に失敗しました。';
                    }
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'confirm_headcount') {
            // 「4. 洗濯ネット数」の登録。返却袋数（青）とは独立して、いつでも単独で登録できる。
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
            $laundryNetCount = parse_laundry_net_count($_POST['laundry_net_count'] ?? '');
            $employeeIds = [];
            foreach ((array) ($_POST['employee_ids'] ?? []) as $rawEmployeeId) {
                $employeeId = (int) $rawEmployeeId;
                if (in_array($employeeId, $validEmployeeIds, true)) {
                    $employeeIds[] = $employeeId;
                }
            }
            $employeeIds = array_values(array_unique($employeeIds));
            if (!$isSharedAccount && !$isAdminView && in_array((int) $staff['id'], $validEmployeeIds, true)) {
                $employeeIds = [(int) $staff['id']];
            }
            $personCount = count($employeeIds);

            // 再表示までの間に他のスタッフが確認済みにした可能性があるため、直前に候補を再取得して検証する。
            $openCycles = find_open_cycles($pdo);
            $openById = [];
            foreach ($openCycles as $cycle) {
                $openById[(int) $cycle['id']] = $cycle;
            }
            $targetCycle = $openById[$cycleId] ?? null;

            if ($laundryNetCount === null) {
                $errorMessage = '洗濯ネット数は0以上の整数を入力してください。';
            } elseif ($targetCycle === null || $targetCycle['arrival_bag_count'] === null) {
                $errorMessage = '対象のサイクルが無効です（未到着、または既に返却済みの可能性があります）。もう一度やり直してください。';
            } elseif ($targetCycle['return_ready_laundry_net_count'] !== null) {
                $errorMessage = '対象のサイクルは既に洗濯ネット数を登録済みです。もう一度やり直してください。';
            } elseif ($isSharedAccount && empty($employeeIds)) {
                $errorMessage = '作業した従業員を1人以上選択してください。';
            } else {
                $now = new DateTime();

                try {
                    $pdo->beginTransaction();

                    $insertStmt = $pdo->prepare(
                        "INSERT INTO work_stage_records
                            (employee_id, category, facility_id, collection_cycle_id, stage, person_count, record_date, record_time, completed_at)
                         VALUES
                            (:employee_id, '洗濯代行', :facility_id, :collection_cycle_id, 'wash', :person_count, :record_date, :record_time, :completed_at)"
                    );
                    $insertStmt->execute([
                        ':employee_id' => $staff['id'],
                        ':facility_id' => $targetCycle['facility_id'],
                        ':collection_cycle_id' => $cycleId,
                        ':person_count' => $personCount,
                        ':record_date' => $now->format('Y-m-d'),
                        ':record_time' => $now->format('H:i:s'),
                        ':completed_at' => $now->format('Y-m-d H:i:s'),
                    ]);
                    $newRecordId = (int) $pdo->lastInsertId();

                    $cycleUpdateStmt = $pdo->prepare(
                        'UPDATE collection_cycles SET return_ready_laundry_net_count = :net_count WHERE id = :id'
                    );
                    $cycleUpdateStmt->execute([':net_count' => $laundryNetCount, ':id' => $cycleId]);

                    $cycleLogStmt = $pdo->prepare(
                        'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:cycle_id, :edited_by, :action, :field_name, NULL, :new_value)'
                    );
                    $cycleLogStmt->execute([
                        ':cycle_id' => $cycleId, ':edited_by' => $staff['id'], ':action' => 'update',
                        ':field_name' => NET_COUNT_LOG_FIELD, ':new_value' => $laundryNetCount,
                    ]);

                    if (!empty($employeeIds)) {
                        record_work_stage_employees($pdo, $newRecordId, $employeeIds, $now);
                    }

                    $logStmt = $pdo->prepare(
                        'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:record_id, :edited_by, :action, :field_name, NULL, :new_value)'
                    );
                    $newValues = [
                        'category' => '洗濯代行',
                        'facility_id' => $targetCycle['facility_id'],
                        'collection_cycle_id' => $cycleId,
                        'stage' => 'wash',
                        'person_count' => $personCount,
                        'record_date' => $now->format('Y-m-d'),
                        'record_time' => $now->format('H:i:s'),
                        'completed_at' => $now->format('Y-m-d H:i:s'),
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
                    $logStmt->execute([
                        ':record_id' => $newRecordId,
                        ':edited_by' => $staff['id'],
                        ':action' => 'create',
                        ':field_name' => 'employee_ids',
                        ':new_value' => implode(',', $employeeIds),
                    ]);

                    $pdo->commit();
                    set_flash('success', htmlspecialchars($targetCycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $targetCycle['pickup_date'] . '集荷分）の洗濯ネット数・人数を登録しました。返却袋数（青）は準備ができ次第、別途登録してください。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'register_return') {
            // 「6. 返却袋数（青）」の登録。洗濯ネット数（confirm_headcount）とは独立しており、
            // 発送（dispatch）の有無や洗濯ネット数登録の前後関係を問わず、到着済みならいつでも登録できる。
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);

            $openCycles = find_open_cycles($pdo);
            $openById = [];
            foreach ($openCycles as $cycle) {
                $openById[(int) $cycle['id']] = $cycle;
            }
            $targetCycle = $openById[$cycleId] ?? null;

            $now = new DateTime();
            $returnReadyBagCount = parse_return_bag_count($_POST['return_bag_count'] ?? '');
            $returnReadyDate = resolve_return_date($_POST['return_date'] ?? '', $now->format('Y-m-d'));
            $returnReadyTime = resolve_return_time($_POST['return_time'] ?? '', $now->format('H:i:s'));
            $returnReadyEmployeeId = resolve_return_employee_id($_POST['return_employee_id'] ?? '', (int) $staff['id'], $validEmployeeIds);

            if ($targetCycle === null || $targetCycle['arrival_bag_count'] === null) {
                $errorMessage = '対象のサイクルが無効です（未到着、または既に返却済みの可能性があります）。もう一度やり直してください。';
            } elseif ($targetCycle['return_ready_bag_count'] !== null) {
                $errorMessage = '対象のサイクルは既に返却袋数（青）を登録済みです。もう一度やり直してください。';
            } elseif ($returnReadyBagCount === null) {
                $errorMessage = '返却リネン袋数は0以上の整数を入力してください。';
            } elseif ($returnReadyDate === false || $returnReadyTime === false || $returnReadyEmployeeId === false) {
                $errorMessage = '登録日・時間・担当者の入力内容が正しくありません。';
            } else {
                $returnReadyAt = $returnReadyDate . ' ' . $returnReadyTime;

                try {
                    $pdo->beginTransaction();

                    $newValues = [
                        'return_ready_bag_count' => $returnReadyBagCount,
                        'return_ready_at' => $returnReadyAt,
                        'return_ready_employee_id' => $returnReadyEmployeeId,
                    ];

                    $logStmt = $pdo->prepare(
                        'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:cycle_id, :edited_by, :action, :field_name, NULL, :new_value)'
                    );
                    foreach (RETURN_READY_LOG_FIELDS as $field) {
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
                         SET return_ready_bag_count = :return_ready_bag_count,
                             return_ready_at = :return_ready_at,
                             return_ready_employee_id = :return_ready_employee_id
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        ':return_ready_bag_count' => $returnReadyBagCount,
                        ':return_ready_at' => $returnReadyAt,
                        ':return_ready_employee_id' => $returnReadyEmployeeId,
                        ':id' => $cycleId,
                    ]);

                    $pdo->commit();
                    set_flash('success', htmlspecialchars($targetCycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $targetCycle['pickup_date'] . '集荷分）の返却袋数（青、' . $returnReadyBagCount . '袋）を登録しました。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'edit_return_ready' || $action === 'delete_return_ready') {
            // 「返却袋数（青）」の修正・取消。洗濯ネット数（return_ready_laundry_net_count）は
            // 別ドメインのため、このアクションでは一切触れない。
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
            $cycleStmt = $pdo->prepare(
                'SELECT cc.id, cc.facility_id, cc.pickup_date, cc.return_ready_bag_count,
                        cc.return_ready_at, cc.return_ready_employee_id,
                        f.name AS facility_name
                 FROM collection_cycles cc
                 INNER JOIN facilities f ON f.id = cc.facility_id
                 WHERE cc.id = :id AND cc.deleted_at IS NULL AND cc.return_ready_bag_count IS NOT NULL'
            );
            $cycleStmt->execute([':id' => $cycleId]);
            $cycle = $cycleStmt->fetch();

            if ($cycle === false) {
                $errorMessage = '対象の返却準備記録が見つかりません。';
            } else {
                $newValues = [];
                if ($action === 'edit_return_ready') {
                    $returnReadyBagCount = parse_return_bag_count($_POST['return_bag_count'] ?? '');
                    $returnReadyDate = resolve_return_date($_POST['return_date'] ?? '', substr((string) $cycle['return_ready_at'], 0, 10));
                    $returnReadyTime = resolve_return_time($_POST['return_time'] ?? '', substr((string) $cycle['return_ready_at'], 11, 8));
                    $returnReadyEmployeeId = resolve_return_employee_id($_POST['return_employee_id'] ?? '', (int) $staff['id'], $validEmployeeIds);
                    if ($returnReadyBagCount === null) {
                        $errorMessage = '返却リネン袋数は0以上の整数を入力してください。';
                    } elseif ($returnReadyDate === false || $returnReadyDate === null || $returnReadyTime === false || $returnReadyTime === null || $returnReadyEmployeeId === false) {
                        $errorMessage = '登録日・時間・担当者の入力内容が正しくありません。';
                    } else {
                        $newValues = [
                            'return_ready_bag_count' => $returnReadyBagCount,
                            'return_ready_at' => $returnReadyDate . ' ' . $returnReadyTime,
                            'return_ready_employee_id' => $returnReadyEmployeeId,
                        ];
                    }
                } else {
                    $newValues = array_fill_keys(RETURN_READY_LOG_FIELDS, null);
                }

                if ($errorMessage === '') {
                    try {
                        $pdo->beginTransaction();
                        $logStmt = $pdo->prepare(
                            'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                             VALUES (:cycle_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                        );
                        foreach (RETURN_READY_LOG_FIELDS as $field) {
                            if ((string) ($cycle[$field] ?? '') === (string) ($newValues[$field] ?? '')) {
                                continue;
                            }
                            $logStmt->execute([
                                ':cycle_id' => $cycleId,
                                ':edited_by' => $staff['id'],
                                ':action' => $action === 'delete_return_ready' ? 'delete' : 'update',
                                ':field_name' => $field,
                                ':old_value' => $cycle[$field],
                                ':new_value' => $newValues[$field],
                            ]);
                        }
                        $updateStmt = $pdo->prepare(
                            'UPDATE collection_cycles
                             SET return_ready_bag_count = :bag_count,
                                 return_ready_at = :ready_at,
                                 return_ready_employee_id = :employee_id
                             WHERE id = :id'
                        );
                        $updateStmt->execute([
                            ':bag_count' => $newValues['return_ready_bag_count'],
                            ':ready_at' => $newValues['return_ready_at'],
                            ':employee_id' => $newValues['return_ready_employee_id'],
                            ':id' => $cycleId,
                        ]);
                        $pdo->commit();
                        set_flash('success', $action === 'delete_return_ready' ? '返却袋数（青）の記録を削除しました。' : '返却袋数（青）の記録を更新しました。');
                        header('Location: ' . $collectionHeadcountPath);
                        exit;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $errorMessage = '返却準備記録の更新に失敗しました。';
                    }
                }
            }
        } elseif ($action === 'delete_headcount') {
            // 「洗濯ネット数」確認記録の取消。返却袋数（青）系フィールドは別ドメインのため触れない
            // （分割前は削除時に道連れでNULLに戻していたが、それだと別担当者が既に登録した
            // 返却袋数を巻き込んで消してしまうため、対象をreturn_ready_laundry_net_countのみに限定する）。
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

                    $cycleStmt = $pdo->prepare('SELECT return_ready_laundry_net_count FROM collection_cycles WHERE id = :id');
                    $cycleStmt->execute([':id' => $record['collection_cycle_id']]);
                    $oldNetCount = $cycleStmt->fetchColumn();

                    $cycleLogStmt = $pdo->prepare(
                        'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:cycle_id, :edited_by, :action, :field_name, :old_value, NULL)'
                    );
                    $cycleLogStmt->execute([
                        ':cycle_id' => $record['collection_cycle_id'], ':edited_by' => $staff['id'],
                        ':action' => 'delete', ':field_name' => NET_COUNT_LOG_FIELD, ':old_value' => $oldNetCount,
                    ]);

                    $clearCycleStmt = $pdo->prepare(
                        'UPDATE collection_cycles SET return_ready_laundry_net_count = NULL WHERE id = :id'
                    );
                    $clearCycleStmt->execute([':id' => $record['collection_cycle_id']]);

                    $pdo->commit();
                    set_flash('success', '洗濯ネット数の確認記録を削除しました。対象のサイクルは未確認一覧に戻ります。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'edit_headcount') {
            // 「洗濯ネット数」確認記録の修正。返却袋数（青）系フィールドは別ドメインのため触れない。
            $recordId = (int) ($_POST['id'] ?? 0);
            [$values, $parseErrors] = parse_headcount_edit_input($_POST, $validEmployeeIds);
            $laundryNetCount = parse_laundry_net_count($_POST['laundry_net_count'] ?? '');
            if ($laundryNetCount === null) {
                $parseErrors[] = '洗濯ネット数は0以上の整数を入力してください。';
            }

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } else {
                $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NOT NULL AND deleted_at IS NULL');
                $recordStmt->execute([':id' => $recordId]);
                $record = $recordStmt->fetch();

                if ($record === false) {
                    $errorMessage = '対象の確認記録が見つかりません。';
                } else {
                    $oldEmployeeIdsStmt = $pdo->prepare('SELECT employee_id FROM work_stage_record_employees WHERE work_stage_record_id = :id ORDER BY employee_id');
                    $oldEmployeeIdsStmt->execute([':id' => $recordId]);
                    $oldEmployeeIds = array_map('intval', array_column($oldEmployeeIdsStmt->fetchAll(), 'employee_id'));
                    $newEmployeeIds = $values['employee_ids'];
                    sort($oldEmployeeIds);
                    $sortedNewEmployeeIds = $newEmployeeIds;
                    sort($sortedNewEmployeeIds);
                    $employeeIdsChanged = $oldEmployeeIds !== $sortedNewEmployeeIds;
                    // completed_atが変わった場合、既存参加者のstarted_atは古いcompleted_atを起点に
                    // 計算済みのため、参加者に変更が無くても開始時刻を計算し直す必要がある。
                    $completedAtChanged = (string) $values['completed_at'] !== (string) $record['completed_at'];
                    $employeesNeedRecompute = $employeeIdsChanged || $completedAtChanged;

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
                        if ($employeeIdsChanged) {
                            $logStmt->execute([
                                ':record_id' => $recordId,
                                ':edited_by' => $staff['id'],
                                ':action' => 'update',
                                ':field_name' => 'employee_ids',
                                ':old_value' => implode(',', $oldEmployeeIds),
                                ':new_value' => implode(',', $newEmployeeIds),
                            ]);
                            $changedCount++;
                        }

                        $updateStmt = $pdo->prepare(
                            'UPDATE work_stage_records SET person_count = :person_count, record_date = :record_date, record_time = :record_time, completed_at = :completed_at WHERE id = :id'
                        );
                        $updateStmt->execute([
                            ':person_count' => $values['person_count'],
                            ':record_date' => $values['record_date'],
                            ':record_time' => $values['record_time'],
                            ':completed_at' => $values['completed_at'],
                            ':id' => $recordId,
                        ]);

                        $cycleValuesStmt = $pdo->prepare(
                            'SELECT return_ready_laundry_net_count FROM collection_cycles WHERE id = :id'
                        );
                        $cycleValuesStmt->execute([':id' => $record['collection_cycle_id']]);
                        $oldNetCount = $cycleValuesStmt->fetchColumn();
                        if ((string) $oldNetCount !== (string) $laundryNetCount) {
                            $cycleLogStmt = $pdo->prepare(
                                'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                                 VALUES (:cycle_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                            );
                            $cycleLogStmt->execute([
                                ':cycle_id' => $record['collection_cycle_id'], ':edited_by' => $staff['id'],
                                ':action' => 'update', ':field_name' => NET_COUNT_LOG_FIELD,
                                ':old_value' => $oldNetCount, ':new_value' => $laundryNetCount,
                            ]);
                            $changedCount++;
                        }
                        $cycleUpdateStmt = $pdo->prepare(
                            'UPDATE collection_cycles SET return_ready_laundry_net_count = :net_count WHERE id = :id'
                        );
                        $cycleUpdateStmt->execute([':net_count' => $laundryNetCount, ':id' => $record['collection_cycle_id']]);

                        if ($employeesNeedRecompute) {
                            $deleteEmployeesStmt = $pdo->prepare('DELETE FROM work_stage_record_employees WHERE work_stage_record_id = :id');
                            $deleteEmployeesStmt->execute([':id' => $recordId]);
                            if (!empty($newEmployeeIds)) {
                                record_work_stage_employees($pdo, $recordId, $newEmployeeIds, new DateTime($values['completed_at']));
                            }
                        }

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '確認記録を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: ' . $collectionHeadcountPath);
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
        "SELECT wsr.*, f.name AS facility_name, cc.pickup_date,
                cc.return_ready_laundry_net_count
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

$editingRecordEmployeeIds = [];
if ($editingRecord !== null) {
    $editingEmployeeIdsStmt = $pdo->prepare('SELECT employee_id FROM work_stage_record_employees WHERE work_stage_record_id = :id');
    $editingEmployeeIdsStmt->execute([':id' => (int) $editingRecord['id']]);
    $editingRecordEmployeeIds = array_map('intval', array_column($editingEmployeeIdsStmt->fetchAll(), 'employee_id'));
}

$editFormValues = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && (string) ($_POST['action'] ?? '') === 'edit_headcount') {
    $editFormValues = [
        'id' => (int) ($_POST['id'] ?? 0),
        'employee_ids' => array_map('intval', (array) ($_POST['employee_ids'] ?? [])),
        'record_date' => (string) ($_POST['record_date'] ?? ''),
        'record_time' => (string) ($_POST['record_time'] ?? ''),
        'laundry_net_count' => (string) ($_POST['laundry_net_count'] ?? ''),
        'facility_name' => $editingRecord['facility_name'] ?? '',
        'pickup_date' => $editingRecord['pickup_date'] ?? '',
    ];
} elseif ($editingRecord !== null) {
    $editFormValues = [
        'id' => (int) $editingRecord['id'],
        'employee_ids' => $editingRecordEmployeeIds,
        'record_date' => $editingRecord['record_date'],
        'record_time' => $editingRecord['record_time'] !== null ? substr($editingRecord['record_time'], 0, 5) : '',
        'laundry_net_count' => (string) ($editingRecord['return_ready_laundry_net_count'] ?? 0),
        'facility_name' => $editingRecord['facility_name'],
        'pickup_date' => $editingRecord['pickup_date'],
    ];
}

$editingReturnReadyId = isset($_GET['return_edit']) ? (int) $_GET['return_edit'] : 0;
$editingReturnReady = null;
if ($editingReturnReadyId > 0) {
    $stmt = $pdo->prepare(
        'SELECT cc.id, cc.pickup_date, cc.return_ready_bag_count, cc.return_ready_at, cc.return_ready_employee_id,
                f.name AS facility_name
         FROM collection_cycles cc
         INNER JOIN facilities f ON f.id = cc.facility_id
         WHERE cc.id = :id AND cc.deleted_at IS NULL AND cc.return_ready_bag_count IS NOT NULL'
    );
    $stmt->execute([':id' => $editingReturnReadyId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingReturnReady = $row;
    }
}

// ---- メインテーブル（返却未完了の集荷サイクル。1サイクル＝1行） ----
$showHistory = isset($_GET['history']);
$tableCycles = $showHistory ? find_returned_cycles($pdo) : find_open_cycles($pdo);
$washedTotalsByFacility = find_washed_totals_by_facility($pdo);

// ---- 参加者名をまとめて取得（操作欄の編集リンク先で使う確認記録の把握用） ----
$participantsByWsrId = [];
$wsrIds = array_filter(array_map(static fn (array $c): ?int => $c['wsr_id'] !== null ? (int) $c['wsr_id'] : null, $tableCycles));
if (!empty($wsrIds)) {
    $placeholders = implode(',', array_fill(0, count($wsrIds), '?'));
    $participantsStmt = $pdo->prepare(
        "SELECT wse.work_stage_record_id, e.name
         FROM work_stage_record_employees wse
         INNER JOIN employees e ON e.id = wse.employee_id
         WHERE wse.work_stage_record_id IN ($placeholders)
         ORDER BY e.name"
    );
    $participantsStmt->execute(array_values($wsrIds));
    foreach ($participantsStmt->fetchAll() as $row) {
        $participantsByWsrId[(int) $row['work_stage_record_id']][] = $row['name'];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title><?= $isAdminView ? '洗濯ネット・返却リネン袋数 | 管理者' : '洗濯ネット・返却リネン袋数登録 | シフト管理' ?></title>
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
        .inline-form { display: inline; }

        table.cycles { border-collapse: collapse; width: 100%; }
        table.cycles th, table.cycles td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.cycles th { background: #f5f5f5; }
        table.cycles input[type="number"] { width: 80px; }
        table.cycles td.done { color: #1e7e34; }
        table.cycles form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    </style>
</head>
<body>
<header>
    <h1><?= $isAdminView ? '洗濯ネット・返却リネン袋数' : '洗濯ネット・返却リネン袋数登録' ?></h1>
    <nav><a href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>">ダッシュボードに戻る</a> | <a href="<?= htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8') ?>">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="manual-register-form">
    <h2>洗濯ネット・到着リネン袋数の登録</h2>
    <fieldset>
        <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="manual_register">
            <div class="form-row">
                <label for="manual_facility_id">施設</label>
                <select id="manual_facility_id" name="facility_id" required>
                    <option value="">選択してください</option>
                    <?php foreach ($manualFacilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>"><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label for="manual_pickup_date">集荷日</label><input type="date" id="manual_pickup_date" name="pickup_date" value="<?= (new DateTime())->format('Y-m-d') ?>" required></div>
            <div class="form-row"><label for="manual_arrival_bag_count">到着リネン袋数</label><input type="number" id="manual_arrival_bag_count" name="arrival_bag_count" min="0" step="1" required></div>
            <div class="form-row"><label for="manual_laundry_net_count">洗濯ネット数</label><input type="number" id="manual_laundry_net_count" name="laundry_net_count" min="0" step="1" required></div>
            <div class="form-row"><label for="manual_return_bag_count">返却リネン袋（青）数</label><input type="number" id="manual_return_bag_count" name="return_bag_count" min="0" step="1" required></div>
            <?php if ($isSharedAccount): ?>
                <div class="form-row">
                    <label for="manual_employee_ids">作業した従業員</label>
                    <select id="manual_employee_ids" name="employee_ids[]" multiple size="<?= max(3, min(8, count($employees))) ?>" required>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>複数人を選択できます。</small>
                </div>
            <?php endif; ?>
            <button type="submit">登録する</button>
        </form>
    </fieldset>
</section>

<?php if ($editFormValues !== null): ?>
<section class="edit-form">
    <h2>洗濯ネット数確認記録の修正（<?= htmlspecialchars($editFormValues['facility_name'], ENT_QUOTES, 'UTF-8') ?>　<?= htmlspecialchars($editFormValues['pickup_date'], ENT_QUOTES, 'UTF-8') ?>集荷分）</h2>
    <fieldset>
        <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_headcount">
            <input type="hidden" name="id" value="<?= (int) $editFormValues['id'] ?>">

            <div class="form-row">
                <label for="e_employee_ids">参加した従業員</label>
                <select id="e_employee_ids" name="employee_ids[]" multiple size="<?= max(2, min(6, count($employees))) ?>">
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= in_array((int) $employee['id'], $editFormValues['employee_ids'], true) ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="e_record_date">確認日</label>
                <input type="date" id="e_record_date" name="record_date" value="<?= htmlspecialchars($editFormValues['record_date'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-row">
                <label for="e_record_time">確認時刻</label>
                <input type="time" id="e_record_time" name="record_time" value="<?= htmlspecialchars($editFormValues['record_time'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-row">
                <label for="e_laundry_net_count">洗濯ネット数</label>
                <input type="number" id="e_laundry_net_count" name="laundry_net_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['laundry_net_count'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit">更新する</button>
            <a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
        </form>
    </fieldset>
</section>
<?php endif; ?>

<?php if ($editingReturnReady !== null): ?>
<section class="edit-form">
    <h2>返却袋数（青）の修正（<?= htmlspecialchars($editingReturnReady['facility_name'], ENT_QUOTES, 'UTF-8') ?>　<?= htmlspecialchars($editingReturnReady['pickup_date'], ENT_QUOTES, 'UTF-8') ?>集荷分）</h2>
    <fieldset>
        <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_return_ready">
            <input type="hidden" name="cycle_id" value="<?= (int) $editingReturnReady['id'] ?>">
            <div class="form-row"><label for="rr_bag_count">返却袋数（青）</label><input type="number" id="rr_bag_count" name="return_bag_count" min="0" step="1" value="<?= (int) $editingReturnReady['return_ready_bag_count'] ?>" required></div>
            <div class="form-row"><label for="rr_date">登録日</label><input type="date" id="rr_date" name="return_date" value="<?= htmlspecialchars(substr($editingReturnReady['return_ready_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-row"><label for="rr_time">登録時間</label><input type="time" id="rr_time" name="return_time" value="<?= htmlspecialchars(substr($editingReturnReady['return_ready_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-row"><label for="rr_employee">担当者</label><select id="rr_employee" name="return_employee_id"><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === (int) $editingReturnReady['return_ready_employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            <button type="submit">更新する</button>
            <a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
        </form>
    </fieldset>
</section>
<?php endif; ?>

<section class="cycle-list">
    <h2><?= $showHistory ? '過去の履歴（返却完了分）' : '集荷サイクル一覧（返却未完了）' ?></h2>
    <p><a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?><?= $showHistory ? '' : '?history=1' ?>"><?= $showHistory ? '未完了一覧に戻る' : '過去の履歴を表示' ?></a></p>
    <p class="notice">この記録簿はチーム共有のため、確認した本人以外でも修正・削除できます。変更内容は履歴に記録されます。</p>

    <?php if (empty($tableCycles)): ?>
        <p class="notice"><?= $showHistory ? '返却完了の記録はまだありません。' : '対応が必要な集荷サイクルはありません。' ?></p>
    <?php else: ?>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設名</th>
                    <th>集荷日</th>
                    <th>集荷（到着）リネン袋（オレンジ）数</th>
                    <th>洗濯ネット数</th>
                    <th>作業完了数</th>
                    <th>未処理数</th>
                    <th>返却リネン袋（青）数</th>
                    <th>返却予定日</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableCycles as $cycle): ?>
                    <?php
                    $cycleId = (int) $cycle['id'];
                    $facilityId = (int) $cycle['facility_id'];
                    $washed = $washedTotalsByFacility[$facilityId] ?? 0;
                    $netCount = $cycle['return_ready_laundry_net_count'] !== null ? (int) $cycle['return_ready_laundry_net_count'] : 0;
                    $notCompleted = max(0, $netCount - $washed);
                    $expectedReturnDate = calc_expected_return_date($cycle['pickup_date'], $cycle['facility_pickup_schedule']);
                    $participants = $participantsByWsrId[(int) ($cycle['wsr_id'] ?? 0)] ?? [];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= $cycle['arrival_bag_count'] !== null ? 'done' : '' ?>"><?= $cycle['arrival_bag_count'] !== null ? (int) $cycle['arrival_bag_count'] . '袋' : '-' ?></td>
                        <td class="<?= $cycle['return_ready_laundry_net_count'] !== null ? 'done' : '' ?>">
                            <?php if ($cycle['return_ready_laundry_net_count'] !== null): ?>
                                <?= (int) $cycle['return_ready_laundry_net_count'] ?>枚 ✓
                                <?php if ($cycle['wsr_id'] !== null): ?>
                                    <br><small><a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>?edit=<?= (int) $cycle['wsr_id'] ?>">編集</a></small>
                                <?php endif; ?>
                            <?php elseif ($cycle['arrival_bag_count'] === null): ?>
                                （到着後に入力可）
                            <?php else: ?>
                                <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="confirm_headcount">
                                    <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                                    <input type="number" name="laundry_net_count" min="0" step="1" required placeholder="枚数">
                                    <?php if ($isSharedAccount || $isAdminView): ?>
                                        <select name="employee_ids[]" multiple size="<?= max(2, min(4, count($employees))) ?>" required>
                                            <?php foreach ($employees as $employee): ?>
                                                <option value="<?= (int) $employee['id'] ?>" <?= !$isSharedAccount && (int) $employee['id'] === (int) $staff['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="employee_ids[]" value="<?= (int) $staff['id'] ?>">
                                    <?php endif; ?>
                                    <button type="submit">確認して登録</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?= $washed ?>枚</td>
                        <td><?= $notCompleted ?>枚</td>
                        <td class="<?= $cycle['return_ready_bag_count'] !== null ? 'done' : '' ?>">
                            <?php if ($cycle['return_ready_bag_count'] !== null): ?>
                                <?= (int) $cycle['return_ready_bag_count'] ?>袋 ✓
                                <br><small><a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>?return_edit=<?= $cycleId ?>">編集</a></small>
                            <?php elseif ($cycle['arrival_bag_count'] === null): ?>
                                （到着後に入力可）
                            <?php else: ?>
                                <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="register_return">
                                    <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                                    <input type="number" name="return_bag_count" min="0" step="1" required placeholder="袋数">
                                    <button type="submit">返却登録</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?= $expectedReturnDate !== null ? htmlspecialchars($expectedReturnDate, ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td>
                            <?php if ($cycle['wsr_id'] !== null): ?>
                                <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この確認記録を削除しますか？対象のサイクルは未確認一覧に戻ります。');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete_headcount">
                                    <input type="hidden" name="id" value="<?= (int) $cycle['wsr_id'] ?>">
                                    <button type="submit" title="洗濯ネット数の確認記録を削除（参加者: <?= !empty($participants) ? htmlspecialchars(implode('・', $participants), ENT_QUOTES, 'UTF-8') : '-' ?>）">ネット数取消</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($cycle['return_ready_bag_count'] !== null): ?>
                                <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この返却袋数（青）の記録を削除しますか？');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete_return_ready">
                                    <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                                    <button type="submit">返却袋数取消</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

</body>
</html>
