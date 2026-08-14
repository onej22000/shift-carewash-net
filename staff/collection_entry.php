<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

/**
 * この工程がまだ入力されていない未完了サイクルを、pickup_date昇順（古い順）で返す。
 * 前工程が未完了のサイクルは対象にしない（＝そのサイクルはまだこの工程を記録できる状態ではない、
 * という定義そのもの。工程順を強制ブロックする処理ではなく、単に「この工程の対象になり得るか」の絞り込み）。
 *
 * collection_cycles.facility_idは常に集荷元の施設（介護施設・自社）を指し、クリーニング所の
 * IDにはならない（1サイクル＝1施設×1集荷日で固定、クリーニング所は複数施設分の荷物を
 * まとめて扱うため）。そのため「到着」「発送」（＝クリーニング所での作業）はfacility_idで
 * 絞り込まず、システム全体の未処理サイクルを対象にする（ドライバーは1回のクリーニング所訪問で
 * 複数施設分をまとめて処理するため）。「返却」（＝施設での作業）のみ、対象施設のfacility_idで
 * 絞り込む。
 *
 * 2026-08-14、1サイクル＝1カードのUIに再構成したのに伴い、候補は画面上でラジオ選択させず、
 * カードそのもの（1件1件がhidden inputでcycle_idを直接指定するフォーム）を選択とみなす形にした。
 */
function find_candidate_cycles(PDO $pdo, string $stage, ?int $facilityId = null): array
{
    switch ($stage) {
        case 'arrival':
            $sql = 'SELECT * FROM collection_cycles
                    WHERE arrival_bag_count IS NULL AND deleted_at IS NULL
                    ORDER BY pickup_date ASC, id ASC';
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll();
        case 'dispatch':
            $sql = 'SELECT * FROM collection_cycles
                    WHERE arrival_bag_count IS NOT NULL AND dispatch_bag_count IS NULL AND deleted_at IS NULL
                    ORDER BY pickup_date ASC, id ASC';
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll();
        case 'return':
            $sql = 'SELECT * FROM collection_cycles
                    WHERE dispatch_bag_count IS NOT NULL AND return_bag_count IS NULL AND deleted_at IS NULL'
                . ($facilityId !== null ? ' AND facility_id = :facility_id' : '')
                . ' ORDER BY pickup_date ASC, id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($facilityId !== null ? [':facility_id' => $facilityId] : []);
            return $stmt->fetchAll();
        default:
            return [];
    }
}

/**
 * 新規集荷登録の対象facility_idについて、直近の既存サイクル（＝これから登録する
 * サイクルの「前回サイクル」）を1件返す。「直近」はbuild_jiro_checklist_data()の
 * latestPickupStmt/latestCycleStmtと同じ基準（pickup_date DESC, id DESC）。
 * この関数は新規サイクルのINSERT前に呼ぶ前提のため、除外条件なしで「facility_idの
 * 既存サイクルの最新1件」を取れば、それがそのまま「前回サイクル」になる。
 */
function find_previous_cycle_for_facility(PDO $pdo, int $facilityId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM collection_cycles
         WHERE facility_id = :facility_id AND deleted_at IS NULL
         ORDER BY pickup_date DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([':facility_id' => $facilityId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function format_cycle_candidate_label(array $cycle, array $facilityNamesById = []): string
{
    $facilityLabel = $facilityNamesById[(int) $cycle['facility_id']] ?? null;
    $parts = [
        ($facilityLabel !== null ? htmlspecialchars($facilityLabel, ENT_QUOTES, 'UTF-8') . ' ' : '')
        . '集荷日 ' . htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8'),
    ];
    if ($cycle['pickup_bag_count'] !== null) {
        $parts[] = '集荷' . (int) $cycle['pickup_bag_count'] . '袋';
    }
    if ($cycle['arrival_bag_count'] !== null) {
        $parts[] = '到着' . (int) $cycle['arrival_bag_count'] . '袋';
    }
    if ($cycle['dispatch_bag_count'] !== null) {
        $parts[] = '発送' . (int) $cycle['dispatch_bag_count'] . '袋';
    }
    if (($cycle['return_ready_bag_count'] ?? null) !== null) {
        $parts[] = '洗濯代行登録' . (int) $cycle['return_ready_bag_count'] . '袋';
    }
    return $parts[0] . '（' . implode('／', array_slice($parts, 1)) . '）';
}

function parse_bag_count($raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int) $raw;
}

/**
 * クイック入力欄の日付・時刻・担当者は「今すぐ・自分名義」を既定値としつつ、後から入力する
 * （実際の作業より遅れて記録する）場合等に備えて上書きできるようにする。空欄なら既定値を使う。
 */
function resolve_entry_date($raw, string $default): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    return $dt !== false ? $dt->format('Y-m-d') : false;
}

function resolve_entry_time($raw, string $default): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt !== false ? $dt->format('H:i:s') : false;
}

