<?php
/**
 * 賃金計算の共通関数。
 * 管理者の賃金確認（admin/wages.php）、従業員の月間打刻実績（staff/attendance_monthly.php）、
 * シフトの見込み額（admin/shifts.php・staff/dashboard.php）、給与計算（admin/payroll*.php）がすべてここを通る。
 * includes/functions.php の末尾で読み込むため、functions.php を読み込んでいる画面はそのまま使える。
 *
 * - 時給は pay_wage_history（適用開始日つき）から日ごとに「その日有効な行」を使う。
 *   履歴が1件も無い日（履歴登録前の新規従業員など）は、渡された $employee の hourly_wage_* で計算し、
 *   その日付を wage_history_missing_dates に入れて返す（給与計算ではこれを確定不可のエラーにする）。
 * - 時間外は「1日8時間超」と「週40時間超（日単位で時間外になった分は除く）」。週の起算曜日は pay_settings.week_start_dow。
 *   月をまたぐ週は、月初より前の同じ週の日（$lookbackDailyMinutes）も40時間の判定に含め、割増は当月の日の分だけに付ける。
 * - 金額は整数演算で1日ごとに四捨五入する（rate×分÷60 等を浮動小数を使わずに計算）。
 * - 給与明細の支給内訳（calc_payslip_pay_items()）は弥生給与と同じく、月の項目ごとに1円未満切上げで別に計算する。
 *   給与計算（admin/payroll*.php）と賃金確認（admin/wages.php）の一覧・確定はこちらの金額を使う。
 */

const WEEKLY_REGULAR_WORK_MINUTES = 40 * 60;

/**
 * pay_settings の割増率・週の起算曜日を整数（百分率）で返す。1リクエスト内はキャッシュする。
 *
 * @return array{week_start_dow:int, overtime_rate_pct:int, night_rate_pct:int}
 */
function wage_calc_settings(PDO $pdo): array
{
    static $settings = null;
    if ($settings === null) {
        $row = $pdo->query('SELECT week_start_dow, overtime_rate, night_rate FROM pay_settings WHERE id = 1')->fetch();
        $settings = [
            'week_start_dow' => $row !== false ? (int) $row['week_start_dow'] : 0,
            // DECIMAL文字列（例 "1.25"）を百分率の整数に変換（浮動小数を経由しない）
            'overtime_rate_pct' => $row !== false ? decimal_string_to_pct((string) $row['overtime_rate']) : 125,
            'night_rate_pct' => $row !== false ? decimal_string_to_pct((string) $row['night_rate']) : 25,
        ];
    }
    return $settings;
}

/** "1.25" → 125、"0.25" → 25、"1.5" → 150（小数第2位まで） */
function decimal_string_to_pct(string $decimal): int
{
    [$intPart, $fracPart] = array_pad(explode('.', trim($decimal), 2), 2, '');
    $fracPart = substr(str_pad($fracPart, 2, '0'), 0, 2);
    return (int) $intPart * 100 + (int) $fracPart;
}

/** 非負の整数 $numerator / $denominator を四捨五入した整数 */
function round_half_up_div(int $numerator, int $denominator): int
{
    return intdiv(2 * $numerator + $denominator, 2 * $denominator);
}

/** $date を含む週の起算日（Y-m-d）。$weekStartDow は 0=日曜〜6=土曜 */
function week_start_date(string $date, int $weekStartDow): string
{
    $d = new DateTime($date);
    $back = ((int) $d->format('w') - $weekStartDow + 7) % 7;
    return $d->modify('-' . $back . ' day')->format('Y-m-d');
}

/**
 * 月初を含む週のうち、月初より前の日の範囲（週40時間判定の持ち越し用）。月初が週の起算日なら null。
 *
 * @return array{0:string,1:string}|null
 */
function wage_lookback_range(string $monthStart, int $weekStartDow): ?array
{
    $weekStart = week_start_date($monthStart, $weekStartDow);
    if ($weekStart === $monthStart) {
        return null;
    }
    return [$weekStart, (new DateTime($monthStart))->modify('-1 day')->format('Y-m-d')];
}

/**
 * 従業員の時給履歴（effective_from昇順）。1リクエスト内はキャッシュし、登録直後に読み直す場合は $refresh=true。
 *
 * @return list<array{effective_from:string, wage_weekday:int, wage_holiday:int}>
 */
function wage_history_for_employee(PDO $pdo, int $employeeId, bool $refresh = false): array
{
    static $cache = [];
    if ($refresh || !isset($cache[$employeeId])) {
        $stmt = $pdo->prepare(
            'SELECT effective_from, wage_weekday, wage_holiday FROM pay_wage_history
             WHERE employee_id = :employee_id ORDER BY effective_from'
        );
        $stmt->execute([':employee_id' => $employeeId]);
        $cache[$employeeId] = array_map(static fn (array $r): array => [
            'effective_from' => $r['effective_from'],
            'wage_weekday' => (int) $r['wage_weekday'],
            'wage_holiday' => (int) $r['wage_holiday'],
        ], $stmt->fetchAll());
    }
    return $cache[$employeeId];
}

/** $date 時点で有効な時給履歴の行。無ければ null */
function wage_history_row_on(PDO $pdo, int $employeeId, string $date): ?array
{
    $found = null;
    foreach (wage_history_for_employee($pdo, $employeeId) as $row) {
        if ($row['effective_from'] <= $date) {
            $found = $row;
        }
    }
    return $found;
}

/**
 * 今日時点で有効な時給履歴の値を employees.hourly_wage_*（表示用）に同期する。
 * 未来日付で登録した時給は、適用日以後に管理画面（ダッシュボード・従業員管理・賃金確認・給与計算）を開いた時点で反映される。
 *
 * @return int 更新した従業員数
 */
