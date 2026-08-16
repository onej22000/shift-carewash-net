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

// 「作業登録」（洗濯・乾燥・畳みの作業実績、collection_cycle_id直結）で新規作成・修正する
// work_stage_recordsのフィールド一覧（監査ログ用）。employee_idsはwork_stage_records自体の
// カラムではなくwork_stage_record_employees側の参加者一覧のため、汎用の差分ログ処理
// （$recordのカラム値と比較する処理）とは別に個別でログを記録する。
const WORK_LOG_FIELDS = ['category', 'facility_id', 'collection_cycle_id', 'stage', 'person_count', 'record_date', 'record_time', 'completed_at'];
// 修正時にユーザーが変更できるのはこの項目のみ（施設・工程・紐づくサイクルは作業登録の性質上変えない）。
// person_count（洗濯完了ネット数＝枚数）はフォームで独立入力する。employee_ids（参加者）は
// person_countとは無関係な別ロジックで扱う。completed_atはrecord_date・record_timeから算出する。
const WORK_EDITABLE_FIELDS = ['person_count', 'record_date', 'record_time', 'completed_at'];

// 洗濯ネット数（到着したリネン袋内の枚数確認）・作業登録（洗濯した人数の記録）・返却リネン袋（青）数
// （返却梱包の準備）は、別々のタイミングで別々の担当者が登録できるよう、それぞれ独立した
// フィールド群として扱う（2026-08-13、confirm_headcountとregister_returnを分割／
// 2026-08-14、洗濯ネット数と作業登録＝旧confirm_headcountの人数選択部分をさらに分割）。
const NET_COUNT_LOG_FIELD = 'return_ready_laundry_net_count';
const RETURN_READY_LOG_FIELDS = ['return_ready_bag_count', 'return_ready_at', 'return_ready_employee_id'];

// 「作業実績一覧」（旧admin/work_stage_records.php、2026-08-14統合)。collection_cycle_idを
// 持たない単独のwork_stage_records（管理者による例外的な直接登録・修正・削除）を、
// admin専用（$isAdminView）セクションとしてこのページの末尾に統合する。移設元と同じく、
// 区分（category）・工程（stage）は選択させず固定値で登録する。
const WSR_STANDALONE_CATEGORY = '洗濯代行';
const WSR_STANDALONE_STAGE = 'wash';
const WSR_STANDALONE_STAGE_LABEL = '洗濯';
const WSR_STANDALONE_EDITABLE_FIELDS = ['employee_id', 'category', 'facility_id', 'stage', 'person_count', 'record_date', 'record_time', 'completed_at'];

