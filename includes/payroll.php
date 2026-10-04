<?php
/**
 * 給与計算（admin/payroll*.php）の共通処理。
 * 勤怠からの賃金計算は includes/wage_calc.php（functions.php 経由で読み込み済み）を使い、
 * ここでは計算期間・支給日・通勤手当の非課税判定・雇用保険・源泉所得税・住民税・明細の保存を扱う。
 * 金額はすべて整数（円）で計算し、浮動小数は使わない。
 */

require_once __DIR__ . '/spreadsheet_reader.php';

const PAY_STATUS_LABELS = ['draft' => '下書き', 'closed' => '確定', 'void' => '取消'];
const PAY_GENDER_LABELS = ['male' => '男', 'female' => '女', 'other' => 'その他'];
const PAY_COMMUTE_METHOD_LABELS = ['public' => '公共交通機関', 'car' => '自動車等', 'mixed' => '併用'];
const PAY_RESIDENT_TAX_METHOD_LABELS = ['special' => '特別徴収（給与から天引き）', 'ordinary' => '普通徴収（本人納付）'];
const PAY_PARKING_PAY_TYPE_LABELS = ['none' => 'なし', 'monthly' => '月額', 'daily' => '日額（出勤回数）'];
const PAY_PARKING_FEE_TYPE_LABELS = ['monthly' => '月単位', 'per_use' => '利用の都度'];
const PAY_WITHHOLDING_TABLE_MAX = 740000; // 月額表の表引きで求められる上限（これ以上は計算式のため手計算）
const PAY_WITHHOLDING_MAX_DEPENDENTS = 7;
const PAY_DAYS_PER_WEEK = 7;
const PAY_RESIDENT_TAX_MONTHS = [6, 7, 8, 9, 10, 11, 12, 1, 2, 3, 4, 5]; // 住民税の年度内の月順（6月始まり）