function sync_employee_wages_from_history(PDO $pdo, ?string $today = null): int
{
    $stmt = $pdo->prepare(
        'UPDATE employees e
         JOIN pay_wage_history h ON h.employee_id = e.id
          AND h.effective_from = (SELECT MAX(h2.effective_from) FROM pay_wage_history h2
                                  WHERE h2.employee_id = e.id AND h2.effective_from <= :today)
         SET e.hourly_wage_weekday = h.wage_weekday, e.hourly_wage_holiday = h.wage_holiday
         WHERE e.hourly_wage_weekday <> h.wage_weekday OR e.hourly_wage_holiday <> h.wage_holiday'
    );
    $stmt->execute([':today' => $today ?? (new DateTime('today'))->format('Y-m-d')]);
    return $stmt->rowCount();
}

/**
 * 時給履歴を1行登録する（同じ適用日の行があれば上書き）。登録後、今日時点の値を employees に同期する。
 */
function register_wage_history(PDO $pdo, int $employeeId, string $effectiveFrom, int $wageWeekday, int $wageHoliday, ?int $createdBy): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO pay_wage_history (employee_id, effective_from, wage_weekday, wage_holiday, created_by)
         VALUES (:employee_id, :effective_from, :wage_weekday, :wage_holiday, :created_by)
         ON DUPLICATE KEY UPDATE wage_weekday = VALUES(wage_weekday), wage_holiday = VALUES(wage_holiday),
                                 created_by = VALUES(created_by), created_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':employee_id' => $employeeId,
        ':effective_from' => $effectiveFrom,
        ':wage_weekday' => $wageWeekday,
        ':wage_holiday' => $wageHoliday,
        ':created_by' => $createdBy,
    ]);
    wage_history_for_employee($pdo, $employeeId, true);
    sync_employee_wages_from_history($pdo);
}

const PAY_DEFAULT_WORK_PREFECTURE = '滋賀県';

/** 給与計算対象か（pay_employees に行が無い従業員は対象として扱う） */
function employee_is_payroll_enabled(PDO $pdo, int $employeeId): bool
{
    $stmt = $pdo->prepare('SELECT payroll_enabled FROM pay_employees WHERE employee_id = :employee_id');
    $stmt->execute([':employee_id' => $employeeId]);
    $enabled = $stmt->fetchColumn();
    return $enabled === false || (int) $enabled === 1;
}