function resolve_entry_employee_id($raw, int $default, array $validEmployeeIds)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    $id = (int) $raw;
    return in_array($id, $validEmployeeIds, true) ? $id : false;
}

function insert_pickup(
    PDO $pdo,
    int $facilityId,
    ?int $bagCount,
    string $pickupDate,
    string $pickupTime,
    int $employeeId,
    ?int $issuedBagOrange,
    ?int $issuedBagYellow,
    ?int $issuedBagBlue,
    ?int $issuedLaundryNetCount
): int {
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
        ':pickup_date' => $pickupDate,
        ':bag_count' => $bagCount,
        ':pickup_time' => $pickupTime,
        ':employee_id' => $employeeId,
        ':issued_bag_orange' => $issuedBagOrange,
        ':issued_bag_yellow' => $issuedBagYellow,
        ':issued_bag_blue' => $issuedBagBlue,
        ':issued_laundry_net_count' => $issuedLaundryNetCount,
    ]);

    return (int) $pdo->lastInsertId();
}

function update_return(PDO $pdo, int $cycleId, int $bagCount, string $returnDate, string $returnTime, int $employeeId): void
{
    $stmt = $pdo->prepare(
        'UPDATE collection_cycles
         SET return_bag_count = :bag_count, return_date = :date, return_time = :time, return_employee_id = :employee_id
         WHERE id = :id'
    );
    $stmt->execute([
        ':bag_count' => $bagCount,
        ':date' => $returnDate,
        ':time' => $returnTime,
        ':employee_id' => $employeeId,
        ':id' => $cycleId,
    ]);
}

function update_arrival(PDO $pdo, int $cycleId, int $bagCount, string $arrivalDate, string $arrivalTime, int $employeeId, int $facilityId): void
{
    $stmt = $pdo->prepare(
        'UPDATE collection_cycles
         SET arrival_bag_count = :bag_count, arrival_date = :date, arrival_time = :time,
             arrival_employee_id = :employee_id, arrival_facility_id = :facility_id
         WHERE id = :id'
    );
    $stmt->execute([
        ':bag_count' => $bagCount,
        ':date' => $arrivalDate,
        ':time' => $arrivalTime,
        ':employee_id' => $employeeId,
        ':facility_id' => $facilityId,
        ':id' => $cycleId,
    ]);
}

function update_dispatch(PDO $pdo, int $cycleId, int $bagCount, string $dispatchDate, string $dispatchTime, int $employeeId, int $facilityId): void
{
    $stmt = $pdo->prepare(
        'UPDATE collection_cycles
         SET dispatch_bag_count = :bag_count, dispatch_date = :dispatch_date, dispatch_time = :time,
             dispatch_employee_id = :employee_id, dispatch_facility_id = :facility_id
         WHERE id = :id'
    );
    $stmt->execute([
        ':bag_count' => $bagCount,
        ':dispatch_date' => $dispatchDate,
        ':time' => $dispatchTime,
        ':employee_id' => $employeeId,
        ':facility_id' => $facilityId,
        ':id' => $cycleId,
    ]);
}

/**
 * カード「集荷」行の表示文字列。集荷袋数が記録されていない場合は、空袋・空ネットの
 * 納品のみだった（データ欠損ではなく正常な業務パターン）ことを明示する。
 */
function format_pickup_summary_label(array $cycle): string
{
    if ($cycle['pickup_bag_count'] === null) {
        return '空袋・空ネット納品のみ';
    }
    return (int) $cycle['pickup_bag_count'] . '袋';
}

// ---- 過去サイクルの修正・削除（従業員・管理者とも可能。工程を跨いだ多人数作業のため、
//      自分が記録した項目だけに絞らずチーム共有データとして扱う） ----
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

$facilitiesStmt = $pdo->query("SELECT id, name, facility_type FROM facilities WHERE is_active = 1 ORDER BY name");
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));
$facilityNamesById = array_column($facilities, 'name', 'id');
$facilityTypesById = array_column($facilities, 'facility_type', 'id');

$cleaningFacilitiesStmt = $pdo->query("SELECT id, name FROM facilities WHERE facility_type = 'クリーニング所' ORDER BY name");
$cleaningFacilities = $cleaningFacilitiesStmt->fetchAll();
$validCleaningFacilityIds = array_map('intval', array_column($cleaningFacilities, 'id'));

$employeesStmt = $pdo->query("SELECT id, name FROM employees WHERE role = 'staff' AND is_shared_account = 0 ORDER BY name");
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