/** 給与計算画面の共通ヘッダー（管理者のみ。各ページで require_login('admin') 済みであること） */
function pay_render_header(array $admin, string $title, string $current = ''): void
{
    $links = [
        'payroll' => ['/admin/payroll.php', '給与計算'],
        'employees' => ['/admin/payroll_employees.php', '従業員の給与設定'],
        'settings' => ['/admin/payroll_settings.php', '給与設定'],
        'ledger' => ['/admin/payroll_ledger.php', '賃金台帳'],
    ];
    $nav = 'ログイン中: ' . htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') . 'さん（管理者）';
    foreach ($links as $key => [$href, $label]) {
        $nav .= ' | ' . ($key === $current ? '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>' : '<a href="' . $href . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>');
    }
    $nav .= ' | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a>';
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        h2 { font-size: 1.1em; margin: 20px 0 8px; }
        h3 { font-size: 1em; margin: 16px 0 6px; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; white-space: pre-wrap; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; margin: 8px 0; }
        .errors { color: #b3261e; font-size: 0.85em; margin: 0; padding-left: 1.1em; }
        .warnings { color: #856404; font-size: 0.85em; margin: 0; padding-left: 1.1em; }
        table.grid { border-collapse: collapse; margin-bottom: 12px; }
        table.grid th, table.grid td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; font-size: 0.9em; }
        table.grid th { background: #f5f5f5; white-space: nowrap; }
        table.grid td.num, table.grid th.num { text-align: right; white-space: nowrap; }
        table.grid tr.total td { font-weight: bold; background: #fafafa; }
        table.grid tr.muted td { color: #999; }
        .scroll { overflow-x: auto; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 10px 12px; margin-bottom: 12px; }
        legend { font-weight: bold; }
        .form-row { margin-bottom: 6px; }
        .form-row label.caption { display: inline-block; min-width: 170px; }
        input[type="number"] { width: 110px; }
        .inline-form { display: inline; }
        .badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .badge-draft { background: #fff3cd; color: #856404; }
        .badge-closed { background: #e6f4ea; color: #1e7e34; }
        .badge-void { background: #eee; color: #777; }
        .small { font-size: 0.85em; color: #555; }
        button.danger { color: #b3261e; }
    </style>
</head>
<body>
<header>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <nav><?= $nav ?></nav>
</header>
    <?php
}

function pay_render_messages(?array $flash, string $errorMessage): void
{
    if ($flash !== null) {
        echo '<p class="message ' . htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($errorMessage !== '') {
        echo '<p class="message error">' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

function pay_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pay_yen(int $amount): string
{
    return number_format($amount) . '円';
}

// ---------------------------------------------------------------------------
// 設定・マスタの参照
// ---------------------------------------------------------------------------

function pay_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM pay_settings WHERE id = 1')->fetch();
    if ($row === false) {
        throw new RuntimeException('pay_settings が未作成です。');
    }
    return $row;
}

/** DECIMAL文字列を、小数第 $scale 位までの整数にする（"0.00550", 5 → 550 / "3.063", 3 → 3063） */
function pay_decimal_to_int(string $decimal, int $scale): int
{
    $decimal = trim($decimal);
    $negative = strncmp($decimal, '-', 1) === 0;
    [$intPart, $fracPart] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '');
    $fracPart = substr(str_pad($fracPart, $scale, '0'), 0, $scale);
    $value = (int) $intPart * (10 ** $scale) + (int) $fracPart;
    return $negative ? -$value : $value;
}

function pay_last_day_of_month(int $year, int $month): int
{
    return (int) (new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');
}

/**
 * 勤務月（YYYY-MM）の賃金計算期間。締日が月末日以上なら勤務月の1日〜末日、
 * それ以外は「前月の締日の翌日〜勤務月の締日」。
 *
 * @return array{0:string,1:string}
 */
function pay_period_for_month(array $settings, string $workMonth): array
{
    [$year, $month] = array_map('intval', explode('-', $workMonth));
    $closingDay = (int) $settings['closing_day'];

    $endDay = min($closingDay, pay_last_day_of_month($year, $month));
    $end = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $endDay));

    $prev = (new DateTime(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');
    $prevEndDay = min($closingDay, (int) $prev->format('t'));
    $start = (new DateTime($prev->format('Y-m-') . sprintf('%02d', $prevEndDay)))->modify('+1 day');

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/** 既定の支給日（支給月の支給日。土日祝なら直前の平日に前倒し） */
function pay_default_pay_date(PDO $pdo, array $settings, string $workMonth): string
{
    $base = (new DateTime($workMonth . '-01'))->modify('+' . (int) $settings['pay_month_offset'] . ' month');
    $day = min((int) $settings['pay_day'], (int) $base->format('t'));
    $date = new DateTime($base->format('Y-m-') . sprintf('%02d', $day));
    for ($guard = 0; $guard < 14 && is_holiday_date($pdo, $date->format('Y-m-d')); $guard++) {
        $date->modify('-1 day');
    }
    return $date->format('Y-m-d');
}

function pay_terms_on(PDO $pdo, int $employeeId, string $date): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM pay_employee_terms WHERE employee_id = :employee_id AND effective_from <= :date
         ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->execute([':employee_id' => $employeeId, ':date' => $date]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** 住民税の年度（6月始まり）。2027-05-10 → 2026、2027-06-10 → 2027 */
function pay_resident_tax_fiscal_year(string $payDate): int
{
    $year = (int) substr($payDate, 0, 4);
    return (int) substr($payDate, 5, 2) >= 6 ? $year : $year - 1;
}

/**
 * 住民税（特別徴収）の年度内の月別額（pay_resident_tax_months）。[月 => 額] を 6月〜翌5月の順で返す。
 * 未登録の年度は空配列。
 */
function pay_resident_tax_months(PDO $pdo, int $employeeId, int $fiscalYear): array
{
    $stmt = $pdo->prepare('SELECT month, amount FROM pay_resident_tax_months WHERE employee_id = :employee_id AND fiscal_year = :fiscal_year');
    $stmt->execute([':employee_id' => $employeeId, ':fiscal_year' => $fiscalYear]);
    $amounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $amounts[(int) $row['month']] = (int) $row['amount'];
    }
    if (empty($amounts)) {
        return [];
    }
    $months = [];
    foreach (PAY_RESIDENT_TAX_MONTHS as $month) {
        $months[$month] = $amounts[$month] ?? 0;
    }
    return $months;
}

function pay_emp_insurance_rate_on(PDO $pdo, string $date): ?string
{
    $stmt = $pdo->prepare('SELECT employee_rate FROM pay_emp_insurance_rates WHERE effective_from <= :date ORDER BY effective_from DESC LIMIT 1');
    $stmt->execute([':date' => $date]);
    $rate = $stmt->fetchColumn();
    return $rate === false ? null : (string) $rate;
}

/**
 * 雇用保険料（労働者負担）。賃金額×料率の 50銭以下切捨て・50銭超切上げ。
 * $rate は DECIMAL(7,5) の文字列（"0.00500"）。
 */
function pay_emp_insurance_premium(int $wage, string $rate): int
{
    if ($wage <= 0) {
        return 0;
    }
    $raw = $wage * pay_decimal_to_int($rate, 5); // 単位: 1/100000 円
    $yen = intdiv($raw, 100000);
    return $raw % 100000 > 50000 ? $yen + 1 : $yen;
}

/** 自動車等の片道距離に応じた非課税限度額（$date 時点で有効な区分表）。区分表が無ければ null */
function pay_commute_car_limit(PDO $pdo, string $distanceKm, string $date): ?int
{
    $effective = $pdo->prepare('SELECT MAX(effective_from) FROM pay_commute_limits WHERE effective_from <= :date');
    $effective->execute([':date' => $date]);
    $effectiveFrom = $effective->fetchColumn();
    if ($effectiveFrom === null || $effectiveFrom === false) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT limit_amount FROM pay_commute_limits
         WHERE effective_from = :effective_from AND min_km <= :km AND (max_km IS NULL OR max_km > :km2)
         ORDER BY min_km DESC LIMIT 1'
    );
    $stmt->execute([':effective_from' => $effectiveFrom, ':km' => $distanceKm, ':km2' => $distanceKm]);
    $limit = $stmt->fetchColumn();
    return $limit === false ? null : (int) $limit;
}

function pay_parking_rule_on(PDO $pdo, string $date): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pay_parking_rules WHERE effective_from <= :date ORDER BY effective_from DESC LIMIT 1');
    $stmt->execute([':date' => $date]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 通勤手当（交通費＋駐車場代）の非課税限度額を求める。
 * 公共交通機関: 月150,000円（pay_settings.public_transit_nontax_limit）
 * 自動車等:    距離区分の限度額＋駐車場等の料金相当額（上限あり、加算ルールの条件を満たす場合）
 * 併用:        運賃（commute_public_monthly）＋距離区分の限度額＋駐車場加算。合計は150,000円まで
 * 適用する区分表・加算ルールは支給日（支払われるべき日）時点のもの。
 *
 * @return array{limit:int, detail:array<string,mixed>}
 */
function pay_commute_nontax_limit(PDO $pdo, array $settings, array $terms, int $commuteTrips, string $payDate, array &$errors): array
{
    $cap = (int) $settings['public_transit_nontax_limit'];
    $method = $terms['commute_method'] ?? null;
    $detail = ['method' => $method, 'cap' => $cap];

    if ($method === null || $method === '') {
        $errors[] = '交通費・駐車場代の支給がありますが、通勤手段が未登録です（従業員の給与設定）。';
        return ['limit' => 0, 'detail' => $detail];
    }
    if ($method === 'public') {
        $detail['public'] = $cap;
        return ['limit' => $cap, 'detail' => $detail];
    }

    $distance = $terms['commute_distance_km'];
    if ($distance === null || $distance === '') {
        $errors[] = '通勤手段が「' . PAY_COMMUTE_METHOD_LABELS[$method] . '」ですが、自動車等の片道距離が未登録です。';
        return ['limit' => 0, 'detail' => $detail];
    }
    $carLimit = pay_commute_car_limit($pdo, (string) $distance, $payDate);
    if ($carLimit === null) {
        $errors[] = '支給日 ' . $payDate . ' 時点の通勤手当の非課税限度額（距離区分）が未登録です。';
        return ['limit' => 0, 'detail' => $detail];
    }
    $detail['distance_km'] = (string) $distance;
    $detail['car_limit'] = $carLimit;

    // 駐車場等の料金相当額の加算（国税庁 通勤手当の非課税限度額の改正Q&A Q3-1〜Q3-4）
    $parkingAdd = 0;
    $rule = pay_parking_rule_on($pdo, $payDate);
    if ($rule !== null && (int) $terms['parking_qualified'] === 1 && $terms['parking_fee_type'] !== null
        && pay_decimal_to_int((string) $distance, 1) >= pay_decimal_to_int((string) $rule['min_distance_km'], 1)) {
        $feeAmount = (int) $terms['parking_fee_amount'];
        $feeEquivalent = $terms['parking_fee_type'] === 'per_use' ? $feeAmount * $commuteTrips : $feeAmount;
        $parkingAdd = min($feeEquivalent, (int) $rule['cap_amount']);
        $detail['parking_fee_equivalent'] = $feeEquivalent;
        $detail['parking_cap'] = (int) $rule['cap_amount'];
    }
    $detail['parking_add'] = $parkingAdd;

    if ($method === 'car') {
        return ['limit' => min($cap, $carLimit + $parkingAdd), 'detail' => $detail];
    }

    // 併用
    if ($terms['commute_public_monthly'] === null) {
        $errors[] = '通勤手段が「併用」ですが、公共交通機関部分の月額（合理的な運賃等の額）が未登録です。';
        return ['limit' => 0, 'detail' => $detail];
    }
    $publicPart = (int) $terms['commute_public_monthly'];
    $detail['public'] = $publicPart;
    return ['limit' => min($cap, $publicPart + $carLimit + $parkingAdd), 'detail' => $detail];
}

/**
 * 源泉所得税（月額表）。表の範囲外（740,000円以上）・扶養8人以上・表の未取込はエラー（手計算が必要）。
 *
 * @return int|null 税額（エラー時 null）
 */
function pay_withholding_tax(PDO $pdo, int $taxableAmount, string $taxColumn, int $dependents, int $tableYear, array &$errors): ?int
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM pay_withholding_table WHERE table_year = :year');
    $count->execute([':year' => $tableYear]);
    if ((int) $count->fetchColumn() === 0) {
        $errors[] = $tableYear . '年分の源泉徴収税額表が未取込です（給与設定・税額表で取り込んでください）。';
        return null;
    }
    if ($taxableAmount <= 0) {
        return 0;
    }
    if ($taxColumn === 'kou' && $dependents > PAY_WITHHOLDING_MAX_DEPENDENTS) {
        $errors[] = '扶養親族等が' . $dependents . '人です（8人以上は表にないため手計算が必要です）。';
        return null;
    }
    if ($taxableAmount >= PAY_WITHHOLDING_TABLE_MAX) {
        $errors[] = '課税対象額が' . pay_yen($taxableAmount) . 'です（740,000円以上は月額表の計算式によるため手計算が必要です）。';
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM pay_withholding_table WHERE table_year = :year AND min_amount <= :amount AND max_amount > :amount2 LIMIT 1'
    );
    $stmt->execute([':year' => $tableYear, ':amount' => $taxableAmount, ':amount2' => $taxableAmount]);
    $row = $stmt->fetch();
    if ($row === false) {
        $errors[] = $tableYear . '年分の税額表に ' . pay_yen($taxableAmount) . ' の行がありません。';
        return null;
    }
    if ($taxColumn === 'kou') {
        return (int) $row['kou' . $dependents];
    }
    if ($row['otsu'] !== null) {
        return (int) $row['otsu'];
    }
    // 率で決まる行（その月の社会保険料等控除後の給与等の金額の3.063%、円未満切捨て）
    return intdiv($taxableAmount * pay_decimal_to_int((string) $row['otsu_rate'], 3), 100000);
}

// ---------------------------------------------------------------------------
// 給与計算の対象者・明細の計算
// ---------------------------------------------------------------------------

/**
 * 給与計算の対象者: 給与計算対象（pay_employees.payroll_enabled=1、行が無い従業員も対象）で、
 * 有効なアカウント（無効化されていない）または無効化済みでも計算期間内に勤怠がある人。
 */
function pay_target_employees(PDO $pdo, string $periodStart, string $periodEnd): array
{
    $stmt = $pdo->prepare(
        "SELECT e.id, e.name, e.status, e.hourly_wage_weekday, e.hourly_wage_holiday,
                e.commute_allowance_type, e.commute_allowance_amount
         FROM employees e
         LEFT JOIN pay_employees p ON p.employee_id = e.id
         WHERE COALESCE(p.payroll_enabled, 1) = 1
           AND (e.status <> 'disabled'
                OR EXISTS (SELECT 1 FROM attendance a WHERE a.employee_id = e.id AND a.deleted_at IS NULL
                           AND DATE(a.clock_in_at) BETWEEN :start AND :end))
         ORDER BY e.id"
    );
    $stmt->execute([':start' => $periodStart, ':end' => $periodEnd]);
    return $stmt->fetchAll();
}

/** 計算期間内の退勤未打刻（status='working'）の件数 */
function pay_open_attendance_count(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM attendance WHERE employee_id = :employee_id AND deleted_at IS NULL AND status = 'working'
         AND DATE(clock_in_at) BETWEEN :start AND :end"
    );
    $stmt->execute([':employee_id' => $employeeId, ':start' => $periodStart, ':end' => $periodEnd]);
    return (int) $stmt->fetchColumn();
}

/**
 * 週7日すべて出勤した週（法定休日労働の可能性。1.35倍の割増が必要になるため自動計算しない）の週の起算日一覧。
 * 計算期間にかかる週は、期間外の日も含めて判定する。
 */
function pay_seven_day_weeks(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd, int $weekStartDow): array
{
    $from = week_start_date($periodStart, $weekStartDow);
    $to = (new DateTime(week_start_date($periodEnd, $weekStartDow)))->modify('+6 day')->format('Y-m-d');
    $days = fetch_attendance_daily_minutes($pdo, $employeeId, $from, $to);
    $daysByWeek = [];
    foreach (array_keys($days) as $date) {
        $daysByWeek[week_start_date($date, $weekStartDow)][] = $date;
    }
    $weeks = [];
    foreach ($daysByWeek as $weekStart => $dates) {
        if (count($dates) >= PAY_DAYS_PER_WEEK) {
            $weeks[] = $weekStart;
        }
    }
    return $weeks;
}

/** 明細の手入力項目（再計算しても保持する）の既定値 */
function pay_manual_defaults(): array
{
    return [
        'attendance_adjust' => 0, 'attendance_adjust_reason' => null,
        'other_taxable' => 0, 'other_taxable_label' => null,
        'other_nontax' => 0, 'other_nontax_label' => null,
        'other_deduction' => 0, 'other_deduction_label' => null,
        'note' => null,
    ];
}

/**
 * 従業員1人分の明細を計算する（DBには書かない）。
 * 戻り値は pay_slips のカラム名 => 値（errors・calc_detail・employee_snapshot は JSON 文字列）。
 */
function pay_calculate_slip(PDO $pdo, array $run, array $employee, array $manual): array
{
    $settings = pay_settings($pdo);
    $employeeId = (int) $employee['id'];
    $periodStart = $run['period_start'];
    $periodEnd = $run['period_end'];
    $payDate = $run['pay_date'];
    $errors = [];
    $warnings = [];

    $terms = pay_terms_on($pdo, $employeeId, $periodEnd);
    $summary = calc_wage_summary_for_period($pdo, $employee, $periodStart, $periodEnd);
    $hasAttendance = $summary['attendance_days'] > 0;

    // ---- 勤怠のチェック ----
    if (!empty($summary['wage_history_missing_dates'])) {
        $errors[] = '時給履歴が未登録の日があります: ' . implode(', ', $summary['wage_history_missing_dates']);
    }
    if ($summary['null_category_count'] > 0) {
        $errors[] = '区分（洗濯代行・店舗・集荷）が未設定の打刻が' . $summary['null_category_count'] . '件あります（打刻修正画面で区分を設定してください）。';
    }
    $openCount = pay_open_attendance_count($pdo, $employeeId, $periodStart, $periodEnd);
    if ($openCount > 0) {
        $errors[] = '退勤が未打刻の勤怠が' . $openCount . '件あります。';
    }
    foreach (pay_seven_day_weeks($pdo, $employeeId, $periodStart, $periodEnd, wage_calc_settings($pdo)['week_start_dow']) as $weekStart) {
        $errors[] = $weekStart . ' から始まる週に7日間出勤しています（法定休日労働の可能性。1.35倍の割増が必要なため手計算してください）。';
    }

    // ---- 最低賃金（勤務日ごと、および支給日時点の平日時給） ----
    $prefecture = $terms['work_prefecture'] ?? PAY_DEFAULT_WORK_PREFECTURE;
    $minWageChecks = [];
    foreach ($summary['daily'] as $day) {
        $minWageChecks[$day['work_day']] = true;
    }
    $minWageChecks[$payDate] = true;
    foreach (array_keys($minWageChecks) as $date) {
        $historyRow = wage_history_row_on($pdo, $employeeId, $date);
        $minWage = min_wage_on($pdo, $prefecture, $date);
        if ($historyRow !== null && $minWage !== null && $historyRow['wage_weekday'] < $minWage) {
            $errors[] = $date . ' 時点の平日時給 ' . pay_yen($historyRow['wage_weekday']) . ' が' . $prefecture . 'の最低賃金 ' . pay_yen($minWage) . ' を下回っています。';
        }
    }

    // ---- 支給 ----
    $categoryColumns = ['洗濯代行' => 'laundry', '店舗' => 'store', '集荷' => 'pickup'];
    $row = [
        'work_days' => $summary['attendance_days'],
        'holiday_work_days' => $summary['holiday_attendance_days'],
        'minutes_total' => $summary['total_minutes'],
        'minutes_overtime_daily' => $summary['daily_overtime_minutes'],
        'minutes_overtime_weekly' => $summary['weekly_overtime_minutes'],
        'minutes_night' => $summary['night_minutes'],
        'commute_trips' => $summary['commute_trips'],
        'pay_overtime' => $summary['overtime_wage'],
        'pay_night' => $summary['night_wage'],
    ];
    foreach ($categoryColumns as $category => $column) {
        $stats = $summary['category_breakdown'][$category] ?? ['total_minutes' => 0, 'overtime_minutes' => 0, 'base_wage' => 0];
        $row['minutes_' . $column] = $stats['total_minutes'] - $stats['overtime_minutes'];
        $row['pay_' . $column] = $stats['base_wage'] ?? 0;
    }

    $allowances = get_employee_allowances($pdo, $employeeId);
    $row['allowance_total'] = sum_allowance_amounts($allowances);
    $row['allowance_detail'] = json_encode(array_map(static fn (array $a): array => ['name' => $a['name'], 'amount' => (int) $a['monthly_amount']], $allowances), JSON_UNESCAPED_UNICODE);

    $row['commute_total'] = calc_commute_allowance_total($employee, $summary['commute_trips']);
    $parkingPayType = $terms['parking_pay_type'] ?? 'none';
    $parkingPayAmount = (int) ($terms['parking_pay_amount'] ?? 0);
    $row['parking_total'] = $parkingPayType === 'monthly' ? $parkingPayAmount
        : ($parkingPayType === 'daily' ? $parkingPayAmount * $summary['commute_trips'] : 0);

    $commuteSum = $row['commute_total'] + $row['parking_total'];
    $limitDetail = [];
    if ($commuteSum > 0) {
        if ($terms === null) {
            $limit = 0;
        } else {
            $limitResult = pay_commute_nontax_limit($pdo, $settings, $terms, $summary['commute_trips'], $payDate, $errors);
            $limit = $limitResult['limit'];
            $limitDetail = $limitResult['detail'];
        }
    } else {
        $limit = 0;
    }
    $row['commute_nontax_limit'] = $limit;
    $row['commute_nontax'] = min($commuteSum, $limit);
    $row['commute_taxable'] = $commuteSum - $row['commute_nontax'];

    $manual = array_merge(pay_manual_defaults(), $manual);
    foreach (pay_manual_defaults() as $key => $_) {
        $row[$key] = $manual[$key];
    }

    $row['gross_total'] = $row['pay_laundry'] + $row['pay_store'] + $row['pay_pickup'] + $row['pay_overtime'] + $row['pay_night']
        + $row['allowance_total'] + $row['commute_total'] + $row['parking_total']
        + (int) $row['attendance_adjust'] + (int) $row['other_taxable'] + (int) $row['other_nontax'];

    $noPayment = !$hasAttendance && $row['gross_total'] === 0;

    // ---- 控除 ----
    if ($terms === null && !$noPayment) {
        $errors[] = '給与設定（甲乙・扶養人数・雇用保険・住民税の徴収方法など）が ' . $periodEnd . ' 時点で未登録です（従業員の給与設定）。';
    }

    $row['emp_insurance_rate'] = null;
    $row['emp_insurance'] = 0;
    if ($terms !== null && (int) $terms['emp_insurance'] === 1) {
        $rate = pay_emp_insurance_rate_on($pdo, $periodEnd);
        if ($rate === null) {
            $errors[] = $periodEnd . ' 時点の雇用保険料率が未登録です。';
        } else {
            $row['emp_insurance_rate'] = $rate;
            $row['emp_insurance'] = pay_emp_insurance_premium($row['gross_total'], $rate);
        }
    }

    $row['taxable_amount'] = $row['gross_total'] - $row['commute_nontax'] - (int) $row['other_nontax'] - $row['emp_insurance'];
    $row['tax_table_year'] = (int) substr($payDate, 0, 4);
    $row['withholding_tax'] = 0;
    if ($terms !== null && !$noPayment) {
        $tax = pay_withholding_tax($pdo, $row['taxable_amount'], $terms['tax_column'], (int) $terms['dependents'], $row['tax_table_year'], $errors);
        $row['withholding_tax'] = $tax ?? 0;
    }

    $row['resident_tax'] = 0;
    $residentTaxDetail = null;
    if ($terms !== null && ($terms['resident_tax_method'] ?? 'ordinary') === 'special') {
        $fiscalYear = pay_resident_tax_fiscal_year($payDate);
        $residentMonths = pay_resident_tax_months($pdo, $employeeId, $fiscalYear);
        if (empty($residentMonths)) {
            $warnings[] = '住民税が特別徴収ですが、' . $fiscalYear . '年度の税額が未登録のため0円にしています。';
        } else {
            // 支給日の属する月のマスの額を控除する（例: 7月10日支給 → 7月の額）
            $payMonth = (int) substr($payDate, 5, 2);
            $row['resident_tax'] = $residentMonths[$payMonth];
            $residentTaxDetail = ['fiscal_year' => $fiscalYear, 'month' => $payMonth, 'months' => $residentMonths];
        }
    }

    $row['deduction_total'] = $row['emp_insurance'] + $row['withholding_tax'] + $row['resident_tax'] + (int) $row['other_deduction'];
    $row['net_pay'] = $row['gross_total'] - $row['deduction_total'];
    if ($row['net_pay'] < 0) {
        $errors[] = '差引支給額がマイナスです（' . pay_yen($row['net_pay']) . '）。';
    }

    $wageHistory = wage_history_for_employee($pdo, $employeeId);
    $row['employee_snapshot'] = json_encode([
        'name' => $employee['name'],
        'status' => $employee['status'],
        'wage_history' => $wageHistory,
        'terms' => $terms,
        'commute_allowance_type' => $employee['commute_allowance_type'],
        'commute_allowance_amount' => (int) $employee['commute_allowance_amount'],
        'allowances' => json_decode($row['allowance_detail'], true),
        'work_prefecture' => $prefecture,
        'resident_tax' => $residentTaxDetail,
        'settings' => [
            'overtime_rate' => $settings['overtime_rate'],
            'night_rate' => $settings['night_rate'],
            'week_start_dow' => (int) $settings['week_start_dow'],
        ],
    ], JSON_UNESCAPED_UNICODE);
    $row['calc_detail'] = json_encode([
        'daily' => $summary['daily'],
        'category_breakdown' => $summary['category_breakdown'],
        'commute_limit' => $limitDetail,
        'warnings' => $warnings,
        'no_payment' => $noPayment,
    ], JSON_UNESCAPED_UNICODE);
    $row['errors'] = json_encode(array_values(array_unique($errors)), JSON_UNESCAPED_UNICODE);

    return $row;
}

const PAY_SLIP_CALC_COLUMNS = [
    'employee_snapshot', 'calc_detail', 'work_days', 'holiday_work_days', 'minutes_total',
    'minutes_laundry', 'minutes_store', 'minutes_pickup', 'minutes_overtime_daily', 'minutes_overtime_weekly', 'minutes_night',
    'commute_trips', 'pay_laundry', 'pay_store', 'pay_pickup', 'pay_overtime', 'pay_night',
    'allowance_total', 'allowance_detail', 'commute_total', 'parking_total', 'commute_nontax_limit', 'commute_nontax', 'commute_taxable',
    'attendance_adjust', 'attendance_adjust_reason', 'other_taxable', 'other_taxable_label', 'other_nontax', 'other_nontax_label',
    'gross_total', 'emp_insurance_rate', 'emp_insurance', 'taxable_amount', 'tax_table_year', 'withholding_tax', 'resident_tax',
    'other_deduction', 'other_deduction_label', 'deduction_total', 'net_pay', 'errors', 'note',
];

function pay_fetch_run(PDO $pdo, int $runId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pay_runs WHERE id = :id');
    $stmt->execute([':id' => $runId]);
    $run = $stmt->fetch();
    return $run === false ? null : $run;
}

/** 明細を1件保存（INSERT または UPDATE） */
function pay_save_slip(PDO $pdo, int $runId, int $employeeId, array $row): void
{
    $columns = PAY_SLIP_CALC_COLUMNS;
    $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
    $updates = array_map(static fn (string $c): string => $c . ' = VALUES(' . $c . ')', $columns);
    $sql = 'INSERT INTO pay_slips (run_id, employee_id, ' . implode(', ', $columns) . ')
            VALUES (:run_id, :employee_id, ' . implode(', ', $placeholders) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    $params = [':run_id' => $runId, ':employee_id' => $employeeId];
    foreach ($columns as $column) {
        $params[':' . $column] = $row[$column];
    }
    $pdo->prepare($sql)->execute($params);
}

/**
 * 下書きの回を勤怠から再計算する。対象者が増えた場合は明細を追加し、対象外になった従業員の明細は削除する。
 * 手入力項目（勤怠調整・その他支給/控除・備考）は保持する。
 */
function pay_recalculate_run(PDO $pdo, int $runId): void
{
    $run = pay_fetch_run($pdo, $runId);
    if ($run === null || $run['status'] !== 'draft') {
        throw new RuntimeException('下書きの回だけ再計算できます。');
    }
    $existing = [];
    $stmt = $pdo->prepare('SELECT * FROM pay_slips WHERE run_id = :run_id');
    $stmt->execute([':run_id' => $runId]);
    foreach ($stmt->fetchAll() as $slip) {
        $existing[(int) $slip['employee_id']] = $slip;
    }

    $targetIds = [];
    foreach (pay_target_employees($pdo, $run['period_start'], $run['period_end']) as $employee) {
        $employeeId = (int) $employee['id'];
        $targetIds[] = $employeeId;
        $manual = [];
        if (isset($existing[$employeeId])) {
            foreach (array_keys(pay_manual_defaults()) as $key) {
                $manual[$key] = $existing[$employeeId][$key];
            }
        }
        pay_save_slip($pdo, $runId, $employeeId, pay_calculate_slip($pdo, $run, $employee, $manual));
    }

    foreach (array_diff(array_keys($existing), $targetIds) as $removedId) {
        $pdo->prepare('DELETE FROM pay_slips WHERE run_id = :run_id AND employee_id = :employee_id')
            ->execute([':run_id' => $runId, ':employee_id' => $removedId]);
    }
    $pdo->prepare('UPDATE pay_runs SET updated_at = NOW() WHERE id = :id')->execute([':id' => $runId]);
}

/** 同じ勤務月に下書き・確定の回が既にあるか（取消は何件あってもよい） */
function pay_active_run_for_month(PDO $pdo, string $workMonth, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM pay_runs WHERE work_month = :work_month AND status IN ('draft', 'closed') LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([':work_month' => $workMonth]);
    $run = $stmt->fetch();
    return $run === false ? null : $run;
}

/**
 * 下書きの回を作成して計算する。同じ勤務月に下書き・確定の回があればエラー。
 *
 * @return int 作成した pay_runs.id
 */
function pay_create_run(PDO $pdo, string $workMonth, ?string $payDate, int $createdBy): int
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $workMonth)) {
        throw new InvalidArgumentException('勤務月の形式が正しくありません。');
    }
    $settings = pay_settings($pdo);
    [$periodStart, $periodEnd] = pay_period_for_month($settings, $workMonth);
    $payDate = ($payDate === null || $payDate === '') ? pay_default_pay_date($pdo, $settings, $workMonth) : $payDate;
    pay_assert_date($payDate, '支給日');

    $pdo->beginTransaction();
    try {
        $active = pay_active_run_for_month($pdo, $workMonth, true);
        if ($active !== null) {
            throw new InvalidArgumentException($workMonth . '分は既に' . PAY_STATUS_LABELS[$active['status']] . 'の回があります（同じ勤務月は1件まで。作り直す場合は取消してください）。');
        }
        $pdo->prepare(
            "INSERT INTO pay_runs (work_month, period_start, period_end, pay_date, status, created_at, created_by, updated_at)
             VALUES (:work_month, :period_start, :period_end, :pay_date, 'draft', NOW(), :created_by, NOW())"
        )->execute([
            ':work_month' => $workMonth,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
            ':pay_date' => $payDate,
            ':created_by' => $createdBy,
        ]);
        $runId = (int) $pdo->lastInsertId();
        pay_recalculate_run($pdo, $runId);
        $pdo->commit();
        return $runId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function pay_assert_date(string $value, string $label): void
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label . 'の日付が正しくありません。');
    }
}

/** 回の明細のうち、確定不可のエラーがあるもの（従業員名 => エラー一覧） */
function pay_run_errors(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.errors, e.name FROM pay_slips s JOIN employees e ON e.id = s.employee_id WHERE s.run_id = :run_id ORDER BY e.id'
    );
    $stmt->execute([':run_id' => $runId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $errors = json_decode((string) $row['errors'], true) ?: [];
        if (!empty($errors)) {
            $result[$row['name']] = $errors;
        }
    }
    return $result;
}

/** 確定（最新の勤怠で再計算し、エラーが1件でもあれば確定しない） */
function pay_close_run(PDO $pdo, int $runId, int $closedBy): void
{
    $pdo->beginTransaction();
    try {
        pay_recalculate_run($pdo, $runId);
        $errors = pay_run_errors($pdo, $runId);
        if (!empty($errors)) {
            $lines = [];
            foreach ($errors as $name => $list) {
                $lines[] = $name . ': ' . implode(' / ', $list);
            }
            throw new InvalidArgumentException("確定できません。次のエラーを解消してください。\n" . implode("\n", $lines));
        }
        $pdo->prepare("UPDATE pay_runs SET status = 'closed', closed_at = NOW(), closed_by = :closed_by, updated_at = NOW() WHERE id = :id AND status = 'draft'")
            ->execute([':closed_by' => $closedBy, ':id' => $runId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 取消（理由必須）。明細は残し、同じ勤務月の新しい下書きを作れるようになる */
function pay_void_run(PDO $pdo, int $runId, string $reason, int $voidedBy): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('取消の理由を入力してください。');
    }
    $stmt = $pdo->prepare(
        "UPDATE pay_runs SET status = 'void', voided_at = NOW(), voided_by = :voided_by, void_reason = :reason, updated_at = NOW()
         WHERE id = :id AND status IN ('draft', 'closed')"
    );
    $stmt->execute([':voided_by' => $voidedBy, ':reason' => mb_substr($reason, 0, 255), ':id' => $runId]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('この回は取消できません。');
    }
}

/** 下書きの削除（明細ごと。確定・取消済みの回は削除できない） */
function pay_delete_draft_run(PDO $pdo, int $runId): void
{
    $pdo->beginTransaction();
    $run = pay_fetch_run($pdo, $runId);
    if ($run === null || $run['status'] !== 'draft') {
        $pdo->rollBack();
        throw new InvalidArgumentException('下書きの回だけ削除できます。');
    }
    $pdo->prepare('DELETE FROM pay_slips WHERE run_id = :id')->execute([':id' => $runId]);
    $pdo->prepare('DELETE FROM pay_runs WHERE id = :id')->execute([':id' => $runId]);
    $pdo->commit();
}

// ---------------------------------------------------------------------------
// 源泉徴収税額表（月額表）の取込
// ---------------------------------------------------------------------------

/**
 * 国税庁の月額表（Excel）の行から、税額表の行を取り出す。
 * - 「X円未満」の行 → 0円以上X円未満、甲欄0円、乙欄は率（3.063%）
 * - 以上・未満が数値の行 → 甲欄0〜7人・乙欄の税額
 * - 「740,000円」以降（計算式で求める行）は取り込まない
 *
 * @return array{rows: list<array>, errors: list<string>}
 */
function pay_parse_withholding_sheet(array $cells): array
{
    $rows = [];
    $errors = [];
    $num = static fn ($v): bool => is_int($v) || (is_float($v) && floor($v) === $v);

    foreach ($cells as $index => $cell) {
        $min = $cell[1] ?? null;
        $max = $cell[2] ?? null;
        $kou = array_slice($cell, 3, 8);
        $otsu = $cell[11] ?? null;
        $kouNumeric = count($kou) === 8 && count(array_filter($kou, $num)) === 8;

        if ($num($min) && is_string($max) && strpos($max, '円未満') !== false && $kouNumeric) {
            if (!preg_match('/([0-9]+\.[0-9]+)\s*[％%]/u', (string) $otsu, $m)) {
                $errors[] = ($index + 1) . '行目: 乙欄の税率が読み取れません。';
                continue;
            }
            $rows[] = ['min_amount' => 0, 'max_amount' => (int) $min, 'kou' => array_map('intval', $kou), 'otsu' => null, 'otsu_rate' => $m[1]];
            continue;
        }
        if ($num($min) && $num($max) && $kouNumeric && $num($otsu)) {
            $rows[] = ['min_amount' => (int) $min, 'max_amount' => (int) $max, 'kou' => array_map('intval', $kou), 'otsu' => (int) $otsu, 'otsu_rate' => null];
        }
    }

    if (empty($rows)) {
        $errors[] = '税額表の行が見つかりません（国税庁の「月額表」のExcelか確認してください）。';
        return ['rows' => [], 'errors' => $errors];
    }
    // 0円から740,000円まで隙間なく続いているか
    if ($rows[0]['min_amount'] !== 0) {
        $errors[] = '最初の行が0円から始まっていません。';
    }
    for ($i = 1, $n = count($rows); $i < $n; $i++) {
        if ($rows[$i]['min_amount'] !== $rows[$i - 1]['max_amount']) {
            $errors[] = pay_yen($rows[$i - 1]['max_amount']) . ' と ' . pay_yen($rows[$i]['min_amount']) . ' の間に行の抜けがあります。';
        }
        for ($k = 0; $k < 8; $k++) {
            if ($rows[$i]['kou'][$k] < $rows[$i - 1]['kou'][$k]) {
                $errors[] = pay_yen($rows[$i]['min_amount']) . ' の行の甲欄' . $k . '人の税額が前の行より小さくなっています。';
            }
        }
    }
    if (end($rows)['max_amount'] !== PAY_WITHHOLDING_TABLE_MAX) {
        $errors[] = '最後の行が ' . pay_yen(PAY_WITHHOLDING_TABLE_MAX) . ' 未満で終わっていません（' . pay_yen(end($rows)['max_amount']) . '）。';
    }
    return ['rows' => $rows, 'errors' => array_values(array_unique($errors))];
}

/** 指定年分の税額表を入れ替える（既存の同じ年分は削除） */
function pay_store_withholding_table(PDO $pdo, int $tableYear, array $rows): int
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM pay_withholding_table WHERE table_year = :year')->execute([':year' => $tableYear]);
        $stmt = $pdo->prepare(
            'INSERT INTO pay_withholding_table (table_year, min_amount, max_amount, kou0, kou1, kou2, kou3, kou4, kou5, kou6, kou7, otsu, otsu_rate)
             VALUES (:year, :min, :max, :k0, :k1, :k2, :k3, :k4, :k5, :k6, :k7, :otsu, :otsu_rate)'
        );
        foreach ($rows as $row) {
            $params = [':year' => $tableYear, ':min' => $row['min_amount'], ':max' => $row['max_amount'], ':otsu' => $row['otsu'], ':otsu_rate' => $row['otsu_rate']];
            foreach ($row['kou'] as $k => $value) {
                $params[':k' . $k] = $value;
            }
            $stmt->execute($params);
        }
        $pdo->commit();
        return count($rows);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// 表示用
// ---------------------------------------------------------------------------

function pay_minutes_label(int $minutes): string
{
    return format_minutes_as_hours($minutes);
}

/** 明細の支給項目（表示順）。[ラベル, 金額, 補足] の配列 */
function pay_slip_payment_lines(array $slip): array
{
    $lines = [
        ['洗濯代行', (int) $slip['pay_laundry'], pay_minutes_label((int) $slip['minutes_laundry'])],
        ['店舗', (int) $slip['pay_store'], pay_minutes_label((int) $slip['minutes_store'])],
        ['集荷', (int) $slip['pay_pickup'], pay_minutes_label((int) $slip['minutes_pickup'])],
        ['時間外手当', (int) $slip['pay_overtime'], pay_minutes_label((int) $slip['minutes_overtime_daily'] + (int) $slip['minutes_overtime_weekly'])],
        ['深夜手当', (int) $slip['pay_night'], pay_minutes_label((int) $slip['minutes_night'])],
    ];
    foreach (json_decode((string) $slip['allowance_detail'], true) ?: [] as $allowance) {
        $lines[] = [$allowance['name'], (int) $allowance['amount'], ''];
    }
    $lines[] = ['交通費', (int) $slip['commute_total'], ''];
    if ((int) $slip['parking_total'] !== 0) {
        $lines[] = ['駐車場代', (int) $slip['parking_total'], ''];
    }
    if ((int) $slip['attendance_adjust'] !== 0) {
        $lines[] = ['勤怠調整', (int) $slip['attendance_adjust'], (string) $slip['attendance_adjust_reason']];
    }
    if ((int) $slip['other_taxable'] !== 0) {
        $lines[] = [(string) ($slip['other_taxable_label'] ?: 'その他（課税）'), (int) $slip['other_taxable'], ''];
    }
    if ((int) $slip['other_nontax'] !== 0) {
        $lines[] = [(string) ($slip['other_nontax_label'] ?: 'その他（非課税）'), (int) $slip['other_nontax'], ''];
    }
    return $lines;
}

/** 明細の控除項目（表示順） */
function pay_slip_deduction_lines(array $slip): array
{
    $lines = [
        ['雇用保険料', (int) $slip['emp_insurance']],
        ['所得税', (int) $slip['withholding_tax']],
        ['住民税', (int) $slip['resident_tax']],
    ];
    if ((int) $slip['other_deduction'] !== 0) {
        $lines[] = [(string) ($slip['other_deduction_label'] ?: 'その他控除'), (int) $slip['other_deduction']];
    }
    return $lines;
}