/** $date 時点の就業地（pay_employee_terms の有効な行。未登録・新規従業員は PAY_DEFAULT_WORK_PREFECTURE） */
function employee_work_prefecture_on(PDO $pdo, ?int $employeeId, string $date): string
{
    if ($employeeId === null) {
        return PAY_DEFAULT_WORK_PREFECTURE;
    }
    $stmt = $pdo->prepare(
        'SELECT work_prefecture FROM pay_employee_terms WHERE employee_id = :employee_id AND effective_from <= :date
         ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->execute([':employee_id' => $employeeId, ':date' => $date]);
    $prefecture = $stmt->fetchColumn();
    return $prefecture === false ? PAY_DEFAULT_WORK_PREFECTURE : (string) $prefecture;
}

/** $prefecture の $date 時点の最低賃金（時間額）。登録が無ければ null */
function min_wage_on(PDO $pdo, string $prefecture, string $date): ?int
{
    $stmt = $pdo->prepare(
        'SELECT hourly FROM pay_min_wages WHERE prefecture = :prefecture AND effective_from <= :date
         ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->execute([':prefecture' => $prefecture, ':date' => $date]);
    $hourly = $stmt->fetchColumn();
    return $hourly === false ? null : (int) $hourly;
}

/**
 * 区分別金額（日ごとの按分値を積み上げた浮動小数）を丸め、丸め誤差が既存の合計額と必ず一致するよう
 * 最大区分に差分を寄せる（区分別内訳の合計が既存の「見込み額」「賃金」等の合計と食い違わないようにするため）。
 *
 * @param array<string,float> $categoryWageAccum
 * @return array<string,int>
 */
function distribute_category_wage_rounding(array $categoryWageAccum, int $totalWage): array
{
    $rounded = array_map(static fn (float $v): int => (int) floor($v + 1e-9), $categoryWageAccum);
    $remainder = $totalWage - array_sum($rounded);
    if ($remainder !== 0 && !empty($rounded)) {
        $largestCategory = array_key_first($rounded);
        foreach ($rounded as $category => $wage) {
            if ($wage > $rounded[$largestCategory]) {
                $largestCategory = $category;
            }
        }
        $rounded[$largestCategory] += $remainder;
    }

    return $rounded;
}

/**
 * @param array<string,int> $dailyMinutes 日付(Y-m-d) => 実働分
 * @param array<string,int> $dailyNightMinutes 日付(Y-m-d) => 深夜（22:00〜翌5:00）実働分。
 *        打刻の時刻情報が無い場合（シフト予定ベースの見込み計算など）は省略でき、その場合は深夜手当0円として扱う。
 * @param array<string, array<string,int>> $dailyCategoryMinutes 日付(Y-m-d) => 区分(SHIFT_CATEGORIES) => 実働分。
 *        指定した場合、その日の実働分に占める区分ごとの割合でday_wage（所定内＋残業。深夜手当は含まない）を按分し、
 *        戻り値のcategory_wageに区分別の金額（合計は必ずtotal_wageと一致）を追加する。省略時はcategory_wageは空。
 * @param array<string,int> $lookbackDailyMinutes 日付(Y-m-d) => 実働分。$dailyMinutes の最初の日と同じ週で、それより前の日
 *        （前月末の日）の実働分。週40時間の判定にだけ使い、金額には含めない。
 * $employee には id と、履歴が無い日の予備として hourly_wage_weekday, hourly_wage_holiday が必要。
 */
function calc_wage_breakdown_from_daily_minutes(PDO $pdo, array $employee, array $dailyMinutes, array $dailyNightMinutes = [], array $dailyCategoryMinutes = [], array $lookbackDailyMinutes = []): array
{
    ksort($dailyMinutes);
    $settings = wage_calc_settings($pdo);
    $employeeId = (int) $employee['id'];

    // 週ごとの「日単位の時間外を除いた労働時間」の累計（週40時間判定用）。前月末の同じ週の日から持ち越す
    $weekRegularMinutes = [];
    foreach ($lookbackDailyMinutes as $date => $minutes) {
        $weekKey = week_start_date($date, $settings['week_start_dow']);
        $weekRegularMinutes[$weekKey] = ($weekRegularMinutes[$weekKey] ?? 0) + min((int) $minutes, REGULAR_WORK_MINUTES_PER_DAY);
    }

    $daily = [];
    $totalMinutes = 0;
    $totalWage = 0;
    $categoryWageAccum = array_fill_keys(SHIFT_CATEGORIES, 0.0);
    $weekdayRegularMinutes = 0;
    $weekdayOvertimeMinutes = 0;
    $holidayRegularMinutes = 0;
    $holidayOvertimeMinutes = 0;
    $weekdayWage = 0;
    $weekdayOvertimeWage = 0;
    $holidayWage = 0;
    $holidayOvertimeWage = 0;
    $nightMinutes = 0;
    $nightWage = 0;
    $weekdayAttendanceDays = 0;
    $holidayAttendanceDays = 0;
    $weekdayNightMinutes = 0;
    $weekdayNightWage = 0;
    $holidayNightMinutes = 0;
    $holidayNightWage = 0;
    $dailyOvertimeMinutesTotal = 0;
    $weeklyOvertimeMinutesTotal = 0;
    $wageHistoryMissingDates = [];

    foreach ($dailyMinutes as $date => $dayMinutes) {
        $dayMinutes = (int) $dayMinutes;
        $isHoliday = is_holiday_date($pdo, $date);
        $historyRow = wage_history_row_on($pdo, $employeeId, $date);
        if ($historyRow !== null) {
            $rateWeekday = $historyRow['wage_weekday'];
            $rateHoliday = $historyRow['wage_holiday'];
        } else {
            $rateWeekday = (int) $employee['hourly_wage_weekday'];
            $rateHoliday = (int) $employee['hourly_wage_holiday'];
            $wageHistoryMissingDates[] = $date;
        }
        $rate = $isHoliday ? $rateHoliday : $rateWeekday;

        // 1日8時間超 → 日単位の時間外。週の累計（日単位の時間外を除く）が40時間を超えた分 → 週単位の時間外
        $weekKey = week_start_date($date, $settings['week_start_dow']);
        $weekBefore = $weekRegularMinutes[$weekKey] ?? 0;
        $regularLimit = min(REGULAR_WORK_MINUTES_PER_DAY, max(0, WEEKLY_REGULAR_WORK_MINUTES - $weekBefore));
        $regularMinutes = min($dayMinutes, $regularLimit);
        $dailyOvertimeMinutes = max(0, $dayMinutes - REGULAR_WORK_MINUTES_PER_DAY);
        $weeklyOvertimeMinutes = $dayMinutes - $regularMinutes - $dailyOvertimeMinutes;
        $overtimeMinutes = $dailyOvertimeMinutes + $weeklyOvertimeMinutes;
        $weekRegularMinutes[$weekKey] = $weekBefore + min($dayMinutes, REGULAR_WORK_MINUTES_PER_DAY);

        $regularWage = round_half_up_div($rate * $regularMinutes, 60);
        $overtimeWage = round_half_up_div($rate * $overtimeMinutes * $settings['overtime_rate_pct'], 6000);
        $dayWage = $regularWage + $overtimeWage;

        $dayNightMinutes = (int) ($dailyNightMinutes[$date] ?? 0);
        $dayNightWage = round_half_up_div($rate * $dayNightMinutes * $settings['night_rate_pct'], 6000);

        $daily[] = [
            'work_day' => $date,
            'day_minutes' => $dayMinutes,
            'is_holiday' => $isHoliday,
            'rate' => $rate,
            'rate_weekday' => $rateWeekday,
            'rate_holiday' => $rateHoliday,
            'regular_limit' => $regularLimit,
            'regular_minutes' => $regularMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'daily_overtime_minutes' => $dailyOvertimeMinutes,
            'weekly_overtime_minutes' => $weeklyOvertimeMinutes,
            'night_minutes' => $dayNightMinutes,
            'regular_wage' => $regularWage,
            'overtime_wage' => $overtimeWage,
            'day_wage' => $dayWage,
            'night_wage' => $dayNightWage,
        ];

        $totalMinutes += $dayMinutes;
        $totalWage += $dayWage;
        $nightMinutes += $dayNightMinutes;
        $nightWage += $dayNightWage;
        $dailyOvertimeMinutesTotal += $dailyOvertimeMinutes;
        $weeklyOvertimeMinutesTotal += $weeklyOvertimeMinutes;

        if ($dayMinutes > 0 && isset($dailyCategoryMinutes[$date])) {
            foreach ($dailyCategoryMinutes[$date] as $category => $categoryMinutes) {
                $categoryWageAccum[$category] = ($categoryWageAccum[$category] ?? 0.0) + $dayWage * $categoryMinutes / $dayMinutes;
            }
        }

        if ($isHoliday) {
            $holidayRegularMinutes += $regularMinutes;
            $holidayOvertimeMinutes += $overtimeMinutes;
            $holidayWage += $regularWage;
            $holidayOvertimeWage += $overtimeWage;
            $holidayAttendanceDays++;
            $holidayNightMinutes += $dayNightMinutes;
            $holidayNightWage += $dayNightWage;
        } else {
            $weekdayRegularMinutes += $regularMinutes;
            $weekdayOvertimeMinutes += $overtimeMinutes;
            $weekdayWage += $regularWage;
            $weekdayOvertimeWage += $overtimeWage;
            $weekdayAttendanceDays++;
            $weekdayNightMinutes += $dayNightMinutes;
            $weekdayNightWage += $dayNightWage;
        }
    }

    return [
        'daily' => $daily,
        'attendance_days' => count($daily),
        'total_minutes' => $totalMinutes,
        'total_wage' => $totalWage,
        'weekday_regular_minutes' => $weekdayRegularMinutes,
        'weekday_overtime_minutes' => $weekdayOvertimeMinutes,
        'holiday_regular_minutes' => $holidayRegularMinutes,
        'holiday_overtime_minutes' => $holidayOvertimeMinutes,
        'night_minutes' => $nightMinutes,
        'weekday_wage' => $weekdayWage,
        'weekday_overtime_wage' => $weekdayOvertimeWage,
        'holiday_wage' => $holidayWage,
        'holiday_overtime_wage' => $holidayOvertimeWage,
        'night_wage' => $nightWage,
        'base_wage' => $weekdayWage + $holidayWage,
        'overtime_wage' => $weekdayOvertimeWage + $holidayOvertimeWage,
        'overtime_minutes' => $weekdayOvertimeMinutes + $holidayOvertimeMinutes,
        'daily_overtime_minutes' => $dailyOvertimeMinutesTotal,
        'weekly_overtime_minutes' => $weeklyOvertimeMinutesTotal,
        'grand_total_wage' => $totalWage + $nightWage,
        'weekday_attendance_days' => $weekdayAttendanceDays,
        'holiday_attendance_days' => $holidayAttendanceDays,
        'weekday_total_minutes' => $weekdayRegularMinutes + $weekdayOvertimeMinutes,
        'holiday_total_minutes' => $holidayRegularMinutes + $holidayOvertimeMinutes,
        'weekday_night_minutes' => $weekdayNightMinutes,
        'holiday_night_minutes' => $holidayNightMinutes,
        'weekday_night_wage' => $weekdayNightWage,
        'holiday_night_wage' => $holidayNightWage,
        'weekday_total_wage' => $weekdayWage + $weekdayOvertimeWage + $weekdayNightWage,
        'holiday_total_wage' => $holidayWage + $holidayOvertimeWage + $holidayNightWage,
        'category_wage' => empty($dailyCategoryMinutes) ? [] : distribute_category_wage_rounding($categoryWageAccum, $totalWage),
        'wage_history_missing_dates' => $wageHistoryMissingDates,
    ];
}

const ATTENDANCE_CATEGORY_NONE_LABEL = '区分なし';

/**
 * 賃金確認（admin/wages.php）の区分別集計で、交通費・手当を全額計上する区分の対応表。
 * キーは employees.id、値は SHIFT_CATEGORIES のいずれか。
 * ここに載っていない従業員（今後入社する従業員を含む）は ALLOWANCE_CATEGORY_DEFAULT（店舗）に計上する。
 * 割り当てを追加・変更する場合はこの表だけを直せばよい。
 */
const ALLOWANCE_CATEGORY_BY_EMPLOYEE_ID = [
    17 => '集荷',     // 安廣洋輔
    40 => '洗濯代行', // 山本真栄
];
const ALLOWANCE_CATEGORY_DEFAULT = '店舗';

function allowance_category_for_employee(int $employeeId): string
{
    return ALLOWANCE_CATEGORY_BY_EMPLOYEE_ID[$employeeId] ?? ALLOWANCE_CATEGORY_DEFAULT;
}

/**
 * 退勤済み打刻（attendance）の日別実働分を返す（週40時間判定の持ち越し用。区分・深夜は不要）。
 *
 * @return array<string,int> 日付(Y-m-d) => 実働分
 */
function fetch_attendance_daily_minutes(PDO $pdo, int $employeeId, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE(clock_in_at) AS work_day, SUM(work_minutes) AS day_minutes
         FROM attendance
         WHERE employee_id = :employee_id AND status = 'done'
           AND deleted_at IS NULL
           AND DATE(clock_in_at) BETWEEN :start AND :end
         GROUP BY DATE(clock_in_at)"
    );
    $stmt->execute([':employee_id' => $employeeId, ':start' => $startDate, ':end' => $endDate]);

    $minutes = [];
    foreach ($stmt->fetchAll() as $row) {
        $minutes[$row['work_day']] = (int) $row['day_minutes'];
    }
    return $minutes;
}

/**
 * 指定従業員・指定期間の退勤済み打刻（attendance）から、平日/土日祝・所定/残業・深夜の時間と賃金を集計する。
 * 管理者の賃金確認（admin/wages.php）の一覧・確定処理、従業員の月間打刻実績（staff/attendance_monthly.php）の
 * 月間集計、給与計算（admin/payroll.php）から呼ばれる（すべての画面で数値が一致するよう共通化）。
 * $employee には id, hourly_wage_weekday, hourly_wage_holiday が必要（時給は pay_wage_history が優先）。
 *
 * 返り値には calc_wage_breakdown_from_daily_minutes() の結果に加え、打刻区分（attendance.category）別の
 * 時間・金額内訳 category_breakdown を含む（詳細は calc_attendance_category_breakdown()。
 * 金額内訳は admin/wages.php の区分別集計でのみ表示）と、交通費（日額）の計上回数 commute_trips
 * （出勤日数＋同じ日の退勤〜次の出勤が COMMUTE_SEPARATE_TRIP_GAP_MINUTES 以上空いた回数）、
 * その区分別の内訳 commute_trips_by_category（区分 => 回数。複数区分が混在するトリップは固定区分に計上）、
 * 区分がNULLの打刻の件数 null_category_count を含む。
 */
function calc_wage_summary(PDO $pdo, array $employee, string $yearMonth): array
{
    [$monthStart, $monthEnd] = get_month_range($yearMonth);
    return calc_wage_summary_for_period($pdo, $employee, $monthStart, $monthEnd);
}

function calc_wage_summary_for_period(PDO $pdo, array $employee, string $periodStart, string $periodEnd): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE(clock_in_at) AS work_day, clock_in_at, clock_out_at, work_minutes, category
         FROM attendance
         WHERE employee_id = :employee_id AND status = 'done'
           AND deleted_at IS NULL
           AND DATE(clock_in_at) BETWEEN :start AND :end
         ORDER BY clock_in_at, id"
    );
    $stmt->execute([':employee_id' => $employee['id'], ':start' => $periodStart, ':end' => $periodEnd]);

    $dailyMinutes = [];
    $dailyNightMinutes = [];
    $records = [];
    $commuteTrips = 0;
    $lastClockOutByDay = [];
    $tripCategories = []; // トリップ番号 => [区分 => true]（区分NULLは ATTENDANCE_CATEGORY_NONE_LABEL）
    $nullCategoryCount = 0;
    foreach ($stmt->fetchAll() as $row) {
        // 交通費の回数: 出勤日ごとに1回＋同じ日の退勤〜次の出勤が COMMUTE_SEPARATE_TRIP_GAP_MINUTES 以上空いた回数
        $lastClockOut = $lastClockOutByDay[$row['work_day']] ?? null;
        if ($lastClockOut === null
            || (strtotime($row['clock_in_at']) - strtotime($lastClockOut)) >= COMMUTE_SEPARATE_TRIP_GAP_MINUTES * 60) {
            $commuteTrips++;
        }
        $tripCategories[$commuteTrips][$row['category'] ?? ATTENDANCE_CATEGORY_NONE_LABEL] = true;
        if ($lastClockOut === null || $row['clock_out_at'] > $lastClockOut) {
            $lastClockOutByDay[$row['work_day']] = $row['clock_out_at'];
        }
        if ($row['category'] === null) {
            $nullCategoryCount++;
        }

        $workMinutes = (int) $row['work_minutes'];
        $nightMinutes = calc_record_night_work_minutes($row['clock_in_at'], $row['clock_out_at'], $workMinutes);
        $dailyMinutes[$row['work_day']] = ($dailyMinutes[$row['work_day']] ?? 0) + $workMinutes;
        $dailyNightMinutes[$row['work_day']] = ($dailyNightMinutes[$row['work_day']] ?? 0) + $nightMinutes;
        $records[] = [
            'work_day' => $row['work_day'],
            'category' => $row['category'],
            'work_minutes' => $workMinutes,
            'night_minutes' => $nightMinutes,
        ];
    }

    $lookbackRange = wage_lookback_range($periodStart, wage_calc_settings($pdo)['week_start_dow']);
    $lookbackDailyMinutes = $lookbackRange === null
        ? []
        : fetch_attendance_daily_minutes($pdo, (int) $employee['id'], $lookbackRange[0], $lookbackRange[1]);

    $summary = calc_wage_breakdown_from_daily_minutes($pdo, $employee, $dailyMinutes, $dailyNightMinutes, [], $lookbackDailyMinutes);
    $summary['category_breakdown'] = calc_attendance_category_breakdown($records, $summary);
    $summary['pay_items'] = calc_payslip_pay_items($pdo, $summary, $records);
    $summary['commute_trips'] = $commuteTrips;
    $summary['null_category_count'] = $nullCategoryCount;

    // トリップごとの区分: トリップ内の打刻がすべて同じ区分ならその区分、複数区分が混在するトリップは
    // 手当と同じ固定区分（allowance_category_for_employee()）に寄せる
    $summary['commute_trips_by_category'] = [];
    foreach ($tripCategories as $categories) {
        $tripCategory = count($categories) === 1
            ? (string) array_key_first($categories)
            : allowance_category_for_employee((int) $employee['id']);
        $summary['commute_trips_by_category'][$tripCategory] = ($summary['commute_trips_by_category'][$tripCategory] ?? 0) + 1;
    }

    return $summary;
}