function parse_work_stage_record_input(array $post, array $validEmployeeIds, array $validFacilityIds): array
{
    $employeeId = (int) ($post['employee_id'] ?? 0);
    $facilityId = (int) ($post['facility_id'] ?? 0);
    $participantIds = [];
    foreach ((array) ($post['employee_ids'] ?? []) as $rawEmployeeId) {
        $participantId = (int) $rawEmployeeId;
        if (in_array($participantId, $validEmployeeIds, true)) {
            $participantIds[] = $participantId;
        }
    }
    $participantIds = array_values(array_unique($participantIds));
    $laundryNetCountRaw = trim((string) ($post['laundry_net_count'] ?? ''));
    $personCount = filter_var($laundryNetCountRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
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
    if (!in_array($employeeId, $validEmployeeIds, true)) {
        $errors[] = '従業員を選択してください。';
    }
    if (!in_array($facilityId, $validFacilityIds, true)) {
        $errors[] = '施設を選択してください。';
    }
    if ($personCount === false) {
        $errors[] = '洗濯ネット数は0以上の整数を入力してください。';
    }
    if ($recordDate === false || $recordDate === null) {
        $errors[] = '作業日の形式が正しくありません。';
    }
    if ($recordTime === false || $recordTime === null) {
        $errors[] = '作業時刻の形式が正しくありません。';
    }

    $completedAt = ($recordDate !== null && $recordDate !== false && $recordTime !== null && $recordTime !== false)
        ? $recordDate . ' ' . $recordTime
        : null;

    return [
        [
            'employee_id' => $employeeId,
            'category' => WSR_STANDALONE_CATEGORY,
            'facility_id' => $facilityId,
            'stage' => WSR_STANDALONE_STAGE,
            'person_count' => $personCount,
            'record_date' => $recordDate,
            'record_time' => $recordTime,
            'completed_at' => $completedAt,
            'employee_ids' => $participantIds,
        ],
        $errors,
    ];
}

/**
 * 返却未完了（return_bag_count IS NULL）の集荷サイクルを、施設（集荷曜日設定含む）・作業実績
 * （work_stage_records、集荷サイクルに紐づく確認記録）・返却準備完了の担当者名まで含めて
 * 1クエリで取得する。1サイクル＝1行のメインテーブルで、集荷→到着→洗濯ネット数→返却リネン袋数
 * の流れをすべてこの1行から組み立てる。
 *
 * pickup_bag_count IS NULLのサイクル（空袋・空ネットの納品のみで実集荷が発生していないもの）は
 * 到着（arrival）自体が発生せず返却袋数も確定しないため無期限にこの一覧へ残ってしまう。
 * データとしては削除しない（在庫トランザクション等が紐づく）が、この一覧の表示対象からは除外する。
 *
 * f.onboarding_start_dateは施設パネル一覧（2026-08-14）の「受託開始日から最初の集荷曜日が
 * まだ来ていない施設を除外する」判定に使う。f.facility_type != 'クリーニング所'は、
 * collection_cycles.facility_idは常に集荷元（介護施設・自社）でクリーニング所にはならない
 * という既存の不変条件を、念のため明示的に保証するもの（施設パネル一覧がクリーニング所を
 * 対象外とする要件のため）。
 */
function find_open_cycles(PDO $pdo): array
{
    $sql = "SELECT cc.*, f.name AS facility_name, f.pickup_schedule AS facility_pickup_schedule,
                   f.onboarding_start_date AS facility_onboarding_start_date,
                   f.issued_linen_bag_orange AS facility_issued_bag_orange,
                   f.issued_linen_bag_yellow AS facility_issued_bag_yellow,
                   wsr.id AS wsr_id, wsr.person_count AS wsr_person_count,
                   wsr.record_date AS wsr_record_date, wsr.record_time AS wsr_record_time,
                   wsr.completed_at AS wsr_completed_at,
                   rre.name AS return_ready_employee_name
            FROM collection_cycles cc
            INNER JOIN facilities f ON f.id = cc.facility_id
            LEFT JOIN work_stage_records wsr
                   ON wsr.collection_cycle_id = cc.id AND wsr.deleted_at IS NULL
            LEFT JOIN employees rre ON rre.id = cc.return_ready_employee_id
            WHERE cc.return_bag_count IS NULL AND cc.pickup_bag_count IS NOT NULL AND cc.deleted_at IS NULL
                  AND f.facility_type != 'クリーニング所'
            ORDER BY cc.pickup_date ASC, cc.id ASC
            LIMIT 200";
    return $pdo->query($sql)->fetchAll();
}

/**
 * 返却が完了した（return_bag_count入力済みの）集荷サイクルの履歴を、直近順に返す。
 */
function find_returned_cycles(PDO $pdo): array
{
    $sql = 'SELECT cc.*, f.name AS facility_name, f.pickup_schedule AS facility_pickup_schedule,
                   f.issued_linen_bag_orange AS facility_issued_bag_orange,
                   f.issued_linen_bag_yellow AS facility_issued_bag_yellow,
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

function parse_work_edit_input(array $post, array $validEmployeeIds): array
{
    $employeeIds = [];
    foreach ((array) ($post['employee_ids'] ?? []) as $rawEmployeeId) {
        $employeeId = (int) $rawEmployeeId;
        if (in_array($employeeId, $validEmployeeIds, true)) {
            $employeeIds[] = $employeeId;
        }
    }
    $employeeIds = array_values(array_unique($employeeIds));
    $personCount = parse_laundry_net_count($post['net_count'] ?? '');

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
        $errors[] = '洗濯完了ネット数は0以上の整数を入力してください。';
    }
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

// 「作業実績一覧」（admin専用セクション）の期間フィルタ。POSTのリダイレクト先でも使うため、
// アクション処理より前に確定させる。
$wsrPeriodLabels = [
    'all' => '全期間',
    '7' => '直近7日',
    '30' => '直近30日',
];
$wsrPeriod = (string) ($_GET['period'] ?? '30');
if (!isset($wsrPeriodLabels[$wsrPeriod])) {
    $wsrPeriod = '30';
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'register_net_count') {
            // 「2. 洗濯ネット数」の登録。作業登録（complete_work）・返却リネン袋数とは完全に独立しており、
            // 従業員選択は行わない（誰が数えたかではなく、到着した数量そのものの確認のため）。
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
            $laundryNetCount = parse_laundry_net_count($_POST['laundry_net_count'] ?? '');

            // 再表示までの間に他のスタッフが登録済みにした可能性があるため、直前に候補を再取得して検証する。
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
            } else {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare('UPDATE collection_cycles SET return_ready_laundry_net_count = :net_count WHERE id = :id')
                        ->execute([':net_count' => $laundryNetCount, ':id' => $cycleId]);

                    $pdo->prepare(
                        'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:cycle_id, :edited_by, "update", :field_name, NULL, :new_value)'
                    )->execute([
                        ':cycle_id' => $cycleId, ':edited_by' => $staff['id'],
                        ':field_name' => NET_COUNT_LOG_FIELD, ':new_value' => $laundryNetCount,
                    ]);

                    $pdo->commit();
                    set_flash('success', htmlspecialchars($targetCycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $targetCycle['pickup_date'] . '集荷分）の洗濯ネット数（' . $laundryNetCount . '枚）を登録しました。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'edit_net_count' || $action === 'delete_net_count') {
            // 「洗濯ネット数」の修正・取消。作業登録（work_stage_records）・返却リネン袋数は
            // 別ドメインのため、このアクションでは一切触れない。
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
            $cycleStmt = $pdo->prepare(
                'SELECT cc.id, cc.facility_id, cc.pickup_date, cc.return_ready_laundry_net_count, f.name AS facility_name
                 FROM collection_cycles cc
                 INNER JOIN facilities f ON f.id = cc.facility_id
                 WHERE cc.id = :id AND cc.deleted_at IS NULL AND cc.return_ready_laundry_net_count IS NOT NULL'
            );
            $cycleStmt->execute([':id' => $cycleId]);
            $cycle = $cycleStmt->fetch();

            if ($cycle === false) {
                $errorMessage = '対象の洗濯ネット数記録が見つかりません。';
            } else {
                $newValue = null;
                if ($action === 'edit_net_count') {
                    $newValue = parse_laundry_net_count($_POST['laundry_net_count'] ?? '');
                    if ($newValue === null) {
                        $errorMessage = '洗濯ネット数は0以上の整数を入力してください。';
                    }
                }

                if ($errorMessage === '') {
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare(
                            'INSERT INTO collection_cycle_edit_logs (collection_cycle_id, edited_by, action, field_name, old_value, new_value)
                             VALUES (:cycle_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                        )->execute([
                            ':cycle_id' => $cycleId, ':edited_by' => $staff['id'],
                            ':action' => $action === 'delete_net_count' ? 'delete' : 'update',
                            ':field_name' => NET_COUNT_LOG_FIELD,
                            ':old_value' => $cycle['return_ready_laundry_net_count'], ':new_value' => $newValue,
                        ]);
                        $pdo->prepare('UPDATE collection_cycles SET return_ready_laundry_net_count = :net_count WHERE id = :id')
                            ->execute([':net_count' => $newValue, ':id' => $cycleId]);
                        $pdo->commit();
                        set_flash('success', $action === 'delete_net_count' ? '洗濯ネット数の記録を削除しました。' : '洗濯ネット数を更新しました。');
                        header('Location: ' . $collectionHeadcountPath);
                        exit;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $errorMessage = '更新に失敗しました。もう一度お試しください。';
                    }
                }
            }
        } elseif ($action === 'complete_work') {
            // 「3. 作業登録」（新設）。このサイクル（collection_cycle_id）に直接紐付けて
            // work_stage_recordsに記録する。洗濯ネット数・返却リネン袋数とは独立しており、
            // どちらが先でも後でも登録できる。
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
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
            $personCount = parse_laundry_net_count($_POST['net_count'] ?? '');

            // 再表示までの間に他のスタッフが登録済みにした可能性があるため、直前に候補を再取得して検証する。
            $openCycles = find_open_cycles($pdo);
            $openById = [];
            foreach ($openCycles as $cycle) {
                $openById[(int) $cycle['id']] = $cycle;
            }
            $targetCycle = $openById[$cycleId] ?? null;

            if ($targetCycle === null || $targetCycle['arrival_bag_count'] === null) {
                $errorMessage = '対象のサイクルが無効です（未到着、または既に返却済みの可能性があります）。もう一度やり直してください。';
            } elseif ($targetCycle['wsr_id'] !== null) {
                $errorMessage = '対象のサイクルは既に作業登録済みです。もう一度やり直してください。';
            } elseif (empty($employeeIds)) {
                $errorMessage = '作業した従業員を1人以上選択してください。';
            } elseif ($personCount === null) {
                $errorMessage = '洗濯完了ネット数は0以上の整数を入力してください。';
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

                    record_work_stage_employees($pdo, $newRecordId, $employeeIds, $now);

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
                    foreach (WORK_LOG_FIELDS as $field) {
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
                    set_flash('success', htmlspecialchars($targetCycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $targetCycle['pickup_date'] . '集荷分）の作業登録（洗濯完了ネット数' . $personCount . '枚）を行いました。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'register_return') {
            // 「4. 返却リネン袋（青）数」の登録。洗濯ネット数・作業登録とは独立しており、
            // 発送（dispatch）の有無や他ステージの前後関係を問わず、到着済みならいつでも登録できる。
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
                $errorMessage = '対象のサイクルは既に返却リネン袋数を登録済みです。もう一度やり直してください。';
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
                    set_flash('success', htmlspecialchars($targetCycle['facility_name'], ENT_QUOTES, 'UTF-8') . '（' . $targetCycle['pickup_date'] . '集荷分）の返却リネン袋数（' . $returnReadyBagCount . '袋）を登録しました。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'edit_return_ready' || $action === 'delete_return_ready') {
            // 「返却リネン袋数」の修正・取消。洗濯ネット数（return_ready_laundry_net_count）は
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
                        set_flash('success', $action === 'delete_return_ready' ? '返却リネン袋数の記録を削除しました。' : '返却リネン袋数の記録を更新しました。');
                        header('Location: ' . $collectionHeadcountPath);
                        exit;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $errorMessage = '返却準備記録の更新に失敗しました。';
                    }
                }
            }
        } elseif ($action === 'delete_work') {
            // 「作業登録」の取消。洗濯ネット数（return_ready_laundry_net_count）は
            // 別ドメインのため、このアクションでは一切触れない。
            $recordId = (int) ($_POST['id'] ?? 0);
            $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NOT NULL AND deleted_at IS NULL');
            $recordStmt->execute([':id' => $recordId]);
            $record = $recordStmt->fetch();

            if ($record === false) {
                $errorMessage = '対象の作業登録が見つかりません。';
            } else {
                try {
                    $pdo->beginTransaction();

                    $logStmt = $pdo->prepare(
                        'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:record_id, :edited_by, :action, :field_name, :old_value, NULL)'
                    );
                    foreach (WORK_LOG_FIELDS as $field) {
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
                    set_flash('success', '作業登録を削除しました。');
                    header('Location: ' . $collectionHeadcountPath);
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        } elseif ($action === 'edit_work') {
            // 「作業登録」の修正。洗濯ネット数は別ドメインのため触れない。
            $recordId = (int) ($_POST['id'] ?? 0);
            [$values, $parseErrors] = parse_work_edit_input($_POST, $validEmployeeIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } else {
                $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NOT NULL AND deleted_at IS NULL');
                $recordStmt->execute([':id' => $recordId]);
                $record = $recordStmt->fetch();

                if ($record === false) {
                    $errorMessage = '対象の作業登録が見つかりません。';
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
                        foreach (WORK_EDITABLE_FIELDS as $field) {
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

                        if ($employeesNeedRecompute) {
                            $deleteEmployeesStmt = $pdo->prepare('DELETE FROM work_stage_record_employees WHERE work_stage_record_id = :id');
                            $deleteEmployeesStmt->execute([':id' => $recordId]);
                            if (!empty($newEmployeeIds)) {
                                record_work_stage_employees($pdo, $recordId, $newEmployeeIds, new DateTime($values['completed_at']));
                            }
                        }

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '作業登録を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: ' . $collectionHeadcountPath);
                        exit;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $errorMessage = '保存に失敗しました。もう一度お試しください。';
                    }
                }
            }
        } elseif ($isAdminView && ($action === 'create_standalone_record' || $action === 'update_standalone_record')) {
            // 「作業実績一覧」（旧admin/work_stage_records.php）の新規登録・修正。
            // collection_cycle_idを持たない単独のwork_stage_recordsのみを対象とする。
            [$values, $parseErrors] = parse_work_stage_record_input($_POST, $validEmployeeIds, $validManualFacilityIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } elseif ($action === 'create_standalone_record') {
                try {
                    $pdo->beginTransaction();

                    $insertStmt = $pdo->prepare(
                        'INSERT INTO work_stage_records (employee_id, category, facility_id, stage, person_count, record_date, record_time, completed_at)
                         VALUES (:employee_id, :category, :facility_id, :stage, :person_count, :record_date, :record_time, :completed_at)'
                    );
                    $insertStmt->execute([
                        ':employee_id' => $values['employee_id'],
                        ':category' => $values['category'],
                        ':facility_id' => $values['facility_id'],
                        ':stage' => $values['stage'],
                        ':person_count' => $values['person_count'],
                        ':record_date' => $values['record_date'],
                        ':record_time' => $values['record_time'],
                        ':completed_at' => $values['completed_at'],
                    ]);
                    $newRecordId = (int) $pdo->lastInsertId();

                    if (!empty($values['employee_ids'])) {
                        record_work_stage_employees($pdo, $newRecordId, $values['employee_ids'], new DateTime($values['completed_at']));
                    }

                    $logStmt = $pdo->prepare(
                        'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:record_id, :edited_by, :action, :field_name, NULL, :new_value)'
                    );
                    foreach (WSR_STANDALONE_EDITABLE_FIELDS as $field) {
                        $logStmt->execute([
                            ':record_id' => $newRecordId,
                            ':edited_by' => $staff['id'],
                            ':action' => 'create',
                            ':field_name' => $field,
                            ':new_value' => $values[$field],
                        ]);
                    }
                    $logStmt->execute([
                        ':record_id' => $newRecordId,
                        ':edited_by' => $staff['id'],
                        ':action' => 'create',
                        ':field_name' => 'employee_ids',
                        ':new_value' => implode(',', $values['employee_ids']),
                    ]);

                    $pdo->commit();
                    set_flash('success', '作業実績を登録しました。');
                    header('Location: ' . $collectionHeadcountPath . '?period=' . urlencode($wsrPeriod));
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。もう一度お試しください。';
                }
            } else {
                $recordId = (int) ($_POST['id'] ?? 0);
                $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NULL AND deleted_at IS NULL');
                $recordStmt->execute([':id' => $recordId]);
                $record = $recordStmt->fetch();

                if ($record === false) {
                    $errorMessage = '対象の作業実績が見つかりません。';
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
                        foreach (WSR_STANDALONE_EDITABLE_FIELDS as $field) {
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
                            'UPDATE work_stage_records
                             SET employee_id = :employee_id, category = :category, facility_id = :facility_id,
                                 stage = :stage, person_count = :person_count, record_date = :record_date, record_time = :record_time,
                                 completed_at = :completed_at
                             WHERE id = :id'
                        );
                        $updateStmt->execute([
                            ':employee_id' => $values['employee_id'],
                            ':category' => $values['category'],
                            ':facility_id' => $values['facility_id'],
                            ':stage' => $values['stage'],
                            ':person_count' => $values['person_count'],
                            ':record_date' => $values['record_date'],
                            ':record_time' => $values['record_time'],
                            ':completed_at' => $values['completed_at'],
                            ':id' => $recordId,
                        ]);

                        if ($employeesNeedRecompute) {
                            $deleteEmployeesStmt = $pdo->prepare('DELETE FROM work_stage_record_employees WHERE work_stage_record_id = :id');
                            $deleteEmployeesStmt->execute([':id' => $recordId]);
                            if (!empty($newEmployeeIds)) {
                                record_work_stage_employees($pdo, $recordId, $newEmployeeIds, new DateTime($values['completed_at']));
                            }
                        }

                        $pdo->commit();
                        set_flash('success', $changedCount > 0
                            ? '作業実績を修正しました（' . $changedCount . '件のフィールドを変更）。'
                            : '変更点はありませんでした。');
                        header('Location: ' . $collectionHeadcountPath . '?period=' . urlencode($wsrPeriod));
                        exit;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $errorMessage = '保存に失敗しました。もう一度お試しください。';
                    }
                }
            }
        } elseif ($isAdminView && $action === 'delete_standalone_record') {
            // 「作業実績一覧」の削除。collection_cycle_idを持つ作業登録はdelete_workが別途扱う。
            $recordId = (int) ($_POST['id'] ?? 0);
            $recordStmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NULL AND deleted_at IS NULL');
            $recordStmt->execute([':id' => $recordId]);
            $record = $recordStmt->fetch();

            if ($record === false) {
                $errorMessage = '対象の作業実績が見つかりません。';
            } else {
                try {
                    $pdo->beginTransaction();

                    $logStmt = $pdo->prepare(
                        'INSERT INTO work_stage_record_edit_logs (work_stage_record_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:record_id, :edited_by, :action, :field_name, :old_value, NULL)'
                    );
                    foreach (WSR_STANDALONE_EDITABLE_FIELDS as $field) {
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
                    set_flash('success', '作業実績を削除しました。');
                    header('Location: ' . $collectionHeadcountPath . '?period=' . urlencode($wsrPeriod));
                    exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。もう一度お試しください。';
                }
            }
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

// ---- 「作業実績一覧」（admin専用、collection_cycle_id無し）の編集対象の読み込み ----
// 既存の?edit=はcollection_cycle_idを持つ「作業登録」（edit_work）専用のため、
// 混同しないよう別パラメータ（?wsr_edit=）を使う。
$editingStandaloneRecord = null;
if ($isAdminView && isset($_GET['wsr_edit'])) {
    $wsrEditId = (int) $_GET['wsr_edit'];
    $stmt = $pdo->prepare('SELECT * FROM work_stage_records WHERE id = :id AND collection_cycle_id IS NULL AND deleted_at IS NULL');
    $stmt->execute([':id' => $wsrEditId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingStandaloneRecord = $row;
    }
}

$standaloneFormAction = 'create';
$standaloneFormId = null;
$standaloneFormEmployeeId = '';
$standaloneFormFacilityId = '';
$standaloneFormEmployeeIds = [];
$standaloneFormLaundryNetCount = '0';
$standaloneFormRecordDate = (new DateTime())->format('Y-m-d');
$standaloneFormRecordTime = (new DateTime())->format('H:i');

if ($isAdminView) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && in_array((string) ($_POST['action'] ?? ''), ['create_standalone_record', 'update_standalone_record'], true)) {
        $standaloneFormAction = (string) $_POST['action'] === 'update_standalone_record' ? 'update' : 'create';
        $standaloneFormId = $standaloneFormAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
        $standaloneFormEmployeeId = (string) ($_POST['employee_id'] ?? '');
        $standaloneFormFacilityId = (string) ($_POST['facility_id'] ?? '');
        $standaloneFormEmployeeIds = array_map('intval', (array) ($_POST['employee_ids'] ?? []));
        $standaloneFormLaundryNetCount = (string) ($_POST['laundry_net_count'] ?? '0');
        $standaloneFormRecordDate = (string) ($_POST['record_date'] ?? '');
        $standaloneFormRecordTime = (string) ($_POST['record_time'] ?? '');
    } elseif ($editingStandaloneRecord !== null) {
        $standaloneFormAction = 'update';
        $standaloneFormId = (int) $editingStandaloneRecord['id'];
        $standaloneFormEmployeeId = (string) $editingStandaloneRecord['employee_id'];
        $standaloneFormFacilityId = (string) $editingStandaloneRecord['facility_id'];
        $editingStandaloneEmployeeIdsStmt = $pdo->prepare('SELECT employee_id FROM work_stage_record_employees WHERE work_stage_record_id = :id');
        $editingStandaloneEmployeeIdsStmt->execute([':id' => (int) $editingStandaloneRecord['id']]);
        $standaloneFormEmployeeIds = array_map('intval', array_column($editingStandaloneEmployeeIdsStmt->fetchAll(), 'employee_id'));
        $standaloneFormLaundryNetCount = (string) $editingStandaloneRecord['person_count'];
        $standaloneFormRecordDate = $editingStandaloneRecord['record_date'];
        $standaloneFormRecordTime = $editingStandaloneRecord['record_time'] !== null ? substr($editingStandaloneRecord['record_time'], 0, 5) : (new DateTime())->format('H:i');
    }
}

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && (string) ($_POST['action'] ?? '') === 'edit_work') {
    $editFormValues = [
        'id' => (int) ($_POST['id'] ?? 0),
        'employee_ids' => array_map('intval', (array) ($_POST['employee_ids'] ?? [])),
        'net_count' => (string) ($_POST['net_count'] ?? ''),
        'record_date' => (string) ($_POST['record_date'] ?? ''),
        'record_time' => (string) ($_POST['record_time'] ?? ''),
        'facility_name' => $editingRecord['facility_name'] ?? '',
        'pickup_date' => $editingRecord['pickup_date'] ?? '',
    ];
} elseif ($editingRecord !== null) {
    $editFormValues = [
        'id' => (int) $editingRecord['id'],
        'employee_ids' => $editingRecordEmployeeIds,
        'net_count' => (string) $editingRecord['person_count'],
        'record_date' => $editingRecord['record_date'],
        'record_time' => $editingRecord['record_time'] !== null ? substr($editingRecord['record_time'], 0, 5) : '',
        'facility_name' => $editingRecord['facility_name'],
        'pickup_date' => $editingRecord['pickup_date'],
    ];
}

// ---- 洗濯ネット数の修正対象の読み込み ----
$editingNetCountId = isset($_GET['edit_net']) ? (int) $_GET['edit_net'] : 0;
$editingNetCount = null;
if ($editingNetCountId > 0) {
    $stmt = $pdo->prepare(
        'SELECT cc.id, cc.pickup_date, cc.return_ready_laundry_net_count, f.name AS facility_name
         FROM collection_cycles cc
         INNER JOIN facilities f ON f.id = cc.facility_id
         WHERE cc.id = :id AND cc.deleted_at IS NULL AND cc.return_ready_laundry_net_count IS NOT NULL'
    );
    $stmt->execute([':id' => $editingNetCountId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingNetCount = $row;
    }
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

// ---- カード一覧（返却未完了の集荷サイクル。1サイクル＝1カード） ----
$showHistory = isset($_GET['history']);
$cardCycles = $showHistory ? find_returned_cycles($pdo) : find_open_cycles($pdo);

// ---- 施設パネル用グループ化（返却未完了一覧のみ。履歴表示では使わない） ----
// グルーピング・並び順・除外ロジックはgroup_open_cycles_into_facility_panels()
// （includes/functions.php）に共通化済み。staff/collection_entry.phpの介護施設向け
// 一覧とロジックを共有する。
$facilityPanelGroups = $showHistory ? [] : group_open_cycles_into_facility_panels($pdo, $cardCycles);

// ---- カード1件分の描画（履歴表示・施設パネル表示の両方から呼ぶ、二重管理を避けるため共通化） ----
// $includeFacilityName: 施設パネル表示ではパネル見出しに施設名が既に出ているため、
// カード見出し側は集荷日のみにして重複を避ける（改善1）。履歴表示（?history=1）は
// パネルを使わない施設横断のフラット一覧なので、そちらだけ施設名を出す。
$renderCycleCard = function (array $cycle, bool $includeFacilityName = false) use ($collectionHeadcountPath, $csrfToken, $isSharedAccount, $isAdminView, $employees, $staff): void {
    $cycleId = (int) $cycle['id'];
    $expectedReturnDate = calc_expected_return_date($cycle['pickup_date'], $cycle['facility_pickup_schedule']);
    $issuedBagRowClass = issued_bag_color_row_class([
        'issued_bag_orange' => $cycle['facility_issued_bag_orange'],
        'issued_bag_yellow' => $cycle['facility_issued_bag_yellow'],
    ]);

    // カード見出し右の編集メニューには、既に登録済み（＝編集先が実在する）項目だけを出す。
    $editMenuItems = [];
    if ($cycle['return_ready_laundry_net_count'] !== null) {
        $editMenuItems[] = ['label' => '洗濯ネット数', 'url' => $collectionHeadcountPath . '?edit_net=' . $cycleId];
    }
    if ($cycle['wsr_id'] !== null) {
        $editMenuItems[] = ['label' => '作業登録', 'url' => $collectionHeadcountPath . '?edit=' . (int) $cycle['wsr_id']];
    }
    if ($cycle['return_ready_bag_count'] !== null) {
        $editMenuItems[] = ['label' => '返却リネン袋数', 'url' => $collectionHeadcountPath . '?return_edit=' . $cycleId];
    }
    ?>
    <article class="cycle-card">
        <div class="cycle-card-header">
            <p class="cycle-card-title"><?php if ($includeFacilityName): ?><?= htmlspecialchars($cycle['facility_name'], ENT_QUOTES, 'UTF-8') ?>　<?php endif; ?>集荷日 <?= htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!empty($editMenuItems)): ?>
                <details class="cycle-card-edit-menu">
                    <summary>編集</summary>
                    <ul class="cycle-card-edit-menu-list">
                        <?php foreach ($editMenuItems as $item): ?>
                            <li><a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
        <p class="cycle-card-info">返却予定日: <?= $expectedReturnDate !== null ? htmlspecialchars($expectedReturnDate, ENT_QUOTES, 'UTF-8') : '-' ?></p>

        <table class="cycle-table">
            <thead>
                <tr><th>項目</th><th>数値</th></tr>
            </thead>
            <tbody>
                <tr class="<?= $issuedBagRowClass ?>">
                    <th>到着リネン袋数</th>
                    <td class="<?= $cycle['arrival_bag_count'] !== null ? 'done' : '' ?>">
                        <?= $cycle['arrival_bag_count'] !== null ? (int) $cycle['arrival_bag_count'] : '未到着' ?>
                    </td>
                </tr>
                <tr>
                    <th>洗濯ネット数</th>
                    <td class="<?= $cycle['return_ready_laundry_net_count'] !== null ? 'done' : '' ?>">
                        <?php if ($cycle['return_ready_laundry_net_count'] !== null): ?>
                            <?= (int) $cycle['return_ready_laundry_net_count'] ?>
                        <?php elseif ($cycle['arrival_bag_count'] === null): ?>
                            （到着後に入力可）
                        <?php else: ?>
                            <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="register_net_count">
                                <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                                <input type="number" name="laundry_net_count" min="0" step="1" required placeholder="枚数">
                                <button type="submit">登録</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>洗濯完了ネット数</th>
                    <td class="<?= $cycle['wsr_id'] !== null ? 'done' : '' ?>">
                        <?php if ($cycle['wsr_id'] !== null): ?>
                            <?= (int) $cycle['wsr_person_count'] ?>
                        <?php elseif ($cycle['arrival_bag_count'] === null): ?>
                            （到着後に入力可）
                        <?php else: ?>
                            <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="complete_work">
                                <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                                <?php if ($isSharedAccount || $isAdminView): ?>
                                    <select name="employee_ids[]" multiple size="<?= max(2, min(4, count($employees))) ?>" required>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?= (int) $employee['id'] ?>" <?= !$isSharedAccount && (int) $employee['id'] === (int) $staff['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="hidden" name="employee_ids[]" value="<?= (int) $staff['id'] ?>">
                                <?php endif; ?>
                                <input type="number" name="net_count" min="0" step="1" required placeholder="枚数">
                                <button type="submit">完了</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="row-return">
                    <th>返却リネン袋数</th>
                    <td class="<?= $cycle['return_ready_bag_count'] !== null ? 'done' : '' ?>">
                        <?php if ($cycle['return_ready_bag_count'] !== null): ?>
                            <?= (int) $cycle['return_ready_bag_count'] ?>
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
                </tr>
            </tbody>
        </table>

        <details class="cycle-card-detail">
            <summary>▼詳細・削除</summary>
            <div class="cycle-card-detail-body">
                <?php if ($cycle['dispatch_bag_count'] !== null): ?>
                    <dl>
                        <dt>発送</dt>
                        <dd><?= (int) $cycle['dispatch_bag_count'] ?>袋・<?= htmlspecialchars((string) $cycle['dispatch_date'], ENT_QUOTES, 'UTF-8') ?></dd>
                    </dl>
                <?php endif; ?>

                <?php if ($cycle['return_ready_laundry_net_count'] !== null): ?>
                    <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この洗濯ネット数の記録を削除しますか？');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_net_count">
                        <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                        <button type="submit">洗濯ネット数を削除</button>
                    </form>
                <?php endif; ?>
                <?php if ($cycle['wsr_id'] !== null): ?>
                    <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この作業登録を削除しますか？');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_work">
                        <input type="hidden" name="id" value="<?= (int) $cycle['wsr_id'] ?>">
                        <button type="submit">作業登録を削除</button>
                    </form>
                <?php endif; ?>
                <?php if ($cycle['return_ready_bag_count'] !== null): ?>
                    <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この返却リネン袋数の記録を削除しますか？');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_return_ready">
                        <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                        <button type="submit">返却リネン袋数を削除</button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
    </article>
    <?php
};

// ---- 「作業実績一覧」（admin専用、collection_cycle_id無し）の一覧取得 ----
$standaloneRecords = [];
$standaloneParticipantsByRecordId = [];
if ($isAdminView) {
    $wsrDateCondition = '';
    $wsrDateParams = [];
    if ($wsrPeriod !== 'all') {
        $wsrEnd = (new DateTime())->format('Y-m-d');
        $wsrStart = (new DateTime())->modify('-' . ((int) $wsrPeriod - 1) . ' days')->format('Y-m-d');
        $wsrDateCondition = 'AND w.record_date BETWEEN :start AND :end';
        $wsrDateParams = [':start' => $wsrStart, ':end' => $wsrEnd];
    }

    $standaloneListStmt = $pdo->prepare(
        "SELECT w.id, w.record_date, w.record_time, w.stage, w.person_count, w.created_at,
                e.name AS employee_name, f.name AS facility_name
         FROM work_stage_records w
         INNER JOIN employees e ON e.id = w.employee_id
         INNER JOIN facilities f ON f.id = w.facility_id
         WHERE w.deleted_at IS NULL AND w.collection_cycle_id IS NULL $wsrDateCondition
         ORDER BY w.record_date DESC, w.id DESC
         LIMIT 300"
    );
    $standaloneListStmt->execute($wsrDateParams);
    $standaloneRecords = $standaloneListStmt->fetchAll();

    if (!empty($standaloneRecords)) {
        $standaloneRecordIds = array_column($standaloneRecords, 'id');
        $placeholders = implode(',', array_fill(0, count($standaloneRecordIds), '?'));
        $standaloneParticipantsStmt = $pdo->prepare(
            "SELECT wse.work_stage_record_id, e.name
             FROM work_stage_record_employees wse
             INNER JOIN employees e ON e.id = wse.employee_id
             WHERE wse.work_stage_record_id IN ($placeholders)
             ORDER BY e.name"
        );
        $standaloneParticipantsStmt->execute($standaloneRecordIds);
        foreach ($standaloneParticipantsStmt->fetchAll() as $row) {
            $standaloneParticipantsByRecordId[(int) $row['work_stage_record_id']][] = $row['name'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title><?= $isAdminView ? '作業登録 | 管理者' : '作業登録 | シフト管理' ?></title>
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

        /* カード一覧（1サイクル＝1カード、4項目を表形式で表示） */
        .cycle-cards { display: flex; flex-direction: column; gap: 12px; }
        .cycle-card { border: 1px solid #ccc; border-radius: 8px; padding: 12px 14px; background: #fff; }
        .cycle-card-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .cycle-card-title { font-weight: bold; font-size: 1.05em; margin: 0; }
        .cycle-card-info { color: #666; font-size: 0.85em; margin: 0 0 8px; }
        .cycle-card-edit-menu { flex-shrink: 0; }
        .cycle-card-edit-menu summary { cursor: pointer; color: #0b5ed7; list-style: none; padding: 4px 10px; border: 1px solid #0b5ed7; border-radius: 6px; font-size: 0.85em; }
        .cycle-card-edit-menu summary::-webkit-details-marker { display: none; }
        .cycle-card-edit-menu-list { list-style: none; margin: 6px 0 0; padding: 0; text-align: right; }
        .cycle-card-edit-menu-list li { margin-bottom: 4px; }
        table.cycle-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.cycle-table th, table.cycle-table td { border: 1px solid #ddd; padding: 6px 8px; font-size: 0.9em; text-align: left; }
        table.cycle-table thead th { background: #f5f5f5; font-weight: bold; }
        table.cycle-table tbody th { font-weight: normal; color: #444; white-space: nowrap; width: 40%; }
        table.cycle-table td.done { color: #1e7e34; font-weight: bold; }
        table.cycle-table td form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        table.cycle-table td input[type="number"] { width: 80px; }
        table.cycle-table tr.row-issued-orange th, table.cycle-table tr.row-issued-orange td { background: #ffe0b2; }
        table.cycle-table tr.row-return th, table.cycle-table tr.row-return td { background: #0b5ed7; color: #fff; }
        table.cycle-table tr.row-return td.done { color: #fff; }
        .cycle-card-detail { margin-top: 8px; }
        .cycle-card-detail summary { cursor: pointer; color: #0b5ed7; }
        .cycle-card-detail-body { padding: 8px 4px 0; font-size: 0.9em; }
        .cycle-card-detail-body dl { margin: 0; }
        .cycle-card-detail-body dt { color: #666; margin-top: 6px; }
        .cycle-card-detail-body dd { margin: 0 0 2px; }

        /* 施設パネル一覧（集荷曜日ごとにグループ化、nav-cardと同じ見た目。デフォルト展開で
           幅に応じたレスポンシブグリッド表示）。カード内の文字量が減ったため、auto-fitの
           成り行きではなく明示的なブレークポイントで列数を固定する：900px未満は1列、
           900px以上は3列固定。 */
        .facility-group { margin-bottom: 20px; }
        .facility-group-title { font-size: 1.05em; margin: 0 0 10px; color: #333; }
        .facility-panels { display: grid; grid-template-columns: 1fr; gap: 16px; align-items: start; }
        @media (min-width: 900px) {
            .facility-panels { grid-template-columns: repeat(3, 1fr); }
        }
        .facility-panel { min-width: 0; border: 1px solid #aeb6c1; border-radius: 14px; background: linear-gradient(145deg, #f4f6f8 0%, #d6dce3 100%); box-shadow: 0 7px 16px rgba(30, 55, 90, 0.13), 0 2px 4px rgba(30, 55, 90, 0.08), inset 0 1px 0 rgba(255,255,255,0.95); overflow: hidden; }
        .facility-panel-summary { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 18px; cursor: pointer; list-style: none; font-weight: bold; color: #0b5ed7; }
        .facility-panel-summary::-webkit-details-marker { display: none; }
        .facility-panel-summary::after { content: '▲'; font-size: 0.7em; color: #0b5ed7; }
        .facility-panel:not([open]) .facility-panel-summary::after { content: '▼'; }
        .facility-panel-count { font-weight: normal; color: #555; font-size: 0.9em; }
        .facility-panel-cycles { padding: 0 14px 14px; }

        /* 作業実績一覧（admin専用セクション、旧admin/work_stage_records.php） */
        .period-nav { margin-bottom: 12px; }
        .period-nav a { margin-right: 12px; padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; text-decoration: none; color: #222; }
        .period-nav a.active { background: #0b5ed7; color: #fff; border-color: #0b5ed7; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.records th { background: #f5f5f5; }
    </style>
</head>
<body>
<header>
    <h1>作業登録</h1>
    <nav><a href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>">ダッシュボードに戻る</a> | <a href="<?= htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8') ?>">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($editFormValues !== null): ?>
<section class="edit-form">
    <h2>作業登録の修正（<?= htmlspecialchars($editFormValues['facility_name'], ENT_QUOTES, 'UTF-8') ?>　<?= htmlspecialchars($editFormValues['pickup_date'], ENT_QUOTES, 'UTF-8') ?>集荷分）</h2>
    <fieldset>
        <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_work">
            <input type="hidden" name="id" value="<?= (int) $editFormValues['id'] ?>">

            <div class="form-row">
                <label for="e_employee_ids">作業した従業員</label>
                <select id="e_employee_ids" name="employee_ids[]" multiple size="<?= max(2, min(6, count($employees))) ?>">
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= in_array((int) $employee['id'], $editFormValues['employee_ids'], true) ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="e_net_count">洗濯完了ネット数</label>
                <input type="number" id="e_net_count" name="net_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['net_count'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-row">
                <label for="e_record_date">作業日</label>
                <input type="date" id="e_record_date" name="record_date" value="<?= htmlspecialchars($editFormValues['record_date'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-row">
                <label for="e_record_time">作業時刻</label>
                <input type="time" id="e_record_time" name="record_time" value="<?= htmlspecialchars($editFormValues['record_time'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit">更新する</button>
            <a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
        </form>
    </fieldset>
</section>
<?php endif; ?>

<?php if ($editingNetCount !== null): ?>
<section class="edit-form">
    <h2>洗濯ネット数の修正（<?= htmlspecialchars($editingNetCount['facility_name'], ENT_QUOTES, 'UTF-8') ?>　<?= htmlspecialchars($editingNetCount['pickup_date'], ENT_QUOTES, 'UTF-8') ?>集荷分）</h2>
    <fieldset>
        <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_net_count">
            <input type="hidden" name="cycle_id" value="<?= (int) $editingNetCount['id'] ?>">
            <div class="form-row"><label for="nc_count">洗濯ネット数</label><input type="number" id="nc_count" name="laundry_net_count" min="0" step="1" value="<?= (int) $editingNetCount['return_ready_laundry_net_count'] ?>" required></div>
            <button type="submit">更新する</button>
            <a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
        </form>
    </fieldset>
</section>
<?php endif; ?>

<?php if ($editingReturnReady !== null): ?>
<section class="edit-form">
    <h2>返却リネン袋数の修正（<?= htmlspecialchars($editingReturnReady['facility_name'], ENT_QUOTES, 'UTF-8') ?>　<?= htmlspecialchars($editingReturnReady['pickup_date'], ENT_QUOTES, 'UTF-8') ?>集荷分）</h2>
    <fieldset>
        <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_return_ready">
            <input type="hidden" name="cycle_id" value="<?= (int) $editingReturnReady['id'] ?>">
            <div class="form-row"><label for="rr_bag_count">返却リネン袋数</label><input type="number" id="rr_bag_count" name="return_bag_count" min="0" step="1" value="<?= (int) $editingReturnReady['return_ready_bag_count'] ?>" required></div>
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

    <?php if ($showHistory): ?>
        <?php if (empty($cardCycles)): ?>
            <p class="notice">返却完了の記録はまだありません。</p>
        <?php else: ?>
            <div class="cycle-cards">
                <?php foreach ($cardCycles as $cycle): ?>
                    <?php $renderCycleCard($cycle, true); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $emptyMessage = '現在、対応が必要な集荷サイクルはありません。';
        require __DIR__ . '/../includes/facility_panel_list.php';
        ?>
        <?php if (empty($facilityPanelGroups)): ?>
            <p class="notice">
                新しい集荷を登録するには、集荷記録簿（<a href="<?= htmlspecialchars($isAdminView ? '/admin/collection_records.php' : '/staff/collection_entry.php', ENT_QUOTES, 'UTF-8') ?>">こちら</a>）から集荷・到着の登録を行ってください。
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($isAdminView): ?>
<section class="standalone-record-form" id="standalone-record-form-section">
    <h2><?= $standaloneFormAction === 'update' ? '作業実績の修正' : '作業実績の新規登録' ?></h2>
    <fieldset>
        <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $standaloneFormAction === 'update' ? 'update_standalone_record' : 'create_standalone_record' ?>">
            <?php if ($standaloneFormAction === 'update'): ?>
                <input type="hidden" name="id" value="<?= (int) $standaloneFormId ?>">
            <?php endif; ?>

            <div class="form-row">
                <label for="wsr_employee_id">記録担当</label>
                <select id="wsr_employee_id" name="employee_id" required <?= empty($employees) ? 'disabled' : '' ?>>
                    <option value="">選択してください</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $standaloneFormEmployeeId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="wsr_record_date">作業日</label>
                <input type="date" id="wsr_record_date" name="record_date" value="<?= htmlspecialchars($standaloneFormRecordDate, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="wsr_record_time">作業時刻</label>
                <input type="time" id="wsr_record_time" name="record_time" value="<?= htmlspecialchars($standaloneFormRecordTime, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="wsr_facility_id">施設</label>
                <select id="wsr_facility_id" name="facility_id" required <?= empty($manualFacilities) ? 'disabled' : '' ?>>
                    <option value="">選択してください</option>
                    <?php foreach ($manualFacilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $standaloneFormFacilityId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="wsr_employee_ids">参加した従業員</label>
                <select id="wsr_employee_ids" name="employee_ids[]" multiple size="<?= max(2, min(6, count($employees))) ?>">
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= in_array((int) $employee['id'], $standaloneFormEmployeeIds, true) ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="wsr_laundry_net_count">洗濯ネット数</label>
                <input type="number" id="wsr_laundry_net_count" name="laundry_net_count" min="0" step="1" value="<?= htmlspecialchars($standaloneFormLaundryNetCount, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit"><?= $standaloneFormAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($standaloneFormAction === 'update'): ?>
                <a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>?period=<?= htmlspecialchars($wsrPeriod, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
            <?php endif; ?>
        </form>
    </fieldset>
</section>

<div class="period-nav">
    <?php foreach ($wsrPeriodLabels as $key => $label): ?>
        <a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>?period=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="<?= $wsrPeriod === (string) $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<section class="standalone-record-list">
    <h2>作業実績一覧</h2>
    <?php if (empty($standaloneRecords)): ?>
        <p class="notice">対象期間の作業実績はありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>作業日</th>
                    <th>時刻</th>
                    <th>記録担当</th>
                    <th>参加した従業員</th>
                    <th>施設</th>
                    <th>工程</th>
                    <th>洗濯ネット数</th>
                    <th>登録日時</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($standaloneRecords as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['record_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $record['record_time'] !== null ? htmlspecialchars(substr($record['record_time'], 0, 5), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= htmlspecialchars($record['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= !empty($standaloneParticipantsByRecordId[(int) $record['id']]) ? htmlspecialchars(implode('・', $standaloneParticipantsByRecordId[(int) $record['id']]), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(WSR_STANDALONE_STAGE_LABEL, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?= (int) $record['person_count'] ?>枚
                            <?php if ($record['record_time'] === null): ?>
                                <br><small>※退勤時記録（人数）</small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($record['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>?period=<?= htmlspecialchars($wsrPeriod, ENT_QUOTES, 'UTF-8') ?>&wsr_edit=<?= (int) $record['id'] ?>">編集</a>
                            <form method="post" action="<?= htmlspecialchars($collectionHeadcountPath, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onsubmit="return confirm('この作業実績を削除しますか？');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_standalone_record">
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
<?php endif; ?>

</body>
</html>