// 場所選択で介護施設を選んだ際の「直前の確認画面」に表示する施設ごとの前回サイクル状況。
// jiro_dashboard.php（本日の集荷予定）と同じソース・同じロジックを再利用する（別計算式は作らない）。
$pickupChecklist = build_jiro_checklist_data($pdo, new DateTime());
$pickupChecklistByFacility = [];
foreach (array_merge($pickupChecklist['today_rows'], $pickupChecklist['other_rows']) as $row) {
    $pickupChecklistByFacility[$row['facility_id']] = $row;
}

$errorMessage = '';
// ステップ2（袋数入力）を描画するための状態。resolve_locationが成功した時だけセットする。
$step2 = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $now = new DateTime();
        $todayStr = $now->format('Y-m-d');
        $nowTimeStr = $now->format('H:i:s');

        if ($action === 'resolve_location') {
            // 場所選択（ステップ1）。介護施設・自社なら集荷登録、クリーニング所なら
            // 発送・到着登録のステップ2を描画するための状態を組み立てる。
            $facilityId = (int) ($_POST['location'] ?? 0);
            $isCleaningSite = ($facilityTypesById[$facilityId] ?? null) === 'クリーニング所';

            if (!in_array($facilityId, $validFacilityIds, true)) {
                $errorMessage = '施設を選択してください。';
            } elseif ($isCleaningSite) {
                $step2 = [
                    'facility_id' => $facilityId,
                    'is_cleaning_site' => true,
                    'arrival_candidates' => find_candidate_cycles($pdo, 'arrival'),
                    'dispatch_candidates' => find_candidate_cycles($pdo, 'dispatch'),
                ];
            } else {
                $step2 = [
                    'facility_id' => $facilityId,
                    'is_cleaning_site' => false,
                ];
            }
        } elseif ($action === 'finalize') {
            // ステップ2の確定（ステップ1で選んだ場所に応じて、クリーニング所なら
            // 発送・到着登録を、介護施設・自社なら集荷登録を行う）。
            $facilityId = (int) ($_POST['facility_id'] ?? 0);
            $isCleaningSite = ($_POST['is_cleaning_site'] ?? '') === '1';

            if (!in_array($facilityId, $validFacilityIds, true)) {
                $errorMessage = '施設を選択してください。最初からやり直してください。';
            } else {
                $facilityName = $facilityNamesById[$facilityId];

                if ($isCleaningSite) {
                    // 他ドライバーの更新と競合していないか確認するため、ここで状態を再計算する
                    // （ステップ1描画時の状態をそのまま信用しない）。
                    $arrivalCandidates = find_candidate_cycles($pdo, 'arrival');
                    $dispatchCandidates = find_candidate_cycles($pdo, 'dispatch');
                    $validArrivalIds = array_map('intval', array_column($arrivalCandidates, 'id'));
                    $validDispatchIds = array_map('intval', array_column($dispatchCandidates, 'id'));

                    $arrivalBagCount = parse_bag_count($_POST['arrival_bag_count'] ?? '');
                    $dispatchBagCount = parse_bag_count($_POST['dispatch_bag_count'] ?? '');
                    $arrivalCycleId = (int) ($_POST['arrival_cycle_id'] ?? 0);
                    $dispatchCycleId = (int) ($_POST['dispatch_cycle_id'] ?? 0);

                    $arrivalDate = resolve_entry_date($_POST['arrival_date'] ?? '', $todayStr);
                    $arrivalTime = resolve_entry_time($_POST['arrival_time'] ?? '', $nowTimeStr);
                    $arrivalEmployeeId = resolve_entry_employee_id($_POST['arrival_employee_id'] ?? '', (int) $staff['id'], $validEmployeeIds);
                    $dispatchDate = resolve_entry_date($_POST['dispatch_date'] ?? '', $todayStr);
                    $dispatchTime = resolve_entry_time($_POST['dispatch_time'] ?? '', $nowTimeStr);
                    $dispatchEmployeeId = resolve_entry_employee_id($_POST['dispatch_employee_id'] ?? '', (int) $staff['id'], $validEmployeeIds);

                    // 対象サイクルが存在し、かつ袋数が入力されている項目だけを「今回記録する」とみなす。
                    $wantsArrival = !empty($arrivalCandidates) && $arrivalBagCount !== null;
                    $wantsDispatch = !empty($dispatchCandidates) && $dispatchBagCount !== null;

                    // ラジオボタンは自動選択せず、担当者が明示的に選ばないとarrival_cycle_id/
                    // dispatch_cycle_id自体がPOSTされない。候補が複数あるのに未選択のまま
                    // 送信された場合は専用メッセージで弾く（8/13に到着側で導入した安全装置。
                    // 場所選択フロー復活を機に発送側にも同じバリデーションを適用する）。
                    if ($wantsArrival && count($arrivalCandidates) > 1 && !isset($_POST['arrival_cycle_id'])) {
                        $errorMessage = '到着対象を選択してください。';
                    } elseif ($wantsDispatch && count($dispatchCandidates) > 1 && !isset($_POST['dispatch_cycle_id'])) {
                        $errorMessage = '発送対象を選択してください。';
                    } elseif ($wantsArrival && !in_array($arrivalCycleId, $validArrivalIds, true)) {
                        $errorMessage = '到着対象のサイクルが無効です（既に他の記録で更新された可能性があります）。もう一度やり直してください。';
                    } elseif ($wantsDispatch && !in_array($dispatchCycleId, $validDispatchIds, true)) {
                        $errorMessage = '発送対象のサイクルが無効です（既に他の記録で更新された可能性があります）。もう一度やり直してください。';
                    } elseif (!$wantsArrival && !$wantsDispatch) {
                        $errorMessage = '到着・発送のいずれかにリネン袋数を入力してください。';
                    } elseif ($wantsArrival && ($arrivalDate === false || $arrivalTime === false || $arrivalEmployeeId === false)) {
                        $errorMessage = '到着の日付・時間・担当者の入力内容が正しくありません。';
                    } elseif ($wantsDispatch && ($dispatchDate === false || $dispatchTime === false || $dispatchEmployeeId === false)) {
                        $errorMessage = '発送の日付・時間・担当者の入力内容が正しくありません。';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            if ($wantsDispatch) {
                                update_dispatch($pdo, $dispatchCycleId, $dispatchBagCount, $dispatchDate, $dispatchTime, $dispatchEmployeeId, $facilityId);
                            }
                            if ($wantsArrival) {
                                update_arrival($pdo, $arrivalCycleId, $arrivalBagCount, $arrivalDate, $arrivalTime, $arrivalEmployeeId, $facilityId);
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
                    // 介護施設・自社：集荷登録（＋前回サイクルの返却自動確定）。
                    $pickupBagCount = parse_bag_count($_POST['pickup_bag_count'] ?? '');
                    $issuedBagOrange = parse_bag_count($_POST['issued_bag_orange'] ?? '');
                    $issuedBagYellow = parse_bag_count($_POST['issued_bag_yellow'] ?? '');
                    $issuedBagBlue = parse_bag_count($_POST['issued_bag_blue'] ?? '');
                    $issuedLaundryNetCount = parse_bag_count($_POST['issued_laundry_net_count'] ?? '');
                    $pickupDate = resolve_entry_date($_POST['pickup_date'] ?? '', $todayStr);
                    $pickupTime = resolve_entry_time($_POST['pickup_time'] ?? '', $nowTimeStr);
                    $pickupEmployeeId = resolve_entry_employee_id($_POST['pickup_employee_id'] ?? '', (int) $staff['id'], $validEmployeeIds);

                    $wantsPickup = $pickupBagCount !== null || $issuedBagOrange !== null || $issuedBagYellow !== null
                        || $issuedBagBlue !== null || $issuedLaundryNetCount !== null;

                    if (!$wantsPickup) {
                        $errorMessage = '集荷リネン袋数、または交付袋数のいずれかを入力してください。';
                    } elseif ($pickupDate === false || $pickupTime === false || $pickupEmployeeId === false) {
                        $errorMessage = '集荷の日付・時間・担当者の入力内容が正しくありません。';
                    } else {
                        // 新規サイクルのINSERT前に取得する＝この時点でfacility_idに存在する最新サイクルが
                        // そのまま「前回サイクル」になる（find_previous_cycle_for_facility()のdocblock参照）。
                        $previousCycle = find_previous_cycle_for_facility($pdo, $facilityId);
                        $autoConfirmedReturnBagCount = null;

                        $pdo->beginTransaction();
                        try {
                            $newCycleId = insert_pickup(
                                $pdo, $facilityId, $pickupBagCount, $pickupDate, $pickupTime, $pickupEmployeeId,
                                $issuedBagOrange, $issuedBagYellow, $issuedBagBlue, $issuedLaundryNetCount
                            );
                            record_collection_cycle_issuance_stock_adjustment($pdo, null, [
                                'issued_bag_orange' => $issuedBagOrange,
                                'issued_bag_yellow' => $issuedBagYellow,
                                'issued_bag_blue' => $issuedBagBlue,
                                'issued_laundry_net_count' => $issuedLaundryNetCount,
                            ], $facilityId, $facilityName, $newCycleId, $staff['id']);

                            // 前回サイクルの「返却」自動確定：洗濯代行が返却リネン袋（青）数
                            // （return_ready_bag_count）を確定済みで、ドライバーの最終返却確定
                            // （return_bag_count）がまだの場合、今回の集荷登録（＝次回訪問時に
                            // 前回分を返却したこと）をもって自動でそのまま確定する。これにより
                            // 独立した「返却登録」操作は不要になる（collection_headcount.phpの
                            // register_returnは手動での例外対応用に残す）。
                            if ($previousCycle !== null
                                && $previousCycle['return_ready_bag_count'] !== null
                                && $previousCycle['return_bag_count'] === null) {
                                $autoConfirmedReturnBagCount = (int) $previousCycle['return_ready_bag_count'];
                                update_return(
                                    $pdo, (int) $previousCycle['id'], $autoConfirmedReturnBagCount,
                                    $pickupDate, $pickupTime, $pickupEmployeeId
                                );
                            }

                            $pdo->commit();
                        } catch (\Throwable $e) {
                            $pdo->rollBack();
                            throw $e;
                        }
                        $flashMessage = ($pickupBagCount !== null ? '集荷（' . $pickupBagCount . '袋）' : '集荷（交付のみ）') . 'を記録しました（' . $facilityName . '）。';
                        if ($autoConfirmedReturnBagCount !== null) {
                            $flashMessage .= '　前回サイクルの返却（' . $autoConfirmedReturnBagCount . '袋）も自動確定しました。';
                        }
                        set_flash('success', $flashMessage);
                        header('Location: /staff/collection_entry.php');
                        exit;
                    }
                }
            }
        } elseif ($action === 'register_return') {
            $cycleId = (int) ($_POST['cycle_id'] ?? 0);
            $returnCandidates = find_candidate_cycles($pdo, 'return');
            $validReturnIds = array_map('intval', array_column($returnCandidates, 'id'));

            $bagCount = parse_bag_count($_POST['return_bag_count'] ?? '');
            $date = resolve_entry_date($_POST['return_date'] ?? '', $todayStr);
            $time = resolve_entry_time($_POST['return_time'] ?? '', $nowTimeStr);
            $employeeId = resolve_entry_employee_id($_POST['return_employee_id'] ?? '', (int) $staff['id'], $validEmployeeIds);

            if (!in_array($cycleId, $validReturnIds, true)) {
                $errorMessage = '対象のサイクルが無効です（既に他の記録で更新された可能性があります）。ページを再読み込みしてください。';
            } elseif ($bagCount === null) {
                $errorMessage = '返却リネン袋数は0以上の整数を入力してください。';
            } elseif ($date === false || $time === false || $employeeId === false) {
                $errorMessage = '返却の日付・時間・担当者の入力内容が正しくありません。';
            } else {
                update_return($pdo, $cycleId, $bagCount, $date, $time, $employeeId);
                set_flash('success', '返却（' . $bagCount . '袋）を記録しました。');
                header('Location: /staff/collection_entry.php');
                exit;
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

                    reverse_collection_cycle_facility_issuance($pdo, $cycleId);
                    cancel_collection_cycle_issuance_stock_transactions($pdo, $cycleId, $staff['id']);

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
            [$values, $parseErrors] = parse_collection_cycle_input($_POST, $validFacilityIds, $validEmployeeIds, $validCleaningFacilityIds);

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
                            $pdo, $cycle, $values, $values['facility_id'], $facilityNamesById[$values['facility_id']] ?? '', $cycleId, $staff['id']
                        );

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

// ---- カード一覧（返却未完了の集荷サイクル。1サイクル＝1カード。施設パネル表示のため
//      施設ごとにグループ化する。介護施設・自社が対象で、クリーニング所は対象外） ----
// pickup_bag_count IS NULLのサイクル（空袋・空ネット納品のみで実集荷が発生していないもの）は
// 到着・発送・返却のいずれも発生しないため、無期限に「未返却」として残ってしまう。
// collection_headcount.phpのfind_open_cycles()と同じ考え方で一覧から除外する。
// facility_pickup_schedule/facility_onboarding_start_dateは
// group_open_cycles_into_facility_panels()（includes/functions.php）が施設パネルの
// 集荷曜日グループ化・受託開始日フィルタに使う。
$openCyclesStmt = $pdo->query(
    "SELECT cc.*, f.name AS facility_name, f.pickup_schedule AS facility_pickup_schedule,
            f.onboarding_start_date AS facility_onboarding_start_date
     FROM collection_cycles cc
     INNER JOIN facilities f ON f.id = cc.facility_id
     WHERE cc.return_bag_count IS NULL AND cc.pickup_bag_count IS NOT NULL AND cc.deleted_at IS NULL
           AND f.facility_type != 'クリーニング所'
     ORDER BY cc.pickup_date ASC, cc.id ASC
     LIMIT 200"
);
$openCycles = $openCyclesStmt->fetchAll();
$openCycleFacilityPanelGroups = group_open_cycles_into_facility_panels($pdo, $openCycles);

// ---- カード1件分の描画（施設パネル表示から呼ぶ） ----
$renderOpenCycleCard = function (array $cycle) use ($csrfToken): void {
    $cycleId = (int) $cycle['id'];
    ?>
    <article class="cycle-card">
        <p class="cycle-card-title">集荷日 <?= htmlspecialchars($cycle['pickup_date'], ENT_QUOTES, 'UTF-8') ?></p>

        <div class="cycle-card-row">
            <span class="label">集荷<?php if ($cycle['pickup_time'] !== null): ?>・<?= htmlspecialchars(substr($cycle['pickup_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
            <span class="value done"><?= htmlspecialchars(format_pickup_summary_label($cycle), ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div class="cycle-card-row">
            <span class="label">到着<?php if ($cycle['arrival_bag_count'] !== null && $cycle['arrival_time'] !== null): ?>・<?= htmlspecialchars(substr($cycle['arrival_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
            <span class="value <?= $cycle['arrival_bag_count'] !== null ? 'done' : '' ?>">
                <?php if ($cycle['arrival_bag_count'] !== null): ?>
                    <?= (int) $cycle['arrival_bag_count'] ?>袋
                <?php else: ?>
                    未到着（上部の「場所を選択」でクリーニング所を選んで登録してください）
                <?php endif; ?>
            </span>
        </div>

        <div class="cycle-card-row">
            <span class="label">発送<?php if ($cycle['dispatch_bag_count'] !== null && $cycle['dispatch_time'] !== null): ?>・<?= htmlspecialchars(substr($cycle['dispatch_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
            <span class="value <?= $cycle['dispatch_bag_count'] !== null ? 'done' : '' ?>">
                <?php if ($cycle['dispatch_bag_count'] !== null): ?>
                    <?= (int) $cycle['dispatch_bag_count'] ?>袋
                <?php elseif ($cycle['arrival_bag_count'] === null): ?>
                    （到着後に入力可）
                <?php else: ?>
                    未発送
                <?php endif; ?>
            </span>
        </div>

        <div class="cycle-card-row">
            <span class="label">返却</span>
            <span class="value">
                <?php if ($cycle['dispatch_bag_count'] === null): ?>
                    次回集荷時に自動登録されます
                <?php else: ?>
                    <form method="post" action="/staff/collection_entry.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="register_return">
                        <input type="hidden" name="cycle_id" value="<?= $cycleId ?>">
                        <input type="number" name="return_bag_count" min="0" step="1" required placeholder="袋数" value="<?= $cycle['return_ready_bag_count'] !== null ? (int) $cycle['return_ready_bag_count'] : '' ?>">
                        <button type="submit">返却登録</button>
                    </form>
                    <br><small>通常は次回集荷時に自動登録されます（上記は例外対応用の手動登録です）。<?php if ($cycle['return_ready_bag_count'] !== null): ?>洗濯代行の登録数（<?= (int) $cycle['return_ready_bag_count'] ?>袋）を初期値にしています。<?php endif; ?></small>
                <?php endif; ?>
            </span>
        </div>
    </article>
    <?php
};

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
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>集荷記録簿 | シフト管理</title>
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
        .candidate-row { border: 1px solid #ccc; border-radius: 4px; padding: 8px; margin-bottom: 6px; }
        .candidate-row label { display: block; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 130px; }
        .form-row select, .form-row input[type="number"] { width: 220px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 16px; }
        .form-grid .form-row label { display: block; width: auto; font-size: 0.85em; margin-bottom: 2px; }
        .form-grid .form-row input, .form-grid .form-row select { width: 100%; box-sizing: border-box; }
        .inline-form { display: inline; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.85em; }
        table.records th { background: #f5f5f5; }
        .done { color: #1e7e34; }
        .pending { color: #999; }

        /* カード一覧（1サイクル＝1カード、集荷→到着→発送→返却を縦積み） */
        .cycle-cards { display: flex; flex-direction: column; gap: 12px; }
        .cycle-card { border: 1px solid #ccc; border-radius: 8px; padding: 12px 14px; background: #fff; }
        .cycle-card-title { font-weight: bold; font-size: 1.05em; margin: 0 0 8px; }
        .cycle-card-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 0; border-top: 1px solid #eee; flex-wrap: wrap; }
        .cycle-card-row:first-of-type { border-top: none; }
        .cycle-card-row .label { color: #666; font-size: 0.85em; min-width: 6em; }
        .cycle-card-row .value { text-align: right; flex: 1; }
        .cycle-card-row .value.done { color: #1e7e34; }
        .cycle-card-row .value form { display: flex; gap: 6px; align-items: center; justify-content: flex-end; flex-wrap: wrap; }
        .cycle-card-row .value input[type="number"] { width: 70px; }
        .cycle-card-row .value select { max-width: 100%; }

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
    </style>
</head>
<body>
<header>
    <h1>集荷記録簿</h1>
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
    <?php $step2FacilityName = htmlspecialchars($facilityNamesById[$step2['facility_id']], ENT_QUOTES, 'UTF-8'); ?>
    <section class="entry-form">
        <h2><?= $step2FacilityName ?>の記録</h2>
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
                            <?php $onlyDispatchCandidate = $step2['dispatch_candidates'][0]; ?>
                            <input type="hidden" name="dispatch_cycle_id" value="<?= (int) $onlyDispatchCandidate['id'] ?>">
                            <p class="notice">発送対象: <?= format_cycle_candidate_label($onlyDispatchCandidate, $facilityNamesById) ?></p>
                        <?php else: ?>
                            <p class="notice">発送待ちのサイクルが複数あります。対象を選んでください。</p>
                            <?php foreach ($step2['dispatch_candidates'] as $cycle): ?>
                                <div class="candidate-row">
                                    <label>
                                        <input type="radio" name="dispatch_cycle_id" value="<?= (int) $cycle['id'] ?>" data-return-ready-bag-count="<?= $cycle['return_ready_bag_count'] !== null ? (int) $cycle['return_ready_bag_count'] : '' ?>">
                                        <?= format_cycle_candidate_label($cycle, $facilityNamesById) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="form-row">
                            <label for="dispatch_bag_count">発送リネン袋数</label>
                            <?php
                            // 洗濯代行が確定した返却リネン袋（青）数をそのまま発送数の初期値にする
                            // （手動での編集は引き続き可能）。候補が複数ある場合は初期値を空にし、
                            // ラジオ選択時にJSで反映する（下部のscript参照）。
                            $dispatchBagCountDefault = count($step2['dispatch_candidates']) === 1
                                ? ($step2['dispatch_candidates'][0]['return_ready_bag_count'] ?? '')
                                : '';
                            ?>
                            <input type="number" id="dispatch_bag_count" name="dispatch_bag_count" min="0" step="1" value="<?= htmlspecialchars((string) $dispatchBagCountDefault, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="dispatch_date">発送日</label>
                                <input type="date" id="dispatch_date" name="dispatch_date" value="<?= (new DateTime())->format('Y-m-d') ?>">
                            </div>
                            <div class="form-row">
                                <label for="dispatch_time">発送時間</label>
                                <input type="time" id="dispatch_time" name="dispatch_time" value="<?= (new DateTime())->format('H:i') ?>">
                            </div>
                            <div class="form-row">
                                <label for="dispatch_employee_id">発送担当者</label>
                                <select id="dispatch_employee_id" name="dispatch_employee_id">
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === (int) $staff['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>
                </fieldset>
                <fieldset<?= empty($step2['arrival_candidates']) ? ' class="disabled"' : '' ?>>
                    <legend>到着（今回持ち込み分）</legend>
                    <?php if (empty($step2['arrival_candidates'])): ?>
                        <p class="notice">現在、到着待ちのサイクル（未到着の集荷）はありません。</p>
                    <?php else: ?>
                        <?php if (count($step2['arrival_candidates']) === 1): ?>
                            <?php $onlyArrivalCandidate = $step2['arrival_candidates'][0]; ?>
                            <input type="hidden" name="arrival_cycle_id" value="<?= (int) $onlyArrivalCandidate['id'] ?>">
                            <p class="notice">到着対象: <?= format_cycle_candidate_label($onlyArrivalCandidate, $facilityNamesById) ?></p>
                        <?php else: ?>
                            <p class="notice">到着待ちのサイクルが複数あります。対象を選んでください。</p>
                            <?php foreach ($step2['arrival_candidates'] as $cycle): ?>
                                <div class="candidate-row">
                                    <label>
                                        <input type="radio" name="arrival_cycle_id" value="<?= (int) $cycle['id'] ?>" data-pickup-bag-count="<?= $cycle['pickup_bag_count'] !== null ? (int) $cycle['pickup_bag_count'] : '' ?>">
                                        <?= format_cycle_candidate_label($cycle, $facilityNamesById) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="form-row">
                            <label for="arrival_bag_count">到着リネン袋数</label>
                            <?php
                            // 集荷リネン袋数がそのまま到着数として引き継がれることが多いため初期値にセットするが、
                            // 現場での増減（一部だけ先に到着等）に対応できるよう編集は妨げない。
                            $arrivalBagCountDefault = count($step2['arrival_candidates']) === 1
                                ? ($step2['arrival_candidates'][0]['pickup_bag_count'] ?? '')
                                : '';
                            ?>
                            <input type="number" id="arrival_bag_count" name="arrival_bag_count" min="0" step="1" value="<?= htmlspecialchars((string) $arrivalBagCountDefault, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="arrival_date">到着日</label>
                                <input type="date" id="arrival_date" name="arrival_date" value="<?= (new DateTime())->format('Y-m-d') ?>">
                            </div>
                            <div class="form-row">
                                <label for="arrival_time">到着時間</label>
                                <input type="time" id="arrival_time" name="arrival_time" value="<?= (new DateTime())->format('H:i') ?>">
                            </div>
                            <div class="form-row">
                                <label for="arrival_employee_id">到着担当者</label>
                                <select id="arrival_employee_id" name="arrival_employee_id">
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === (int) $staff['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>
                </fieldset>
            <?php else: ?>
                <?php $prevCycleRow = $pickupChecklistByFacility[$step2['facility_id']] ?? null; ?>
                <?php if ($prevCycleRow !== null): ?>
                    <p class="notice">
                        前回サイクル状況：返却空リネン袋（オレンジ）
                        <?= $prevCycleRow['latest_cycle_pickup_bag_count'] !== null ? (int) $prevCycleRow['latest_cycle_pickup_bag_count'] . '袋' : '-' ?>
                        ／ 集荷空リネン袋（青）
                        <?php if ($prevCycleRow['latest_cycle_status'] === 'confirmed'): ?>
                            <?= (int) $prevCycleRow['latest_cycle_return_ready_bag_count'] ?>袋（今回の集荷登録で前回サイクルの返却として自動確定されます）
                        <?php elseif ($prevCycleRow['latest_cycle_status'] === 'in_progress'): ?>
                            作業前
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <fieldset>
                    <legend>集荷（今回分・新規サイクル）</legend>
                    <div class="form-row">
                        <label for="pickup_bag_count">集荷リネン袋数</label>
                        <input type="number" id="pickup_bag_count" name="pickup_bag_count" min="0" step="1">
                    </div>
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="pickup_date">集荷日</label>
                            <input type="date" id="pickup_date" name="pickup_date" value="<?= (new DateTime())->format('Y-m-d') ?>">
                        </div>
                        <div class="form-row">
                            <label for="pickup_time">集荷時間</label>
                            <input type="time" id="pickup_time" name="pickup_time" value="<?= (new DateTime())->format('H:i') ?>">
                        </div>
                        <div class="form-row">
                            <label for="pickup_employee_id">集荷担当者</label>
                            <select id="pickup_employee_id" name="pickup_employee_id">
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === (int) $staff['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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

            <p class="notice">日付・時間は現在日時、担当者はログイン中のご本人（<?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?>）を初期値にしています。必要に応じて変更できます。リネン袋数の入力欄が空欄のままの項目は今回記録されません。</p>
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
                    <label for="e_arrival_date">到着日</label>
                    <input type="date" id="e_arrival_date" name="arrival_date" value="<?= htmlspecialchars($editFormValues['arrival_date'], ENT_QUOTES, 'UTF-8') ?>">
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
                    <label for="e_arrival_facility_id">到着クリーニング所</label>
                    <select id="e_arrival_facility_id" name="arrival_facility_id">
                        <option value="">未設定</option>
                        <?php foreach ($cleaningFacilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $editFormValues['arrival_facility_id'] ? 'selected' : '' ?>><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
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
                    <label for="e_dispatch_facility_id">発送元クリーニング所</label>
                    <select id="e_dispatch_facility_id" name="dispatch_facility_id">
                        <option value="">未設定</option>
                        <?php foreach ($cleaningFacilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (string) $facility['id'] === $editFormValues['dispatch_facility_id'] ? 'selected' : '' ?>><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="e_return_bag_count">返却リネン袋数</label>
                    <input type="number" id="e_return_bag_count" name="return_bag_count" min="0" step="1" value="<?= htmlspecialchars($editFormValues['return_bag_count'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-row">
                    <label for="e_return_date">返却日</label>
                    <input type="date" id="e_return_date" name="return_date" value="<?= htmlspecialchars($editFormValues['return_date'], ENT_QUOTES, 'UTF-8') ?>">
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

<section class="cycle-list">
    <h2>集荷サイクル一覧（返却未完了）</h2>
    <p class="notice">この記録簿はチーム共有のため、記録した本人以外でも登録・修正・削除できます。</p>

    <?php
    $facilityPanelGroups = $openCycleFacilityPanelGroups;
    $renderCycleCard = $renderOpenCycleCard;
    $emptyMessage = '対応が必要な集荷サイクルはありません。';
    require __DIR__ . '/../includes/facility_panel_list.php';
    ?>
</section>

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

<script>
document.querySelectorAll('input[name="arrival_cycle_id"][type="radio"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var bagCountInput = document.getElementById('arrival_bag_count');
        if (bagCountInput) {
            bagCountInput.value = this.dataset.pickupBagCount || '';
        }
    });
});
document.querySelectorAll('input[name="dispatch_cycle_id"][type="radio"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var bagCountInput = document.getElementById('dispatch_bag_count');
        if (bagCountInput) {
            bagCountInput.value = this.dataset.returnReadyBagCount || '';
        }
    });
});
</script>
</body>
</html>