/** 整数 $numerator / 正の整数 $denominator の1未満切上げ（負の値は0に近い側＝切上げ） */
function ceil_div(int $numerator, int $denominator): int
{
    $quotient = intdiv($numerator, $denominator);
    return $numerator % $denominator > 0 ? $quotient + 1 : $quotient;
}

/**
 * 給与明細の支給内訳（弥生給与の分け方をベースに、基本給だけ区分別にしたもの）。
 *   洗濯代行／店舗／集荷 = その区分の全労働時間（時間外を含む）× 平日時給
 *   休日手当             = 土日祝の労働時間（時間外を含む）×（土日祝時給 − 平日時給）
 *   普通残業手当         = 平日の時間外時間 × 平日時給 × 割増分（pay_settings.overtime_rate − 1、通常0.25）
 *   休日残業手当         = 土日祝の時間外時間 × 土日祝時給 × 割増分
 *   深夜手当             = 深夜時間 × その日の時給 × pay_settings.night_rate（通常0.25）
 * 時給は日ごと（pay_wage_history のその日の行）。月内で時給が変わった場合も、1か月分の端数のない額を
 * 項目ごとに合計してから1円未満を切り上げる（弥生と同じく項目ごとに1回だけ切上げ）。
 * 区分がNULLの打刻は ATTENDANCE_CATEGORY_NONE_LABEL の区分に計上する（給与計算では確定不可のエラー）。
 * $summary は calc_wage_breakdown_from_daily_minutes() の結果、$records は calc_attendance_category_breakdown() と同じ打刻の配列。
 *
 * @return array{category: array<string, array{minutes:int, amount:int}>, holiday_minutes:int, holiday_allowance:int,
 *               weekday_overtime_minutes:int, weekday_overtime_allowance:int, holiday_overtime_minutes:int,
 *               holiday_overtime_allowance:int, night_minutes:int, night_allowance:int, total:int}
 */
