<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll.php';

$admin = require_login('admin');
$pdo = getPdo();
sync_employee_wages_from_history($pdo);

$todayStr = (new DateTime('today'))->format('Y-m-d');

function employee_redirect(int $employeeId, string $anchor = ''): void
{
    header('Location: /admin/payroll_employees.php?employee_id=' . $employeeId . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
}

/** 空欄なら null、0以上の整数なら int、それ以外は例外 */
function optional_int($value, string $label): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{1,9}$/', $value)) {
        throw new InvalidArgumentException($label . 'は0以上の整数で入力してください。');
    }
    return (int) $value;
}

function fetch_employee(PDO $pdo, int $employeeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.name, e.role, e.status, e.hourly_wage_weekday, e.hourly_wage_holiday, e.commute_allowance_type, e.commute_allowance_amount,
                COALESCE(p.payroll_enabled, 1) AS payroll_enabled, p.gender
         FROM employees e LEFT JOIN pay_employees p ON p.employee_id = e.id WHERE e.id = :id'
    );
    $stmt->execute([':id' => $employeeId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        try {
            if (fetch_employee($pdo, $employeeId) === null) {
                throw new InvalidArgumentException('従業員が見つかりません。');
            }
            if ($action === 'save_profile') {
                $enabled = isset($_POST['payroll_enabled']) ? 1 : 0;
                $gender = (string) ($_POST['gender'] ?? '');
                if ($gender !== '' && !isset(PAY_GENDER_LABELS[$gender])) {
                    throw new InvalidArgumentException('性別の指定が正しくありません。');
                }
                $pdo->prepare(
                    'INSERT INTO pay_employees (employee_id, payroll_enabled, gender, updated_at) VALUES (:id, :enabled, :gender, NOW())
                     ON DUPLICATE KEY UPDATE payroll_enabled = VALUES(payroll_enabled), gender = VALUES(gender), updated_at = NOW()'
                )->execute([':id' => $employeeId, ':enabled' => $enabled, ':gender' => $gender === '' ? null : $gender]);
                set_flash('success', '給与計算対象・性別を保存しました。');
                employee_redirect($employeeId, 'profile');
            } elseif ($action === 'save_terms') {
                $effectiveFrom = (string) ($_POST['effective_from'] ?? '');
                pay_assert_date($effectiveFrom, '適用開始日');
                $taxColumn = (string) ($_POST['tax_column'] ?? '');
                $dependents = optional_int($_POST['dependents'] ?? '', '扶養親族等の数');
                $empInsurance = isset($_POST['emp_insurance']) ? 1 : 0;
                $residentTaxMethod = (string) ($_POST['resident_tax_method'] ?? '');
                $commuteMethod = (string) ($_POST['commute_method'] ?? '');
                $distance = trim((string) ($_POST['commute_distance_km'] ?? ''));
                $publicMonthly = optional_int($_POST['commute_public_monthly'] ?? '', '公共交通機関部分の月額');
                $parkingPayType = (string) ($_POST['parking_pay_type'] ?? 'none');
                $parkingPayAmount = optional_int($_POST['parking_pay_amount'] ?? '', '駐車場代の支給額') ?? 0;
                $parkingFeeType = (string) ($_POST['parking_fee_type'] ?? '');
                $parkingFeeAmount = optional_int($_POST['parking_fee_amount'] ?? '', '駐車場等の料金');
                $parkingQualified = isset($_POST['parking_qualified']) ? 1 : 0;
                $prefecture = trim((string) ($_POST['work_prefecture'] ?? ''));

                if (!in_array($taxColumn, ['kou', 'otsu'], true)) {
                    throw new InvalidArgumentException('甲欄・乙欄を選択してください。');
                }
                if ($dependents === null || $dependents > 20) {
                    throw new InvalidArgumentException('扶養親族等の数を0〜20で入力してください（8人以上は給与計算で手計算のエラーになります）。');
                }
                if ($taxColumn === 'otsu' && $dependents > 0) {
                    throw new InvalidArgumentException('乙欄の場合、扶養親族等の数は0にしてください。');
                }
                if (!isset(PAY_RESIDENT_TAX_METHOD_LABELS[$residentTaxMethod])) {
                    throw new InvalidArgumentException('住民税の徴収方法を選択してください。');
                }
                if ($commuteMethod !== '' && !isset(PAY_COMMUTE_METHOD_LABELS[$commuteMethod])) {
                    throw new InvalidArgumentException('通勤手段の指定が正しくありません。');
                }
                if ($distance !== '' && !preg_match('/^\d{1,3}(\.\d)?$/', $distance)) {
                    throw new InvalidArgumentException('片道距離は km（小数第1位まで）で入力してください。');
                }
                if (in_array($commuteMethod, ['car', 'mixed'], true) && $distance === '') {
                    throw new InvalidArgumentException('通勤手段が自動車等・併用の場合は、自動車等を使う片道距離を入力してください。');
                }
                if ($commuteMethod === 'mixed' && $publicMonthly === null) {
                    throw new InvalidArgumentException('通勤手段が併用の場合は、公共交通機関部分の月額（合理的な運賃等の額）を入力してください。');
                }
                if (!isset(PAY_PARKING_PAY_TYPE_LABELS[$parkingPayType])) {
                    throw new InvalidArgumentException('駐車場代の支給方法の指定が正しくありません。');
                }
                if ($parkingPayType !== 'none' && $parkingPayAmount === 0) {
                    throw new InvalidArgumentException('駐車場代を支給する場合は支給額を入力してください。');
                }
                if ($parkingFeeType !== '' && !isset(PAY_PARKING_FEE_TYPE_LABELS[$parkingFeeType])) {
                    throw new InvalidArgumentException('駐車場等の料金の定め方の指定が正しくありません。');
                }
                if (($parkingFeeType === '') !== ($parkingFeeAmount === null)) {
                    throw new InvalidArgumentException('本人が負担する駐車場等の料金は、定め方と金額を両方入力してください（駐車場を利用しない場合は両方空欄）。');
                }
                if ($prefecture === '' || mb_strlen($prefecture) > 10) {
                    throw new InvalidArgumentException('就業地（都道府県）を入力してください。');
                }

                $pdo->prepare(
                    'INSERT INTO pay_employee_terms (employee_id, effective_from, tax_column, dependents, emp_insurance, resident_tax_method,
                        commute_method, commute_distance_km, commute_public_monthly, parking_pay_type, parking_pay_amount,
                        parking_fee_type, parking_fee_amount, parking_qualified, work_prefecture, created_by)
                     VALUES (:employee_id, :effective_from, :tax_column, :dependents, :emp_insurance, :resident_tax_method,
                        :commute_method, :distance, :public_monthly, :parking_pay_type, :parking_pay_amount,
                        :parking_fee_type, :parking_fee_amount, :parking_qualified, :prefecture, :created_by)
                     ON DUPLICATE KEY UPDATE tax_column = VALUES(tax_column), dependents = VALUES(dependents), emp_insurance = VALUES(emp_insurance),
                        resident_tax_method = VALUES(resident_tax_method), commute_method = VALUES(commute_method),
                        commute_distance_km = VALUES(commute_distance_km), commute_public_monthly = VALUES(commute_public_monthly),
                        parking_pay_type = VALUES(parking_pay_type), parking_pay_amount = VALUES(parking_pay_amount),
                        parking_fee_type = VALUES(parking_fee_type), parking_fee_amount = VALUES(parking_fee_amount),
                        parking_qualified = VALUES(parking_qualified), work_prefecture = VALUES(work_prefecture),
                        created_by = VALUES(created_by), created_at = CURRENT_TIMESTAMP'
                )->execute([
                    ':employee_id' => $employeeId,
                    ':effective_from' => $effectiveFrom,
                    ':tax_column' => $taxColumn,
                    ':dependents' => $dependents,
                    ':emp_insurance' => $empInsurance,
                    ':resident_tax_method' => $residentTaxMethod,
                    ':commute_method' => $commuteMethod === '' ? null : $commuteMethod,
                    ':distance' => $distance === '' ? null : $distance,
                    ':public_monthly' => $commuteMethod === 'mixed' ? $publicMonthly : null,
                    ':parking_pay_type' => $parkingPayType,
                    ':parking_pay_amount' => $parkingPayType === 'none' ? 0 : $parkingPayAmount,
                    ':parking_fee_type' => $parkingFeeType === '' ? null : $parkingFeeType,
                    ':parking_fee_amount' => $parkingFeeAmount,
                    ':parking_qualified' => $parkingQualified,
                    ':prefecture' => $prefecture,
                    ':created_by' => (int) $admin['id'],
                ]);
                set_flash('success', '給与設定（' . $effectiveFrom . ' から適用）を保存しました。下書きの給与計算は「再計算」で反映されます。');
                employee_redirect($employeeId, 'terms');
            } elseif ($action === 'delete_terms') {
                $pdo->prepare('DELETE FROM pay_employee_terms WHERE id = :id AND employee_id = :employee_id')
                    ->execute([':id' => (int) ($_POST['terms_id'] ?? 0), ':employee_id' => $employeeId]);
                set_flash('success', '給与設定の履歴を1件削除しました。');
                employee_redirect($employeeId, 'terms');
            } elseif ($action === 'save_resident_tax') {
                $fiscalYear = optional_int($_POST['fiscal_year'] ?? '', '年度');
                $juneAmount = optional_int($_POST['june_amount'] ?? '', '6月分');
                $monthlyAmount = optional_int($_POST['monthly_amount'] ?? '', '7月〜翌5月分');
                $municipality = trim((string) ($_POST['municipality'] ?? ''));
                if ($fiscalYear === null || $fiscalYear < 2020 || $fiscalYear > 2100 || $juneAmount === null || $monthlyAmount === null) {
                    throw new InvalidArgumentException('年度・6月分・7月〜翌5月分を入力してください。');
                }
                if (mb_strlen($municipality) > 50) {
                    throw new InvalidArgumentException('市区町村名は50文字以内で入力してください。');
                }
                $pdo->prepare(
                    'INSERT INTO pay_resident_tax (employee_id, fiscal_year, june_amount, monthly_amount, municipality)
                     VALUES (:employee_id, :fiscal_year, :june, :monthly, :municipality)
                     ON DUPLICATE KEY UPDATE june_amount = VALUES(june_amount), monthly_amount = VALUES(monthly_amount), municipality = VALUES(municipality)'
                )->execute([
                    ':employee_id' => $employeeId, ':fiscal_year' => $fiscalYear, ':june' => $juneAmount,
                    ':monthly' => $monthlyAmount, ':municipality' => $municipality === '' ? null : $municipality,
                ]);
                set_flash('success', $fiscalYear . '年度の住民税を保存しました。');
                employee_redirect($employeeId, 'resident');
            } elseif ($action === 'delete_resident_tax') {
                $pdo->prepare('DELETE FROM pay_resident_tax WHERE id = :id AND employee_id = :employee_id')
                    ->execute([':id' => (int) ($_POST['resident_tax_id'] ?? 0), ':employee_id' => $employeeId]);
                set_flash('success', '住民税の登録を1件削除しました。');
                employee_redirect($employeeId, 'resident');
            }
        } catch (InvalidArgumentException $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();
$selectedId = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : (int) ($_POST['employee_id'] ?? 0);
$selected = $selectedId > 0 ? fetch_employee($pdo, $selectedId) : null;
$currentFiscalYear = pay_resident_tax_fiscal_year($todayStr);

$employees = $pdo->query(
    "SELECT e.id, e.name, e.role, e.status, e.hourly_wage_weekday, e.hourly_wage_holiday, e.commute_allowance_type, e.commute_allowance_amount,
            COALESCE(p.payroll_enabled, 1) AS payroll_enabled, p.gender
     FROM employees e LEFT JOIN pay_employees p ON p.employee_id = e.id
     ORDER BY COALESCE(p.payroll_enabled, 1) DESC, FIELD(e.status, 'active', 'invited', 'disabled'), e.id"
)->fetchAll();

pay_render_header($admin, '従業員の給与設定', 'employees');
pay_render_messages($flash, $errorMessage);
?>

<section>
    <h2>一覧（<?= pay_h($todayStr) ?> 時点）</h2>
    <div class="scroll">
    <table class="grid">
        <thead><tr>
            <th>氏名</th><th>状態</th><th>給与計算</th><th>性別</th><th>甲乙・扶養</th><th>雇用保険</th><th>住民税</th>
            <th>通勤手段</th><th>就業地</th><th class="num">平日時給</th><th>交通費</th><th>未設定</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $emp): ?>
            <?php
            $terms = pay_terms_on($pdo, (int) $emp['id'], $todayStr);
            $missing = [];
            if ((int) $emp['payroll_enabled'] === 1) {
                if ($terms === null) {
                    $missing[] = '給与設定';
                } else {
                    if ((int) $emp['commute_allowance_amount'] > 0 && $terms['commute_method'] === null) {
                        $missing[] = '通勤手段';
                    }
                    if ($terms['resident_tax_method'] === 'special' && pay_resident_tax_row($pdo, (int) $emp['id'], $currentFiscalYear) === null) {
                        $missing[] = $currentFiscalYear . '年度住民税';
                    }
                }
                if ($emp['gender'] === null) {
                    $missing[] = '性別';
                }
            }
            ?>
            <tr class="<?= ((int) $emp['payroll_enabled'] === 0 || $emp['status'] === 'disabled') ? 'muted' : '' ?>">
                <td><?= pay_h($emp['name']) ?></td>
                <td><?= pay_h(['active' => '有効', 'invited' => '招待中', 'disabled' => '無効'][$emp['status']] ?? $emp['status']) ?></td>
                <td><?= (int) $emp['payroll_enabled'] === 1 ? '対象' : '対象外' ?></td>
                <td><?= pay_h(PAY_GENDER_LABELS[$emp['gender']] ?? '—') ?></td>
                <td><?= $terms === null ? '—' : ($terms['tax_column'] === 'kou' ? '甲・扶養' . (int) $terms['dependents'] . '人' : '乙') ?></td>
                <td><?= $terms === null ? '—' : ((int) $terms['emp_insurance'] === 1 ? '加入' : '—') ?></td>
                <td><?= $terms === null ? '—' : ($terms['resident_tax_method'] === 'special' ? '特別徴収' : '普通徴収') ?></td>
                <td><?= $terms === null || $terms['commute_method'] === null ? '—' : pay_h(PAY_COMMUTE_METHOD_LABELS[$terms['commute_method']]) . ($terms['commute_distance_km'] !== null ? ' ' . pay_h($terms['commute_distance_km']) . 'km' : '') ?></td>
                <td><?= $terms === null ? '—' : pay_h($terms['work_prefecture']) ?></td>
                <td class="num"><?= pay_yen((int) $emp['hourly_wage_weekday']) ?></td>
                <td><?= $emp['commute_allowance_type'] === 'monthly' ? '月額' : '日額' ?> <?= pay_yen((int) $emp['commute_allowance_amount']) ?></td>
                <td style="color:#b3261e;"><?= pay_h(implode('・', $missing)) ?></td>
                <td><a href="/admin/payroll_employees.php?employee_id=<?= (int) $emp['id'] ?>">設定</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="small">時給・交通費（金額）・手当は <a href="/admin/employees.php">従業員管理</a> で登録します。給与計算対象外（オーナー・共用アカウント・検証用など）は給与計算に出ません。無効化済みでも、計算期間内に勤怠がある人は給与計算の対象になります。</p>
</section>

<?php if ($selected !== null): ?>
    <?php
    $termsHistory = $pdo->prepare('SELECT * FROM pay_employee_terms WHERE employee_id = :id ORDER BY effective_from DESC');
    $termsHistory->execute([':id' => $selected['id']]);
    $termsHistory = $termsHistory->fetchAll();
    $latest = $termsHistory[0] ?? null;
    $residentRows = $pdo->prepare('SELECT * FROM pay_resident_tax WHERE employee_id = :id ORDER BY fiscal_year DESC');
    $residentRows->execute([':id' => $selected['id']]);
    $residentRows = $residentRows->fetchAll();
    $wageHistory = wage_history_for_employee($pdo, (int) $selected['id']);
    $allowances = get_employee_allowances($pdo, (int) $selected['id']);
    $v = static fn (string $key, $default = '') => $latest !== null && $latest[$key] !== null ? $latest[$key] : $default;
    ?>
    <section>
        <h2><?= pay_h($selected['name']) ?>さんの給与設定</h2>

        <fieldset id="profile">
            <legend>給与計算対象・性別</legend>
            <form method="post" action="/admin/payroll_employees.php">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_profile">
                <input type="hidden" name="employee_id" value="<?= (int) $selected['id'] ?>">
                <label><input type="checkbox" name="payroll_enabled" value="1" <?= (int) $selected['payroll_enabled'] === 1 ? 'checked' : '' ?>> 給与計算の対象にする</label>
                　性別
                <select name="gender">
                    <option value="">未設定</option>
                    <?php foreach (PAY_GENDER_LABELS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $selected['gender'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">保存</button>
            </form>
        </fieldset>

        <fieldset id="terms">
            <legend>源泉・雇用保険・住民税・通勤の設定（適用開始日ごとの履歴）</legend>
            <?php if (!empty($termsHistory)): ?>
            <div class="scroll">
            <table class="grid">
                <thead><tr><th>適用開始日</th><th>甲乙</th><th class="num">扶養</th><th>雇用保険</th><th>住民税</th><th>通勤手段</th><th class="num">片道距離</th>
                    <th class="num">公共交通（併用）</th><th>駐車場代の支給</th><th>駐車場等の料金</th><th>要件</th><th>就業地</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($termsHistory as $t): ?>
                    <tr>
                        <td><?= pay_h($t['effective_from']) ?></td>
                        <td><?= $t['tax_column'] === 'kou' ? '甲' : '乙' ?></td>
                        <td class="num"><?= (int) $t['dependents'] ?>人</td>
                        <td><?= (int) $t['emp_insurance'] === 1 ? '加入' : '—' ?></td>
                        <td><?= $t['resident_tax_method'] === 'special' ? '特別徴収' : '普通徴収' ?></td>
                        <td><?= $t['commute_method'] === null ? '—' : pay_h(PAY_COMMUTE_METHOD_LABELS[$t['commute_method']]) ?></td>
                        <td class="num"><?= $t['commute_distance_km'] !== null ? pay_h($t['commute_distance_km']) . 'km' : '—' ?></td>
                        <td class="num"><?= $t['commute_public_monthly'] !== null ? pay_yen((int) $t['commute_public_monthly']) : '—' ?></td>
                        <td><?= pay_h(PAY_PARKING_PAY_TYPE_LABELS[$t['parking_pay_type']]) ?><?= $t['parking_pay_type'] !== 'none' ? ' ' . pay_yen((int) $t['parking_pay_amount']) : '' ?></td>
                        <td><?= $t['parking_fee_type'] === null ? '—' : pay_h(PAY_PARKING_FEE_TYPE_LABELS[$t['parking_fee_type']]) . ' ' . pay_yen((int) $t['parking_fee_amount']) ?></td>
                        <td><?= (int) $t['parking_qualified'] === 1 ? '該当' : '—' ?></td>
                        <td><?= pay_h($t['work_prefecture']) ?></td>
                        <td><form method="post" action="/admin/payroll_employees.php" class="inline-form" onsubmit="return confirm('この適用開始日の設定を削除しますか？（確定済みの給与明細は変わりません）');">
                            <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="delete_terms">
                            <input type="hidden" name="employee_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="terms_id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="danger">削除</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <p class="notice">まだ設定がありません。給与計算で確定するには登録が必要です。</p>
            <?php endif; ?>

            <h3>設定を登録（同じ適用開始日なら上書き）</h3>
            <form method="post" action="/admin/payroll_employees.php">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_terms">
                <input type="hidden" name="employee_id" value="<?= (int) $selected['id'] ?>">
                <div class="form-row"><label class="caption">適用開始日</label><input type="date" name="effective_from" value="<?= pay_h($latest === null ? '2026-01-01' : $todayStr) ?>" required>
                    <span class="small">給与計算では、勤務月の末日時点で有効な設定を使います</span></div>
                <div class="form-row"><label class="caption">源泉徴収</label>
                    <label><input type="radio" name="tax_column" value="kou" <?= $v('tax_column', 'kou') === 'kou' ? 'checked' : '' ?>> 甲欄（扶養控除等申告書の提出あり）</label>
                    <label><input type="radio" name="tax_column" value="otsu" <?= $v('tax_column') === 'otsu' ? 'checked' : '' ?>> 乙欄</label></div>
                <div class="form-row"><label class="caption">源泉控除対象の扶養親族等の数</label><input type="number" name="dependents" min="0" max="20" value="<?= (int) $v('dependents', 0) ?>" required>人</div>
                <div class="form-row"><label class="caption">雇用保険</label><label><input type="checkbox" name="emp_insurance" value="1" <?= (int) $v('emp_insurance', 0) === 1 ? 'checked' : '' ?>> 被保険者</label></div>
                <div class="form-row"><label class="caption">住民税</label>
                    <?php foreach (PAY_RESIDENT_TAX_METHOD_LABELS as $key => $label): ?>
                        <label><input type="radio" name="resident_tax_method" value="<?= $key ?>" <?= $v('resident_tax_method', 'ordinary') === $key ? 'checked' : '' ?>> <?= pay_h($label) ?></label>
                    <?php endforeach; ?></div>
                <div class="form-row"><label class="caption">通勤手段</label>
                    <select name="commute_method">
                        <option value="">未設定（交通費なし）</option>
                        <?php foreach (PAY_COMMUTE_METHOD_LABELS as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $v('commute_method') === $key ? 'selected' : '' ?>><?= pay_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    片道距離（自動車等） <input type="text" name="commute_distance_km" size="6" value="<?= pay_h($v('commute_distance_km')) ?>">km
                    公共交通機関部分の月額（併用のみ） <input type="number" name="commute_public_monthly" min="0" value="<?= pay_h($v('commute_public_monthly')) ?>">円</div>
                <div class="form-row"><label class="caption">駐車場代の支給</label>
                    <select name="parking_pay_type">
                        <?php foreach (PAY_PARKING_PAY_TYPE_LABELS as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $v('parking_pay_type', 'none') === $key ? 'selected' : '' ?>><?= pay_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    支給額 <input type="number" name="parking_pay_amount" min="0" value="<?= (int) $v('parking_pay_amount', 0) ?>">円（月額 or 1回あたり）</div>
                <div class="form-row"><label class="caption">本人負担の駐車場等の料金</label>
                    <select name="parking_fee_type">
                        <option value="">利用なし</option>
                        <?php foreach (PAY_PARKING_FEE_TYPE_LABELS as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $v('parking_fee_type') === $key ? 'selected' : '' ?>><?= pay_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="parking_fee_amount" min="0" value="<?= pay_h($v('parking_fee_amount')) ?>">円（税込。月額 or 1回あたり）
                    <label><input type="checkbox" name="parking_qualified" value="1" <?= (int) $v('parking_qualified', 0) === 1 ? 'checked' : '' ?>> 勤務場所または利用駅等の周辺の駐車場（自宅付近は対象外）</label></div>
                <div class="form-row"><label class="caption">就業地（最低賃金の判定）</label><input type="text" name="work_prefecture" size="8" value="<?= pay_h($v('work_prefecture', PAY_DEFAULT_WORK_PREFECTURE)) ?>" required></div>
                <button type="submit">登録</button>
            </form>
        </fieldset>

        <fieldset id="resident">
            <legend>住民税（特別徴収の月割額。6月〜翌5月）</legend>
            <table class="grid">
                <thead><tr><th>年度</th><th class="num">6月分</th><th class="num">7月〜翌5月分</th><th>市区町村</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($residentRows)): ?><tr><td colspan="5">未登録</td></tr><?php endif; ?>
                <?php foreach ($residentRows as $rt): ?>
                    <tr><td><?= (int) $rt['fiscal_year'] ?>年度（<?= (int) $rt['fiscal_year'] ?>年6月〜<?= (int) $rt['fiscal_year'] + 1 ?>年5月）</td>
                        <td class="num"><?= pay_yen((int) $rt['june_amount']) ?></td><td class="num"><?= pay_yen((int) $rt['monthly_amount']) ?></td><td><?= pay_h($rt['municipality'] ?? '') ?></td>
                        <td><form method="post" action="/admin/payroll_employees.php" class="inline-form" onsubmit="return confirm('この年度の住民税を削除しますか？');">
                            <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="delete_resident_tax">
                            <input type="hidden" name="employee_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="resident_tax_id" value="<?= (int) $rt['id'] ?>">
                            <button type="submit" class="danger">削除</button></form></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="/admin/payroll_employees.php">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="save_resident_tax">
                <input type="hidden" name="employee_id" value="<?= (int) $selected['id'] ?>">
                <input type="number" name="fiscal_year" min="2020" max="2100" value="<?= $currentFiscalYear ?>" style="width:70px;" required>年度
                6月分 <input type="number" name="june_amount" min="0" required>円
                7月〜翌5月分 <input type="number" name="monthly_amount" min="0" required>円
                市区町村 <input type="text" name="municipality" maxlength="50" size="12">
                <button type="submit">登録・更新</button>
            </form>
            <p class="small">住民税の徴収方法が「特別徴収」の場合だけ給与から控除します。支給日が6月なら6月分、それ以外は7月〜翌5月分の額です（年度は支給日で判定）。</p>
        </fieldset>

        <fieldset>
            <legend>時給・交通費・手当（従業員管理で登録）</legend>
            <ul>
                <?php foreach (array_reverse($wageHistory) as $wh): ?>
                    <li><?= pay_h($wh['effective_from']) ?>〜 平日<?= pay_yen($wh['wage_weekday']) ?> / 土日祝<?= pay_yen($wh['wage_holiday']) ?><?= $wh['effective_from'] > $todayStr ? '（予定）' : '' ?></li>
                <?php endforeach; ?>
                <li>交通費: <?= $selected['commute_allowance_type'] === 'monthly' ? '月額' : '日額（出勤1回あたり）' ?> <?= pay_yen((int) $selected['commute_allowance_amount']) ?></li>
                <li>手当: <?= empty($allowances) ? 'なし' : pay_h(implode('、', array_map(static fn (array $a): string => $a['name'] . ' ' . number_format((int) $a['monthly_amount']) . '円', $allowances))) ?></li>
            </ul>
            <a href="/admin/employees.php">従業員管理で変更する</a>
        </fieldset>
    </section>
<?php endif; ?>
</body>
</html>