function calc_payslip_pay_items(PDO $pdo, array $summary, array $records): array
{
    $settings = wage_calc_settings($pdo);
    $premiumPct = $settings['overtime_rate_pct'] - 100;

    $dayByDate = [];
    foreach ($summary['daily'] as $dayRow) {
        $dayByDate[$dayRow['work_day']] = $dayRow;
    }

    // 端数のない額は「円×60」（基本給・休日手当）または「円×6000」（割増）の単位で積み上げる
    $categoryMinutes = array_fill_keys(SHIFT_CATEGORIES, 0);
    $categoryNumerator = array_fill_keys(SHIFT_CATEGORIES, 0);
    foreach ($records as $record) {
        $category = $record['category'] ?? ATTENDANCE_CATEGORY_NONE_LABEL;
        $dayRow = $dayByDate[$record['work_day']];
        $categoryMinutes[$category] = ($categoryMinutes[$category] ?? 0) + $record['work_minutes'];
        $categoryNumerator[$category] = ($categoryNumerator[$category] ?? 0) + $dayRow['rate_weekday'] * $record['work_minutes'];
    }

    $holidayMinutes = 0;
    $holidayNumerator = 0;
    $weekdayOvertimeNumerator = 0;
    $holidayOvertimeNumerator = 0;
    $nightNumerator = 0;
    foreach ($summary['daily'] as $dayRow) {
        if ($dayRow['is_holiday']) {
            $holidayMinutes += $dayRow['day_minutes'];
            $holidayNumerator += ($dayRow['rate_holiday'] - $dayRow['rate_weekday']) * $dayRow['day_minutes'];
            $holidayOvertimeNumerator += $dayRow['rate_holiday'] * $dayRow['overtime_minutes'] * $premiumPct;
        } else {
            $weekdayOvertimeNumerator += $dayRow['rate_weekday'] * $dayRow['overtime_minutes'] * $premiumPct;
        }
        $nightNumerator += $dayRow['rate'] * $dayRow['night_minutes'] * $settings['night_rate_pct'];
    }

    $items = [
        'category' => [],
        'holiday_minutes' => $holidayMinutes,
        'holiday_allowance' => ceil_div($holidayNumerator, 60),
        'weekday_overtime_minutes' => $summary['weekday_overtime_minutes'],
        'weekday_overtime_allowance' => ceil_div($weekdayOvertimeNumerator, 6000),
        'holiday_overtime_minutes' => $summary['holiday_overtime_minutes'],
        'holiday_overtime_allowance' => ceil_div($holidayOvertimeNumerator, 6000),
        'night_minutes' => $summary['night_minutes'],
        'night_allowance' => ceil_div($nightNumerator, 6000),
    ];
    $total = $items['holiday_allowance'] + $items['weekday_overtime_allowance'] + $items['holiday_overtime_allowance'] + $items['night_allowance'];
    foreach ($categoryMinutes as $category => $minutes) {
        $amount = ceil_div($categoryNumerator[$category], 60);
        $items['category'][$category] = ['minutes' => $minutes, 'amount' => $amount];
        $total += $amount;
    }
    $items['total'] = $total;

    return $items;
}

/**
 * 打刻区分（attendance.category）別の 出勤日数・労働時間・残業時間・深夜労働時間 と、その金額を集計する。
 * - 残業時間: 1日の打刻を出勤時刻順に積み上げ、その日の所定内の上限（通常は REGULAR_WORK_MINUTES_PER_DAY＝8時間。
 *   週40時間を超えた日はそれより短い。calc_wage_breakdown_from_daily_minutes() の daily[].regular_limit）を
 *   超えた時点以降に働いていた区分へ割り当てる（例: 店舗376分→洗濯代行181分の日は、残業77分がすべて洗濯代行）。
 *   区分別残業の合計は、日単位で算出する残業時間（overtime_minutes）と必ず一致する。
 * - 出勤日数: 延べ日数。同じ日に複数区分で打刻した日は、それぞれの区分に1日ずつ計上する。
 * - 区分がNULLの打刻（区分カラム導入前の2026年7月分など）は ATTENDANCE_CATEGORY_NONE_LABEL として計上し、
 *   区分別の労働・残業・深夜時間の合計が月合計と一致するようにする。
 * - 金額（$summary を渡した場合のみ）: 区分ごとの時給は存在しないため、日ごとに算出済みの所定内賃金・残業賃金・
 *   深夜手当（その日の適用時給＝平日/土日祝で計算済み）を、その日の区分別の所定内・残業・深夜時間の比率で按分し、
 *   distribute_category_wage_rounding() で丸める。基本給・残業手当・深夜手当のそれぞれで区分別の合計が
 *   月合計（base_wage / overtime_wage / night_wage）と1円単位で一致し、total_wage の合計も grand_total_wage と一致する。
 * $records は出勤時刻順に並んだ [work_day, category, work_minutes, night_minutes] の配列。
 * $summary は同じ打刻から calc_wage_breakdown_from_daily_minutes() で求めた結果（省略時は時間のみ集計し、上限は8時間）。
 * 返り値は SHIFT_CATEGORIES の順（区分なしは該当打刻がある場合のみ末尾に追加）。
 */
function calc_attendance_category_breakdown(array $records, ?array $summary = null): array
{
    $breakdown = [];
    foreach (SHIFT_CATEGORIES as $category) {
        $breakdown[$category] = ['attendance_days' => 0, 'total_minutes' => 0, 'overtime_minutes' => 0, 'night_minutes' => 0];
    }

    $regularLimitByDay = [];
    foreach ($summary['daily'] ?? [] as $dayRow) {
        $regularLimitByDay[$dayRow['work_day']] = (int) $dayRow['regular_limit'];
    }

    $cumulativeMinutesByDay = [];
    $daysByCategory = [];
    $dayCategoryMinutes = []; // 日付 => 区分 => [regular, overtime, night]
    foreach ($records as $record) {
        $category = $record['category'] ?? ATTENDANCE_CATEGORY_NONE_LABEL;
        if (!isset($breakdown[$category])) {
            $breakdown[$category] = ['attendance_days' => 0, 'total_minutes' => 0, 'overtime_minutes' => 0, 'night_minutes' => 0];
        }

        $day = $record['work_day'];
        $before = $cumulativeMinutesByDay[$day] ?? 0;
        $regularLimit = $regularLimitByDay[$day] ?? REGULAR_WORK_MINUTES_PER_DAY;
        $regularMinutes = max(0, min($record['work_minutes'], $regularLimit - $before));
        $cumulativeMinutesByDay[$day] = $before + $record['work_minutes'];

        $breakdown[$category]['total_minutes'] += $record['work_minutes'];
        $breakdown[$category]['overtime_minutes'] += $record['work_minutes'] - $regularMinutes;
        $breakdown[$category]['night_minutes'] += $record['night_minutes'];
        $daysByCategory[$category][$day] = true;

        $dayCategoryMinutes[$day][$category]['regular'] = ($dayCategoryMinutes[$day][$category]['regular'] ?? 0) + $regularMinutes;
        $dayCategoryMinutes[$day][$category]['overtime'] = ($dayCategoryMinutes[$day][$category]['overtime'] ?? 0) + $record['work_minutes'] - $regularMinutes;
        $dayCategoryMinutes[$day][$category]['night'] = ($dayCategoryMinutes[$day][$category]['night'] ?? 0) + $record['night_minutes'];
    }

    foreach ($daysByCategory as $category => $days) {
        $breakdown[$category]['attendance_days'] = count($days);
    }

    if ($summary === null) {
        return $breakdown;
    }

    $wageAccum = [
        'regular' => array_fill_keys(array_keys($breakdown), 0.0),
        'overtime' => array_fill_keys(array_keys($breakdown), 0.0),
        'night' => array_fill_keys(array_keys($breakdown), 0.0),
    ];
    foreach ($summary['daily'] as $dayRow) {
        $day = $dayRow['work_day'];
        $dayTotals = [
            'regular' => [(int) $dayRow['regular_minutes'], (int) $dayRow['regular_wage']],
            'overtime' => [(int) $dayRow['overtime_minutes'], (int) $dayRow['overtime_wage']],
            'night' => [(int) $dayRow['night_minutes'], (int) $dayRow['night_wage']],
        ];
        foreach ($dayTotals as $kind => [$dayKindMinutes, $dayKindWage]) {
            if ($dayKindMinutes <= 0) {
                continue;
            }
            foreach ($dayCategoryMinutes[$day] ?? [] as $category => $minutesByKind) {
                $wageAccum[$kind][$category] += $dayKindWage * $minutesByKind[$kind] / $dayKindMinutes;
            }
        }
    }

    $baseWage = distribute_category_wage_rounding($wageAccum['regular'], (int) $summary['base_wage']);
    $overtimeWage = distribute_category_wage_rounding($wageAccum['overtime'], (int) $summary['overtime_wage']);
    $nightWage = distribute_category_wage_rounding($wageAccum['night'], (int) $summary['night_wage']);
    foreach (array_keys($breakdown) as $category) {
        $breakdown[$category]['base_wage'] = $baseWage[$category];
        $breakdown[$category]['overtime_wage'] = $overtimeWage[$category];
        $breakdown[$category]['night_wage'] = $nightWage[$category];
        $breakdown[$category]['total_wage'] = $baseWage[$category] + $overtimeWage[$category] + $nightWage[$category];
    }

    return $breakdown;
}

/**
 * 月間集計の表示用データ（calc_wage_summary() の結果＋交通費・手当・確定状態）をまとめて返す。
 * 従業員の月間打刻実績（staff/attendance_monthly.php）の月間集計でのみ使う。表示専用で、確定・保存は行わない
 * （admin/wages.php はこの関数を使わず、calc_wage_summary() 等から独自に同じ値を計算している）。
 * 交通費・手当・合計は admin/wages.php の従業員一覧と同じく、確定済み（monthly_wagesに行がある）月は
 * 確定時の値、未確定の月は現在の実績からの試算値を display_* に入れる。
 * $employee には id, hourly_wage_weekday, hourly_wage_holiday, commute_allowance_type, commute_allowance_amount が必要。
 */
function build_monthly_wage_overview(PDO $pdo, array $employee, string $yearMonth): array
{
    $employeeId = (int) $employee['id'];
    $summary = calc_wage_summary($pdo, $employee, $yearMonth);
    $commuteTotal = calc_commute_allowance_total($employee, $summary['commute_trips']);
    $allowanceTotal = sum_allowance_amounts(get_employee_allowances($pdo, $employeeId));

    $confirmedStmt = $pdo->prepare(
        'SELECT total_wage, commute_allowance_total, allowance_total
         FROM monthly_wages WHERE employee_id = :employee_id AND `year_month` = :year_month'
    );
    $confirmedStmt->execute([':employee_id' => $employeeId, ':year_month' => $yearMonth]);
    $confirmed = $confirmedStmt->fetch();

    if ($confirmed !== false) {
        $displayCommuteTotal = (int) $confirmed['commute_allowance_total'];
        $displayAllowanceTotal = (int) $confirmed['allowance_total'];
        $displayGrandTotal = (int) $confirmed['total_wage'] + $displayCommuteTotal + $displayAllowanceTotal;
    } else {
        $displayCommuteTotal = $commuteTotal;
        $displayAllowanceTotal = $allowanceTotal;
        $displayGrandTotal = $summary['grand_total_wage'] + $commuteTotal + $allowanceTotal;
    }

    return [
        'summary' => $summary,
        'is_confirmed' => $confirmed !== false,
        'display_commute_total' => $displayCommuteTotal,
        'display_allowance_total' => $displayAllowanceTotal,
        'display_grand_total' => $displayGrandTotal,
    ];
}

/**
 * 交通費の月間計上額を計算する。
 * 日額区分: その月の交通費の計上回数（calc_wage_summary() の commute_trips。出勤日ごとに1回、
 *           同じ日に退勤〜再出勤の空きが COMMUTE_SEPARATE_TRIP_GAP_MINUTES 以上あればその分追加）× 日額
 * 月額区分: 出勤日数・回数に関わらず固定額
 */
function calc_commute_allowance_total(array $employee, int $commuteTrips): int
{
    $amount = (int) ($employee['commute_allowance_amount'] ?? 0);

    if (($employee['commute_allowance_type'] ?? 'daily') === 'monthly') {
        return $amount;
    }

    return $amount * $commuteTrips;
}

/**
 * 交通費の月間計上額を、トリップの区分別回数（calc_wage_summary() の commute_trips_by_category）の比率で区分に振り分ける。
 * 日額区分で回数が変わっていなければ「日額×その区分の回数」と一致する。月額区分や確定済みの月の確定額など、
 * 回数×日額にならない額も比率で按分し、端数は distribute_category_wage_rounding() で合計が $commuteTotal と一致するよう調整する。
 * トリップが無いのに額がある場合（出勤なしの月額区分など）は固定区分（allowance_category_for_employee()）に全額計上する。
 *
 * @param array<string,int> $tripsByCategory
 * @return array<string,int> 区分 => 交通費
 */
function distribute_commute_allowance_by_category(array $tripsByCategory, int $commuteTotal, int $employeeId): array
{
    $totalTrips = array_sum($tripsByCategory);
    if ($totalTrips === 0) {
        return $commuteTotal === 0 ? [] : [allowance_category_for_employee($employeeId) => $commuteTotal];
    }

    $accum = [];
    foreach ($tripsByCategory as $category => $trips) {
        $accum[$category] = $commuteTotal * $trips / $totalTrips;
    }

    return distribute_category_wage_rounding($accum, $commuteTotal);
}

/**
 * 区分別集計の行（calc_wage_summary() の category_breakdown に交通費・手当・合計を加えたもの）を作る。
 * 管理者の賃金確認（admin/wages.php）の区分別集計と、従業員の月間打刻実績（staff/attendance_monthly.php）の
 * 区分別で共通に使う（両画面で数値が一致するよう共通化）。
 * 交通費は distribute_commute_allowance_by_category() でトリップの区分別回数比に振り分け、
 * 手当は固定区分（allowance_category_for_employee()）に全額計上する。
 * $commuteTotal / $allowanceTotal は確定済みの月なら確定時の値、未確定なら現在の試算値を渡す。
 *
 * @return array<string, array> 区分 => category_breakdown の各値＋commute_allowance, allowance, grand_total
 */
function build_category_wage_rows(array $summary, int $commuteTotal, int $allowanceTotal, int $employeeId): array
{
    $commuteByCategory = distribute_commute_allowance_by_category($summary['commute_trips_by_category'], $commuteTotal, $employeeId);
    $allowanceCategory = allowance_category_for_employee($employeeId);

    $rows = [];
    foreach ($summary['category_breakdown'] as $category => $stats) {
        $commute = $commuteByCategory[$category] ?? 0;
        $allowance = $category === $allowanceCategory ? $allowanceTotal : 0;
        $rows[$category] = $stats + [
            'commute_allowance' => $commute,
            'allowance' => $allowance,
            'grand_total' => $stats['total_wage'] + $commute + $allowance,
        ];
    }

    return $rows;
}

function get_employee_allowances(PDO $pdo, int $employeeId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, monthly_amount FROM employee_allowances WHERE employee_id = :employee_id ORDER BY id'
    );
    $stmt->execute([':employee_id' => $employeeId]);
    return $stmt->fetchAll();
}

function sum_allowance_amounts(array $allowances): int
{
    $total = 0;
    foreach ($allowances as $allowance) {
        $total += (int) $allowance['monthly_amount'];
    }
    return $total;
}

/**
 * @return array{total: array<string,int>, category: array<string, array<string,int>>}
 *         total: 日付(Y-m-d) => 予定実働分（法定休憩控除後）
 *         category: 日付(Y-m-d) => 区分(SHIFT_CATEGORIES) => 予定実働分（法定休憩控除後）。
 *         法定休憩は、recalculate_daily_breaks()がその日最後のシフト（開始時刻が最も遅いもの）に
 *         寄せる仕様と揃えるため、区分別内訳でも最後のシフトの区分から差し引く。
 */
function calc_planned_minutes_by_day(PDO $pdo, int $employeeId, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        'SELECT work_date, start_time, end_time, categories
         FROM shifts
         WHERE employee_id = :employee_id AND work_date BETWEEN :start AND :end
         ORDER BY work_date, start_time, id'
    );
    $stmt->execute([':employee_id' => $employeeId, ':start' => $startDate, ':end' => $endDate]);

    $shiftsByDay = [];
    foreach ($stmt->fetchAll() as $shift) {
        $shiftsByDay[$shift['work_date']][] = $shift;
    }

    $plannedMinutesByDay = [];
    $plannedCategoryMinutesByDay = [];
    foreach ($shiftsByDay as $date => $dayShifts) {
        $rawMinutes = 0;
        $rawCategoryMinutes = array_fill_keys(SHIFT_CATEGORIES, 0);
        foreach ($dayShifts as $shift) {
            $minutes = calc_work_minutes($shift['start_time'], $shift['end_time'], 0);
            $rawMinutes += $minutes;
            $category = resolve_shift_category(categories_from_value($shift['categories']));
            if ($category !== null) {
                $rawCategoryMinutes[$category] += $minutes;
            }
        }

        $legalBreak = calc_legal_break_minutes($rawMinutes);
        $plannedMinutesByDay[$date] = max(0, $rawMinutes - $legalBreak);

        if ($legalBreak > 0) {
            $lastShift = end($dayShifts);
            $lastCategory = resolve_shift_category(categories_from_value($lastShift['categories']));
            if ($lastCategory !== null) {
                $rawCategoryMinutes[$lastCategory] = max(0, $rawCategoryMinutes[$lastCategory] - $legalBreak);
            }
        }
        $plannedCategoryMinutesByDay[$date] = $rawCategoryMinutes;
    }

    return ['total' => $plannedMinutesByDay, 'category' => $plannedCategoryMinutesByDay];
}

/**
 * シフト予定から月の見込み賃金を計算する（従業員ダッシュボード・管理者シフト表の「見込み」）。
 * 打刻ベースの賃金計算と同じ calc_wage_breakdown_from_daily_minutes() を使い、時給履歴・1日8時間／週40時間の
 * 割増を反映する。週40時間の判定には、月初より前の同じ週のシフト予定も含める。
 * $employee には id（と、時給履歴が無い場合の予備として hourly_wage_weekday, hourly_wage_holiday）が必要。
 */
function calc_shift_wage_estimate(PDO $pdo, array $employee, string $yearMonth): array
{
    [$monthStart, $monthEnd] = get_month_range($yearMonth);
    $planned = calc_planned_minutes_by_day($pdo, (int) $employee['id'], $monthStart, $monthEnd);

    $lookbackRange = wage_lookback_range($monthStart, wage_calc_settings($pdo)['week_start_dow']);
    $lookbackDailyMinutes = $lookbackRange === null
        ? []
        : calc_planned_minutes_by_day($pdo, (int) $employee['id'], $lookbackRange[0], $lookbackRange[1])['total'];

    return calc_wage_breakdown_from_daily_minutes($pdo, $employee, $planned['total'], [], $planned['category'], $lookbackDailyMinutes);
}
